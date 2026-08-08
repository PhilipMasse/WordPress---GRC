<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation des fichiers uploadés par les citoyens (photos, documents).
 *
 * IMPORTANT — ce que ce scanner fait et ne fait PAS :
 * - Il vérifie la signature binaire réelle du fichier (pas seulement l'extension
 *   ou le Content-Type déclaré par le navigateur, facilement falsifiables).
 * - Pour les DOCX (qui sont des ZIP), il vérifie la structure interne attendue
 *   et REJETTE tout document contenant une macro (word/vbaProject.bin), qui est
 *   le vecteur d'infection le plus courant dans les documents Office.
 * - Pour les PDF, il rejette les fichiers contenant du JavaScript embarqué ou des
 *   actions d'ouverture automatique (/OpenAction, /Launch), vecteurs classiques
 *   d'exploitation PDF.
 * - Si le binaire ClamAV (clamscan) est disponible sur le serveur, il est utilisé
 *   en complément pour une vraie analyse antivirus.
 *
 * Ce que ce scanner NE garantit PAS : sans moteur antivirus dédié (ClamAV ou
 * équivalent) installé sur le serveur, il n'y a AUCUNE garantie qu'un fichier
 * soit totalement exempt de code malveillant — seulement que les vecteurs les
 * plus courants et les incohérences de structure sont détectés et bloqués.
 */
class GRC_File_Scanner {

	/** Types de documents autorisés pour les démarches administratives.
	 *  Le format .doc (OLE binaire legacy) est volontairement EXCLU : son format
	 *  binaire propriétaire rend la détection fiable de macros/objets malveillants
	 *  nettement plus difficile qu'avec les formats ZIP modernes (.docx) ou PDF.
	 *  On demande donc aux citoyens un PDF ou un .docx.
	 */
	const ALLOWED_DOCUMENT_MIME = [
		'application/pdf' => 'pdf',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
	];

	const ALLOWED_IMAGE_MIME = [
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/webp' => 'webp',
		'image/gif'  => 'gif',
	];

	/**
	 * Valide un fichier temporaire uploadé. Retourne l'extension validée en cas
	 * de succès, ou un WP_Error explicite en cas de rejet.
	 *
	 * @param string $tmp_path Chemin du fichier temporaire (tmp_name de $_FILES).
	 * @param array  $allowed_mimes Map mime => extension autorisée pour ce contexte.
	 * @return string|WP_Error
	 */
	public static function validate( string $tmp_path, array $allowed_mimes ) {
		if ( ! file_exists( $tmp_path ) ) {
			return new WP_Error( 'grc_file_missing', 'Fichier introuvable après envoi.' );
		}

		$finfo     = finfo_open( FILEINFO_MIME_TYPE );
		$real_mime = finfo_file( $finfo, $tmp_path );
		// Pas de finfo_close() ici : déprécié depuis PHP 8.5 (les objets finfo sont
		// désormais libérés automatiquement), et son appel émettrait un warning
		// affiché en clair par PHP, ce qui casserait le JSON de la réponse REST.
		// La ressource est de toute façon libérée par le ramasse-miettes PHP dès
		// que $finfo sort de portée, quelle que soit la version de PHP.

		if ( ! isset( $allowed_mimes[ $real_mime ] ) ) {
			return new WP_Error( 'grc_invalid_type', 'Type de fichier non autorisé (détecté : ' . $real_mime . ').' );
		}

		$extension = $allowed_mimes[ $real_mime ];

		// --- Vérification de signature binaire (magic bytes) ------------------
		$header = file_get_contents( $tmp_path, false, null, 0, 8 );

		switch ( $extension ) {
			case 'pdf':
				if ( 0 !== strpos( $header, '%PDF-' ) ) {
					return new WP_Error( 'grc_corrupted_file', 'Le fichier PDF semble corrompu ou invalide.' );
				}
				$check = self::check_pdf_safety( $tmp_path );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
				break;

			case 'docx':
				if ( 0 !== strpos( $header, "PK\x03\x04" ) && 0 !== strpos( $header, "PK\x05\x06" ) ) {
					return new WP_Error( 'grc_corrupted_file', 'Le fichier Word (.docx) semble corrompu ou invalide.' );
				}
				$check = self::check_docx_safety( $tmp_path );
				if ( is_wp_error( $check ) ) {
					return $check;
				}
				break;

			case 'jpg':
				if ( "\xFF\xD8" !== substr( $header, 0, 2 ) ) {
					return new WP_Error( 'grc_corrupted_file', 'Le fichier JPEG semble corrompu.' );
				}
				break;

			case 'png':
				if ( "\x89PNG" !== substr( $header, 0, 4 ) ) {
					return new WP_Error( 'grc_corrupted_file', 'Le fichier PNG semble corrompu.' );
				}
				break;
		}

		// --- Analyse antivirus si ClamAV est disponible sur le serveur ---------
		$clam_result = self::maybe_run_clamav( $tmp_path );
		if ( is_wp_error( $clam_result ) ) {
			return $clam_result;
		}

		return $extension;
	}

	/**
	 * Rejette les PDF contenant du JavaScript embarqué ou des actions
	 * d'ouverture/lancement automatique — vecteurs classiques d'exploitation PDF.
	 */
	private static function check_pdf_safety( string $path ) {
		$content = file_get_contents( $path );
		if ( false === $content ) {
			return new WP_Error( 'grc_read_error', 'Impossible de lire le fichier pour analyse.' );
		}

		$suspicious_tokens = [ '/JavaScript', '/JS ', '/OpenAction', '/Launch', '/AA ' ];
		foreach ( $suspicious_tokens as $token ) {
			if ( false !== strpos( $content, $token ) ) {
				return new WP_Error(
					'grc_suspicious_pdf',
					'Ce PDF contient du contenu actif (JavaScript ou action automatique) non autorisé pour des raisons de sécurité. Merci de fournir un PDF standard sans contenu interactif.'
				);
			}
		}

		return true;
	}

	/**
	 * Rejette les .docx contenant une macro VBA (word/vbaProject.bin) — le
	 * vecteur d'infection le plus courant dans les documents Office modernes.
	 * Vérifie aussi la présence des parties internes attendues d'un DOCX valide.
	 */
	private static function check_docx_safety( string $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			// L'extension PHP zip n'est pas installée sur ce serveur : on ne peut
			// pas vérifier la structure interne du .docx. On accepte le fichier
			// (la vérification de signature binaire a déjà eu lieu) mais ce cas
			// devrait être signalé à l'hébergeur pour activer l'extension zip.
			return true;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new WP_Error( 'grc_corrupted_file', 'Le fichier .docx ne peut pas être ouvert comme une archive valide.' );
		}

		if ( false === $zip->locateName( '[Content_Types].xml' ) || false === $zip->locateName( 'word/document.xml' ) ) {
			$zip->close();
			return new WP_Error( 'grc_corrupted_file', 'La structure interne du fichier .docx est invalide ou incomplète.' );
		}

		if ( false !== $zip->locateName( 'word/vbaProject.bin' ) ) {
			$zip->close();
			return new WP_Error(
				'grc_macro_detected',
				'Ce document contient une macro, ce qui n\'est pas autorisé pour des raisons de sécurité. Merci d\'envoyer un document .docx sans macro.'
			);
		}

		$zip->close();
		return true;
	}

	/**
	 * Lance une analyse ClamAV si le binaire est disponible sur le serveur.
	 * N'échoue jamais bruyamment si ClamAV est absent ou shell_exec désactivé :
	 * dans ce cas, on continue avec les seules vérifications heuristiques ci-dessus.
	 */
	private static function maybe_run_clamav( string $path ) {
		if ( ! function_exists( 'shell_exec' ) || ! function_exists( 'escapeshellarg' ) ) {
			return true;
		}

		$clamscan_path = trim( (string) @shell_exec( 'which clamscan 2>/dev/null' ) );
		if ( empty( $clamscan_path ) ) {
			return true; // ClamAV non installé sur ce serveur — on ne peut pas l'exiger.
		}

		$output = @shell_exec( escapeshellcmd( $clamscan_path ) . ' --no-summary ' . escapeshellarg( $path ) . ' 2>&1' );
		if ( null === $output ) {
			return true; // Échec d'exécution : on ne bloque pas l'upload pour autant.
		}

		if ( false !== strpos( $output, 'FOUND' ) ) {
			return new WP_Error( 'grc_virus_detected', 'Ce fichier a été détecté comme potentiellement malveillant par l\'analyse antivirus et a été rejeté.' );
		}

		return true;
	}
}
