<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Génère une image de carte statique centrée sur un point (latitude/longitude),
 * en assemblant des tuiles OpenStreetMap (usage standard, faible volume) et en
 * dessinant un marqueur exactement à l'emplacement du signalement. N'utilise
 * aucun service de carte statique tiers non-officiel.
 *
 * Nécessite l'extension GD (présente sur la quasi-totalité des hébergements
 * mutualisés WordPress).
 */
class GRC_Static_Map {

	const TILE_SIZE = 256;
	const GRID      = 3; // Grille 3x3 tuiles autour du point.

	/**
	 * Génère (ou récupère depuis le cache) l'image de carte pour une demande,
	 * et retourne le chemin du fichier PNG sur disque, ou null si GD est
	 * indisponible ou si les tuiles n'ont pas pu être récupérées.
	 */
	public static function get_or_generate( int $demande_id, float $lat, float $lng, int $zoom = 17 ): ?string {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return null;
		}

		$upload_dir = wp_upload_dir();
		$cache_dir  = trailingslashit( $upload_dir['basedir'] ) . 'grc-maps';
		if ( ! file_exists( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
			// Protège le répertoire au même titre que les autres dossiers GRC.
			file_put_contents( $cache_dir . '/.htaccess', "Options -Indexes\n" );
		}

		$cache_file = $cache_dir . '/demande-' . $demande_id . '.png';
		if ( file_exists( $cache_file ) ) {
			return $cache_file;
		}

		$image = self::render( $lat, $lng, $zoom );
		if ( ! $image ) {
			return null;
		}

		imagepng( $image, $cache_file );
		imagedestroy( $image );

		return file_exists( $cache_file ) ? $cache_file : null;
	}

	/**
	 * Supprime l'image en cache (à appeler si les coordonnées d'une demande changent).
	 */
	public static function purge_cache( int $demande_id ) {
		$upload_dir = wp_upload_dir();
		$cache_file = trailingslashit( $upload_dir['basedir'] ) . 'grc-maps/demande-' . $demande_id . '.png';
		if ( file_exists( $cache_file ) ) {
			unlink( $cache_file );
		}
	}

	private static function render( float $lat, float $lng, int $zoom ) {
		$n = 2 ** $zoom;
		$lat_rad = deg2rad( $lat );

		$xtile_f = ( $lng + 180 ) / 360 * $n;
		$ytile_f = ( 1 - log( tan( $lat_rad ) + 1 / cos( $lat_rad ) ) / M_PI ) / 2 * $n;

		$center_tile_x = (int) floor( $xtile_f );
		$center_tile_y = (int) floor( $ytile_f );

		$half = (int) floor( self::GRID / 2 );
		$canvas_size = self::GRID * self::TILE_SIZE;
		$canvas = imagecreatetruecolor( $canvas_size, $canvas_size );

		$tiles_ok = 0;
		for ( $dx = -$half; $dx <= $half; $dx++ ) {
			for ( $dy = -$half; $dy <= $half; $dy++ ) {
				$tx = $center_tile_x + $dx;
				$ty = $center_tile_y + $dy;
				$tile_data = self::fetch_tile( $zoom, $tx, $ty );
				if ( $tile_data ) {
					$tile_img = @imagecreatefromstring( $tile_data );
					if ( $tile_img ) {
						imagecopy(
							$canvas, $tile_img,
							( $dx + $half ) * self::TILE_SIZE, ( $dy + $half ) * self::TILE_SIZE,
							0, 0, self::TILE_SIZE, self::TILE_SIZE
						);
						imagedestroy( $tile_img );
						$tiles_ok++;
					}
				}
			}
		}

		if ( 0 === $tiles_ok ) {
			imagedestroy( $canvas );
			return null;
		}

		// Position exacte du marqueur dans le canevas assemblé.
		$global_px = $xtile_f * self::TILE_SIZE;
		$global_py = $ytile_f * self::TILE_SIZE;
		$origin_px = ( $center_tile_x - $half ) * self::TILE_SIZE;
		$origin_py = ( $center_tile_y - $half ) * self::TILE_SIZE;
		$marker_x  = (int) round( $global_px - $origin_px );
		$marker_y  = (int) round( $global_py - $origin_py );

		self::draw_marker( $canvas, $marker_x, $marker_y );

		// Attribution OpenStreetMap obligatoire.
		self::draw_attribution( $canvas, $canvas_size );

		return $canvas;
	}

	private static function fetch_tile( int $z, int $x, int $y ) {
		$url = "https://tile.openstreetmap.org/{$z}/{$x}/{$y}.png";
		$response = wp_remote_get( $url, [
			'timeout'    => 8,
			'user-agent' => 'GRC-WordPress-Plugin/' . GRC_VERSION . ' (Mairie de Berre-les-Alpes; contact via site officiel)',
		] );
		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		return wp_remote_retrieve_body( $response );
	}

	private static function draw_marker( $canvas, int $x, int $y ) {
		$rouge  = imagecolorallocate( $canvas, 0xB3, 0x2D, 0x2E );
		$blanc  = imagecolorallocate( $canvas, 255, 255, 255 );

		// Pastille circulaire avec contour blanc, centrée exactement sur le point.
		imagefilledellipse( $canvas, $x, $y, 26, 26, $blanc );
		imagefilledellipse( $canvas, $x, $y, 18, 18, $rouge );
		imagefilledellipse( $canvas, $x, $y, 6, 6, $blanc );
	}

	private static function draw_attribution( $canvas, int $canvas_size ) {
		$blanc_fond = imagecolorallocatealpha( $canvas, 255, 255, 255, 40 );
		$noir       = imagecolorallocate( $canvas, 30, 30, 30 );
		$texte      = '© OpenStreetMap contributors';
		$largeur    = 6 * strlen( $texte ) + 10;
		imagefilledrectangle( $canvas, 0, $canvas_size - 16, $largeur, $canvas_size, $blanc_fond );
		imagestring( $canvas, 2, 4, $canvas_size - 15, $texte, $noir );
	}
}
