<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-auth.php';
require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-demandes.php';
require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-rdv.php';

class GRC_REST_API {

	const NAMESPACE_V1 = 'grc/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'authenticate_request' ], 10, 3 );
	}

	public static function register_routes() {
		GRC_REST_Auth::register_routes();
		GRC_REST_Demandes::register_routes();
		GRC_REST_RDV::register_routes();
	}

	/**
	 * Middleware d'authentification JWT pour toutes les routes grc/v1
	 * (sauf login/register/guest-lookup qui sont publiques).
	 */
	public static function authenticate_request( $result, $server, $request ) {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . self::NAMESPACE_V1 ) ) {
			return $result; // Pas une route GRC, on ne touche à rien.
		}

		$public_routes = [
			'/grc/v1/auth/login',
			'/grc/v1/auth/refresh',
			'/grc/v1/demandes/guest-lookup',
			'/grc/v1/demandes/public-submit',
		];
		if ( in_array( $route, $public_routes, true ) ) {
			return $result;
		}

		$auth_header = $request->get_header( 'authorization' );
		if ( ! $auth_header || 0 !== stripos( $auth_header, 'Bearer ' ) ) {
			return new WP_Error( 'grc_no_token', 'Token d\'authentification manquant.', [ 'status' => 401 ] );
		}

		$token   = trim( substr( $auth_header, 7 ) );
		$payload = GRC_JWT::verify( $token );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}

		// Injecte l'utilisateur authentifié dans le contexte WP pour current_user_can(), etc.
		wp_set_current_user( (int) $payload['sub'] );
		$request->set_param( '_grc_jwt_payload', $payload );

		return $result;
	}

	/**
	 * Rate limiting simple basé sur transient (IP + route).
	 */
	public static function check_rate_limit( string $bucket, int $max_attempts = 10, int $window_seconds = 60 ): bool {
		$ip  = self::get_client_ip();
		$key = 'grc_rl_' . md5( $bucket . '|' . $ip );
		$count = (int) get_transient( $key );
		if ( $count >= $max_attempts ) {
			return false;
		}
		set_transient( $key, $count + 1, $window_seconds );
		return true;
	}

	public static function get_client_ip(): string {
		foreach ( [ 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0] );
			}
		}
		return '';
	}
}
