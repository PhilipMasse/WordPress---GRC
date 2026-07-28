<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-auth.php';
require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-citoyen.php';
require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-demandes.php';
require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-rdv.php';
require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-attachments.php';
require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-demarches.php';
require_once GRC_PLUGIN_DIR . 'includes/rest/class-grc-rest-satisfaction.php';

class GRC_REST_API {

	const NAMESPACE_V1 = 'grc/v1';

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );
		add_filter( 'rest_pre_dispatch', [ __CLASS__, 'authenticate_request' ], 10, 3 );
		add_filter( 'rest_pre_serve_request', [ __CLASS__, 'prevent_caching' ], 10, 4 );
	}

	/**
	 * Empêche la mise en cache des réponses de l'API GRC. Certains plugins/caches
	 * serveur agressifs mettent en cache les requêtes GET vers /wp-json/, ce qui
	 * peut faire persister une version obsolète (ex: fil de messages sans les
	 * derniers messages) même après une mise à jour côté serveur.
	 */
	public static function prevent_caching( $served, $result, $request, $server ) {
		if ( 0 === strpos( $request->get_route(), '/' . self::NAMESPACE_V1 ) ) {
			nocache_headers();
		}
		return $served;
	}

	public static function register_routes() {
		GRC_REST_Auth::register_routes();
		GRC_REST_Citoyen::register_routes();
		GRC_REST_Demandes::register_routes();
		GRC_REST_RDV::register_routes();
		GRC_REST_Attachments::register_routes();
		GRC_REST_Demarches::register_routes();
		GRC_REST_Satisfaction::register_routes();
	}

	/**
	 * Middleware d'authentification JWT pour toutes les routes grc/v1.
	 * Certaines routes sont publiques par nature (login, soumission invité, pièces jointes
	 * dont l'autorisation est vérifiée dans le callback lui-même) : pour celles-ci, le JWT
	 * reste optionnel — s'il est présent et valide, l'utilisateur est authentifié quand même
	 * (utile pour qu'un citoyen connecté accède à ses propres pièces jointes).
	 */
	public static function authenticate_request( $result, $server, $request ) {
		$route = $request->get_route();
		if ( 0 !== strpos( $route, '/' . self::NAMESPACE_V1 ) ) {
			return $result; // Pas une route GRC, on ne touche à rien.
		}

		$public_route_patterns = [
			'#^/grc/v1/auth/login$#',
			'#^/grc/v1/auth/refresh$#',
			'#^/grc/v1/citoyen/register$#',
			'#^/grc/v1/citoyen/login$#',
			'#^/grc/v1/citoyen/refresh$#',
			'#^/grc/v1/demandes/guest-lookup$#',
			'#^/grc/v1/demandes/public-submit$#',
			'#^/grc/v1/demandes/\d+/pieces-jointes$#',
			'#^/grc/v1/demandes/\d+/satisfaction$#',
			'#^/grc/v1/pieces-jointes/\d+$#',
			'#^/grc/v1/rdv/creneaux$#',
			'#^/grc/v1/rdv/disponibilites$#',
			'#^/grc/v1/rdv/durees$#',
			'#^/grc/v1/demarches/types$#',
			'#^/grc/v1/demarches$#',
			'#^/grc/v1/rdv$#',
		];

		$is_public_route = false;
		foreach ( $public_route_patterns as $pattern ) {
			if ( preg_match( $pattern, $route ) ) {
				$is_public_route = true;
				break;
			}
		}

		$auth_header = $request->get_header( 'authorization' );
		$has_bearer_header = $auth_header && 0 === stripos( $auth_header, 'Bearer ' );

		// Fallback : un lien <a href> classique (ex: ouvrir/télécharger une pièce
		// jointe dans un nouvel onglet) ne peut pas transporter d'en-tête HTTP
		// personnalisé. On accepte donc aussi le JWT citoyen en paramètre "token"
		// pour ces cas précis, en plus de l'en-tête Authorization habituel.
		$token_param = $request->get_param( 'token' );
		$has_token   = $has_bearer_header || ! empty( $token_param );

		if ( ! $is_public_route && ! $has_token && ! is_user_logged_in() ) {
			return new WP_Error( 'grc_no_token', 'Authentification requise.', [ 'status' => 401 ] );
		}

		if ( $has_token ) {
			$token   = $has_bearer_header ? trim( substr( $auth_header, 7 ) ) : (string) $token_param;
			$payload = GRC_JWT::verify( $token );

			if ( is_wp_error( $payload ) ) {
				// Sur une route publique, un token invalide ne bloque pas la requête :
				// elle continue simplement sans utilisateur authentifié.
				if ( $is_public_route ) {
					return $result;
				}
				return $payload;
			}

			$request->set_param( '_grc_jwt_payload', $payload );

			// IMPORTANT : le "sub" d'un token citoyen est un ID de la table wp_grc_citoyens,
			// PAS un ID wp_users. On n'appelle wp_set_current_user() QUE pour les tokens
			// agents (staff), afin d'éviter qu'un citoyen ne s'authentifie par coïncidence
			// comme un utilisateur WordPress partageant le même ID numérique.
			$token_type = $payload['type'] ?? 'agent'; // Rétrocompatibilité : anciens tokens sans "type" = agent.
			if ( 'agent' === $token_type ) {
				wp_set_current_user( (int) $payload['sub'] );
			}
		}

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
