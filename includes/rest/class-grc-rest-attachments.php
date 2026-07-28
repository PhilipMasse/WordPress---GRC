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
 *
 * Tous les fichiers passent par GRC_File_Scanner::validate() avant stockage
 * (signature binaire réelle, structure interne, heuristiques anti-malware,
 * ClamAV si disponible sur le serveur — voir class-grc-file-scanner.php).
 */
class GRC_REST_Attachments {

	const MAX_SIZE_BYTES = 8 * 1024 * 1024; // 8 Mo

	/**
	 * Normalise une entrée $_FILES potentiellement multiple (ex: name="files[]")
	 * en une liste plate de fichiers individuels au format standard PHP.
	 */
	public static function normalize_multi_files( $files_entry ): array {
		if ( empty( $files_entry ) ) {
			return [];
		}
		// Fichier unique (pas de tableau imbriqué).
		if ( ! is_array( $files_entry['name'] ) ) {
			return [ $files_entry ];
		}
		$normalized = [];
		foreach ( $files_entry['name'] as $i => $name ) {
			if ( '' === $name ) {
				continue;
			}
			$normalized[] = [
				'name'     => $files_entry['name'][ $i ],
				'type'     => $files_entry['type'][ $i ],
				'tmp_name' => $files_entry['tmp_name'][ $i ],
				'error'    => $files_entry['error'][ $i ],
				'size'     => $files_entry['size'][ $i ],
			];
		}
		return $normalized;
	}

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/demandes/(?P<id>\d+)/pieces-jointes', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'upload_for_demande' ],
			'permission_callback' => '__return_true', // Autorisation vérifiée dans le callback (invité ou connecté).
		] );

		register_rest_route( $ns, '/demarches/(?P<id>\d+)/pieces-jointes', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'upload_for_demarche' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return GRC_REST_Demarches::can_access_demarche( $request, absint( $request['id'] ) );
			},
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

	public static function upload_for_demande( WP_REST_Request $request ) {
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

		$allowed_mimes = GRC_File_Scanner::ALLOWED_IMAGE_MIME + GRC_File_Scanner::ALLOWED_DOCUMENT_MIME;

		$results = self::process_multi_upload( $request, $allowed_mimes, [ 'demande_id' => $demande_id ], 'demande', $demande_id );
		if ( empty( $results ) ) {
			return new WP_Error( 'grc_no_file', 'Aucun fichier reçu (champ "file" ou "files" attendu).', [ 'status' => 400 ] );
		}
		return $results;
	}

	/**
	 * Upload d'une ou plusieurs pièces jointes pour un dossier de démarche
	 * (documents uniquement : PDF ou Word .docx — voir GRC_File_Scanner).
	 */
	public static function upload_for_demarche( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'upload', 20, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop d\'envois, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$demarche_id = absint( $request['id'] );

		global $wpdb;
		$table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $demarche_id ) );
		if ( ! $exists ) {
			return new WP_Error( 'grc_not_found', 'Dossier introuvable.', [ 'status' => 404 ] );
		}

		$results = self::process_multi_upload( $request, GRC_File_Scanner::ALLOWED_DOCUMENT_MIME, [ 'demarche_id' => $demarche_id ], 'demarche', $demarche_id );
		if ( empty( $results ) ) {
			return new WP_Error( 'grc_no_file', 'Aucun fichier reçu (champ "file" ou "files" attendu).', [ 'status' => 400 ] );
		}
		return $results;
	}

	/**
	 * Traite un ou plusieurs fichiers envoyés dans la même requête. Accepte le
	 * champ "file" (fichier unique, rétrocompatibilité) ou "files"/"files[]"
	 * (plusieurs fichiers). Retourne un tableau de résultats (un par fichier),
	 * chacun étant soit les infos du fichier stocké, soit une erreur associée
	 * à son nom d'origine — un fichier refusé n'empêche pas les autres d'être traités.
	 */
	private static function process_multi_upload( WP_REST_Request $request, array $allowed_mimes, array $link_columns, string $log_type, int $log_id, ?int $message_id = null ) {
		$files_params = $request->get_file_params();
		return self::process_multi_upload_raw( $files_params, $allowed_mimes, $link_columns, $log_type, $log_id, $message_id );
	}

	/**
	 * Version réutilisable acceptant directement un tableau au format $_FILES,
	 * sans dépendre d'un WP_REST_Request. Utilisée par l'API REST comme par les
	 * formulaires admin classiques (admin-post.php, qui ne passe pas par la REST API).
	 */
	public static function process_multi_upload_raw( array $files_params, array $allowed_mimes, array $link_columns, string $log_type, int $log_id, ?int $message_id = null ) {
		$files = [];

		if ( ! empty( $files_params['files'] ) ) {
			$files = self::normalize_multi_files( $files_params['files'] );
		} elseif ( ! empty( $files_params['file'] ) ) {
			$files = self::normalize_multi_files( $files_params['file'] );
		}

		if ( empty( $files ) ) {
			return [];
		}

		$results = [];
		foreach ( $files as $file ) {
			$results[] = self::process_single_file( $file, $allowed_mimes, $link_columns, $log_type, $log_id, $message_id );
		}

		return $results;
	}

	/**
	 * Valide, scanne et stocke un fichier individuel déjà extrait de $_FILES.
	 */
	private static function process_single_file( array $file, array $allowed_mimes, array $link_columns, string $log_type, int $log_id, ?int $message_id ) {
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			return [ 'nom_original' => $file['name'], 'error' => true, 'message' => 'Erreur lors de l\'envoi du fichier.' ];
		}
		if ( $file['size'] > self::MAX_SIZE_BYTES ) {
			return [ 'nom_original' => $file['name'], 'error' => true, 'message' => 'Fichier trop volumineux (8 Mo maximum).' ];
		}

		$validation = GRC_File_Scanner::validate( $file['tmp_name'], $allowed_mimes );
		if ( is_wp_error( $validation ) ) {
			GRC_Audit_Log::log( 'piece_jointe_rejected', $log_type, $log_id, [ 'reason' => $validation->get_error_code(), 'fichier' => $file['name'] ] );
			return [ 'nom_original' => $file['name'], 'error' => true, 'message' => $validation->get_error_message() ];
		}
		$extension = $validation;

		$real_mime   = array_search( $extension, $allowed_mimes, true ) ?: 'application/octet-stream';
		$random_name = bin2hex( random_bytes( 16 ) ) . '.' . $extension;
		$dir         = self::get_storage_dir();
		$dest_path   = trailingslashit( $dir ) . $random_name;

		if ( ! move_uploaded_file( $file['tmp_name'], $dest_path ) ) {
			return [ 'nom_original' => $file['name'], 'error' => true, 'message' => 'Impossible d\'enregistrer le fichier.' ];
		}

		global $wpdb;
		$pj_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';
		$data = array_merge( $link_columns, [
			'chemin_fichier' => 'grc-attachments/' . $random_name,
			'nom_original'   => sanitize_file_name( $file['name'] ),
			'mime_type'      => $real_mime,
			'taille_octets'  => (int) $file['size'],
			'created_at'     => current_time( 'mysql' ),
		] );
		if ( $message_id ) {
			$data['demarche_message_id'] = $message_id;
		}
		$wpdb->insert( $pj_table, $data );
		$attachment_id = (int) $wpdb->insert_id;

		GRC_Audit_Log::log( 'piece_jointe_uploaded', $log_type, $log_id, [ 'attachment_id' => $attachment_id ] );

		return [
			'id'           => $attachment_id,
			'nom_original' => sanitize_file_name( $file['name'] ),
			'mime_type'    => $real_mime,
			'download_url' => rest_url( GRC_REST_API::NAMESPACE_V1 . '/pieces-jointes/' . $attachment_id ),
			'error'        => false,
		];
	}

	/**
	 * Point d'entrée public réutilisé par GRC_REST_Demarches::add_message() pour
	 * attacher des fichiers à un message précis du fil d'échange.
	 */
	public static function upload_files_for_demarche_message( WP_REST_Request $request, int $demarche_id, int $message_id ): array {
		return self::process_multi_upload(
			$request,
			GRC_File_Scanner::ALLOWED_DOCUMENT_MIME,
			[ 'demarche_id' => $demarche_id ],
			'demarche',
			$demarche_id,
			$message_id
		);
	}

	public static function download( WP_REST_Request $request ) {
		global $wpdb;
		$pj_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';

		$attachment_id = absint( $request['id'] );
		$piece = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$pj_table} WHERE id = %d", $attachment_id ) );
		if ( ! $piece ) {
			return new WP_Error( 'grc_not_found', 'Fichier introuvable.', [ 'status' => 404 ] );
		}

		if ( $piece->demande_id ) {
			$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
			$demande = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$demandes_table} WHERE id = %d", $piece->demande_id ) );
			if ( ! $demande ) {
				return new WP_Error( 'grc_not_found', 'Demande associée introuvable.', [ 'status' => 404 ] );
			}
			$authorized = self::authorize_demande_access( $request, $demande );
		} elseif ( $piece->demarche_id ) {
			$authorized = GRC_REST_Demarches::can_access_demarche( $request, (int) $piece->demarche_id )
				? true
				: new WP_Error( 'grc_forbidden', 'Accès non autorisé à ce fichier.', [ 'status' => 403 ] );
		} else {
			$authorized = new WP_Error( 'grc_forbidden', 'Fichier orphelin.', [ 'status' => 403 ] );
		}

		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$upload_dir = wp_upload_dir();
		$full_path  = trailingslashit( $upload_dir['basedir'] ) . $piece->chemin_fichier;

		if ( ! file_exists( $full_path ) ) {
			return new WP_Error( 'grc_file_missing', 'Le fichier n\'existe plus sur le serveur.', [ 'status' => 404 ] );
		}

		GRC_Audit_Log::log( 'piece_jointe_downloaded', $piece->demande_id ? 'demande' : 'demarche', (int) ( $piece->demande_id ?: $piece->demarche_id ), [ 'attachment_id' => $attachment_id ] );

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
		// Agent/élu connecté via WordPress (cookie+nonce, ou JWT type=agent déjà résolu par le middleware).
		if ( is_user_logged_in() && ( current_user_can( 'grc_view_all' ) || current_user_can( 'grc_manage_demandes' ) ) ) {
			return true;
		}

		// Citoyen authentifié via JWT (type=citoyen), propriétaire de la demande.
		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
		if ( $citoyen_id && (int) $citoyen_id === (int) $demande->citoyen_id ) {
			return true;
		}

		// Mode invité : email fourni correspondant au citoyen de la demande.
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
