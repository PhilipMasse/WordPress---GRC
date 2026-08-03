<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers GRC_Encryption
 */
final class EncryptionTest extends TestCase {

	public function test_encrypt_then_decrypt_returns_original_value(): void {
		$original = 'Cyrille Mus';
		$encrypted = GRC_Encryption::encrypt( $original );

		$this->assertNotSame( $original, $encrypted, 'La valeur chiffrée ne doit jamais être identique au texte en clair.' );
		$this->assertSame( $original, GRC_Encryption::decrypt( $encrypted ) );
	}

	public function test_encrypt_is_non_deterministic(): void {
		// Le nonce aléatoire doit produire un résultat différent à chaque
		// appel, même pour la même valeur en clair (propriété de sécurité
		// importante : deux citoyens de même nom ne doivent pas avoir le
		// même texte chiffré en base).
		$a = GRC_Encryption::encrypt( 'test@example.com' );
		$b = GRC_Encryption::encrypt( 'test@example.com' );

		$this->assertNotSame( $a, $b );
		$this->assertSame( 'test@example.com', GRC_Encryption::decrypt( $a ) );
		$this->assertSame( 'test@example.com', GRC_Encryption::decrypt( $b ) );
	}

	public function test_encrypt_null_or_empty_is_passthrough(): void {
		$this->assertNull( GRC_Encryption::encrypt( null ) );
		$this->assertSame( '', GRC_Encryption::encrypt( '' ) );
		$this->assertNull( GRC_Encryption::decrypt( null ) );
		$this->assertSame( '', GRC_Encryption::decrypt( '' ) );
	}

	public function test_decrypt_of_corrupted_data_returns_null_instead_of_crashing(): void {
		// Une donnée corrompue ou tronquée en base ne doit jamais faire
		// planter le site : decrypt() doit retourner null proprement.
		$this->assertNull( GRC_Encryption::decrypt( 'ceci-nest-pas-du-base64-valide!!!' ) );
		$this->assertNull( GRC_Encryption::decrypt( base64_encode( 'trop court' ) ) );
	}

	public function test_search_hash_is_deterministic(): void {
		// Contrairement à encrypt(), search_hash() DOIT être déterministe :
		// c'est ce qui permet de retrouver un citoyen par email sans
		// déchiffrer toute la table.
		$hash1 = GRC_Encryption::search_hash( 'citoyen@example.fr' );
		$hash2 = GRC_Encryption::search_hash( 'citoyen@example.fr' );

		$this->assertSame( $hash1, $hash2 );
	}

	public function test_search_hash_is_case_and_whitespace_insensitive(): void {
		// Un email saisi avec une casse ou des espaces différents doit
		// produire le même hash, pour que la recherche fonctionne malgré
		// les variations de saisie du citoyen.
		$hash_ref = GRC_Encryption::search_hash( 'Citoyen@Example.fr' );

		$this->assertSame( $hash_ref, GRC_Encryption::search_hash( '  citoyen@example.fr  ' ) );
		$this->assertSame( $hash_ref, GRC_Encryption::search_hash( 'CITOYEN@EXAMPLE.FR' ) );
	}

	public function test_search_hash_differs_for_different_values(): void {
		$this->assertNotSame(
			GRC_Encryption::search_hash( 'alice@example.fr' ),
			GRC_Encryption::search_hash( 'bob@example.fr' )
		);
	}

	public function test_generate_key_produces_valid_sodium_key(): void {
		$key = GRC_Encryption::generate_key();
		$decoded = base64_decode( $key, true );

		$this->assertNotFalse( $decoded );
		$this->assertSame( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen( $decoded ) );
	}
}
