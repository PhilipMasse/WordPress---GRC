<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captcha mathématique auto-hébergé (aucun service tiers, aucune donnée
 * transmise hors du site — préférable à Google reCAPTCHA du point de vue
 * RGPD pour un site institutionnel). Le défi est stocké côté serveur dans un
 * transient WordPress à courte durée de vie, à usage unique.
 */
class GRC_Captcha {

	const TTL = 300; // 5 minutes.

	/**
	 * Génère un nouveau défi et retourne un token + la question à afficher.
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

		$token = wp_generate_password( 32, false );
		set_transient( 'grc_captcha_' . $token, (string) $reponse, self::TTL );

		return [
			'token'    => $token,
			'question' => "Combien font {$gauche} {$op} {$droite} ?",
		];
	}

	/**
	 * Vérifie une réponse. Le défi est supprimé après une seule tentative,
	 * qu'elle soit correcte ou non (évite le bruteforce sur la réponse).
	 */
	public static function verify( ?string $token, $reponse ): bool {
		if ( ! $token ) {
			return false;
		}
		$key = 'grc_captcha_' . $token;
		$attendu = get_transient( $key );
		delete_transient( $key );

		if ( false === $attendu ) {
			return false; // Expiré ou déjà utilisé.
		}

		return (string) $attendu === trim( (string) $reponse );
	}
}
