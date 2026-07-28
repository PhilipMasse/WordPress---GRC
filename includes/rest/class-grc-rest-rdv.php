<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoints REST pour la prise de rendez-vous.
 */
class GRC_REST_RDV {

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/rdv/creneaux', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_creneaux' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'service_id' => [ 'required' => true, 'type' => 'integer' ],
			],
		] );

		register_rest_route( $ns, '/rdv/disponibilites', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_disponibilites' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'service_id' => [ 'required' => true, 'type' => 'integer' ],
			],
		] );

		register_rest_route( $ns, '/rdv', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'book' ],
			'permission_callback' => '__return_true', // Citoyen JWT ou invité — vérifié dans le callback.
		] );

		register_rest_route( $ns, '/mes-rdv', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'my_rdv' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
			},
		] );

		register_rest_route( $ns, '/rdv/(?P<id>\d+)/annuler', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'cancel' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return self::can_access_rdv( $request, absint( $request['id'] ) );
			},
		] );
	}

	public static function list_creneaux( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';

		$service_id    = absint( $request->get_param( 'service_id' ) );
		$mois          = sanitize_text_field( $request->get_param( 'mois' ) ?? '' ); // format "YYYY-MM"
		$duree_filtre  = absint( $request->get_param( 'duree' ) ?? 0 ); // 30 ou 60, 0 = toutes durées

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $mois ) ) {
			$mois = current_time( 'Y-m' );
		}

		$debut_mois = $mois . '-01 00:00:00';
		$fin_mois   = gmdate( 'Y-m-t 23:59:59', strtotime( $debut_mois ) );
		// Ne propose jamais un créneau déjà passé, même s'il est dans le mois affiché.
		$borne_min  = max( strtotime( $debut_mois ), time() );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE service_id = %d AND debut >= %s AND debut <= %s ORDER BY debut ASC",
			$service_id,
			gmdate( 'Y-m-d H:i:s', $borne_min ),
			$fin_mois
		) );

		$results = [];
		foreach ( $rows as $c ) {
			$duree_minutes = round( ( strtotime( $c->fin ) - strtotime( $c->debut ) ) / 60 );
			if ( $duree_filtre && $duree_minutes !== $duree_filtre ) {
				continue;
			}
			$results[] = [
				'id'               => (int) $c->id,
				'debut'            => $c->debut,
				'fin'              => $c->fin,
				'duree_minutes'    => $duree_minutes,
				'capacite'         => (int) $c->capacite,
				'places_restantes' => max( 0, (int) $c->capacite - (int) $c->reserve ),
			];
		}

		return $results;
	}

	/**
	 * Agrégation par jour pour la vue calendrier citoyenne : nombre de places
	 * restantes et statut (aucune / dernieres / disponible) par jour, pour un
	 * service et une durée de créneau données, sur un mois donné.
	 */
	public static function list_disponibilites( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';

		$service_id = absint( $request->get_param( 'service_id' ) );
		$duree      = absint( $request->get_param( 'duree' ) ?? 30 );
		$mois       = sanitize_text_field( $request->get_param( 'mois' ) ?? '' );

		if ( ! preg_match( '/^\d{4}-\d{2}$/', $mois ) ) {
			$mois = gmdate( 'Y-m' );
		}

		$debut_mois = $mois . '-01 00:00:00';
		$fin_mois   = gmdate( 'Y-m-t 23:59:59', strtotime( $debut_mois ) );
		$borne_min  = max( strtotime( $debut_mois ), time() );

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE(debut) as jour, SUM(capacite) as total, SUM(reserve) as reserve
			 FROM {$table}
			 WHERE service_id = %d
			 AND debut >= %s
			 AND debut <= %s
			 AND TIMESTAMPDIFF(MINUTE, debut, fin) = %d
			 GROUP BY DATE(debut)",
			$service_id,
			gmdate( 'Y-m-d H:i:s', $borne_min ),
			$fin_mois,
			$duree
		) );

		return array_map( function ( $r ) {
			$total     = (int) $r->total;
			$restantes = max( 0, $total - (int) $r->reserve );
			$seuil_bas = max( 1, (int) round( $total * 0.2 ) );

			if ( $restantes <= 0 ) {
				$statut = 'aucune';
			} elseif ( $restantes <= $seuil_bas ) {
				$statut = 'dernieres';
			} else {
				$statut = 'disponible';
			}

			return [
				'jour'             => $r->jour,
				'places_restantes' => $restantes,
				'statut'           => $statut,
			];
		}, $rows );
	}

	public static function book( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'rdv_book', 10, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez plus tard.', [ 'status' => 429 ] );
		}

		global $wpdb;
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$creneau_id = absint( $request->get_param( 'creneau_id' ) );
		$motif      = sanitize_text_field( $request->get_param( 'motif' ) ?? '' );

		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
		$email      = null;

		if ( ! $citoyen_id ) {
			$email = sanitize_email( $request->get_param( 'email' ) ?? '' );
			$nom       = sanitize_text_field( $request->get_param( 'nom' ) ?? '' );
			$prenom    = sanitize_text_field( $request->get_param( 'prenom' ) ?? '' );
			$telephone = sanitize_text_field( $request->get_param( 'telephone' ) ?? '' );

			if ( ! $email ) {
				return new WP_Error( 'grc_missing_email', 'Email obligatoire pour une prise de rendez-vous en mode invité.', [ 'status' => 400 ] );
			}

			$hash     = GRC_Encryption::search_hash( $email );
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$citoyens_table} WHERE email_hash = %s", $hash ) );

			if ( $existing ) {
				$citoyen_id = (int) $existing;
			} else {
				$wpdb->insert( $citoyens_table, [
					'nom'            => $nom ? GRC_Encryption::encrypt( $nom ) : null,
					'prenom'         => $prenom ? GRC_Encryption::encrypt( $prenom ) : null,
					'email'          => GRC_Encryption::encrypt( $email ),
					'email_hash'     => $hash,
					'telephone'      => $telephone ? GRC_Encryption::encrypt( $telephone ) : null,
					'telephone_hash' => $telephone ? GRC_Encryption::search_hash( $telephone ) : null,
					'is_guest'       => 1,
					'created_at'     => current_time( 'mysql' ),
				] );
				$citoyen_id = (int) $wpdb->insert_id;
			}
		}

		// Verrouillage optimiste : on ne réserve que si une place reste disponible.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$creneaux_table} SET reserve = reserve + 1 WHERE id = %d AND reserve < capacite",
			$creneau_id
		) );

		if ( ! $updated ) {
			return new WP_Error( 'grc_creneau_full', 'Ce créneau n\'est plus disponible.', [ 'status' => 409 ] );
		}

		$creneau = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$creneaux_table} WHERE id = %d", $creneau_id ) );

		$wpdb->insert( $rdv_table, [
			'citoyen_id' => $citoyen_id,
			'service_id' => $creneau->service_id,
			'creneau_id' => $creneau_id,
			'motif'      => $motif,
			'statut'     => 'confirme',
			'created_at' => current_time( 'mysql' ),
		] );
		$rdv_id = (int) $wpdb->insert_id;

		GRC_Audit_Log::log( 'rdv_created', 'rdv', $rdv_id );

		// Email de confirmation si on connaît l'email (citoyen ou invité).
		if ( ! $email && $citoyen_id ) {
			$email_encrypted = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$citoyens_table} WHERE id = %d", $citoyen_id ) );
			$email = $email_encrypted ? GRC_Encryption::decrypt( $email_encrypted ) : null;
		}
		if ( $email ) {
			GRC_Notifications::send_rdv_confirmation( $email, $creneau->debut );
		}

		return [ 'success' => true, 'id' => $rdv_id ];
	}

	public static function my_rdv( WP_REST_Request $request ) {
		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );

		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, c.debut, c.fin, s.nom AS service_nom FROM {$rdv_table} r
			 LEFT JOIN {$creneaux_table} c ON c.id = r.creneau_id
			 LEFT JOIN {$services_table} s ON s.id = r.service_id
			 WHERE r.citoyen_id = %d ORDER BY c.debut DESC",
			$citoyen_id
		) );

		return array_map( function ( $r ) {
			return [
				'id'          => (int) $r->id,
				'service_nom' => $r->service_nom,
				'motif'       => $r->motif,
				'statut'      => $r->statut,
				'debut'       => $r->debut,
				'fin'         => $r->fin,
			];
		}, $rows );
	}

	public static function cancel( WP_REST_Request $request ) {
		$id = absint( $request['id'] );

		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';

		$rdv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rdv_table} WHERE id = %d", $id ) );
		if ( ! $rdv ) {
			return new WP_Error( 'grc_not_found', 'Rendez-vous introuvable.', [ 'status' => 404 ] );
		}
		if ( 'annule' === $rdv->statut ) {
			return new WP_Error( 'grc_already_cancelled', 'Ce rendez-vous est déjà annulé.', [ 'status' => 400 ] );
		}

		$wpdb->update( $rdv_table, [ 'statut' => 'annule' ], [ 'id' => $id ] );
		$wpdb->query( $wpdb->prepare( "UPDATE {$creneaux_table} SET reserve = GREATEST(0, reserve - 1) WHERE id = %d", $rdv->creneau_id ) );

		GRC_Audit_Log::log( 'rdv_cancelled', 'rdv', $id );

		return [ 'success' => true ];
	}

	public static function can_access_rdv( WP_REST_Request $request, int $rdv_id ): bool {
		if ( current_user_can( 'grc_manage_demandes' ) || current_user_can( 'grc_view_all' ) ) {
			return true;
		}

		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
		if ( ! $citoyen_id ) {
			return false;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$owner = $wpdb->get_var( $wpdb->prepare( "SELECT citoyen_id FROM {$table} WHERE id = %d", $rdv_id ) );

		return $owner && (int) $owner === $citoyen_id;
	}
}
