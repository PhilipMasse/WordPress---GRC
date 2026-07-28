<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administration des démarches : définition des types (avec leurs champs)
 * et traitement des dossiers soumis par les citoyens.
 */
class GRC_Admin_Demarches {

	public static function init() {
		add_action( 'admin_post_grc_save_demarche_type', [ __CLASS__, 'handle_save_type' ] );
		add_action( 'admin_post_grc_delete_demarche_type', [ __CLASS__, 'handle_delete_type' ] );
		add_action( 'admin_post_grc_demarche_statut', [ __CLASS__, 'handle_update_statut' ] );
		add_action( 'admin_post_grc_demarche_message', [ __CLASS__, 'handle_add_message' ] );
	}

	public static function render() {
		if ( ! current_user_can( 'grc_manage_settings' ) && ! current_user_can( 'grc_manage_demandes' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		if ( isset( $_GET['grc_notice'] ) ) {
			self::render_notice( sanitize_text_field( wp_unslash( $_GET['grc_notice'] ) ) );
		}

		$dossier_id = isset( $_GET['dossier_id'] ) ? absint( $_GET['dossier_id'] ) : 0;
		if ( $dossier_id ) {
			self::render_dossier_detail( $dossier_id );
			return;
		}

		self::render_types_section();
		self::render_dossiers_section();
	}

	private static function render_notice( string $code ) {
		$messages = [
			'type_saved'     => [ 'success', 'Type de démarche enregistré.' ],
			'type_deleted'   => [ 'success', 'Type de démarche supprimé.' ],
			'statut_updated' => [ 'success', 'Statut du dossier mis à jour.' ],
			'error'          => [ 'error', 'Une erreur est survenue (vérifiez le format JSON des champs).' ],
		];
		if ( isset( $messages[ $code ] ) ) {
			[ $type, $text ] = $messages[ $code ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	private static function render_types_section() {
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$types = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY nom" );

		?>
		<div class="wrap">
			<h1>Démarches administratives</h1>
			<h2>Types de démarches</h2>
			<p class="description">Définissez les champs de chaque type de démarche avec le constructeur ci-dessous — pas besoin d'écrire du JSON à la main.</p>

			<?php foreach ( $types as $t ) : ?>
				<?php self::render_type_card( $t ); ?>
			<?php endforeach; ?>

			<h3>Nouveau type de démarche</h3>
			<?php self::render_type_card( null ); ?>
		</div>
		<?php
	}

	/**
	 * Affiche une carte "type de démarche" avec le constructeur de champs visuel.
	 * $type est null pour la carte de création d'un nouveau type.
	 */
	private static function render_type_card( $type ) {
		$id          = $type->id ?? 0;
		$nom         = $type->nom ?? '';
		$slug        = $type->slug ?? '';
		$actif       = $type ? (int) $type->actif : 1;
		$champs_json = $type->champs_json ?? '[]';
		$champs      = json_decode( $champs_json, true ) ?: [];
		$uid         = 'grc-champs-builder-' . ( $id ?: 'new' );
		?>
		<div class="card" style="padding:16px;max-width:700px;margin-bottom:20px;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="grc-demarche-type-form">
				<input type="hidden" name="action" value="grc_save_demarche_type">
				<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
				<?php wp_nonce_field( 'grc_save_demarche_type' ); ?>

				<div style="display:flex;gap:12px;margin-bottom:12px;">
					<div style="flex:1;">
						<label style="display:block;font-weight:600;margin-bottom:4px;">Nom</label>
						<input type="text" name="nom" value="<?php echo esc_attr( $nom ); ?>" style="width:100%;" placeholder="Ex : Demande d'état civil" required>
					</div>
					<div style="flex:1;">
						<label style="display:block;font-weight:600;margin-bottom:4px;">Slug (identifiant technique)</label>
						<input type="text" name="slug" value="<?php echo esc_attr( $slug ); ?>" style="width:100%;" placeholder="etat-civil" required>
					</div>
					<div>
						<label style="display:block;font-weight:600;margin-bottom:4px;">Actif</label>
						<input type="checkbox" name="actif" value="1" <?php checked( $actif, 1 ); ?> style="margin-top:8px;">
					</div>
				</div>

				<label style="display:block;font-weight:600;margin-bottom:6px;">Champs du formulaire</label>
				<div class="grc-champs-builder" id="<?php echo esc_attr( $uid ); ?>" data-initial='<?php echo esc_attr( wp_json_encode( $champs ) ); ?>'></div>
				<button type="button" class="button grc-champs-add-btn" data-target="<?php echo esc_attr( $uid ); ?>" style="margin:8px 0 16px;">+ Ajouter un champ</button>

				<input type="hidden" name="champs_json" class="grc-champs-json-input">

				<div>
					<button type="submit" class="button button-primary"><?php echo $id ? 'Enregistrer' : 'Créer le type'; ?></button>
					<?php if ( $id ) : ?>
						<a class="button" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_delete_demarche_type&id=' . $id ), 'grc_delete_demarche_type_' . $id ) ); ?>" onclick="return confirm('Supprimer ce type ?');">Supprimer</a>
					<?php endif; ?>
				</div>
			</form>
		</div>
		<?php
	}

	private static function render_dossiers_section() {
		global $wpdb;
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$types_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';

		$rows = $wpdb->get_results(
			"SELECT d.*, t.nom AS type_nom FROM {$demarches_table} d
			 LEFT JOIN {$types_table} t ON t.slug = d.type_demarche
			 ORDER BY d.created_at DESC LIMIT 100"
		);

		$statut_labels = [
			'en_attente'         => 'En attente',
			'en_cours'           => 'En cours',
			'valide'             => 'Validé',
			'rejete'             => 'Rejeté',
			'complement_requis'  => 'Complément requis',
		];

		?>
		<div class="wrap" style="margin-top:24px;">
			<h2>Dossiers soumis</h2>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>Type</th><th>Statut</th><th>Soumis le</th><th>Action</th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="4">Aucun dossier soumis pour le moment.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $d ) : ?>
					<tr>
						<td><?php echo esc_html( $d->type_nom ?: $d->type_demarche ); ?></td>
						<td><?php echo esc_html( $statut_labels[ $d->statut ] ?? $d->statut ); ?></td>
						<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $d->created_at ) ); ?></td>
						<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demarches&dossier_id=' . $d->id ) ); ?>">Voir</a></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_dossier_detail( int $id ) {
		global $wpdb;
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$citoyens_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$types_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';

		$dossier = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$demarches_table} WHERE id = %d", $id ) );
		if ( ! $dossier ) {
			echo '<div class="wrap"><p>Dossier introuvable.</p></div>';
			return;
		}

		$type    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$types_table} WHERE slug = %s", $dossier->type_demarche ) );
		$citoyen = $dossier->citoyen_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$citoyens_table} WHERE id = %d", $dossier->citoyen_id ) ) : null;
		$donnees = json_decode( $dossier->donnees_json, true ) ?: [];
		$champs  = $type ? ( json_decode( $type->champs_json, true ) ?: [] ) : [];

		$msg_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_messages';
		$messages  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$msg_table} WHERE demarche_id = %d ORDER BY created_at ASC", $id ) );

		$pj_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';
		$pieces   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pj_table} WHERE demarche_id = %d ORDER BY created_at ASC", $id ) );

		GRC_Audit_Log::log( 'demarche_viewed_admin', 'demarche', $id );

		?>
		<div class="wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demarches' ) ); ?>">&larr; Retour</a></p>
			<h1><?php echo esc_html( $type->nom ?? $dossier->type_demarche ); ?></h1>

			<div class="card" style="padding:16px;max-width:600px;margin-bottom:16px;">
				<h2>Citoyen</h2>
				<?php if ( $citoyen ) : ?>
					<p><?php echo esc_html( trim( ( $citoyen->prenom ? GRC_Encryption::decrypt( $citoyen->prenom ) : '' ) . ' ' . ( $citoyen->nom ? GRC_Encryption::decrypt( $citoyen->nom ) : '' ) ) ?: '—' ); ?></p>
					<?php if ( $citoyen->email ) : ?><p><?php echo esc_html( GRC_Encryption::decrypt( $citoyen->email ) ); ?></p><?php endif; ?>
				<?php endif; ?>
			</div>

			<div class="card" style="padding:16px;max-width:600px;margin-bottom:16px;">
				<h2>Informations soumises</h2>
				<table class="widefat">
					<?php foreach ( $champs as $champ ) : ?>
						<tr>
							<td style="width:200px;font-weight:600;"><?php echo esc_html( $champ['label'] ?? $champ['key'] ); ?></td>
							<td><?php echo esc_html( $donnees[ $champ['key'] ] ?? '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>

			<?php if ( ! empty( $pieces ) ) : ?>
			<div class="card" style="padding:16px;max-width:600px;margin-bottom:16px;">
				<h2>Documents joints</h2>
				<ul style="margin:0;">
					<?php foreach ( $pieces as $piece ) : ?>
						<li>
							<a href="<?php echo esc_url( GRC_Admin::get_download_url( $piece->id ) ); ?>" target="_blank">
								<?php echo esc_html( $piece->nom_original ); ?>
							</a>
							<span style="color:#666;font-size:12px;"> (<?php echo esc_html( strtoupper( pathinfo( $piece->nom_original, PATHINFO_EXTENSION ) ) ); ?>)</span>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			<?php endif; ?>

			<div class="card" style="padding:16px;max-width:600px;margin-bottom:16px;">
				<h2>Échanges avec le citoyen</h2>
				<?php if ( empty( $messages ) ) : ?>
					<p><em>Aucun message pour le moment.</em></p>
				<?php endif; ?>
				<?php foreach ( $messages as $m ) : ?>
					<div style="border-left:3px solid <?php echo 'agent' === $m->auteur_type ? '#2D6AB0' : '#587526'; ?>;padding:8px 12px;margin-bottom:10px;background:#f9f9f9;">
						<strong><?php echo 'agent' === $m->auteur_type ? 'Mairie' : 'Citoyen'; ?></strong>
						<span style="color:#666;font-size:12px;"> — <?php echo esc_html( mysql2date( 'd/m/Y H:i', $m->created_at ) ); ?></span>
						<p style="margin:6px 0 0;"><?php echo esc_html( $m->contenu ); ?></p>
						<?php
						$msg_pieces = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pj_table} WHERE demarche_message_id = %d", $m->id ) );
						foreach ( $msg_pieces as $mp ) :
							?>
							<p style="margin:4px 0 0;"><a href="<?php echo esc_url( GRC_Admin::get_download_url( $mp->id ) ); ?>" target="_blank">📄 <?php echo esc_html( $mp->nom_original ); ?></a></p>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-top:12px;">
					<input type="hidden" name="action" value="grc_demarche_message">
					<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'grc_demarche_message_' . $id ); ?>
					<textarea name="contenu" rows="3" style="width:100%;" placeholder="Écrire un message au citoyen..."></textarea>
					<p><label>Joindre un ou plusieurs documents (PDF/.docx) : <input type="file" name="files[]" multiple accept=".pdf,.docx,application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document"></label></p>
					<button type="submit" class="button button-primary" style="margin-top:8px;">Envoyer</button>
				</form>
			</div>

			<div class="card" style="padding:16px;max-width:600px;">
				<h2>Statut</h2>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="grc_demarche_statut">
					<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'grc_demarche_statut_' . $id ); ?>
					<select name="statut" style="margin-bottom:8px;display:block;">
						<?php foreach ( [ 'en_attente' => 'En attente', 'en_cours' => 'En cours', 'valide' => 'Validé', 'rejete' => 'Rejeté', 'complement_requis' => 'Complément requis' ] as $val => $label ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $dossier->statut, $val ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<label style="display:block;margin-bottom:6px;">Commentaire à communiquer au citoyen (obligatoire si Rejeté ou Complément requis)</label>
					<textarea name="commentaire" rows="3" style="width:100%;margin-bottom:8px;" placeholder="Ex : merci de fournir une copie de votre pièce d'identité."></textarea>
					<button type="submit" class="button button-primary">Mettre à jour</button>
				</form>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------

	public static function handle_save_type() {
		check_admin_referer( 'grc_save_demarche_type' );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$champs_json = wp_unslash( $_POST['champs_json'] ?? '[]' );
		$decoded     = json_decode( $champs_json, true );
		if ( null === $decoded && '' !== trim( $champs_json ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=grc-demarches&grc_notice=error' ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';

		$id   = absint( $_POST['id'] ?? 0 );
		$data = [
			'nom'         => sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
			'slug'        => sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) ),
			'champs_json' => wp_json_encode( $decoded ?: [] ),
			'actif'       => ! empty( $_POST['actif'] ) ? 1 : 0,
		];

		if ( $id ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
		} else {
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}

		GRC_Audit_Log::log( 'demarche_type_saved', 'demarche_type', $id );
		wp_safe_redirect( admin_url( 'admin.php?page=grc-demarches&grc_notice=type_saved' ) );
		exit;
	}

	public static function handle_delete_type() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_delete_demarche_type_' . $id );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types', [ 'id' => $id ] );
		GRC_Audit_Log::log( 'demarche_type_deleted', 'demarche_type', $id );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-demarches&grc_notice=type_deleted' ) );
		exit;
	}

	public static function handle_update_statut() {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'grc_demarche_statut_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$statut      = sanitize_text_field( wp_unslash( $_POST['statut'] ?? '' ) );
		$commentaire = sanitize_textarea_field( wp_unslash( $_POST['commentaire'] ?? '' ) );
		$allowed     = [ 'en_attente', 'en_cours', 'valide', 'rejete', 'complement_requis' ];

		if ( in_array( $statut, $allowed, true ) ) {
			global $wpdb;
			$wpdb->update( $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches', [ 'statut' => $statut ], [ 'id' => $id ] );
			GRC_Audit_Log::log( 'demarche_statut_changed', 'demarche', $id, [ 'nouveau_statut' => $statut ] );

			if ( '' !== trim( $commentaire ) ) {
				$msg_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_messages';
				$wpdb->insert( $msg_table, [
					'demarche_id' => $id,
					'auteur_type' => 'agent',
					'auteur_id'   => get_current_user_id(),
					'contenu'     => $commentaire,
					'created_at'  => current_time( 'mysql' ),
				] );
				GRC_Audit_Log::log( 'demarche_message_added', 'demarche', $id, [ 'auteur_type' => 'agent' ] );
			}
		}

		wp_safe_redirect( admin_url( "admin.php?page=grc-demarches&dossier_id={$id}&grc_notice=statut_updated" ) );
		exit;
	}

	public static function handle_add_message() {
		$id = absint( $_POST['id'] ?? 0 );
		check_admin_referer( 'grc_demarche_message_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$contenu   = sanitize_textarea_field( wp_unslash( $_POST['contenu'] ?? '' ) );
		$has_files = ! empty( $_FILES['files']['name'][0] ?? '' );

		if ( '' === trim( $contenu ) && ! $has_files ) {
			wp_safe_redirect( admin_url( "admin.php?page=grc-demarches&dossier_id={$id}&grc_notice=error" ) );
			exit;
		}
		if ( '' === trim( $contenu ) ) {
			$contenu = '[Document joint]';
		}

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_messages', [
			'demarche_id' => $id,
			'auteur_type' => 'agent',
			'auteur_id'   => get_current_user_id(),
			'contenu'     => $contenu,
			'created_at'  => current_time( 'mysql' ),
		] );
		$message_id = (int) $wpdb->insert_id;
		GRC_Audit_Log::log( 'demarche_message_added', 'demarche', $id, [ 'auteur_type' => 'agent' ] );

		if ( $has_files ) {
			GRC_REST_Attachments::process_multi_upload_raw(
				$_FILES,
				GRC_File_Scanner::ALLOWED_DOCUMENT_MIME,
				[ 'demarche_id' => $id ],
				'demarche',
				$id,
				$message_id
			);
		}

		wp_safe_redirect( admin_url( "admin.php?page=grc-demarches&dossier_id={$id}&grc_notice=statut_updated" ) );
		exit;
	}
}
