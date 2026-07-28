<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Génère un numéro citoyen unique et lisible (ex: CIT-000042), utile pour
 * distinguer des homonymes en administration. Basé directement sur l'ID
 * auto-incrémenté de la table wp_grc_citoyens : garanti unique sans nécessiter
 * de colonne ni de migration supplémentaire.
 */
class GRC_Citoyen_Helper {

	public static function numero( int $citoyen_id ): string {
		return 'CIT-' . str_pad( (string) $citoyen_id, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * Tente d'extraire un ID citoyen depuis une chaîne saisie par un agent
	 * (accepte "CIT-000042", "000042" ou "42"). Retourne null si non numérique.
	 */
	public static function parse_numero( string $input ): ?int {
		$digits = preg_replace( '/\D/', '', $input );
		return '' !== $digits ? (int) $digits : null;
	}
}
