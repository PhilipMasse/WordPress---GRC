<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers GRC_Captcha
 */
final class CaptchaTest extends TestCase {

	public function test_generate_returns_token_and_question(): void {
		$challenge = GRC_Captcha::generate();

		$this->assertArrayHasKey( 'token', $challenge );
		$this->assertArrayHasKey( 'question', $challenge );
		$this->assertStringContainsString( 'Combien font', $challenge['question'] );
	}

	public function test_correct_answer_is_accepted(): void {
		// On ne peut pas connaître à l'avance la question générée (nombres
		// aléatoires), donc on extrait la réponse attendue directement du
		// token auto-porteur pour la boucle de vérification.
		$challenge = GRC_Captcha::generate();
		$reponse = $this->extraire_reponse_attendue( $challenge['token'] );

		$this->assertTrue( GRC_Captcha::verify( $challenge['token'], $reponse ) );
	}

	public function test_incorrect_answer_is_rejected(): void {
		$challenge = GRC_Captcha::generate();
		$reponse_attendue = $this->extraire_reponse_attendue( $challenge['token'] );

		$this->assertFalse( GRC_Captcha::verify( $challenge['token'], $reponse_attendue + 1000 ) );
	}

	public function test_empty_or_missing_token_is_rejected(): void {
		$this->assertFalse( GRC_Captcha::verify( null, '5' ) );
		$this->assertFalse( GRC_Captcha::verify( '', '5' ) );
	}

	public function test_tampered_token_is_rejected(): void {
		// Falsifier le token (ex: remplacer la réponse encodée dedans par
		// une valeur arbitraire) doit invalider la signature.
		$challenge = GRC_Captcha::generate();
		$tampered = substr( $challenge['token'], 0, -1 ) . ( '0' === substr( $challenge['token'], -1 ) ? '1' : '0' );

		$this->assertFalse( GRC_Captcha::verify( $tampered, '5' ) );
	}

	public function test_malformed_token_does_not_crash(): void {
		$this->assertFalse( GRC_Captcha::verify( 'pas-un-token-valide', '5' ) );
		$this->assertFalse( GRC_Captcha::verify( 'sans-point-separateur', '5' ) );
	}

	public function test_answer_with_surrounding_whitespace_is_accepted(): void {
		$challenge = GRC_Captcha::generate();
		$reponse = $this->extraire_reponse_attendue( $challenge['token'] );

		$this->assertTrue( GRC_Captcha::verify( $challenge['token'], ' ' . $reponse . ' ' ) );
	}

	/**
	 * Décode la réponse attendue directement depuis le token (sans passer
	 * par la vérification HMAC), uniquement pour construire les scénarios
	 * de test — ne reflète pas un usage normal de la classe.
	 */
	private function extraire_reponse_attendue( string $token ): string {
		[ $encoded_payload, ] = explode( '.', $token, 2 );
		$payload = base64_decode( strtr( $encoded_payload, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $encoded_payload ) % 4 ) % 4 ) );
		[ $reponse, ] = explode( '|', $payload, 2 );
		return $reponse;
	}
}
