<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implémentation TOTP (RFC 6238 — Time-based One-Time Password), compatible
 * avec toute application d'authentification standard (Google Authenticator,
 * Authy, FreeOTP...). Aucune dépendance externe.
 */
class GRC_TOTP {

	const PERIODE       = 30; // secondes
	const CHIFFRES      = 6;
	const FENETRE_DERIVE = 1; // tolère ±1 période (horloge légèrement désynchronisée)

	/**
	 * Génère un secret aléatoire encodé en Base32 (format attendu par les
	 * applications d'authentification).
	 */
	public static function generer_secret(): string {
		$octets = random_bytes( 20 ); // 160 bits, recommandation RFC 4226.
		return self::base32_encode( $octets );
	}

	/**
	 * Construit l'URI otpauth:// à encoder en QR code pour l'ajout dans une
	 * application d'authentification.
	 */
	public static function uri_provisionnement( string $secret, string $compte, string $emetteur = 'Mairie de Berre-les-Alpes' ): string {
		return sprintf(
			'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
			rawurlencode( $emetteur ),
			rawurlencode( $compte ),
			$secret,
			rawurlencode( $emetteur ),
			self::CHIFFRES,
			self::PERIODE
		);
	}

	/**
	 * Vérifie un code à 6 chiffres saisi par l'utilisateur, avec une
	 * tolérance de dérive d'horloge de ±1 période (soit jusqu'à 30s environ).
	 */
	public static function verifier( string $secret, string $code ): bool {
		$code = trim( $code );
		if ( ! preg_match( '/^\d{6}$/', $code ) ) {
			return false;
		}

		$tranche_actuelle = (int) floor( time() / self::PERIODE );

		for ( $decalage = -self::FENETRE_DERIVE; $decalage <= self::FENETRE_DERIVE; $decalage++ ) {
			if ( hash_equals( self::code_pour_tranche( $secret, $tranche_actuelle + $decalage ), $code ) ) {
				return true;
			}
		}

		return false;
	}

	private static function code_pour_tranche( string $secret, int $tranche ): string {
		$cle = self::base32_decode( $secret );
		$binaire_tranche = pack( 'N*', 0 ) . pack( 'N*', $tranche ); // 8 octets big-endian.
		$hash = hash_hmac( 'sha1', $binaire_tranche, $cle, true );

		$offset = ord( $hash[19] ) & 0x0F;
		$morceau = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
			| ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
			| ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
			| ( ord( $hash[ $offset + 3 ] ) & 0xFF );

		$code = $morceau % ( 10 ** self::CHIFFRES );
		return str_pad( (string) $code, self::CHIFFRES, '0', STR_PAD_LEFT );
	}

	private static function base32_encode( string $data ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$binaire = '';
		foreach ( str_split( $data ) as $octet ) {
			$binaire .= str_pad( decbin( ord( $octet ) ), 8, '0', STR_PAD_LEFT );
		}
		$resultat = '';
		foreach ( str_split( $binaire, 5 ) as $groupe ) {
			$groupe = str_pad( $groupe, 5, '0', STR_PAD_RIGHT );
			$resultat .= $alphabet[ bindec( $groupe ) ];
		}
		return $resultat;
	}

	private static function base32_decode( string $secret ): string {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$secret = strtoupper( preg_replace( '/[^A-Z2-7]/i', '', $secret ) );
		$binaire = '';
		foreach ( str_split( $secret ) as $caractere ) {
			$position = strpos( $alphabet, $caractere );
			if ( false === $position ) {
				continue;
			}
			$binaire .= str_pad( decbin( $position ), 5, '0', STR_PAD_LEFT );
		}
		$octets = '';
		foreach ( str_split( $binaire, 8 ) as $groupe ) {
			if ( 8 === strlen( $groupe ) ) {
				$octets .= chr( bindec( $groupe ) );
			}
		}
		return $octets;
	}
}
