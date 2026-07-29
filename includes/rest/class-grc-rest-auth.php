<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authentification pour l'app mobile : login (email/mot de passe WP), émission JWT + refresh token.
 */
class GRC_REST_Auth {

	public static function register_routes() {
		register_rest_route( GRC_REST_API::NAMESPACE_V1, '/auth/login', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'login' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'username' => [ 'required' => true, 'type' => 'string' ],
				'password' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( GRC_REST_API::NAMESPACE_V1, '/auth/refresh', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'refresh' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'refresh_token' => [ 'required' => true, 'type' => 'string' ],
			],
		] );

		register_rest_route( GRC_REST_API::NAMESPACE_V1, '/auth/logout', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'logout' ],
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		] );
	}

	public static function login( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'login', 5, 60 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez dans une minute.', [ 'status' => 429 ] );
		}

		$username = sanitize_user( $request->get_param( 'username' ) );
		$password = $request->get_param( 'password' );

		$user = wp_authenticate( $username, $password );
		if ( is_wp_error( $user ) ) {
			GRC_Audit_Log::log( 'login_failed', 'user', null, [ 'username' => $username ] );
			return new WP_Error( 'grc_login_failed', 'Identifiants invalides.', [ 'status' => 401 ] );
		}

		$access_token  = GRC_JWT::issue( $user->ID, 3600, [ 'type' => 'agent' ] ); // 1h
		$refresh_token = self::issue_refresh_token( $user->ID, $request->get_param( 'device_label' ) ?: '' );

		GRC_Audit_Log::log( 'login_success', 'user', $user->ID );

		return [
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'expires_in'    => 3600,
			'user'          => [
				'id'    => $user->ID,
				'email' => $user->user_email,
				'name'  => $user->display_name,
				'roles' => $user->roles,
			],
		];
	}

	public static function refresh( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'api_tokens';

		$token_hash = hash( 'sha256', $request->get_param( 'refresh_token' ) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE refresh_token_hash = %s AND revoked = 0 AND expires_at > %s AND ( device_label IS NULL OR device_label != 'citoyen' )",
			$token_hash,
			current_time( 'mysql' )
		) );

		if ( ! $row ) {
			return new WP_Error( 'grc_invalid_refresh', 'Refresh token invalide ou expiré.', [ 'status' => 401 ] );
		}

		$access_token = GRC_JWT::issue( (int) $row->wp_user_id, 3600, [ 'type' => 'agent' ] );

		return [
			'access_token' => $access_token,
			'expires_in'   => 3600,
		];
	}

	public static function logout( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'api_tokens';
		$wpdb->update( $table, [ 'revoked' => 1 ], [ 'wp_user_id' => get_current_user_id() ] );
		GRC_Audit_Log::log( 'agent_logout', 'user', get_current_user_id() );
		return [ 'success' => true ];
	}

	private static function issue_refresh_token( int $user_id, string $device_label ): string {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'api_tokens';

		$raw_token  = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $raw_token );

		$wpdb->insert( $table, [
			'wp_user_id'         => $user_id,
			'refresh_token_hash' => $token_hash,
			'device_label'       => sanitize_text_field( $device_label ),
			'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + ( 90 * DAY_IN_SECONDS ) ),
			'created_at'         => current_time( 'mysql' ),
		] );

		return $raw_token;
	}
}
