<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Génère automatiquement les créneaux réservables (wp_grc_creneaux) à partir
 * du modèle hebdomadaire de disponibilité d'un service (heures d'ouverture,
 * pause méridienne, durée de créneau) et des absences déclarées.
 *
 * L'administrateur ne gère plus jamais les créneaux un par un : il définit un
 * horaire par jour de semaine, et ce générateur matérialise les créneaux
 * correspondants au fur et à mesure (idempotent : peut être rappelé sans
 * risque de doublon).
 */
class GRC_Creneaux_Generator {

	/**
	 * Génère les créneaux pour un mois donné (format "YYYY-MM") d'un service,
	 * si ce n'est pas déjà fait. Appelé à la demande quand un citoyen consulte
	 * le calendrier, et quotidiennement par le cron pour garder une fenêtre
	 * glissante toujours à jour.
	 */
	public static function ensure_generated_for_month( int $service_id, string $mois ) {
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $mois ) ) {
			return;
		}
		$debut = $mois . '-01';
		$fin   = gmdate( 'Y-m-t', strtotime( $debut ) );
		self::generate_range( $service_id, $debut, $fin );
	}

	/**
	 * Génère les créneaux pour une plage de dates donnée d'un service.
	 */
	public static function generate_range( int $service_id, string $date_from, string $date_to ) {
		global $wpdb;
		$dispo_table    = $wpdb->prefix . GRC_TABLE_PREFIX . 'disponibilites';
		$absences_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'absences';

		$templates = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$dispo_table} WHERE service_id = %d AND actif = 1",
			$service_id
		) );
		if ( empty( $templates ) ) {
			return; // Aucun horaire défini pour ce service : rien à générer.
		}

		$templates_par_jour = [];
		foreach ( $templates as $t ) {
			$templates_par_jour[ (int) $t->jour_semaine ] = $t;
		}

		$absences = $wpdb->get_results( $wpdb->prepare(
			"SELECT date_debut, date_fin FROM {$absences_table}
			 WHERE ( service_id = %d OR service_id IS NULL )
			 AND date_debut <= %s AND date_fin >= %s",
			$service_id,
			$date_to,
			$date_from
		) );

		$current = strtotime( $date_from );
		$end_ts  = strtotime( $date_to );

		while ( $current <= $end_ts ) {
			$jour_str = gmdate( 'Y-m-d', $current );
			$dow      = (int) gmdate( 'w', $current );

			if ( isset( $templates_par_jour[ $dow ] ) && ! self::is_blocked( $jour_str, $absences ) ) {
				self::generate_day_slots( $service_id, $jour_str, $templates_par_jour[ $dow ] );
			}

			$current = strtotime( '+1 day', $current );
		}
	}

	private static function is_blocked( string $jour_str, array $absences ): bool {
		foreach ( $absences as $a ) {
			if ( $jour_str >= $a->date_debut && $jour_str <= $a->date_fin ) {
				return true;
			}
		}
		return false;
	}

	private static function generate_day_slots( int $service_id, string $jour_str, $tpl ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';

		$duree_secondes  = max( 5, (int) $tpl->duree_minutes ) * 60;
		$slot_start      = strtotime( $jour_str . ' ' . $tpl->heure_debut );
		$slot_end_of_day = strtotime( $jour_str . ' ' . $tpl->heure_fin );
		$pause_debut     = $tpl->pause_debut ? strtotime( $jour_str . ' ' . $tpl->pause_debut ) : null;
		$pause_fin       = $tpl->pause_fin ? strtotime( $jour_str . ' ' . $tpl->pause_fin ) : null;

		// Ne génère jamais un créneau déjà passé.
		$maintenant = time();

		while ( $slot_start + $duree_secondes <= $slot_end_of_day ) {
			$slot_end = $slot_start + $duree_secondes;

			$dans_la_pause = $pause_debut && $pause_fin && $slot_start < $pause_fin && $slot_end > $pause_debut;

			if ( ! $dans_la_pause && $slot_start > $maintenant ) {
				$debut_mysql = gmdate( 'Y-m-d H:i:s', $slot_start );
				$existe = $wpdb->get_var( $wpdb->prepare(
					"SELECT id FROM {$table} WHERE service_id = %d AND debut = %s",
					$service_id,
					$debut_mysql
				) );
				if ( ! $existe ) {
					$wpdb->insert( $table, [
						'service_id' => $service_id,
						'debut'      => $debut_mysql,
						'fin'        => gmdate( 'Y-m-d H:i:s', $slot_end ),
						'capacite'   => (int) $tpl->capacite,
						'reserve'    => 0,
					] );
				}
			}

			$slot_start = $slot_end;
		}
	}

	/**
	 * Supprime les créneaux futurs NON réservés d'un service (utilisé quand
	 * l'admin modifie son horaire hebdomadaire, pour que la régénération
	 * reflète le nouveau modèle sans laisser d'anciens créneaux orphelins).
	 * Les créneaux déjà réservés ne sont jamais supprimés automatiquement.
	 */
	public static function purge_unreserved_future( int $service_id ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$table} WHERE service_id = %d AND reserve = 0 AND debut > %s",
			$service_id,
			current_time( 'mysql' )
		) );
	}

	/**
	 * Supprime les créneaux non réservés dans une plage de dates (utilisé lors
	 * de la déclaration d'une absence) et retourne le nombre de rendez-vous
	 * déjà confirmés dans cette plage (à traiter manuellement par l'admin).
	 */
	public static function purge_unreserved_range( ?int $service_id, string $date_debut, string $date_fin ): int {
		global $wpdb;
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';

		$service_condition = $service_id ? $wpdb->prepare( 'AND service_id = %d', $service_id ) : '';

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$creneaux_table} WHERE reserve = 0 AND debut >= %s AND debut <= %s" . ( $service_id ? " AND service_id = %d" : '' ),
			...array_filter( [ $date_debut . ' 00:00:00', $date_fin . ' 23:59:59', $service_id ] )
		) );

		$count_sql = "SELECT COUNT(*) FROM {$rdv_table} r
			INNER JOIN {$creneaux_table} c ON c.id = r.creneau_id
			WHERE r.statut = 'confirme' AND c.debut >= %s AND c.debut <= %s" . ( $service_id ? " AND c.service_id = %d" : '' );

		return (int) $wpdb->get_var( $wpdb->prepare(
			$count_sql,
			...array_filter( [ $date_debut . ' 00:00:00', $date_fin . ' 23:59:59', $service_id ] )
		) );
	}
}
