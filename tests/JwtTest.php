<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers GRC_JWT
 */
final class JwtTest extends TestCase {

	public function test_issued_token_verifies_successfully(): void {
		$token = GRC_JWT::issue( 42, 3600 );
		$payload = GRC_JWT::verify( $token );

		$this->assertIsArray( $payload );
		$this->assertSame( 42, $payload['sub'] );
	}

	public function test_token_has_three_dot_separated_segments(): void {
		$token = GRC_JWT::issue( 1 );
		$this->assertCount( 3, explode( '.', $token ) );
	}

	public function test_extra_claims_are_preserved(): void {
		$token = GRC_JWT::issue( 7, 3600, [ 'type' => 'citoyen' ] );
		$payload = GRC_JWT::verify( $token );

		$this->assertSame( 'citoyen', $payload['type'] );
	}

	public function test_expired_token_is_rejected(): void {
		// TTL négatif => le token est déjà expiré au moment de l'émission.
		$token = GRC_JWT::issue( 1, -10 );
		$result = GRC_JWT::verify( $token );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'grc_jwt_expired', $result->get_error_code() );
	}

	public function test_malformed_token_is_rejected(): void {
		$result = GRC_JWT::verify( 'ceci-nest-pas-un-jwt' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'grc_jwt_malformed', $result->get_error_code() );
	}

	public function test_tampered_payload_is_rejected(): void {
		// Sécurité critique : falsifier la partie payload (ex: changer le
		// "sub" pour usurper un autre citoyen/agent) doit invalider la
		// signature et être détecté.
		$token = GRC_JWT::issue( 1, 3600 );
		[ $header, $payload, $signature ] = explode( '.', $token );

		$decoded_payload = json_decode( base64_decode( strtr( $payload, '-_', '+/' ) ), true );
		$decoded_payload['sub'] = 999; // Tentative d'usurpation.
		$tampered_payload = rtrim( strtr( base64_encode( json_encode( $decoded_payload ) ), '+/', '-_' ), '=' );

		$tampered_token = $header . '.' . $tampered_payload . '.' . $signature;
		$result = GRC_JWT::verify( $tampered_token );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'grc_jwt_invalid_signature', $result->get_error_code() );
	}

	public function test_token_signed_with_different_secret_is_rejected(): void {
		// Un token émis par une autre installation (secret JWT différent)
		// ne doit jamais être accepté.
		$foreign_signature = hash_hmac( 'sha256', 'donnee-quelconque', 'un-autre-secret-totalement-different', true );
		$fake_token = rtrim( strtr( base64_encode( '{"alg":"HS256"}' ), '+/', '-_' ), '=' )
			. '.' . rtrim( strtr( base64_encode( json_encode( [ 'sub' => 1, 'exp' => time() + 3600 ] ) ), '+/', '-_' ), '=' )
			. '.' . rtrim( strtr( base64_encode( $foreign_signature ), '+/', '-_' ), '=' );

		$result = GRC_JWT::verify( $fake_token );

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
