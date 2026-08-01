<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Géocodage inversé (coordonnées → adresse), via Nominatim (OpenStreetMap).
 * Passe par le serveur plutôt que directement depuis le navigateur du citoyen
 * pour respecter la politique d'usage de Nominatim (User-Agent identifiant
 * l'application obligatoire, ce que le fetch() navigateur ne permet pas de
 * définir librement) et limiter le débit d'appels.
 */
class GRC_REST_Geocode {

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/geocode/reverse', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'reverse' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'lat' => [ 'required' => true ],
				'lng' => [ 'required' => true ],
			],
		] );
	}

	public static function reverse( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'geocode_reverse', 30, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de requêtes, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$lat = (float) $request->get_param( 'lat' );
		$lng = (float) $request->get_param( 'lng' );

		if ( ! $lat || ! $lng ) {
			return new WP_Error( 'grc_invalid_coords', 'Coordonnées invalides.', [ 'status' => 400 ] );
		}

		$url = add_query_arg( [
			'format'      => 'jsonv2',
			'lat'         => $lat,
			'lon'         => $lng,
			'addressdetails' => 1,
			'zoom'        => 18,
		], 'https://nominatim.openstreetmap.org/reverse' );

		$response = wp_remote_get( $url, [
			'timeout'    => 8,
			'user-agent' => 'GRC-WordPress-Plugin/' . GRC_VERSION . ' (Mairie de Berre-les-Alpes; contact via site officiel)',
		] );

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'grc_geocode_failed', 'Adresse introuvable pour ces coordonnées.', [ 'status' => 502 ] );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['display_name'] ) ) {
			return new WP_Error( 'grc_geocode_failed', 'Adresse introuvable pour ces coordonnées.', [ 'status' => 404 ] );
		}

		return [
			'adresse'   => self::format_adresse( $body ),
			'brut'      => $body['display_name'],
		];
	}

	/**
	 * Construit une adresse courte et lisible (numéro, rue, ville) plutôt que
	 * la chaîne complète et verbeuse retournée par Nominatim par défaut.
	 */
	private static function format_adresse( array $body ): string {
		$a = $body['address'] ?? [];

		$rue = trim( ( $a['house_number'] ?? '' ) . ' ' . ( $a['road'] ?? $a['pedestrian'] ?? $a['neighbourhood'] ?? '' ) );
		$ville = $a['village'] ?? $a['town'] ?? $a['city'] ?? $a['municipality'] ?? '';
		$cp = $a['postcode'] ?? '';

		$parts = array_filter( [ $rue, trim( $cp . ' ' . $ville ) ] );

		return $parts ? implode( ', ', $parts ) : $body['display_name'];
	}
}
