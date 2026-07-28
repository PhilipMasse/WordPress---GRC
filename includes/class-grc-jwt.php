<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implémentation JWT minimaliste (HS256) — pas de dépendance externe.
 * Utilisée pour authentifier l'app mobile (Android/iOS) sur l'API REST /grc/v1/.
 */
class GRC_JWT {

	private const ALGO = 'sha256';

	/**
	 * Génère un token pour un user_id donné, avec durée de vie en secondes.
	 */
	public static function issue( int $user_id, int $ttl = 3600, array $extra_claims = [] ): string {
		$header = [ 'alg' => 'HS256', 'typ' => 'JWT' ];
		$now    = time();
		$payload = array_merge( [
			'sub' => $user_id,
			'iat' => $now,
			'exp' => $now + $ttl,
			'iss' => home_url(),
		], $extra_claims );

		$segments   = [];
		$segments[] = self::base64url_encode( wp_json_encode( $header ) );
		$segments[] = self::base64url_encode( wp_json_encode( $payload ) );
		$signing_input = implode( '.', $segments );
		$signature     = self::sign( $signing_input );
		$segments[]    = self::base64url_encode( $signature );

		return implode( '.', $segments );
	}

	/**
	 * Vérifie et décode un token. Retourne le payload (array) ou WP_Error.
	 */
	public static function verify( string $token ) {
		$parts = explode( '.', $token );
		if ( 3 !== count( $parts ) ) {
			return new WP_Error( 'grc_jwt_malformed', 'Token malformé.' );
		}
		[ $header_b64, $payload_b64, $sig_b64 ] = $parts;

		$expected_sig = self::sign( $header_b64 . '.' . $payload_b64 );
		$actual_sig   = self::base64url_decode( $sig_b64 );

		if ( ! hash_equals( $expected_sig, $actual_sig ) ) {
			return new WP_Error( 'grc_jwt_invalid_signature', 'Signature invalide.' );
		}

		$payload = json_decode( self::base64url_decode( $payload_b64 ), true );
		if ( ! is_array( $payload ) ) {
			return new WP_Error( 'grc_jwt_invalid_payload', 'Payload invalide.' );
		}

		if ( ! isset( $payload['exp'] ) || time() > (int) $payload['exp'] ) {
			return new WP_Error( 'grc_jwt_expired', 'Token expiré.' );
		}

		return $payload;
	}

	private static function sign( string $data ): string {
		if ( ! defined( 'GRC_JWT_SECRET' ) ) {
			throw new RuntimeException( 'GRC_JWT_SECRET non définie dans wp-config.php.' );
		}
		return hash_hmac( self::ALGO, $data, GRC_JWT_SECRET, true );
	}

	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $data ): string {
		$padded = str_pad( strtr( $data, '-_', '+/' ), strlen( $data ) % 4 === 0 ? strlen( $data ) : strlen( $data ) + ( 4 - strlen( $data ) % 4 ), '=', STR_PAD_RIGHT );
		return base64_decode( $padded );
	}
}
