<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestion des pièces jointes (photos de signalement, documents de démarche).
 *
 * Stockage : wp-content/uploads/grc-attachments/, protégé par .htaccess (Deny from all)
 * et servi UNIQUEMENT via l'endpoint de téléchargement authentifié ci-dessous —
 * jamais par URL directe, pour éviter l'exposition de photos/documents personnels.
 */
class GRC_REST_Attachments {

	const ALLOWED_MIME = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
		'application/pdf' => 'pdf',
	];

	const MAX_SIZE_BYTES = 8 * 1024 * 1024; // 8 Mo

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/demandes/(?P<id>\d+)/pieces-jointes', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'upload' ],
			'permission_callback' => '__return_true', // Autorisation vérifiée dans le callback (invité ou connecté).
		] );

		register_rest_route( $ns, '/pieces-jointes/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'download' ],
			'permission_callback' => '__return_true', // Autorisation vérifiée dans le callback.
		] );
	}

	/**
	 * Prépare le dossier de stockage protégé (créé au premier upload si besoin).
	 */
	private static function get_storage_dir(): string {
		$upload_dir = wp_upload_dir();
		$dir = trailingslashit( $upload_dir['basedir'] ) . 'grc-attachments';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
		}
		return $dir;
	}

	public static function upload( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'upload', 20, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop d\'envois, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$demande_id = absint( $request['id'] );

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$demande = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$demandes_table} WHERE id = %d", $demande_id ) );
		if ( ! $demande ) {
			return new WP_Error( 'grc_not_found', 'Demande introuvable.', [ 'status' => 404 ] );
		}

		$authorized = self::authorize_demande_access( $request, $demande );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'grc_no_file', 'Aucun fichier reçu (champ "file" attendu).', [ 'status' => 400 ] );
		}

		$file = $files['file'];

		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return new WP_Error( 'grc_upload_error', 'Erreur lors de l\'envoi du fichier.', [ 'status' => 400 ] );
		}
		if ( $file['size'] > self::MAX_SIZE_BYTES ) {
			return new WP_Error( 'grc_file_too_large', 'Fichier trop volumineux (8 Mo maximum).', [ 'status' => 400 ] );
		}

		// Vérification MIME réelle (pas seulement l'extension déclarée par le navigateur).
		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $file['tmp_name'] );
		finfo_close( $finfo );

		if ( ! isset( self::ALLOWED_MIME[ $real_mime ] ) ) {
			return new WP_Error( 'grc_invalid_type', 'Type de fichier non autorisé. Formats acceptés : JPG, PNG, WEBP, GIF, PDF.', [ 'status' => 400 ] );
		}

		$extension     = self::ALLOWED_MIME[ $real_mime ];
		$random_name   = bin2hex( random_bytes( 16 ) ) . '.' . $extension;
		$dir           = self::get_storage_dir();
		$dest_path     = trailingslashit( $dir ) . $random_name;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			return new WP_Error( 'grc_move_failed', 'Impossible d\'enregistrer le fichier.', [ 'status' => 500 ] );
		}

		$pj_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';
		$wpdb->insert( $pj_table, [
			'demande_id'     => $demande_id,
			'chemin_fichier' => 'grc-attachments/' . $random_name,
			'nom_original'   => sanitize_file_name( $file['name'] ),
			'mime_type'      => $real_mime,
			'taille_octets'  => (int) $file['size'],
			'created_at'     => current_time( 'mysql' ),
		] );
		$attachment_id = (int) $wpdb->insert_id;

		GRC_Audit_Log::log( 'piece_jointe_uploaded', 'demande', $demande_id, [ 'attachment_id' => $attachment_id ] );

		return [
			'id'           => $attachment_id,
			'nom_original' => sanitize_file_name( $file['name'] ),
			'mime_type'    => $real_mime,
			'download_url' => rest_url( GRC_REST_API::NAMESPACE_V1 . '/pieces-jointes/' . $attachment_id ),
		];
	}

	public static function download( WP_REST_Request $request ) {
		global $wpdb;
		$pj_table       = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$attachment_id = absint( $request['id'] );
		$piece = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pj_table} WHERE id = %d", $attachment_id ) );
		if ( ! $piece ) {
			return new WP_Error( 'grc_not_found', 'Fichier introuvable.', [ 'status' => 404 ] );
		}

		$demande = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$demandes_table} WHERE id = %d", $piece->demande_id ) );
		if ( ! $demande ) {
			return new WP_Error( 'grc_not_found', 'Demande associée introuvable.', [ 'status' => 404 ] );
		}

		$authorized = self::authorize_demande_access( $request, $demande );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$upload_dir = wp_upload_dir();
		$full_path  = trailingslashit( $upload_dir['basedir'] ) . $piece->chemin_fichier;

		if ( ! file_exists( $full_path ) ) {
			return new WP_Error( 'grc_file_missing', 'Le fichier n\'existe plus sur le serveur.', [ 'status' => 404 ] );
		}

		GRC_Audit_Log::log( 'piece_jointe_downloaded', 'demande', $piece->demande_id, [ 'attachment_id' => $attachment_id ] );

		// Sert le fichier directement (hors répertoire public accessible) et termine la requête.
		header( 'Content-Type: ' . $piece->mime_type );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $piece->nom_original ) . '"' );
		header( 'Content-Length: ' . filesize( $full_path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $full_path );
		exit;
	}

	/**
	 * Autorise l'accès à une demande (upload ou téléchargement de pièce jointe) si :
	 * - l'utilisateur est un agent/élu authentifié (JWT), OU
	 * - l'utilisateur connecté est le citoyen propriétaire de la demande, OU
	 * - le mode invité fournit numero_suivi + email correspondant au citoyen de la demande.
	 */
	private static function authorize_demande_access( WP_REST_Request $request, $demande ) {
		// Agent/élu ou citoyen authentifié via JWT (middleware rest_pre_dispatch a déjà tenté wp_set_current_user).
		if ( is_user_logged_in() ) {
			if ( current_user_can( 'grc_view_all' ) || current_user_can( 'grc_manage_demandes' ) ) {
				return true;
			}
			global $wpdb;
			$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
			$mon_citoyen_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$citoyens_table} WHERE wp_user_id = %d", get_current_user_id() ) );
			if ( $mon_citoyen_id && (int) $mon_citoyen_id === (int) $demande->citoyen_id ) {
				return true;
			}
		}

		// Mode invité : numero_suivi (implicite car on connaît déjà la demande) + email fourni.
		$email = sanitize_email( $request->get_param( 'email' ) ?? '' );
		if ( $email && $demande->citoyen_id ) {
			global $wpdb;
			$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
			$hash = GRC_Encryption::search_hash( $email );
			$match = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$citoyens_table} WHERE id = %d AND email_hash = %s",
				$demande->citoyen_id,
				$hash
			) );
			if ( $match ) {
				return true;
			}
		}

		return new WP_Error( 'grc_forbidden', 'Accès non autorisé à cette demande.', [ 'status' => 403 ] );
	}
}
