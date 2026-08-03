<?php
/**
 * Bootstrap de test — fournit des substituts (stubs) minimalistes aux
 * fonctions WordPress dont dépendent les classes testées ci-dessous, sans
 * nécessiter une installation WordPress/MySQL complète. Suffisant pour
 * tester en isolation la logique "pure" (chiffrement, JWT, captcha,
 * numéro citoyen) qui ne dépend pas de la base de données.
 *
 * Les tests d'intégration nécessitant $wpdb (réservation de créneaux,
 * requêtes REST complètes) ne sont volontairement PAS couverts ici : ils
 * nécessiteraient une véritable installation WordPress de test
 * (wp-phpunit + MySQL), hors de portée de cet environnement de
 * développement. Voir tests/README.md pour la marche à suivre si une
 * telle installation devient disponible.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'GRC_TABLE_PREFIX', 'grc_' );
define( 'GRC_VERSION', 'test' );

// --- Constantes de chiffrement/JWT de test (jamais utilisées en prod) -----
define( 'GRC_ENCRYPTION_KEY', base64_encode( str_repeat( "\x11", SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) ) );
define( 'GRC_JWT_SECRET', 'clef-de-test-ne-jamais-utiliser-en-production-0123456789abcdef' );

// --- Stubs WordPress minimalistes ------------------------------------------
if ( ! function_exists( 'home_url' ) ) {
	function home_url( $path = '' ) {
		return 'https://test.exemple.fr' . $path;
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

if ( ! function_exists( 'wp_rand' ) ) {
	function wp_rand( $min, $max ) {
		return random_int( $min, $max );
	}
}

if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'sel-de-test-' . $scheme . '-0123456789abcdef0123456789abcdef';
	}
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public $errors = [];
		public $error_data = [];

		public function __construct( $code = '', $message = '', $data = '' ) {
			if ( $code ) {
				$this->errors[ $code ][] = $message;
				if ( $data ) {
					$this->error_data[ $code ] = $data;
				}
			}
		}

		public function get_error_code() {
			$codes = array_keys( $this->errors );
			return $codes[0] ?? '';
		}

		public function get_error_message( $code = '' ) {
			if ( ! $code ) {
				$code = $this->get_error_code();
			}
			return $this->errors[ $code ][0] ?? '';
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// --- Classes testées --------------------------------------------------------
require_once __DIR__ . '/../includes/class-grc-encryption.php';
require_once __DIR__ . '/../includes/class-grc-jwt.php';
require_once __DIR__ . '/../includes/class-grc-captcha.php';
require_once __DIR__ . '/../includes/class-grc-citoyen-helper.php';
require_once __DIR__ . '/../includes/class-grc-totp.php';
