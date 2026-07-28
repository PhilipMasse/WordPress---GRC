<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoints REST pour les demandes/signalements.
 * - public-submit : création par un citoyen (connecté ou invité)
 * - guest-lookup   : suivi d'une demande via numéro + email (mode invité)
 * - CRUD authentifié pour les agents/élus
 */
class GRC_REST_Demandes {

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/demandes/public-submit', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'public_submit' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/demandes/guest-lookup', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'guest_lookup' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'numero_suivi' => [ 'required' => true, 'type' => 'string' ],
				'email'        => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( $ns, '/demandes', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_demandes' ],
			'permission_callback' => function () {
				return current_user_can( 'grc_manage_demandes' ) || current_user_can( 'grc_view_all' );
			},
		] );

		register_rest_route( $ns, '/demandes/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_demande' ],
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		] );

		register_rest_route( $ns, '/demandes/(?P<id>\d+)/statut', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'update_statut' ],
			'permission_callback' => function () {
				return current_user_can( 'grc_manage_demandes' );
			},
		] );

		register_rest_route( $ns, '/mes-demandes', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'my_demandes' ],
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		] );
	}

	/**
	 * Création d'une demande — compte connecté OU invité (email fourni, pas de compte requis).
	 */
	public static function public_submit( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'submit', 20, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de signalements envoyés, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$params = $request->get_json_params();

		$titre       = sanitize_text_field( $params['titre'] ?? '' );
		$description = wp_kses_post( $params['description'] ?? '' );
		$categorie_id = absint( $params['categorie_id'] ?? 0 );
		$email       = sanitize_email( $params['email'] ?? '' );
		$nom         = sanitize_text_field( $params['nom'] ?? '' );
		$prenom      = sanitize_text_field( $params['prenom'] ?? '' );
		$telephone   = sanitize_text_field( $params['telephone'] ?? '' );

		if ( empty( $titre ) ) {
			return new WP_Error( 'grc_missing_titre', 'Le titre est obligatoire.', [ 'status' => 400 ] );
		}
		if ( ! is_user_logged_in() && empty( $email ) ) {
			return new WP_Error( 'grc_missing_email', 'Email obligatoire pour un signalement en mode invité.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$citoyen_id = self::find_or_create_citoyen( [
			'wp_user_id' => get_current_user_id() ?: null,
			'nom'        => $nom,
			'prenom'     => $prenom,
			'email'      => $email,
			'telephone'  => $telephone,
			'is_guest'   => is_user_logged_in() ? 0 : 1,
		] );

		$numero_suivi = self::generate_numero_suivi();

		$categorie = null;
		if ( $categorie_id ) {
			$cat_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';
			$categorie = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$cat_table} WHERE id = %d", $categorie_id ) );
		}

		$sla_deadline = null;
		if ( $categorie && ! empty( $categorie->sla_heures ) ) {
			$sla_deadline = gmdate( 'Y-m-d H:i:s', time() + ( (int) $categorie->sla_heures * HOUR_IN_SECONDS ) );
		}

		$wpdb->insert( $demandes_table, [
			'numero_suivi'   => $numero_suivi,
			'citoyen_id'     => $citoyen_id,
			'categorie_id'   => $categorie_id ?: null,
			'service_id'     => $categorie->service_id ?? null,
			'titre'          => $titre,
			'description'    => $description,
			'statut'         => 'nouveau',
			'latitude'       => isset( $params['latitude'] ) ? floatval( $params['latitude'] ) : null,
			'longitude'      => isset( $params['longitude'] ) ? floatval( $params['longitude'] ) : null,
			'adresse_lieu'   => sanitize_text_field( $params['adresse_lieu'] ?? '' ),
			'date_limite_sla'=> $sla_deadline,
			'created_at'     => current_time( 'mysql' ),
		] );
		$demande_id = (int) $wpdb->insert_id;

		GRC_Audit_Log::log( 'demande_created', 'demande', $demande_id );

		if ( ! empty( $email ) ) {
			GRC_Notifications::send_demande_created( $demande_id, $email, $numero_suivi );
		}

		return [
			'id'           => $demande_id,
			'numero_suivi' => $numero_suivi,
			'statut'       => 'nouveau',
		];
	}

	/**
	 * Suivi en mode invité : numéro de suivi + email (vérifié via hash, jamais en clair).
	 */
	public static function guest_lookup( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'guest_lookup', 10, 60 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives.', [ 'status' => 429 ] );
		}

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$numero = sanitize_text_field( $request->get_param( 'numero_suivi' ) );
		$email_hash = GRC_Encryption::search_hash( sanitize_email( $request->get_param( 'email' ) ) );

		$demande = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.* FROM {$demandes_table} d
			 INNER JOIN {$citoyens_table} c ON c.id = d.citoyen_id
			 WHERE d.numero_suivi = %s AND c.email_hash = %s",
			$numero,
			$email_hash
		) );

		if ( ! $demande ) {
			return new WP_Error( 'grc_not_found', 'Aucune demande trouvée pour ces informations.', [ 'status' => 404 ] );
		}

		return self::format_demande_public( $demande );
	}

	public static function my_demandes( WP_REST_Request $request ) {
		global $wpdb;
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$citoyen_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$citoyens_table} WHERE wp_user_id = %d", get_current_user_id() ) );
		if ( ! $citoyen_id ) {
			return [];
		}

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$demandes_table} WHERE citoyen_id = %d ORDER BY created_at DESC", $citoyen_id ) );
		return array_map( [ __CLASS__, 'format_demande_public' ], $rows );
	}

	public static function list_demandes( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$where  = [ '1=1' ];
		$params = [];

		if ( ! current_user_can( 'grc_view_all' ) ) {
			// L'agent ne voit que les demandes de son service.
			$agents_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'agents';
			$service_id   = $wpdb->get_var( $wpdb->prepare( "SELECT service_id FROM {$agents_table} WHERE wp_user_id = %d", get_current_user_id() ) );
			$where[]  = 'service_id = %d';
			$params[] = (int) $service_id;
		}

		if ( $statut = $request->get_param( 'statut' ) ) {
			$where[]  = 'statut = %s';
			$params[] = sanitize_text_field( $statut );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC LIMIT 100';
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		return array_map( [ __CLASS__, 'format_demande_public' ], $rows );
	}

	public static function get_demande( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$demande = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
		if ( ! $demande ) {
			return new WP_Error( 'grc_not_found', 'Demande introuvable.', [ 'status' => 404 ] );
		}
		GRC_Audit_Log::log( 'demande_viewed', 'demande', $demande->id );
		return self::format_demande_public( $demande );
	}

	public static function update_statut( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$id    = (int) $request['id'];
		$statut = sanitize_text_field( $request->get_param( 'statut' ) );

		$allowed = [ 'nouveau', 'en_cours', 'assigne', 'resolu', 'cloture', 'reouvert' ];
		if ( ! in_array( $statut, $allowed, true ) ) {
			return new WP_Error( 'grc_invalid_statut', 'Statut invalide.', [ 'status' => 400 ] );
		}

		$extra = [];
		if ( 'resolu' === $statut ) {
			$extra['resolved_at'] = current_time( 'mysql' );
		}
		if ( 'cloture' === $statut ) {
			$extra['closed_at'] = current_time( 'mysql' );
		}

		$wpdb->update( $table, array_merge( [ 'statut' => $statut ], $extra ), [ 'id' => $id ] );
		GRC_Audit_Log::log( 'demande_statut_changed', 'demande', $id, [ 'nouveau_statut' => $statut ] );

		return [ 'success' => true, 'statut' => $statut ];
	}

	// -- Helpers -------------------------------------------------------------

	private static function find_or_create_citoyen( array $data ): int {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		if ( ! empty( $data['wp_user_id'] ) ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE wp_user_id = %d", $data['wp_user_id'] ) );
			if ( $existing ) {
				return (int) $existing;
			}
		} elseif ( ! empty( $data['email'] ) ) {
			$hash = GRC_Encryption::search_hash( $data['email'] );
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE email_hash = %s", $hash ) );
			if ( $existing ) {
				return (int) $existing;
			}
		}

		$wpdb->insert( $table, [
			'wp_user_id'      => $data['wp_user_id'],
			'nom'             => GRC_Encryption::encrypt( $data['nom'] ),
			'prenom'          => GRC_Encryption::encrypt( $data['prenom'] ),
			'email'           => GRC_Encryption::encrypt( $data['email'] ),
			'email_hash'      => ! empty( $data['email'] ) ? GRC_Encryption::search_hash( $data['email'] ) : null,
			'telephone'       => GRC_Encryption::encrypt( $data['telephone'] ),
			'telephone_hash'  => ! empty( $data['telephone'] ) ? GRC_Encryption::search_hash( $data['telephone'] ) : null,
			'is_guest'        => $data['is_guest'] ? 1 : 0,
			'created_at'      => current_time( 'mysql' ),
		] );

		return (int) $wpdb->insert_id;
	}

	private static function generate_numero_suivi(): string {
		return 'GRC-' . gmdate( 'Y' ) . '-' . strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) );
	}

	private static function format_demande_public( $demande ): array {
		return [
			'id'           => (int) $demande->id,
			'numero_suivi' => $demande->numero_suivi,
			'titre'        => $demande->titre,
			'description'  => $demande->description,
			'statut'       => $demande->statut,
			'priorite'     => $demande->priorite,
			'categorie_id' => $demande->categorie_id ? (int) $demande->categorie_id : null,
			'service_id'   => $demande->service_id ? (int) $demande->service_id : null,
			'created_at'   => $demande->created_at,
			'updated_at'   => $demande->updated_at,
			'resolved_at'  => $demande->resolved_at,
		];
	}
}
