<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Démarches administratives : chaque "type" (état civil, urbanisme...) définit
 * ses propres champs (champs_json), un citoyen soumet un dossier validé contre
 * cette définition, un agent traite le dossier.
 */
class GRC_REST_Demarches {

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/demarches/types', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_types' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/demarches', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'submit' ],
			'permission_callback' => '__return_true', // Invité ou citoyen JWT — vérifié dans le callback.
		] );

		register_rest_route( $ns, '/mes-demarches', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'my_demarches' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
			},
		] );

		register_rest_route( $ns, '/demarches/(?P<id>\d+)', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_demarche' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return self::can_access_demarche( $request, absint( $request['id'] ) );
			},
		] );

		register_rest_route( $ns, '/demarches/(?P<id>\d+)/messages', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'add_message' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return self::can_access_demarche( $request, absint( $request['id'] ) );
			},
		] );

		register_rest_route( $ns, '/demarches/(?P<id>\d+)/statut', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'update_statut' ],
			'permission_callback' => function () {
				return current_user_can( 'grc_manage_demandes' );
			},
		] );
	}

	public static function list_types() {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$rows  = $wpdb->get_results( "SELECT id, nom, slug, description, champs_json FROM {$table} WHERE actif = 1 ORDER BY nom" );

		return array_map( function ( $t ) {
			return [
				'id'          => (int) $t->id,
				'nom'         => $t->nom,
				'slug'        => $t->slug,
				'description' => $t->description,
				'champs'      => json_decode( $t->champs_json, true ) ?: [],
			];
		}, $rows );
	}

	public static function submit( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'demarche_submit', 15, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de soumissions, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$params = $request->get_json_params();
		$slug   = sanitize_key( $params['type_slug'] ?? '' );

		global $wpdb;
		$types_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$type = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$types_table} WHERE slug = %s AND actif = 1", $slug ) );
		if ( ! $type ) {
			return new WP_Error( 'grc_invalid_type', 'Type de démarche invalide ou inactif.', [ 'status' => 400 ] );
		}

		$champs   = json_decode( $type->champs_json, true ) ?: [];
		$donnees  = is_array( $params['donnees'] ?? null ) ? $params['donnees'] : [];
		$validated = [];

		foreach ( $champs as $champ ) {
			$key   = sanitize_key( $champ['key'] ?? '' );
			$label = $champ['label'] ?? $key;
			$type_champ = $champ['type'] ?? 'text';
			$requis = ! empty( $champ['requis'] );

			$valeur = $donnees[ $key ] ?? null;

			if ( $requis && ( null === $valeur || '' === $valeur ) ) {
				return new WP_Error( 'grc_missing_field', sprintf( 'Le champ "%s" est obligatoire.', $label ), [ 'status' => 400 ] );
			}

			if ( null !== $valeur ) {
				switch ( $type_champ ) {
					case 'email':
						$valeur = sanitize_email( $valeur );
						break;
					case 'number':
						$valeur = is_numeric( $valeur ) ? $valeur + 0 : null;
						break;
					case 'textarea':
						$valeur = sanitize_textarea_field( $valeur );
						break;
					case 'date':
						if ( '' !== $valeur && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valeur ) ) {
							return new WP_Error( 'grc_invalid_date', sprintf( 'Le champ "%s" doit être une date valide.', $label ), [ 'status' => 400 ] );
						}
						$valeur = sanitize_text_field( $valeur );
						break;
					case 'phone':
						$valeur = preg_replace( '/[^\d+]/', '', (string) $valeur );
						if ( '' !== $valeur && ! preg_match( '/^\+[1-9]\d{7,14}$/', $valeur ) ) {
							return new WP_Error( 'grc_invalid_phone', sprintf( 'Le champ "%s" doit être un numéro de téléphone valide (avec indicatif pays).', $label ), [ 'status' => 400 ] );
						}
						break;
					default:
						$valeur = sanitize_text_field( $valeur );
				}
			}
			$validated[ $key ] = $valeur;
		}

		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
		if ( ! $citoyen_id ) {
			// Honeypot : champ invisible que seuls les robots remplissent habituellement.
			if ( ! empty( $params['site_web'] ) ) {
				return new WP_Error( 'grc_invalid_captcha', 'Vérification anti-robot invalide.', [ 'status' => 400 ] );
			}

			$captcha_provider = get_option( 'grc_captcha_provider', 'interne' );
			if ( 'interne' !== $captcha_provider ) {
				if ( ! GRC_REST_Citoyen::verify_captcha_provider( $captcha_provider, $params['captcha_provider_token'] ?? null ) ) {
					return new WP_Error( 'grc_invalid_captcha', 'Vérification anti-robot incorrecte ou expirée. Merci de réessayer.', [ 'status' => 400 ] );
				}
			} elseif ( ! GRC_Captcha::verify( $params['captcha_token'] ?? null, $params['captcha_reponse'] ?? null ) ) {
				return new WP_Error( 'grc_invalid_captcha', 'Vérification anti-robot incorrecte ou expirée. Merci de réessayer.', [ 'status' => 400 ] );
			}

			$email = sanitize_email( $params['email'] ?? '' );
			if ( ! $email ) {
				return new WP_Error( 'grc_missing_email', 'Email obligatoire pour une démarche en mode invité.', [ 'status' => 400 ] );
			}
			$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
			$hash = GRC_Encryption::search_hash( $email );
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$citoyens_table} WHERE email_hash = %s", $hash ) );
			if ( $existing ) {
				$citoyen_id = (int) $existing;
			} else {
				$wpdb->insert( $citoyens_table, [
					'email'      => GRC_Encryption::encrypt( $email ),
					'email_hash' => $hash,
					'is_guest'   => 1,
					'created_at' => current_time( 'mysql' ),
				] );
				$citoyen_id = (int) $wpdb->insert_id;
			}
		}

		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$numero_dossier  = self::generate_numero( 'DEM' );
		$wpdb->insert( $demarches_table, [
			'numero_dossier' => $numero_dossier,
			'citoyen_id'     => $citoyen_id,
			'type_demarche'  => $slug,
			'statut'         => 'en_attente',
			'donnees_json'   => wp_json_encode( $validated ),
			'created_at'     => current_time( 'mysql' ),
		] );
		$demarche_id = (int) $wpdb->insert_id;

		GRC_Audit_Log::log( 'demarche_created', 'demarche', $demarche_id, [ 'type' => $slug ] );
		GRC_Notifications::notify_agents_nouvelle_demarche( $demarche_id );

		$citoyens_table_email = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$email_encrypted = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$citoyens_table_email} WHERE id = %d", $citoyen_id ) );
		$email_citoyen = $email_encrypted ? GRC_Encryption::decrypt( $email_encrypted ) : null;
		if ( $email_citoyen ) {
			GRC_Notifications::send_demarche_created( $demarche_id, $email_citoyen, $numero_dossier );
		}

		return [ 'id' => $demarche_id, 'numero_dossier' => $numero_dossier, 'statut' => 'en_attente' ];
	}

	private static function generate_numero( string $prefixe ): string {
		return $prefixe . '-' . gmdate( 'Y' ) . '-' . strtoupper( substr( bin2hex( random_bytes( 4 ) ), 0, 6 ) );
	}

	public static function my_demarches( WP_REST_Request $request ) {
		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );

		global $wpdb;
		$table       = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$types_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$rows  = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.*, t.nom AS type_nom FROM {$table} d
			 LEFT JOIN {$types_table} t ON t.slug = d.type_demarche
			 WHERE d.citoyen_id = %d ORDER BY d.created_at DESC",
			$citoyen_id
		) );

		return array_map( function ( $d ) {
			return [
				'id'             => (int) $d->id,
				'numero_dossier' => $d->numero_dossier,
				'type_demarche'  => $d->type_demarche,
				'type_nom'       => $d->type_nom,
				'statut'         => $d->statut,
				'created_at'     => $d->created_at,
				'updated_at'     => $d->updated_at,
			];
		}, $rows );
	}

	/**
	 * Détail d'un dossier avec le fil de messages (utilisé par le citoyen et l'admin).
	 */
	public static function get_demarche( WP_REST_Request $request ) {
		global $wpdb;
		$table       = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$types_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$msg_table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_messages';

		$id      = absint( $request['id'] );
		$dossier = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );
		if ( ! $dossier ) {
			return new WP_Error( 'grc_not_found', 'Dossier introuvable.', [ 'status' => 404 ] );
		}

		GRC_Audit_Log::log( 'demarche_viewed', 'demarche', $id );

		$type     = $wpdb->get_row( $wpdb->prepare( "SELECT nom, champs_json FROM {$types_table} WHERE slug = %s", $dossier->type_demarche ) );
		$messages = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$msg_table} WHERE demarche_id = %d ORDER BY created_at ASC", $id ) );

		$pj_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';
		$pieces_dossier = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$pj_table} WHERE demarche_id = %d AND demarche_message_id IS NULL ORDER BY created_at ASC",
			$id
		) );

		return [
			'id'             => (int) $dossier->id,
			'numero_dossier' => $dossier->numero_dossier,
			'type_demarche'  => $dossier->type_demarche,
			'type_nom'      => $type->nom ?? $dossier->type_demarche,
			'statut'        => $dossier->statut,
			'donnees'       => json_decode( $dossier->donnees_json, true ) ?: [],
			'champs'        => $type ? ( json_decode( $type->champs_json, true ) ?: [] ) : [],
			'created_at'    => $dossier->created_at,
			'pieces_jointes'=> array_map( [ __CLASS__, 'format_piece' ], $pieces_dossier ),
			'messages'      => array_map( function ( $m ) use ( $wpdb, $pj_table ) {
				$pieces = $wpdb->get_results( $wpdb->prepare(
					"SELECT * FROM {$pj_table} WHERE demarche_message_id = %d ORDER BY created_at ASC",
					$m->id
				) );
				return [
					'id'             => (int) $m->id,
					'auteur_type'    => $m->auteur_type,
					'contenu'        => $m->contenu,
					'created_at'     => $m->created_at,
					'pieces_jointes' => array_map( [ __CLASS__, 'format_piece' ], $pieces ),
				];
			}, $messages ),
		];
	}

	private static function format_piece( $p ): array {
		return [
			'id'           => (int) $p->id,
			'nom_original' => $p->nom_original,
			'mime_type'    => $p->mime_type,
			'download_url' => rest_url( GRC_REST_API::NAMESPACE_V1 . '/pieces-jointes/' . $p->id ),
		];
	}

	/**
	 * Ajoute un message au fil d'échange d'un dossier (agent ou citoyen propriétaire),
	 * avec possibilité de joindre un ou plusieurs documents (PDF/.docx).
	 */
	public static function add_message( WP_REST_Request $request ) {
		$id      = absint( $request['id'] );
		$contenu = sanitize_textarea_field( $request->get_param( 'contenu' ) ?? '' );

		$files_params = $request->get_file_params();
		$has_files    = ! empty( $files_params['files'] ) || ! empty( $files_params['file'] );

		if ( '' === trim( $contenu ) && ! $has_files ) {
			return new WP_Error( 'grc_empty_message', 'Le message ne peut pas être vide.', [ 'status' => 400 ] );
		}
		if ( '' === trim( $contenu ) ) {
			$contenu = '[Document joint]';
		}

		$auteur_type = current_user_can( 'grc_manage_demandes' ) ? 'agent' : 'citoyen';
		$auteur_id   = 'agent' === $auteur_type ? get_current_user_id() : GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_messages';
		$wpdb->insert( $table, [
			'demarche_id' => $id,
			'auteur_type' => $auteur_type,
			'auteur_id'   => $auteur_id,
			'contenu'     => $contenu,
			'created_at'  => current_time( 'mysql' ),
		] );
		$message_id = (int) $wpdb->insert_id;

		GRC_Audit_Log::log( 'demarche_message_added', 'demarche', $id, [ 'auteur_type' => $auteur_type ] );

		if ( 'citoyen' === $auteur_type ) {
			GRC_Notifications::notify_agents_nouveau_message_demarche( $id );
		} else {
			GRC_Notifications::notify_citoyen_nouveau_message_demarche( $id );
		}

		$pieces_resultats = [];
		if ( $has_files ) {
			$pieces_resultats = GRC_REST_Attachments::upload_files_for_demarche_message( $request, $id, $message_id );
		}

		return [ 'success' => true, 'id' => $message_id, 'pieces_jointes' => $pieces_resultats ];
	}

	/**
	 * Autorise l'accès (lecture/écriture) à un dossier : agent avec capacité, ou
	 * citoyen JWT propriétaire du dossier.
	 */
	public static function can_access_demarche( WP_REST_Request $request, int $demarche_id ): bool {
		if ( current_user_can( 'grc_manage_demandes' ) || current_user_can( 'grc_view_all' ) ) {
			return true;
		}

		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
		if ( ! $citoyen_id ) {
			return false;
		}

		global $wpdb;
		$table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$dossier = $wpdb->get_var( $wpdb->prepare( "SELECT citoyen_id FROM {$table} WHERE id = %d", $demarche_id ) );

		return $dossier && (int) $dossier === $citoyen_id;
	}

	public static function update_statut( WP_REST_Request $request ) {
		$id     = absint( $request['id'] );
		$statut = sanitize_text_field( $request->get_param( 'statut' ) );
		$commentaire = sanitize_textarea_field( $request->get_param( 'commentaire' ) ?? '' );

		$allowed = [ 'en_attente', 'en_cours', 'valide', 'rejete', 'complement_requis' ];
		if ( ! in_array( $statut, $allowed, true ) ) {
			return new WP_Error( 'grc_invalid_statut', 'Statut invalide.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$wpdb->update( $table, [ 'statut' => $statut ], [ 'id' => $id ] );

		if ( '' !== trim( $commentaire ) ) {
			$msg_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_messages';
			$wpdb->insert( $msg_table, [
				'demarche_id' => $id,
				'auteur_type' => 'agent',
				'auteur_id'   => get_current_user_id(),
				'contenu'     => $commentaire,
				'created_at'  => current_time( 'mysql' ),
			] );
		}

		GRC_Audit_Log::log( 'demarche_statut_changed', 'demarche', $id, [ 'nouveau_statut' => $statut ] );

		return [ 'success' => true, 'statut' => $statut ];
	}
}
