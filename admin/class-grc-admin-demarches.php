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
		add_action( 'admin_post_grc_archive_demarche', [ __CLASS__, 'handle_archive_demarche' ] );
		add_action( 'admin_post_grc_unarchive_demarche', [ __CLASS__, 'handle_unarchive_demarche' ] );
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

		self::render_dossiers_section();
	}

	/**
	 * Écran "Types de démarches" : liste des types, ou écran d'édition/création
	 * si un type_id est fourni (ou action=new).
	 */
	public static function render_types() {
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		if ( isset( $_GET['grc_notice'] ) ) {
			self::render_notice( sanitize_text_field( wp_unslash( $_GET['grc_notice'] ) ) );
		}

		$editing = isset( $_GET['type_id'] ) || 'new' === ( $_GET['action'] ?? '' );

		if ( $editing ) {
			$type_id = absint( $_GET['type_id'] ?? 0 );
			self::render_type_edit_screen( $type_id );
			return;
		}

		self::render_types_list();
	}

	private static function render_types_list() {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$types = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY nom" );

		?>
		<div class="wrap">
			<h1>
				Types de démarches
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demarche-types&action=new' ) ); ?>" class="page-title-action">Ajouter un type</a>
			</h1>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>Nom</th><th>Slug</th><th>Nb champs</th><th>Actif</th><th>Action</th></tr></thead>
				<tbody>
					<?php if ( empty( $types ) ) : ?>
						<tr><td colspan="5">Aucun type de démarche pour le moment.</td></tr>
					<?php endif; ?>
					<?php foreach ( $types as $t ) : ?>
						<?php $nb_champs = count( json_decode( $t->champs_json, true ) ?: [] ); ?>
						<tr>
							<td><strong><?php echo esc_html( $t->nom ); ?></strong></td>
							<td><code><?php echo esc_html( $t->slug ); ?></code></td>
							<td><?php echo (int) $nb_champs; ?></td>
							<td><?php echo $t->actif ? '✅' : '—'; ?></td>
							<td style="white-space:nowrap;">
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demarche-types&type_id=' . $t->id ) ); ?>">Modifier</a>
								<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_delete_demarche_type&id=' . $t->id ), 'grc_delete_demarche_type_' . $t->id ) ); ?>" onclick="return confirm('Supprimer ce type ?');">Supprimer</a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private static function render_type_edit_screen( int $type_id ) {
		global $wpdb;
		$type = null;
		if ( $type_id ) {
			$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
			$type  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $type_id ) );
			if ( ! $type ) {
				echo '<div class="wrap"><p>Type introuvable.</p></div>';
				return;
			}
		}
		?>
		<div class="wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demarche-types' ) ); ?>">&larr; Retour à la liste des types</a></p>
			<h1><?php echo $type ? 'Modifier le type de démarche' : 'Nouveau type de démarche'; ?></h1>
			<?php self::render_type_card( $type ); ?>
		</div>
		<?php
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

		$filtre_type   = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );
		$filtre_statut = sanitize_text_field( wp_unslash( $_GET['statut'] ?? '' ) );
		$date_from     = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
		$date_to       = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
		$vue_archive   = sanitize_key( $_GET['vue'] ?? 'actives' );
		$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page      = 25;

		$where  = [ '1=1' ];
		$params = [];

		if ( $filtre_type ) {
			$where[]  = 'd.type_demarche = %s';
			$params[] = $filtre_type;
		}
		if ( $filtre_statut ) {
			$where[]  = 'd.statut = %s';
			$params[] = $filtre_statut;
		}
		if ( $date_from ) {
			$where[]  = 'd.created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'd.created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}
		if ( 'archivees' === $vue_archive ) {
			$where[] = 'd.archive = 1';
		} elseif ( 'toutes' !== $vue_archive ) {
			$where[] = 'd.archive = 0';
		}

		$where_sql = implode( ' AND ', $where );

		// --- Reporting : répartition par statut et par type (sur l'ensemble filtré hors pagination) ---
		$stats_statut_sql = "SELECT d.statut, COUNT(*) as total FROM {$demarches_table} d WHERE {$where_sql} GROUP BY d.statut";
		$stats_statut     = $params ? $wpdb->get_results( $wpdb->prepare( $stats_statut_sql, $params ) ) : $wpdb->get_results( $stats_statut_sql );

		$stats_type_sql = "SELECT d.type_demarche, t.nom AS type_nom, COUNT(*) as total FROM {$demarches_table} d LEFT JOIN {$types_table} t ON t.slug = d.type_demarche WHERE {$where_sql} GROUP BY d.type_demarche ORDER BY total DESC";
		$stats_type     = $params ? $wpdb->get_results( $wpdb->prepare( $stats_type_sql, $params ) ) : $wpdb->get_results( $stats_type_sql );

		$total_count_sql = "SELECT COUNT(*) FROM {$demarches_table} d WHERE {$where_sql}";
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_count_sql, $params ) ) : $wpdb->get_var( $total_count_sql ) );

		// --- Liste paginée ---
		$offset   = ( $paged - 1 ) * $per_page;
		$list_sql = "SELECT d.*, t.nom AS type_nom, ci.nom AS c_nom, ci.prenom AS c_prenom FROM {$demarches_table} d
			LEFT JOIN {$types_table} t ON t.slug = d.type_demarche
			LEFT JOIN {$wpdb->prefix}" . GRC_TABLE_PREFIX . "citoyens ci ON ci.id = d.citoyen_id
			WHERE {$where_sql}
			ORDER BY d.created_at DESC
			LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, [ $per_page, $offset ] ) ) );

		$types = $wpdb->get_results( "SELECT slug, nom FROM {$types_table} ORDER BY nom" );

		$statut_labels = [
			'en_attente'        => 'En attente',
			'en_cours'          => 'En cours',
			'valide'            => 'Validé',
			'rejete'            => 'Rejeté',
			'complement_requis' => 'Complément requis',
		];

		?>
		<div class="wrap">
			<h1>Démarches — Dossiers soumis</h1>

			<?php if ( ! empty( $stats_statut ) ) : ?>
			<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">
				<div class="card" style="padding:12px 20px;"><strong style="font-size:22px;"><?php echo (int) $total; ?></strong><br>Total (filtré)</div>
				<?php foreach ( $stats_statut as $s ) : ?>
					<div class="card" style="padding:12px 20px;"><strong style="font-size:22px;"><?php echo (int) $s->total; ?></strong><br><?php echo esc_html( $statut_labels[ $s->statut ] ?? $s->statut ); ?></div>
				<?php endforeach; ?>
			</div>

			<?php if ( ! empty( $stats_type ) ) : ?>
			<div class="card" style="padding:16px;max-width:400px;margin-bottom:20px;">
				<h3 style="margin-top:0;">Répartition par type</h3>
				<table class="widefat">
					<?php foreach ( $stats_type as $st ) : ?>
						<tr><td><?php echo esc_html( $st->type_nom ?: $st->type_demarche ); ?></td><td style="text-align:right;"><?php echo (int) $st->total; ?></td></tr>
					<?php endforeach; ?>
				</table>
			</div>
			<?php endif; ?>
			<?php endif; ?>

			<form method="get" style="margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
				<input type="hidden" name="page" value="grc-demarches">

				<select name="type">
					<option value="">Tous les types</option>
					<?php foreach ( $types as $t ) : ?>
						<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $filtre_type, $t->slug ); ?>><?php echo esc_html( $t->nom ); ?></option>
					<?php endforeach; ?>
				</select>

				<select name="statut">
					<option value="">Tous les statuts</option>
					<?php foreach ( $statut_labels as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filtre_statut, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<label>Du <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"></label>
				<label>Au <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"></label>

				<select name="vue">
					<option value="actives" <?php selected( $vue_archive, 'actives' ); ?>>Actives (masquer les archives)</option>
					<option value="archivees" <?php selected( $vue_archive, 'archivees' ); ?>>Archivées uniquement</option>
					<option value="toutes" <?php selected( $vue_archive, 'toutes' ); ?>>Toutes</option>
				</select>

				<button type="submit" class="button">Filtrer</button>
				<?php if ( $filtre_type || $filtre_statut || $date_from || $date_to || 'actives' !== $vue_archive ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demarches' ) ); ?>" class="button">Réinitialiser</a>
				<?php endif; ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>N° dossier</th><th>Citoyen</th><th>Type</th><th>Statut</th><th>Soumis le</th><th>Action</th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6">Aucun dossier trouvé.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $d ) : ?>
					<?php
						$nom_complet = trim(
							( $d->c_prenom ? GRC_Encryption::decrypt( $d->c_prenom ) : '' ) . ' ' .
							( $d->c_nom ? GRC_Encryption::decrypt( $d->c_nom ) : '' )
						);
					?>
					<tr>
						<td><code><?php echo esc_html( $d->numero_dossier ?: '#' . $d->id ); ?></code></td>
						<td>
							<?php if ( $d->citoyen_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $d->citoyen_id ) ); ?>"><?php echo esc_html( $nom_complet ?: '—' ); ?></a>
								<br><code style="font-size:11px;color:#888;"><?php echo esc_html( GRC_Citoyen_Helper::numero( (int) $d->citoyen_id ) ); ?></code>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $d->type_nom ?: $d->type_demarche ); ?></td>
						<td><?php echo esc_html( $statut_labels[ $d->statut ] ?? $d->statut ); ?></td>
						<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $d->created_at ) ); ?></td>
						<td style="white-space:nowrap;">
							<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demarches&dossier_id=' . $d->id ) ); ?>">Voir</a>
							<?php if ( $d->archive ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_unarchive_demarche&id=' . $d->id ), 'grc_archive_demarche_' . $d->id ) ); ?>">Désarchiver</a>
							<?php else : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_archive_demarche&id=' . $d->id ), 'grc_archive_demarche_' . $d->id ) ); ?>">Archiver</a>
							<?php endif; ?>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) :
				?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo paginate_links( [
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '«',
						'next_text' => '»',
						'total'     => $total_pages,
						'current'   => $paged,
					] );
					?>
				</div></div>
			<?php endif; ?>
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
			<h1>
				<?php echo esc_html( $type->nom ?? $dossier->type_demarche ); ?>
				<code style="font-size:14px;font-weight:400;color:#888;"><?php echo esc_html( $dossier->numero_dossier ?: '#' . $dossier->id ); ?></code>
			</h1>

			<div class="card" style="padding:16px;max-width:600px;margin-bottom:16px;">
				<h2>Citoyen</h2>
				<?php if ( $citoyen ) : ?>
					<p><code style="color:#888;"><?php echo esc_html( GRC_Citoyen_Helper::numero( (int) $citoyen->id ) ); ?></code></p>
					<p><?php echo esc_html( trim( ( $citoyen->prenom ? GRC_Encryption::decrypt( $citoyen->prenom ) : '' ) . ' ' . ( $citoyen->nom ? GRC_Encryption::decrypt( $citoyen->nom ) : '' ) ) ?: '—' ); ?></p>
					<?php if ( $citoyen->email ) : ?><p><?php echo esc_html( GRC_Encryption::decrypt( $citoyen->email ) ); ?></p><?php endif; ?>
					<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $citoyen->id ) ); ?>">Voir la fiche complète →</a></p>
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
			wp_safe_redirect( admin_url( 'admin.php?page=grc-demarche-types&grc_notice=error' ) );
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
		wp_safe_redirect( admin_url( 'admin.php?page=grc-demarche-types&grc_notice=type_saved' ) );
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

		wp_safe_redirect( admin_url( 'admin.php?page=grc-demarche-types&grc_notice=type_deleted' ) );
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

	public static function handle_archive_demarche() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_archive_demarche_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}
		global $wpdb;
		$wpdb->update( $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches', [ 'archive' => 1 ], [ 'id' => $id ] );
		GRC_Audit_Log::log( 'demarche_archived', 'demarche', $id );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=grc-demarches' ) );
		exit;
	}

	public static function handle_unarchive_demarche() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_archive_demarche_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}
		global $wpdb;
		$wpdb->update( $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches', [ 'archive' => 0 ], [ 'id' => $id ] );
		GRC_Audit_Log::log( 'demarche_unarchived', 'demarche', $id );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=grc-demarches' ) );
		exit;
	}
}
