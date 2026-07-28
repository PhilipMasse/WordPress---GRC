<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authentification des citoyens — système indépendant des comptes WordPress (wp_users).
 * Les agents/élus/admin continuent d'utiliser wp_authenticate() (voir GRC_REST_Auth).
 * Ici, un citoyen est authentifié via wp_grc_citoyens.password_hash (wp_hash_password,
 * même mécanisme de hachage que WordPress mais table séparée).
 */
class GRC_REST_Citoyen {

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/citoyen/register', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'register' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/login', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'login' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/refresh', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'refresh' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/me', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'me' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== self::get_authenticated_citoyen_id( $request );
			},
		] );
	}

	/**
	 * Inscription : crée un compte citoyen avec mot de passe, ou "complète" une
	 * fiche invité existante (créée lors d'un signalement précédent) si l'email
	 * correspond déjà à un citoyen sans mot de passe.
	 */
	public static function register( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'citoyen_register', 10, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$email    = sanitize_email( $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );
		$nom      = sanitize_text_field( $request->get_param( 'nom' ) ?? '' );
		$prenom   = sanitize_text_field( $request->get_param( 'prenom' ) ?? '' );
		$telephone = sanitize_text_field( $request->get_param( 'telephone' ) ?? '' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'grc_invalid_email', 'Adresse email invalide.', [ 'status' => 400 ] );
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'grc_weak_password', 'Le mot de passe doit contenir au moins 8 caractères.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$hash  = GRC_Encryption::search_hash( $email );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", $hash ) );

		if ( $existing && ! empty( $existing->password_hash ) ) {
			return new WP_Error( 'grc_email_taken', 'Un compte existe déjà avec cet email.', [ 'status' => 409 ] );
		}

		$password_hash = wp_hash_password( $password );

		if ( $existing ) {
			// Fiche invité existante (créée lors d'un signalement) : on la complète.
			$wpdb->update( $table, [
				'password_hash' => $password_hash,
				'is_guest'      => 0,
				'nom'           => $nom ? GRC_Encryption::encrypt( $nom ) : $existing->nom,
				'prenom'        => $prenom ? GRC_Encryption::encrypt( $prenom ) : $existing->prenom,
				'telephone'     => $telephone ? GRC_Encryption::encrypt( $telephone ) : $existing->telephone,
				'telephone_hash'=> $telephone ? GRC_Encryption::search_hash( $telephone ) : $existing->telephone_hash,
			], [ 'id' => $existing->id ] );
			$citoyen_id = (int) $existing->id;
		} else {
			$wpdb->insert( $table, [
				'nom'            => GRC_Encryption::encrypt( $nom ),
				'prenom'         => GRC_Encryption::encrypt( $prenom ),
				'email'          => GRC_Encryption::encrypt( $email ),
				'email_hash'     => $hash,
				'telephone'      => $telephone ? GRC_Encryption::encrypt( $telephone ) : null,
				'telephone_hash' => $telephone ? GRC_Encryption::search_hash( $telephone ) : null,
				'password_hash'  => $password_hash,
				'is_guest'       => 0,
				'created_at'     => current_time( 'mysql' ),
			] );
			$citoyen_id = (int) $wpdb->insert_id;
		}

		GRC_Audit_Log::log( 'citoyen_registered', 'citoyen', $citoyen_id );

		return self::issue_tokens( $citoyen_id );
	}

	public static function login( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'citoyen_login', 8, 60 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez dans une minute.', [ 'status' => 429 ] );
		}

		$email    = sanitize_email( $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$hash  = GRC_Encryption::search_hash( $email );

		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", $hash ) );

		if ( ! $citoyen || empty( $citoyen->password_hash ) || ! wp_check_password( $password, $citoyen->password_hash ) ) {
			GRC_Audit_Log::log( 'citoyen_login_failed', 'citoyen', $citoyen->id ?? null );
			return new WP_Error( 'grc_login_failed', 'Identifiants invalides.', [ 'status' => 401 ] );
		}

		GRC_Audit_Log::log( 'citoyen_login_success', 'citoyen', (int) $citoyen->id );

		return self::issue_tokens( (int) $citoyen->id );
	}

	public static function refresh( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'api_tokens';

		$token_hash = hash( 'sha256', (string) $request->get_param( 'refresh_token' ) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE refresh_token_hash = %s AND revoked = 0 AND expires_at > %s AND device_label = %s",
			$token_hash,
			current_time( 'mysql' ),
			'citoyen'
		) );

		if ( ! $row ) {
			return new WP_Error( 'grc_invalid_refresh', 'Refresh token invalide ou expiré.', [ 'status' => 401 ] );
		}

		$access_token = GRC_JWT::issue( (int) $row->wp_user_id, 3600, [ 'type' => 'citoyen' ] );

		return [ 'access_token' => $access_token, 'expires_in' => 3600 ];
	}

	public static function me( WP_REST_Request $request ) {
		$citoyen_id = self::get_authenticated_citoyen_id( $request );

		global $wpdb;
		$table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $citoyen_id ) );

		if ( ! $citoyen ) {
			return new WP_Error( 'grc_not_found', 'Citoyen introuvable.', [ 'status' => 404 ] );
		}

		return [
			'id'      => (int) $citoyen->id,
			'nom'     => $citoyen->nom ? GRC_Encryption::decrypt( $citoyen->nom ) : null,
			'prenom'  => $citoyen->prenom ? GRC_Encryption::decrypt( $citoyen->prenom ) : null,
			'email'   => $citoyen->email ? GRC_Encryption::decrypt( $citoyen->email ) : null,
			'telephone' => $citoyen->telephone ? GRC_Encryption::decrypt( $citoyen->telephone ) : null,
		];
	}

	/**
	 * Récupère l'ID citoyen authentifié via un JWT de type "citoyen" (voir middleware GRC_REST_API).
	 * Retourne null si non authentifié ou si le token est de type "agent".
	 */
	public static function get_authenticated_citoyen_id( WP_REST_Request $request ): ?int {
		$payload = $request->get_param( '_grc_jwt_payload' );
		if ( is_array( $payload ) && isset( $payload['type'] ) && 'citoyen' === $payload['type'] ) {
			return (int) $payload['sub'];
		}
		return null;
	}

	private static function issue_tokens( int $citoyen_id ): array {
		$access_token  = GRC_JWT::issue( $citoyen_id, 3600, [ 'type' => 'citoyen' ] );
		$refresh_token = self::issue_refresh_token( $citoyen_id );

		return [
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'expires_in'    => 3600,
			'citoyen_id'    => $citoyen_id,
		];
	}

	private static function issue_refresh_token( int $citoyen_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'api_tokens';

		$raw_token  = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $raw_token );

		// Réutilise wp_user_id pour stocker le citoyen_id ; device_label='citoyen' distingue le type de token
		// (voir aussi GRC_REST_Auth pour les tokens agents, qui n'utilisent pas ce marqueur).
		$wpdb->insert( $table, [
			'wp_user_id'         => $citoyen_id,
			'refresh_token_hash' => $token_hash,
			'device_label'       => 'citoyen',
			'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + ( 90 * DAY_IN_SECONDS ) ),
			'created_at'         => current_time( 'mysql' ),
		] );

		return $raw_token;
	}
}
