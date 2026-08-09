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

		register_rest_route( $ns, '/categories', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_categories' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/services', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_services' ],
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

		register_rest_route( $ns, '/demandes/proches', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'proches' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'lat' => [ 'required' => true ],
				'lng' => [ 'required' => true ],
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
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
			},
		] );
	}

	/**
	 * Création d'une demande — compte connecté OU invité (email fourni, pas de compte requis).
	 */
	public static function public_submit( WP_REST_Request $request ) {
		$citoyen_id_authentifie = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
		if ( ! $citoyen_id_authentifie ) {
			return new WP_Error( 'grc_login_required', 'Vous devez être connecté(e) à votre espace citoyen pour signaler un problème.', [ 'status' => 401 ] );
		}

		if ( ! GRC_REST_API::check_rate_limit( 'submit', 20, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de signalements envoyés, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$params = $request->get_json_params();

		$titre       = sanitize_text_field( $params['titre'] ?? '' );
		$description = wp_kses_post( $params['description'] ?? '' );
		$categorie_id = absint( $params['categorie_id'] ?? 0 );

		if ( empty( $titre ) ) {
			return new WP_Error( 'grc_missing_titre', 'Le titre est obligatoire.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$citoyen_id = $citoyen_id_authentifie;

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
		GRC_Notifications::notify_agents_nouvelle_demande( $demande_id );

		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$email_encrypted = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$citoyens_table} WHERE id = %d", $citoyen_id ) );
		$email = $email_encrypted ? GRC_Encryption::decrypt( $email_encrypted ) : '';

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
	/**
	 * Retourne les signalements non résolus situés à proximité d'un point
	 * donné (rayon fixe de 100 mètres), pour aider le citoyen à repérer un
	 * doublon potentiel avant d'envoyer son propre signalement. Formule de
	 * Haversine calculée directement en SQL (léger, pas besoin d'extension
	 * géospatiale MySQL pour un rayon aussi restreint).
	 */
	public static function proches( WP_REST_Request $request ) {
		$lat = (float) $request->get_param( 'lat' );
		$lng = (float) $request->get_param( 'lng' );
		if ( ! $lat || ! $lng ) {
			return [];
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$rayon_metres = 100;

		// 6371000 = rayon terrestre moyen en mètres.
		$sql = "SELECT id, numero_suivi, titre, statut, created_at,
				( 6371000 * acos(
					cos( radians(%f) ) * cos( radians(latitude) ) * cos( radians(longitude) - radians(%f) )
					+ sin( radians(%f) ) * sin( radians(latitude) )
				) ) AS distance_m
			FROM {$table}
			WHERE latitude IS NOT NULL AND longitude IS NOT NULL
			AND statut NOT IN ('resolu', 'cloture')
			HAVING distance_m <= %d
			ORDER BY distance_m ASC
			LIMIT 5";

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $lat, $lng, $lat, $rayon_metres ) );

		$statut_labels = [ 'nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'assigne' => 'Assigné', 'reouvert' => 'Réouvert' ];

		return array_map( function ( $d ) use ( $statut_labels ) {
			return [
				'titre'      => $d->titre,
				'statut'     => $statut_labels[ $d->statut ] ?? $d->statut,
				'distance_m' => (int) round( $d->distance_m ),
				'date'       => mysql2date( 'd/m/Y', $d->created_at ),
			];
		}, $rows );
	}

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

		GRC_Audit_Log::log( 'demande_guest_lookup', 'demande', (int) $demande->id );

		return self::format_demande_public( $demande );
	}

	/**
	 * Liste des catégories de signalement actives, pour peupler le sélecteur
	 * du formulaire côté application mobile (le site web les insère
	 * directement côté serveur dans la page, d'où l'absence historique de
	 * cette route jusqu'ici).
	 */
	public static function list_categories( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';
		$rows  = $wpdb->get_results( "SELECT id, nom, parent_id, service_id FROM {$table} WHERE actif = 1 ORDER BY ordre, nom" );

		return array_map( function ( $c ) {
			return [
				'id'         => (int) $c->id,
				'nom'        => $c->nom,
				'parent_id'  => $c->parent_id ? (int) $c->parent_id : null,
				'service_id' => $c->service_id ? (int) $c->service_id : null,
			];
		}, $rows );
	}

	/**
	 * Liste des services actifs, pour peupler le sélecteur de service côté
	 * application mobile (formulaire de prise de rendez-vous notamment) —
	 * même situation que list_categories() : jusqu'ici uniquement rendu
	 * côté serveur dans les pages du site web.
	 */
	public static function list_services( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$rows  = $wpdb->get_results( "SELECT id, nom FROM {$table} WHERE actif = 1 ORDER BY nom" );

		return array_map( function ( $s ) {
			return [
				'id'  => (int) $s->id,
				'nom' => $s->nom,
			];
		}, $rows );
	}

	public static function my_demandes( WP_REST_Request $request ) {
		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
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

	private static function generate_numero_suivi(): string {
		return 'GRC-' . gmdate( 'Y' ) . '-' . strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) );
	}

	private static function format_demande_public( $demande ): array {
		global $wpdb;
		$pj_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';
		$pieces = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, nom_original, mime_type FROM {$pj_table} WHERE demande_id = %d ORDER BY created_at ASC",
			$demande->id
		) );

		$satisfaction_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'satisfaction';
		$deja_note = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$satisfaction_table} WHERE demande_id = %d", $demande->id ) );

		return [
			'id'            => (int) $demande->id,
			'numero_suivi'  => $demande->numero_suivi,
			'titre'         => $demande->titre,
			'description'   => $demande->description,
			'statut'        => $demande->statut,
			'priorite'      => $demande->priorite,
			'categorie_id'  => $demande->categorie_id ? (int) $demande->categorie_id : null,
			'service_id'    => $demande->service_id ? (int) $demande->service_id : null,
			'latitude'      => $demande->latitude !== null ? (float) $demande->latitude : null,
			'longitude'     => $demande->longitude !== null ? (float) $demande->longitude : null,
			'adresse_lieu'  => $demande->adresse_lieu,
			'created_at'    => $demande->created_at,
			'updated_at'    => $demande->updated_at,
			'resolved_at'   => $demande->resolved_at,
			'peut_etre_note'=> in_array( $demande->statut, [ 'resolu', 'cloture' ], true ) && ! $deja_note,
			'pieces_jointes'=> array_map( function ( $p ) {
				return [
					'id'           => (int) $p->id,
					'nom_original' => $p->nom_original,
					'mime_type'    => $p->mime_type,
					'download_url' => rest_url( GRC_REST_API::NAMESPACE_V1 . '/pieces-jointes/' . $p->id ),
				];
			}, $pieces ),
		];
	}
}
