<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import de la configuration du plugin au format JSON — utile pour
 * dupliquer services, catégories, types de démarches, modèles de messages et
 * réglages généraux entre deux environnements (ex: test3.berrelesalpes.fr
 * vers la production), sans ressaisir manuellement.
 *
 * Volontairement exclus de l'export : toutes les données citoyennes
 * (demandes, démarches, RDV, citoyens), les clés de chiffrement/JWT, les
 * identifiants SMTP et les clés secrètes des fournisseurs de captcha — ces
 * éléments sont propres à chaque environnement et ne doivent jamais être
 * dupliqués tels quels.
 */
class GRC_Admin_Export_Import {

	const FORMAT_VERSION = 1;

	public static function init() {
		add_action( 'admin_post_grc_export_config', [ __CLASS__, 'handle_export' ] );
		add_action( 'admin_post_grc_import_config', [ __CLASS__, 'handle_import' ] );
	}

	public static function render() {
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		if ( isset( $_GET['grc_import_resume'] ) ) {
			$resume = get_transient( 'grc_import_resume_' . get_current_user_id() );
			if ( $resume ) {
				echo '<div class="notice notice-success"><p><strong>Import terminé.</strong> ' . esc_html( $resume ) . '</p></div>';
				delete_transient( 'grc_import_resume_' . get_current_user_id() );
			}
		}
		if ( isset( $_GET['grc_import_erreur'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['grc_import_erreur'] ) ) ) . '</p></div>';
		}

		?>
		<div class="wrap">
			<h1>Export / Import de configuration</h1>
			<p class="description">Dupliquez services, catégories, types de démarches, modèles de messages et réglages généraux entre deux environnements (ex : de <code>test3.berrelesalpes.fr</code> vers la production).</p>

			<div style="display:flex;gap:24px;margin-top:20px;">
				<div class="card" style="padding:16px;flex:1;">
					<h2>Exporter</h2>
					<p>Génère un fichier JSON contenant :</p>
					<ul style="list-style:disc;margin-left:20px;">
						<li>Services et catégories de signalement</li>
						<li>Types de démarches (avec leurs champs)</li>
						<li>Modèles de messages</li>
						<li>Réglages généraux (délais, session, matrice de notifications, fournisseur de captcha choisi)</li>
					</ul>
					<p class="description"><strong>Non inclus</strong> (volontairement) : données citoyennes, clés de chiffrement, identifiants SMTP, clés secrètes de captcha.</p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_export_config' ), 'grc_export_config' ) ); ?>">Télécharger l'export JSON</a>
					</p>
				</div>

				<div class="card" style="padding:16px;flex:1;">
					<h2>Importer</h2>
					<p>Charge un fichier JSON exporté depuis un autre environnement GRC. Les éléments déjà existants (même nom / même slug) sont <strong>mis à jour</strong>, les nouveaux sont créés — rien n'est jamais supprimé automatiquement.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="grc_import_config">
						<?php wp_nonce_field( 'grc_import_config' ); ?>
						<p><input type="file" name="fichier_config" accept="application/json,.json" required></p>
						<button type="submit" class="button button-primary" onclick="return confirm('Importer cette configuration ? Les éléments existants (même nom/slug) seront mis à jour.');">Importer</button>
					</form>
				</div>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Export
	// ------------------------------------------------------------------

	public static function handle_export() {
		check_admin_referer( 'grc_export_config' );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$services_table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$categories_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';
		$types_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$modeles_table    = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';

		$services = $wpdb->get_results( "SELECT nom, description, email_contact, actif FROM {$services_table}", ARRAY_A );

		$categories_brutes = $wpdb->get_results( "SELECT * FROM {$categories_table}", ARRAY_A );
		$services_par_id = $wpdb->get_results( "SELECT id, nom FROM {$services_table}", OBJECT_K );
		$categories_par_id = [];
		foreach ( $categories_brutes as $c ) {
			$categories_par_id[ $c['id'] ] = $c['nom'];
		}
		$categories = array_map( function ( $c ) use ( $services_par_id, $categories_par_id ) {
			return [
				'nom'         => $c['nom'],
				'service_nom' => $c['service_id'] && isset( $services_par_id[ $c['service_id'] ] ) ? $services_par_id[ $c['service_id'] ]->nom : null,
				'parent_nom'  => $c['parent_id'] && isset( $categories_par_id[ $c['parent_id'] ] ) ? $categories_par_id[ $c['parent_id'] ] : null,
				'sla_heures'  => $c['sla_heures'] ? (int) $c['sla_heures'] : null,
				'ordre'       => (int) $c['ordre'],
				'actif'       => (int) $c['actif'],
			];
		}, $categories_brutes );

		$demarche_types = $wpdb->get_results( "SELECT nom, slug, description, champs_json, actif FROM {$types_table}", ARRAY_A );

		$modeles = $wpdb->get_results( "SELECT titre, sujet, contenu, contexte, notif_type, ordre FROM {$modeles_table}", ARRAY_A );

		$export = [
			'format_version' => self::FORMAT_VERSION,
			'plugin_version' => GRC_VERSION,
			'exporte_le'     => current_time( 'mysql' ),
			'site'           => home_url(),
			'services'       => $services,
			'categories'     => $categories,
			'demarche_types' => $demarche_types,
			'modeles_messages' => $modeles,
			'reglages'       => [
				'delai_validation_heures'   => get_option( 'grc_rdv_delai_validation_heures', 48 ),
				'session_timeout_minutes'   => get_option( 'grc_session_timeout_minutes', 30 ),
				'audit_retention_mois'      => get_option( 'grc_audit_retention_mois', 12 ),
				'captcha_provider'          => get_option( 'grc_captcha_provider', 'interne' ),
				'notif_matrix'              => get_option( 'grc_notif_matrix', [] ),
			],
		];

		GRC_Audit_Log::log( 'config_exported', 'settings', 0 );

		$filename = 'grc-config-' . gmdate( 'Y-m-d-His' ) . '.json';
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	// ------------------------------------------------------------------
	// Import
	// ------------------------------------------------------------------

	public static function handle_import() {
		check_admin_referer( 'grc_import_config' );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		if ( empty( $_FILES['fichier_config']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['fichier_config']['error'] ) {
			self::rediriger_erreur( 'Aucun fichier reçu ou erreur de téléversement.' );
		}

		$contenu = file_get_contents( $_FILES['fichier_config']['tmp_name'] );
		$data = json_decode( $contenu, true );

		if ( null === $data || ! isset( $data['format_version'] ) ) {
			self::rediriger_erreur( 'Fichier JSON invalide ou non reconnu comme export GRC.' );
		}

		global $wpdb;
		$compteurs = [ 'services' => 0, 'categories' => 0, 'demarche_types' => 0, 'modeles_messages' => 0 ];

		// --- Services (doivent être importés avant les catégories qui les référencent) ---
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		foreach ( $data['services'] ?? [] as $s ) {
			$existant = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services_table} WHERE nom = %s", $s['nom'] ) );
			$champs = [
				'nom'           => $s['nom'],
				'description'   => $s['description'] ?? null,
				'email_contact' => $s['email_contact'] ?? null,
				'actif'         => $s['actif'] ?? 1,
			];
			if ( $existant ) {
				$wpdb->update( $services_table, $champs, [ 'id' => $existant ] );
			} else {
				$wpdb->insert( $services_table, $champs );
			}
			$compteurs['services']++;
		}

		// --- Catégories : deux passes (création sans parent, puis résolution du parent par nom) ---
		$categories_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';
		$services_par_nom = $wpdb->get_results( "SELECT id, nom FROM {$services_table}", OBJECT_K );
		$id_par_nom_service = [];
		foreach ( $services_par_nom as $row ) {
			$id_par_nom_service[ $row->nom ] = $row->id;
		}

		foreach ( $data['categories'] ?? [] as $c ) {
			$existant = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$categories_table} WHERE nom = %s", $c['nom'] ) );
			$champs = [
				'nom'        => $c['nom'],
				'service_id' => ! empty( $c['service_nom'] ) ? ( $id_par_nom_service[ $c['service_nom'] ] ?? null ) : null,
				'sla_heures' => $c['sla_heures'] ?? null,
				'ordre'      => $c['ordre'] ?? 0,
				'actif'      => $c['actif'] ?? 1,
			];
			if ( $existant ) {
				$wpdb->update( $categories_table, $champs, [ 'id' => $existant ] );
			} else {
				$wpdb->insert( $categories_table, $champs );
			}
			$compteurs['categories']++;
		}
		// Deuxième passe : résolution des parent_id par nom (les sous-catégories peuvent référencer une catégorie créée dans la même passe).
		$categories_par_nom = $wpdb->get_results( "SELECT id, nom FROM {$categories_table}", OBJECT_K );
		$id_par_nom_categorie = [];
		foreach ( $categories_par_nom as $row ) {
			$id_par_nom_categorie[ $row->nom ] = $row->id;
		}
		foreach ( $data['categories'] ?? [] as $c ) {
			if ( ! empty( $c['parent_nom'] ) && isset( $id_par_nom_categorie[ $c['parent_nom'] ], $id_par_nom_categorie[ $c['nom'] ] ) ) {
				$wpdb->update( $categories_table, [ 'parent_id' => $id_par_nom_categorie[ $c['parent_nom'] ] ], [ 'id' => $id_par_nom_categorie[ $c['nom'] ] ] );
			}
		}

		// --- Types de démarches (identifiés par slug, unique) ---
		$types_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		foreach ( $data['demarche_types'] ?? [] as $t ) {
			$existant = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types_table} WHERE slug = %s", $t['slug'] ) );
			$champs = [
				'nom'         => $t['nom'],
				'slug'        => $t['slug'],
				'description' => $t['description'] ?? null,
				'champs_json' => $t['champs_json'] ?? '[]',
				'actif'       => $t['actif'] ?? 1,
			];
			if ( $existant ) {
				$wpdb->update( $types_table, $champs, [ 'id' => $existant ] );
			} else {
				$wpdb->insert( $types_table, $champs );
			}
			$compteurs['demarche_types']++;
		}

		// --- Modèles de messages (identifiés par notif_type si présent, sinon par titre) ---
		$modeles_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';
		foreach ( $data['modeles_messages'] ?? [] as $m ) {
			if ( ! empty( $m['notif_type'] ) ) {
				$existant = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$modeles_table} WHERE notif_type = %s", $m['notif_type'] ) );
			} else {
				$existant = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$modeles_table} WHERE titre = %s AND notif_type IS NULL", $m['titre'] ) );
			}
			$champs = [
				'titre'      => $m['titre'],
				'sujet'      => $m['sujet'] ?? null,
				'contenu'    => $m['contenu'],
				'contexte'   => $m['contexte'] ?? 'tous',
				'notif_type' => $m['notif_type'] ?? null,
				'ordre'      => $m['ordre'] ?? 0,
			];
			if ( $existant ) {
				$wpdb->update( $modeles_table, $champs, [ 'id' => $existant ] );
			} else {
				$wpdb->insert( $modeles_table, $champs );
			}
			$compteurs['modeles_messages']++;
		}

		// --- Réglages généraux (non sensibles uniquement) ---
		if ( isset( $data['reglages'] ) ) {
			$r = $data['reglages'];
			if ( isset( $r['delai_validation_heures'] ) ) {
				update_option( 'grc_rdv_delai_validation_heures', absint( $r['delai_validation_heures'] ) );
			}
			if ( isset( $r['session_timeout_minutes'] ) ) {
				update_option( 'grc_session_timeout_minutes', absint( $r['session_timeout_minutes'] ) );
			}
			if ( isset( $r['audit_retention_mois'] ) ) {
				update_option( 'grc_audit_retention_mois', absint( $r['audit_retention_mois'] ) );
			}
			if ( isset( $r['captcha_provider'] ) && in_array( $r['captcha_provider'], [ 'interne', 'turnstile', 'recaptcha', 'hcaptcha' ], true ) ) {
				update_option( 'grc_captcha_provider', $r['captcha_provider'] );
			}
			if ( isset( $r['notif_matrix'] ) && is_array( $r['notif_matrix'] ) ) {
				update_option( 'grc_notif_matrix', $r['notif_matrix'] );
			}
		}

		GRC_Audit_Log::log( 'config_imported', 'settings', 0, $compteurs );

		$resume = sprintf(
			'%d service(s), %d catégorie(s), %d type(s) de démarche, %d modèle(s) de message importés/mis à jour.',
			$compteurs['services'], $compteurs['categories'], $compteurs['demarche_types'], $compteurs['modeles_messages']
		);
		set_transient( 'grc_import_resume_' . get_current_user_id(), $resume, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-export-import&grc_import_resume=1' ) );
		exit;
	}

	private static function rediriger_erreur( string $message ) {
		wp_safe_redirect( admin_url( 'admin.php?page=grc-export-import&grc_import_erreur=' . rawurlencode( $message ) ) );
		exit;
	}
}
