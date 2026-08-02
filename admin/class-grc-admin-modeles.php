<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modèles de messages types (réponses pré-rédigées) que les agents peuvent
 * insérer en un clic lorsqu'ils répondent à un signalement ou une démarche —
 * accusé de réception, demande de complément, etc.
 */
class GRC_Admin_Modeles {

	public static function init() {
		add_action( 'admin_post_grc_save_modele', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_grc_delete_modele', [ __CLASS__, 'handle_delete' ] );
	}

	public static function render() {
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		$edit_id = isset( $_GET['modele_id'] ) ? absint( $_GET['modele_id'] ) : 0;

		if ( isset( $_GET['grc_notice'] ) ) {
			$messages = [
				'saved'   => [ 'success', 'Modèle enregistré.' ],
				'deleted' => [ 'success', 'Modèle supprimé.' ],
			];
			$code = sanitize_text_field( wp_unslash( $_GET['grc_notice'] ) );
			if ( isset( $messages[ $code ] ) ) {
				[ $type, $text ] = $messages[ $code ];
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';

		$modele_edite = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ) ) : null;
		$modeles = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY ordre ASC, titre ASC" );

		$contexte_labels = [ 'tous' => 'Signalements et démarches', 'demande' => 'Signalements uniquement', 'demarche' => 'Démarches uniquement' ];

		?>
		<div class="wrap">
			<h1>Modèles de messages</h1>
			<p class="description">Réponses pré-rédigées que vous pouvez insérer en un clic lors d'un échange avec un citoyen (accusé de réception, demande de complément, information...).</p>

			<div style="display:flex;gap:24px;align-items:flex-start;margin-top:16px;">
				<div style="flex:1;max-width:480px;">
					<div class="card" style="padding:16px;">
						<h2><?php echo $modele_edite ? 'Modifier le modèle' : 'Nouveau modèle'; ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="grc_save_modele">
							<input type="hidden" name="id" value="<?php echo esc_attr( $modele_edite->id ?? 0 ); ?>">
							<?php wp_nonce_field( 'grc_save_modele' ); ?>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Titre (repère interne)</label>
							<input type="text" name="titre" value="<?php echo esc_attr( $modele_edite->titre ?? '' ); ?>" required style="width:100%;margin-bottom:10px;" placeholder="Ex : Accusé de réception">

							<label style="display:block;font-weight:600;margin-bottom:4px;">Contexte</label>
							<select name="contexte" style="width:100%;margin-bottom:10px;">
								<?php foreach ( $contexte_labels as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $modele_edite->contexte ?? 'tous', $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Contenu du message</label>
							<textarea name="contenu" rows="6" required style="width:100%;margin-bottom:6px;"><?php echo esc_textarea( $modele_edite->contenu ?? '' ); ?></textarea>
							<p class="description" style="margin-bottom:10px;">
								Balises disponibles, remplacées automatiquement par les informations du dossier au moment de l'insertion :
								<?php foreach ( self::balises_disponibles() as $balise => $desc ) : ?>
									<br><code><?php echo esc_html( $balise ); ?></code> — <?php echo esc_html( $desc ); ?>
								<?php endforeach; ?>
							</p>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Ordre d'affichage</label>
							<input type="number" name="ordre" value="<?php echo esc_attr( $modele_edite->ordre ?? 0 ); ?>" style="width:100px;margin-bottom:14px;">

							<button type="submit" class="button button-primary"><?php echo $modele_edite ? 'Enregistrer les modifications' : 'Créer le modèle'; ?></button>
							<?php if ( $modele_edite ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-modeles' ) ); ?>" class="button">Annuler</a>
							<?php endif; ?>
						</form>
					</div>
				</div>

				<div style="flex:2;">
					<table class="wp-list-table widefat fixed striped">
						<thead><tr><th>Titre</th><th>Contexte</th><th>Aperçu</th><th>Action</th></tr></thead>
						<tbody>
							<?php if ( empty( $modeles ) ) : ?>
								<tr><td colspan="4">Aucun modèle créé pour le moment.</td></tr>
							<?php endif; ?>
							<?php foreach ( $modeles as $m ) : ?>
								<tr>
									<td><?php echo esc_html( $m->titre ); ?></td>
									<td><?php echo esc_html( $contexte_labels[ $m->contexte ] ?? $m->contexte ); ?></td>
									<td><?php echo esc_html( wp_trim_words( $m->contenu, 12 ) ); ?></td>
									<td style="white-space:nowrap;">
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-modeles&modele_id=' . $m->id ) ); ?>">Modifier</a>
										<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_delete_modele&id=' . $m->id ), 'grc_delete_modele_' . $m->id ) ); ?>" onclick="return confirm('Supprimer ce modèle ?');">Suppr.</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	public static function handle_save() {
		check_admin_referer( 'grc_save_modele' );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$id       = absint( $_POST['id'] ?? 0 );
		$titre    = sanitize_text_field( wp_unslash( $_POST['titre'] ?? '' ) );
		$contenu  = sanitize_textarea_field( wp_unslash( $_POST['contenu'] ?? '' ) );
		$contexte = sanitize_key( $_POST['contexte'] ?? 'tous' );
		$ordre    = absint( $_POST['ordre'] ?? 0 );

		if ( ! in_array( $contexte, [ 'tous', 'demande', 'demarche' ], true ) ) {
			$contexte = 'tous';
		}

		if ( ! $titre || ! $contenu ) {
			wp_safe_redirect( admin_url( 'admin.php?page=grc-modeles' ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';
		$data  = [ 'titre' => $titre, 'contenu' => $contenu, 'contexte' => $contexte, 'ordre' => $ordre ];

		if ( $id ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
			GRC_Audit_Log::log( 'modele_message_updated', 'modele_message', $id );
		} else {
			$wpdb->insert( $table, $data );
			GRC_Audit_Log::log( 'modele_message_created', 'modele_message', (int) $wpdb->insert_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=grc-modeles&grc_notice=saved' ) );
		exit;
	}

	public static function handle_delete() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_delete_modele_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages', [ 'id' => $id ] );
		GRC_Audit_Log::log( 'modele_message_deleted', 'modele_message', $id );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-modeles&grc_notice=deleted' ) );
		exit;
	}

	/**
	 * Retourne les modèles applicables à un contexte donné ('demande' ou
	 * 'demarche'), pour alimenter le sélecteur d'insertion rapide.
	 */
	/**
	 * Remplace les balises {xxx} d'un modèle par les valeurs réelles du dossier
	 * concerné (numéro, nom du citoyen, statut...). Les balises non fournies
	 * dans $donnees sont laissées telles quelles pour rester visibles.
	 */
	public static function resolve_balises( string $contenu, array $donnees ): string {
		$remplacements = [];
		foreach ( $donnees as $cle => $valeur ) {
			$remplacements[ '{' . $cle . '}' ] = (string) $valeur;
		}
		return strtr( $contenu, $remplacements );
	}

	/**
	 * Liste des balises disponibles, affichée en aide dans l'écran de gestion
	 * des modèles.
	 */
	public static function balises_disponibles(): array {
		return [
			'{numero}'  => 'Numéro de suivi (signalement) ou de dossier (démarche)',
			'{titre}'   => 'Objet du signalement (signalements uniquement)',
			'{prenom}'  => 'Prénom du citoyen',
			'{nom}'     => 'Nom du citoyen',
			'{statut}'  => 'Statut actuel',
			'{service}' => 'Service concerné',
			'{date}'    => 'Date du jour',
		];
	}

	public static function get_modeles_pour( string $contexte ): array {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, titre, contenu FROM {$table} WHERE contexte = 'tous' OR contexte = %s ORDER BY ordre ASC, titre ASC",
			$contexte
		) );
	}
}
