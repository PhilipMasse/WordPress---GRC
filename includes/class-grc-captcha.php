<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captcha mathématique auto-hébergé et SANS ÉTAT côté serveur : la réponse
 * attendue et une date d'expiration sont encodées directement dans le token,
 * signé par HMAC-SHA256 (clé dérivée des sels WordPress). Le client renvoie
 * ce token avec sa réponse ; le serveur revérifie la signature et compare —
 * sans jamais avoir besoin de retrouver un état stocké précédemment.
 *
 * Ce choix évite toute dépendance à un cache d'objets/transients, qui peut
 * se révéler peu fiable selon la configuration d'hébergement (plusieurs
 * processus PHP-FPM sans cache partagé, cache d'objets mal configuré, etc.) :
 * un problème déjà rencontré plusieurs fois sur ce site pour d'autres
 * fonctionnalités.
 */
class GRC_Captcha {

	const TTL = 300; // 5 minutes.

	/**
	 * Génère un nouveau défi et retourne un token auto-porteur + la question
	 * à afficher (aucun stockage serveur nécessaire).
	 */
	public static function generate(): array {
		$a = wp_rand( 2, 9 );
		$b = wp_rand( 2, 9 );
		$operations = [
			'+' => $a + $b,
			'-' => max( $a, $b ) - min( $a, $b ),
		];
		$op = array_rand( $operations );
		$reponse = $operations[ $op ];

		// Pour la soustraction, toujours poser le plus grand nombre en premier.
		$gauche = '-' === $op ? max( $a, $b ) : $a;
		$droite = '-' === $op ? min( $a, $b ) : $b;

		$expiration = time() + self::TTL;
		$payload    = $reponse . '|' . $expiration;
		$token      = self::encode( $payload );

		return [
			'token'    => $token,
			'question' => "Combien font {$gauche} {$op} {$droite} ?",
		];
	}

	/**
	 * Vérifie une réponse en revérifiant la signature et l'expiration du
	 * token — aucune lecture d'état serveur requise.
	 */
	public static function verify( ?string $token, $reponse ): bool {
		if ( ! $token ) {
			return false;
		}

		$payload = self::decode( $token );
		if ( null === $payload ) {
			return false; // Signature invalide (falsifié) ou format incorrect.
		}

		[ $attendu, $expiration ] = explode( '|', $payload, 2 );

		if ( time() > (int) $expiration ) {
			return false; // Expiré.
		}

		return (string) $attendu === trim( (string) $reponse );
	}

	private static function encode( string $payload ): string {
		$signature = self::sign( $payload );
		return self::base64url_encode( $payload ) . '.' . $signature;
	}

	private static function decode( string $token ): ?string {
		$parts = explode( '.', $token, 2 );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		[ $encoded_payload, $signature ] = $parts;
		$payload = self::base64url_decode( $encoded_payload );
		if ( false === $payload || ! hash_equals( self::sign( $payload ), $signature ) ) {
			return null;
		}
		return $payload;
	}

	private static function sign( string $payload ): string {
		return hash_hmac( 'sha256', $payload, self::secret() );
	}

	private static function secret(): string {
		// Dérivé des sels d'authentification WordPress : stable tant que
		// wp-config.php n'est pas modifié, unique par installation.
		return wp_salt( 'auth' ) . '|grc_captcha';
	}

	private static function base64url_encode( string $data ): string {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	private static function base64url_decode( string $data ) {
		return base64_decode( strtr( $data, '-_', '+/' ) . str_repeat( '=', ( 4 - strlen( $data ) % 4 ) % 4 ), true );
	}
}
