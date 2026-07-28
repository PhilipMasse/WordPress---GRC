<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chiffrement applicatif des données personnelles avant stockage en BDD.
 *
 * - Chiffrement : XChaCha20-Poly1305 (libsodium), authentifié.
 * - La clé est dérivée de la constante GRC_ENCRYPTION_KEY (wp-config.php),
 *   jamais stockée en base, jamais versionnée sur GitHub.
 * - Un HMAC (SHA-256) est calculé en parallèle pour permettre la recherche
 *   exacte (ex: retrouver un citoyen par email) sans déchiffrer toute la table.
 */
class GRC_Encryption {

	/**
	 * Retourne la clé binaire (32 octets) dérivée de la constante wp-config.
	 */
	private static function get_key(): string {
		if ( ! defined( 'GRC_ENCRYPTION_KEY' ) ) {
			throw new RuntimeException( 'GRC_ENCRYPTION_KEY non définie dans wp-config.php.' );
		}
		// La constante est en base64 (générée une fois via GRC_Encryption::generate_key()).
		$key = base64_decode( GRC_ENCRYPTION_KEY, true );
		if ( false === $key || SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen( $key ) ) {
			// Dérivation de secours si la constante n'est pas une clé sodium brute.
			$key = sodium_crypto_generichash( GRC_ENCRYPTION_KEY, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
		}
		return $key;
	}

	/**
	 * Chiffre une chaîne. Retourne une chaîne base64 : nonce + ciphertext.
	 */
	public static function encrypt( ?string $plaintext ): ?string {
		if ( null === $plaintext || '' === $plaintext ) {
			return $plaintext;
		}
		$nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::get_key() );
		return base64_encode( $nonce . $ciphertext );
	}

	/**
	 * Déchiffre une chaîne produite par encrypt().
	 */
	public static function decrypt( ?string $encoded ): ?string {
		if ( null === $encoded || '' === $encoded ) {
			return $encoded;
		}
		$raw = base64_decode( $encoded, true );
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null; // Donnée corrompue ou non chiffrée.
		}
		$nonce      = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$ciphertext = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plaintext  = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::get_key() );
		return false === $plaintext ? null : $plaintext;
	}

	/**
	 * HMAC déterministe (pour indexer/rechercher une valeur chiffrée, ex: email).
	 * Ne PAS utiliser pour des données à forte cardinalité faible (ex: booléens).
	 */
	public static function search_hash( string $value ): string {
		return hash_hmac( 'sha256', mb_strtolower( trim( $value ) ), self::get_key() );
	}

	/**
	 * Génère une clé aléatoire à copier dans wp-config.php (à exécuter une seule fois, en WP-CLI ou script ponctuel).
	 */
	public static function generate_key(): string {
		return base64_encode( random_bytes( SODIUM_CRYPTO_SECRETBOX_KEYBYTES ) );
	}
}
