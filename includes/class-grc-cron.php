<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tâches planifiées : escalade des demandes en dépassement de SLA,
 * purge RGPD des données au-delà de la durée de conservation.
 */
class GRC_Cron {

	public static function init() {
		add_action( 'grc_daily_maintenance', [ __CLASS__, 'check_sla_escalation' ] );
		add_action( 'grc_daily_maintenance', [ __CLASS__, 'purge_rgpd_expired' ] );
		add_action( 'grc_daily_maintenance', [ __CLASS__, 'send_rdv_reminders' ] );
		add_action( 'grc_daily_maintenance', [ __CLASS__, 'extend_creneaux_generation' ] );
		add_action( 'grc_hourly_maintenance', [ __CLASS__, 'check_rdv_auto_refus' ] );

		if ( ! wp_next_scheduled( 'grc_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'grc_daily_maintenance' );
		}
		if ( ! wp_next_scheduled( 'grc_hourly_maintenance' ) ) {
			wp_schedule_event( time(), 'hourly', 'grc_hourly_maintenance' );
		}
	}

	/**
	 * Refuse automatiquement les demandes de rendez-vous en attente depuis plus
	 * longtemps que le délai configuré (Réglages GRC), et notifie le citoyen.
	 */
	public static function check_rdv_auto_refus() {
		$delai_heures = max( 1, (int) get_option( 'grc_rdv_delai_validation_heures', 48 ) );

		global $wpdb;
		$rdv_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';

		$limite = gmdate( 'Y-m-d H:i:s', time() - ( $delai_heures * HOUR_IN_SECONDS ) );

		$expires = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$rdv_table} WHERE statut = 'en_attente' AND created_at <= %s",
			$limite
		) );

		foreach ( $expires as $rdv_id ) {
			GRC_Admin_RDV::refuse_rdv( (int) $rdv_id, true );
		}
	}

	/**
	 * Maintient une fenêtre glissante de créneaux générés (90 jours) pour tous
	 * les services ayant un modèle de disponibilité actif, afin que le
	 * calendrier citoyen ne soit jamais en attente de génération à la volée
	 * au-delà de cette fenêtre.
	 */
	public static function extend_creneaux_generation() {
		global $wpdb;
		$dispo_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'disponibilites';
		$service_ids = $wpdb->get_col( "SELECT DISTINCT service_id FROM {$dispo_table} WHERE actif = 1" );

		$debut = current_time( 'Y-m-d' );
		$fin   = gmdate( 'Y-m-d', strtotime( '+90 days' ) );

		foreach ( $service_ids as $service_id ) {
			GRC_Creneaux_Generator::generate_range( (int) $service_id, $debut, $fin );
		}
	}

	/**
	 * Envoie un rappel par email pour les rendez-vous confirmés ayant lieu le lendemain.
	 */
	public static function send_rdv_reminders() {
		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$demain_debut = gmdate( 'Y-m-d 00:00:00', strtotime( '+1 day' ) );
		$demain_fin   = gmdate( 'Y-m-d 23:59:59', strtotime( '+1 day' ) );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.id, r.citoyen_id, c.debut FROM {$rdv_table} r
			 INNER JOIN {$creneaux_table} c ON c.id = r.creneau_id
			 WHERE r.statut = 'confirme' AND c.debut BETWEEN %s AND %s",
			$demain_debut,
			$demain_fin
		) );

		foreach ( $rows as $rdv ) {
			if ( ! $rdv->citoyen_id ) {
				continue;
			}
			$email_encrypted = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$citoyens_table} WHERE id = %d", $rdv->citoyen_id ) );
			if ( ! $email_encrypted ) {
				continue;
			}
			$email = GRC_Encryption::decrypt( $email_encrypted );
			if ( $email ) {
				GRC_Notifications::send_rdv_reminder( $email, $rdv->debut );
			}
		}
	}

	public static function check_sla_escalation() {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$en_retard = $wpdb->get_results(
			"SELECT * FROM {$table}
			 WHERE date_limite_sla IS NOT NULL
			 AND date_limite_sla < NOW()
			 AND statut NOT IN ('resolu', 'cloture')"
		);

		foreach ( $en_retard as $demande ) {
			do_action( 'grc_sla_depasse', $demande->id );
			GRC_Audit_Log::log( 'sla_depasse', 'demande', $demande->id );
		}
	}

	/**
	 * Purge RGPD : anonymise les fiches citoyens liées à des demandes
	 * clôturées depuis plus de X années (configurable, 3 ans par défaut).
	 */
	public static function purge_rgpd_expired() {
		$retention_years = (int) get_option( 'grc_rgpd_retention_years', 3 );

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$seuil = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_years} years" ) );

		$citoyens_a_anonymiser = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT c.id FROM {$citoyens_table} c
			 INNER JOIN {$demandes_table} d ON d.citoyen_id = c.id
			 WHERE d.closed_at IS NOT NULL AND d.closed_at < %s
			 AND c.nom != 'ANONYMISE'",
			$seuil
		) );

		foreach ( $citoyens_a_anonymiser as $citoyen_id ) {
			$wpdb->update( $citoyens_table, [
				'nom'            => 'ANONYMISE',
				'prenom'         => null,
				'email'          => null,
				'email_hash'     => null,
				'telephone'      => null,
				'telephone_hash' => null,
				'adresse'        => null,
			], [ 'id' => $citoyen_id ] );
			GRC_Audit_Log::log( 'rgpd_auto_anonymise', 'citoyen', (int) $citoyen_id );
		}
	}
}
