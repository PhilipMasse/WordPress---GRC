<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers GRC_TOTP
 */
final class TotpTest extends TestCase {

	/**
	 * Vecteur de test officiel RFC 6238 (Appendix B) : secret ASCII
	 * "12345678901234567890", encodé en Base32 (notre implémentation ne
	 * travaille qu'en Base32, format standard des applications
	 * d'authentification).
	 */
	private function secret_test_rfc(): string {
		return $this->base32_depuis_ascii( '12345678901234567890' );
	}

	private function base32_depuis_ascii( string $s ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$bin = '';
		foreach ( str_split( $s ) as $c ) {
			$bin .= str_pad( decbin( ord( $c ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bin, 5 ) as $g ) {
			$g = str_pad( $g, 5, '0', STR_PAD_RIGHT );
			$out .= $alphabet[ bindec( $g ) ];
		}
		return $out;
	}

	private function code_pour_tranche( string $secret, int $tranche ): string {
		$reflection = new ReflectionClass( GRC_TOTP::class );
		$methode = $reflection->getMethod( 'code_pour_tranche' );
		$methode->setAccessible( true );
		return $methode->invoke( null, $secret, $tranche );
	}

	public function test_code_matches_official_rfc6238_test_vector(): void {
		// T=59 secondes => tranche 1 (59 / 30 = 1.96 => floor = 1).
		$this->assertSame( '287082', $this->code_pour_tranche( $this->secret_test_rfc(), 1 ) );
	}

	public function test_generer_secret_produces_valid_base32(): void {
		$secret = GRC_TOTP::generer_secret();

		$this->assertMatchesRegularExpression( '/^[A-Z2-7]+$/', $secret );
		$this->assertGreaterThanOrEqual( 16, strlen( $secret ) );
	}

	public function test_uri_provisionnement_contains_expected_parts(): void {
		$secret = GRC_TOTP::generer_secret();
		$uri = GRC_TOTP::uri_provisionnement( $secret, 'citoyen@example.fr' );

		$this->assertStringStartsWith( 'otpauth://totp/', $uri );
		$this->assertStringContainsString( 'secret=' . $secret, $uri );
		$this->assertStringContainsString( 'digits=6', $uri );
		$this->assertStringContainsString( 'period=30', $uri );
	}

	public function test_verifier_accepts_current_valid_code(): void {
		$secret = GRC_TOTP::generer_secret();
		$tranche_actuelle = (int) floor( time() / 30 );
		$code_valide = $this->code_pour_tranche( $secret, $tranche_actuelle );

		$this->assertTrue( GRC_TOTP::verifier( $secret, $code_valide ) );
	}

	public function test_verifier_rejects_incorrect_code(): void {
		$secret = GRC_TOTP::generer_secret();

		$this->assertFalse( GRC_TOTP::verifier( $secret, '000000' ) );
	}

	public function test_verifier_rejects_malformed_input(): void {
		$secret = GRC_TOTP::generer_secret();

		$this->assertFalse( GRC_TOTP::verifier( $secret, 'abcdef' ) );
		$this->assertFalse( GRC_TOTP::verifier( $secret, '123' ) );
		$this->assertFalse( GRC_TOTP::verifier( $secret, '' ) );
	}

	public function test_verifier_tolerates_one_period_clock_drift(): void {
		// Un décalage d'horloge léger (jusqu'à ±30s) doit rester accepté,
		// pour tolérer les appareils dont l'horloge n'est pas parfaitement
		// synchronisée.
		$secret = GRC_TOTP::generer_secret();
		$tranche_precedente = (int) floor( time() / 30 ) - 1;
		$code_precedent = $this->code_pour_tranche( $secret, $tranche_precedente );

		$this->assertTrue( GRC_TOTP::verifier( $secret, $code_precedent ) );
	}

	public function test_verifier_rejects_code_too_far_outside_window(): void {
		$secret = GRC_TOTP::generer_secret();
		$tranche_lointaine = (int) floor( time() / 30 ) - 10;
		$code_lointain = $this->code_pour_tranche( $secret, $tranche_lointaine );

		$this->assertFalse( GRC_TOTP::verifier( $secret, $code_lointain ) );
	}
}
