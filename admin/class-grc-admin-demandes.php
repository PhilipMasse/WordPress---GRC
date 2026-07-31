<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface d'administration des demandes/signalements.
 * Liste filtrable + vue détail (assignation, changement de statut, messages internes).
 */
class GRC_Admin_Demandes {

	public static function init() {
		add_action( 'admin_post_grc_assign_agent', [ __CLASS__, 'handle_assign_agent' ] );
		add_action( 'admin_post_grc_change_statut', [ __CLASS__, 'handle_change_statut' ] );
		add_action( 'admin_post_grc_add_message', [ __CLASS__, 'handle_add_message' ] );
		add_action( 'admin_post_grc_archive_demande', [ __CLASS__, 'handle_archive_demande' ] );
		add_action( 'admin_post_grc_unarchive_demande', [ __CLASS__, 'handle_unarchive_demande' ] );
		add_action( 'admin_post_grc_download_pdf', [ __CLASS__, 'handle_download_pdf' ] );
	}

	public static function render() {
		$demande_id = isset( $_GET['demande_id'] ) ? absint( $_GET['demande_id'] ) : 0;

		if ( isset( $_GET['grc_notice'] ) ) {
			self::render_notice( sanitize_text_field( wp_unslash( $_GET['grc_notice'] ) ) );
		}

		if ( $demande_id ) {
			self::render_detail( $demande_id );
		} else {
			self::render_list();
		}
	}

	private static function render_notice( string $code ) {
		$messages = [
			'assigned'       => [ 'success', 'Agent assigné avec succès.' ],
			'statut_updated' => [ 'success', 'Statut mis à jour.' ],
			'message_added'  => [ 'success', 'Message ajouté.' ],
			'error'          => [ 'error', 'Une erreur est survenue.' ],
			'archive_error'  => [ 'error', 'Seule une demande Résolue ou Clôturée peut être archivée.' ],
		];
		if ( isset( $messages[ $code ] ) ) {
			[ $type, $text ] = $messages[ $code ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	// ------------------------------------------------------------------
	// LISTE
	// ------------------------------------------------------------------

	private static function render_list() {
		global $wpdb;
		$demandes_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$citoyens_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$services_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$categories_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';

		$filtre_statut    = sanitize_text_field( wp_unslash( $_GET['statut'] ?? '' ) );
		$filtre_service   = absint( $_GET['service_id'] ?? 0 );
		$filtre_categorie = absint( $_GET['categorie_id'] ?? 0 );
		$filtre_numero    = sanitize_text_field( wp_unslash( $_GET['numero'] ?? '' ) );
		$vue_archive      = sanitize_key( $_GET['vue'] ?? 'actives' ); // actives | archivees | toutes
		$paged            = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page         = 20;

		$where  = [ '1=1' ];
		$params = [];

		// Restriction par service pour les agents (non élus/admin).
		if ( ! current_user_can( 'grc_view_all' ) ) {
			$agents_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'agents';
			$mon_service  = $wpdb->get_var( $wpdb->prepare( "SELECT service_id FROM {$agents_table} WHERE wp_user_id = %d AND actif = 1", get_current_user_id() ) );
			$where[]  = 'd.service_id = %d';
			$params[] = (int) $mon_service;
		}

		if ( $filtre_statut ) {
			$where[]  = 'd.statut = %s';
			$params[] = $filtre_statut;
		}
		if ( $filtre_service ) {
			$where[]  = 'd.service_id = %d';
			$params[] = $filtre_service;
		}
		if ( $filtre_categorie ) {
			$where[]  = 'd.categorie_id = %d';
			$params[] = $filtre_categorie;
		}
		if ( $filtre_numero ) {
			$where[]  = 'd.numero_suivi LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $filtre_numero ) . '%';
		}
		if ( 'archivees' === $vue_archive ) {
			$where[] = 'd.archive = 1';
		} elseif ( 'toutes' !== $vue_archive ) {
			$where[] = 'd.archive = 0';
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = ( $paged - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$demandes_table} d WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT d.*, c.nom AS c_nom, c.prenom AS c_prenom, s.nom AS service_nom, cat.nom AS categorie_nom
			FROM {$demandes_table} d
			LEFT JOIN {$citoyens_table} c ON c.id = d.citoyen_id
			LEFT JOIN {$services_table} s ON s.id = d.service_id
			LEFT JOIN {$categories_table} cat ON cat.id = d.categorie_id
			WHERE {$where_sql}
			ORDER BY d.created_at DESC
			LIMIT %d OFFSET %d";

		$query_params = array_merge( $params, [ $per_page, $offset ] );
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, $query_params ) );

		$services   = $wpdb->get_results( "SELECT id, nom FROM {$services_table} WHERE actif = 1 ORDER BY nom" );
		$categories = $wpdb->get_results( "SELECT id, nom FROM {$categories_table} WHERE actif = 1 ORDER BY nom" );

		$statuts = [
			''         => 'Tous les statuts',
			'nouveau'  => 'Nouveau',
			'en_cours' => 'En cours',
			'assigne'  => 'Assigné',
			'resolu'   => 'Résolu',
			'cloture'  => 'Clôturé',
			'reouvert' => 'Réouvert',
		];

		?>
		<div class="wrap">
			<h1>Demandes</h1>

			<form method="get" style="margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
				<input type="hidden" name="page" value="grc-demandes">
				<input type="text" name="numero" placeholder="N° de suivi..." value="<?php echo esc_attr( $filtre_numero ); ?>" style="width:160px;">

				<select name="statut">
					<?php foreach ( $statuts as $val => $label ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $filtre_statut, $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>

				<select name="service_id">
					<option value="0">Tous les services</option>
					<?php foreach ( $services as $s ) : ?>
						<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $filtre_service, (int) $s->id ); ?>><?php echo esc_html( $s->nom ); ?></option>
					<?php endforeach; ?>
				</select>

				<select name="categorie_id">
					<option value="0">Toutes les catégories</option>
					<?php foreach ( $categories as $c ) : ?>
						<option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $filtre_categorie, (int) $c->id ); ?>><?php echo esc_html( $c->nom ); ?></option>
					<?php endforeach; ?>
				</select>

				<select name="vue">
					<option value="actives" <?php selected( $vue_archive, 'actives' ); ?>>Actives (masquer les archives)</option>
					<option value="archivees" <?php selected( $vue_archive, 'archivees' ); ?>>Archivées uniquement</option>
					<option value="toutes" <?php selected( $vue_archive, 'toutes' ); ?>>Toutes (actives + archivées)</option>
				</select>

				<button type="submit" class="button">Filtrer</button>
				<?php if ( $filtre_statut || $filtre_service || $filtre_categorie || $filtre_numero || 'actives' !== $vue_archive ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demandes' ) ); ?>" class="button">Réinitialiser</a>
				<?php endif; ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>N° suivi</th>
						<th>Titre</th>
						<th>Citoyen</th>
						<th>Catégorie</th>
						<th>Service</th>
						<th>Statut</th>
						<th>Créée le</th>
						<th>SLA</th>
						<th>Action</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="9">Aucune demande trouvée.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$nom_complet = trim(
							( $row->c_prenom ? GRC_Encryption::decrypt( $row->c_prenom ) : '' ) . ' ' .
							( $row->c_nom ? GRC_Encryption::decrypt( $row->c_nom ) : '' )
						);
						$en_retard = $row->date_limite_sla && strtotime( $row->date_limite_sla ) < time() && ! in_array( $row->statut, [ 'resolu', 'cloture' ], true );
						?>
						<tr>
							<td><code><?php echo esc_html( $row->numero_suivi ); ?></code></td>
							<td><?php echo esc_html( $row->titre ); ?></td>
							<td>
								<?php if ( $row->citoyen_id ) : ?>
									<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $row->citoyen_id ) ); ?>"><?php echo esc_html( $nom_complet ?: '—' ); ?></a>
									<br><code style="font-size:11px;color:#888;"><?php echo esc_html( GRC_Citoyen_Helper::numero( (int) $row->citoyen_id ) ); ?></code>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row->categorie_nom ?: '—' ); ?></td>
							<td><?php echo esc_html( $row->service_nom ?: '—' ); ?></td>
							<td><?php self::render_statut_badge( $row->statut ); ?></td>
							<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $row->created_at ) ); ?></td>
							<td><?php echo $en_retard ? '<span style="color:#b32d2e;font-weight:600;">En retard</span>' : '—'; ?></td>
							<td style="white-space:nowrap;">
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demandes&demande_id=' . $row->id ) ); ?>">Voir</a>
								<?php if ( $row->archive ) : ?>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_unarchive_demande&id=' . $row->id ), 'grc_archive_demande_' . $row->id ) ); ?>">Désarchiver</a>
								<?php else : ?>
									<?php if ( in_array( $row->statut, [ 'resolu', 'cloture' ], true ) ) : ?>
									<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_archive_demande&id=' . $row->id ), 'grc_archive_demande_' . $row->id ) ); ?>">Archiver</a>
								<?php else : ?>
									<span class="button button-small" style="opacity:0.4;cursor:not-allowed;" title="Seule une demande Résolue ou Clôturée peut être archivée">Archiver</span>
								<?php endif; ?>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php self::render_pagination( $total, $per_page, $paged ); ?>
		</div>
		<?php
	}

	private static function render_pagination( int $total, int $per_page, int $paged ) {
		$total_pages = (int) ceil( $total / $per_page );
		if ( $total_pages <= 1 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( [
			'base'      => add_query_arg( 'paged', '%#%' ),
			'format'    => '',
			'prev_text' => '«',
			'next_text' => '»',
			'total'     => $total_pages,
			'current'   => $paged,
		] );
		echo '</div></div>';
	}

	private static function render_statut_badge( string $statut ) {
		$colors = [
			'nouveau'  => '#2D6AB0',
			'en_cours' => '#DEA128',
			'assigne'  => '#DEA128',
			'resolu'   => '#587526',
			'cloture'  => '#666',
			'reouvert' => '#b32d2e',
		];
		$labels = [
			'nouveau'  => 'Nouveau',
			'en_cours' => 'En cours',
			'assigne'  => 'Assigné',
			'resolu'   => 'Résolu',
			'cloture'  => 'Clôturé',
			'reouvert' => 'Réouvert',
		];
		$color = $colors[ $statut ] ?? '#666';
		$label = $labels[ $statut ] ?? $statut;
		printf(
			'<span style="background:%s;color:#fff;padding:2px 10px;border-radius:12px;font-size:12px;">%s</span>',
			esc_attr( $color ),
			esc_html( $label )
		);
	}

	// ------------------------------------------------------------------
	// DÉTAIL
	// ------------------------------------------------------------------

	private static function render_detail( int $demande_id ) {
		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$agents_table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'agents';
		$messages_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'messages';

		$demande = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$demandes_table} WHERE id = %d", $demande_id ) );
		if ( ! $demande ) {
			echo '<div class="wrap"><p>Demande introuvable.</p></div>';
			return;
		}

		// Contrôle d'accès : un agent ne voit que les demandes de son service.
		if ( ! current_user_can( 'grc_view_all' ) && ! GRC_Roles::can_manage_service( (int) $demande->service_id ) ) {
			echo '<div class="wrap"><p>Vous n\'avez pas accès à cette demande.</p></div>';
			return;
		}

		GRC_Audit_Log::log( 'demande_viewed_admin', 'demande', $demande_id );

		$citoyen = $demande->citoyen_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$citoyens_table} WHERE id = %d", $demande->citoyen_id ) ) : null;
		$services = $wpdb->get_results( "SELECT id, nom FROM {$services_table} WHERE actif = 1 ORDER BY nom" );
		$agents_du_service = $demande->service_id ? $wpdb->get_results( $wpdb->prepare(
			"SELECT a.wp_user_id, u.display_name FROM {$agents_table} a
			 INNER JOIN {$wpdb->users} u ON u.ID = a.wp_user_id
			 WHERE a.service_id = %d AND a.actif = 1", $demande->service_id
		) ) : [];
		$messages = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$messages_table} WHERE demande_id = %d ORDER BY created_at ASC", $demande_id ) );

		$pj_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';
		$pieces   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$pj_table} WHERE demande_id = %d ORDER BY created_at ASC", $demande_id ) );

		$nom      = $citoyen && $citoyen->nom ? GRC_Encryption::decrypt( $citoyen->nom ) : '';
		$prenom   = $citoyen && $citoyen->prenom ? GRC_Encryption::decrypt( $citoyen->prenom ) : '';
		$email    = $citoyen && $citoyen->email ? GRC_Encryption::decrypt( $citoyen->email ) : '';
		$telephone = $citoyen && $citoyen->telephone ? GRC_Encryption::decrypt( $citoyen->telephone ) : '';

		?>
		<div class="wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demandes' ) ); ?>">&larr; Retour à la liste</a></p>
			<h1>
				<?php echo esc_html( $demande->titre ); ?>
				<code style="font-size:14px;font-weight:400;"><?php echo esc_html( $demande->numero_suivi ); ?></code>
			</h1>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_download_pdf&id=' . $demande_id ), 'grc_download_pdf_' . $demande_id ) ); ?>">📄 Télécharger le PDF</a>
			</p>

			<div style="display:flex;gap:24px;margin-top:20px;align-items:flex-start;">
				<div style="flex:2;">
					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Description</h2>
						<p><?php echo wp_kses_post( wpautop( $demande->description ) ); ?></p>
						<?php if ( $demande->adresse_lieu ) : ?>
							<p><strong>Lieu :</strong> <?php echo esc_html( $demande->adresse_lieu ); ?></p>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $pieces ) ) : ?>
					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Pièces jointes (<?php echo count( $pieces ); ?>)</h2>
						<div style="display:flex;gap:12px;flex-wrap:wrap;">
							<?php foreach ( $pieces as $piece ) : ?>
								<?php $url = GRC_Admin::get_download_url( $piece->id ); ?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" style="display:block;width:120px;text-align:center;font-size:12px;">
									<?php if ( 0 === strpos( $piece->mime_type, 'image/' ) ) : ?>
										<span class="dashicons dashicons-format-image" style="font-size:48px;width:48px;height:48px;color:#2D6AB0;"></span>
									<?php else : ?>
										<span class="dashicons dashicons-media-document" style="font-size:48px;width:48px;height:48px;color:#587526;"></span>
									<?php endif; ?>
									<span style="word-break:break-all;"><?php echo esc_html( $piece->nom_original ); ?></span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
					<?php endif; ?>

					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Échanges</h2>
						<?php if ( empty( $messages ) ) : ?>
							<p><em>Aucun message pour le moment.</em></p>
						<?php endif; ?>
						<?php foreach ( $messages as $m ) : ?>
							<div style="border-left:3px solid <?php echo $m->interne ? '#DEA128' : '#2D6AB0'; ?>;padding:8px 12px;margin-bottom:10px;background:#f9f9f9;">
								<strong><?php echo $m->interne ? 'Note interne' : 'Message'; ?></strong>
								<span style="color:#666;font-size:12px;"> — <?php echo esc_html( mysql2date( 'd/m/Y H:i', $m->created_at ) ); ?></span>
								<p style="margin:6px 0 0;"><?php echo esc_html( $m->contenu ); ?></p>
							</div>
						<?php endforeach; ?>

						<?php if ( current_user_can( 'grc_manage_demandes' ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
								<input type="hidden" name="action" value="grc_add_message">
								<input type="hidden" name="demande_id" value="<?php echo esc_attr( $demande_id ); ?>">
								<?php wp_nonce_field( 'grc_add_message_' . $demande_id ); ?>
								<textarea name="contenu" rows="3" style="width:100%;" placeholder="Écrire un message ou une note interne..."></textarea>
								<label style="display:block;margin:8px 0;">
									<input type="checkbox" name="interne" value="1"> Note interne (non visible du citoyen)
								</label>
								<button type="submit" class="button button-primary">Envoyer</button>
							</form>
						<?php endif; ?>
					</div>
				</div>

				<div style="flex:1;">
					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Citoyen</h2>
						<?php if ( $citoyen ) : ?>
							<p><code style="color:#888;"><?php echo esc_html( GRC_Citoyen_Helper::numero( (int) $citoyen->id ) ); ?></code></p>
						<?php endif; ?>
						<p><strong><?php echo esc_html( trim( "$prenom $nom" ) ?: 'Non renseigné' ); ?></strong></p>
						<?php if ( $email ) : ?><p><?php echo esc_html( $email ); ?></p><?php endif; ?>
						<?php if ( $telephone ) : ?><p><?php echo esc_html( $telephone ); ?></p><?php endif; ?>
						<?php if ( $citoyen && $citoyen->is_guest ) : ?><p><em>Mode invité</em></p><?php endif; ?>
						<?php if ( $citoyen ) : ?><p><a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $citoyen->id ) ); ?>">Voir la fiche complète →</a></p><?php endif; ?>
					</div>

					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Statut</h2>
						<p><?php self::render_statut_badge( $demande->statut ); ?></p>
						<?php if ( current_user_can( 'grc_manage_demandes' ) ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="grc_change_statut">
								<input type="hidden" name="demande_id" value="<?php echo esc_attr( $demande_id ); ?>">
								<?php wp_nonce_field( 'grc_change_statut_' . $demande_id ); ?>
								<select name="statut" style="width:100%;margin-bottom:8px;">
									<?php foreach ( [ 'nouveau', 'en_cours', 'assigne', 'resolu', 'cloture', 'reouvert' ] as $s ) : ?>
										<option value="<?php echo esc_attr( $s ); ?>" <?php selected( $demande->statut, $s ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $s ) ) ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button">Mettre à jour</button>
							</form>
						<?php endif; ?>
					</div>

					<?php if ( current_user_can( 'grc_assign_demandes' ) ) : ?>
					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Assignation</h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="grc_assign_agent">
							<input type="hidden" name="demande_id" value="<?php echo esc_attr( $demande_id ); ?>">
							<?php wp_nonce_field( 'grc_assign_agent_' . $demande_id ); ?>

							<label style="display:block;margin-bottom:6px;">Service</label>
							<select name="service_id" style="width:100%;margin-bottom:8px;">
								<?php foreach ( $services as $s ) : ?>
									<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( (int) $demande->service_id, (int) $s->id ); ?>><?php echo esc_html( $s->nom ); ?></option>
								<?php endforeach; ?>
							</select>

							<label style="display:block;margin-bottom:6px;">Agent</label>
							<select name="agent_id" style="width:100%;margin-bottom:8px;">
								<option value="0">Non assigné</option>
								<?php foreach ( $agents_du_service as $a ) : ?>
									<option value="<?php echo esc_attr( $a->wp_user_id ); ?>" <?php selected( (int) $demande->agent_assigne_id, (int) $a->wp_user_id ); ?>><?php echo esc_html( $a->display_name ); ?></option>
								<?php endforeach; ?>
							</select>

							<button type="submit" class="button button-primary">Assigner</button>
						</form>
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// ACTIONS (admin-post)
	// ------------------------------------------------------------------

	public static function handle_assign_agent() {
		$demande_id = absint( $_POST['demande_id'] ?? 0 );
		check_admin_referer( 'grc_assign_agent_' . $demande_id );

		if ( ! current_user_can( 'grc_assign_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$service_id = absint( $_POST['service_id'] ?? 0 );
		$agent_id   = absint( $_POST['agent_id'] ?? 0 );

		$numero_suivi = $wpdb->get_var( $wpdb->prepare( "SELECT numero_suivi FROM {$table} WHERE id = %d", $demande_id ) );

		$wpdb->update( $table, [
			'service_id'       => $service_id ?: null,
			'agent_assigne_id' => $agent_id ?: null,
			'statut'           => $agent_id ? 'assigne' : 'nouveau',
		], [ 'id' => $demande_id ] );

		$agent_nom = $agent_id ? get_userdata( $agent_id )->display_name ?? null : null;

		GRC_Audit_Log::log( 'demande_assigned', 'demande', $demande_id, [
			'numero_suivi' => $numero_suivi,
			'agent_id'     => $agent_id,
			'agent_nom'    => $agent_nom,
			'service_id'   => $service_id,
		] );

		wp_safe_redirect( admin_url( "admin.php?page=grc-demandes&demande_id={$demande_id}&grc_notice=assigned" ) );
		exit;
	}

	public static function handle_change_statut() {
		$demande_id = absint( $_POST['demande_id'] ?? 0 );
		check_admin_referer( 'grc_change_statut_' . $demande_id );

		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$statut  = sanitize_text_field( wp_unslash( $_POST['statut'] ?? '' ) );
		$allowed = [ 'nouveau', 'en_cours', 'assigne', 'resolu', 'cloture', 'reouvert' ];
		if ( ! in_array( $statut, $allowed, true ) ) {
			wp_safe_redirect( admin_url( "admin.php?page=grc-demandes&demande_id={$demande_id}&grc_notice=error" ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$avant = $wpdb->get_row( $wpdb->prepare( "SELECT statut, numero_suivi, citoyen_id FROM {$table} WHERE id = %d", $demande_id ) );

		$extra = [];
		if ( 'resolu' === $statut ) {
			$extra['resolved_at'] = current_time( 'mysql' );
		}
		if ( 'cloture' === $statut ) {
			$extra['closed_at'] = current_time( 'mysql' );
		}

		$wpdb->update( $table, array_merge( [ 'statut' => $statut ], $extra ), [ 'id' => $demande_id ] );
		GRC_Audit_Log::log( 'demande_statut_changed', 'demande', $demande_id, [
			'numero_suivi'   => $avant->numero_suivi ?? null,
			'citoyen_id'     => $avant->citoyen_id ?? null,
			'ancien_statut'  => $avant->statut ?? null,
			'nouveau_statut' => $statut,
		] );

		// Notification citoyen si un email est disponible.
		self::notify_citoyen_if_email( $demande_id, $statut );

		wp_safe_redirect( admin_url( "admin.php?page=grc-demandes&demande_id={$demande_id}&grc_notice=statut_updated" ) );
		exit;
	}

	public static function handle_add_message() {
		$demande_id = absint( $_POST['demande_id'] ?? 0 );
		check_admin_referer( 'grc_add_message_' . $demande_id );

		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$contenu = sanitize_textarea_field( wp_unslash( $_POST['contenu'] ?? '' ) );
		$interne = ! empty( $_POST['interne'] ) ? 1 : 0;

		if ( '' === trim( $contenu ) ) {
			wp_safe_redirect( admin_url( "admin.php?page=grc-demandes&demande_id={$demande_id}&grc_notice=error" ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'messages';
		$wpdb->insert( $table, [
			'demande_id'  => $demande_id,
			'auteur_type' => 'agent',
			'auteur_id'   => get_current_user_id(),
			'contenu'     => $contenu,
			'interne'     => $interne,
			'created_at'  => current_time( 'mysql' ),
		] );

		GRC_Audit_Log::log( 'message_added', 'demande', $demande_id, [ 'interne' => $interne ] );

		wp_safe_redirect( admin_url( "admin.php?page=grc-demandes&demande_id={$demande_id}&grc_notice=message_added" ) );
		exit;
	}

	private static function notify_citoyen_if_email( int $demande_id, string $statut ) {
		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$citoyen_id = $wpdb->get_var( $wpdb->prepare( "SELECT citoyen_id FROM {$demandes_table} WHERE id = %d", $demande_id ) );
		if ( ! $citoyen_id ) {
			return;
		}
		$email_encrypted = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$citoyens_table} WHERE id = %d", $citoyen_id ) );
		if ( ! $email_encrypted ) {
			return;
		}
		$email = GRC_Encryption::decrypt( $email_encrypted );
		if ( $email ) {
			GRC_Notifications::send_statut_change( $demande_id, $email, $statut );
		}
	}

	public static function handle_download_pdf() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_download_pdf_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$pdf_content = GRC_PDF_Signalement::generate( $id );
		if ( ! $pdf_content ) {
			wp_die( 'Impossible de générer le PDF (demande introuvable).' );
		}

		global $wpdb;
		$numero = $wpdb->get_var( $wpdb->prepare(
			"SELECT numero_suivi FROM {$wpdb->prefix}" . GRC_TABLE_PREFIX . "demandes WHERE id = %d", $id
		) );

		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="signalement-' . sanitize_file_name( $numero ?: $id ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf_content ) );
		echo $pdf_content;
		exit;
	}

	public static function handle_archive_demande() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_archive_demande_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}
		global $wpdb;
		$table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$statut = $wpdb->get_var( $wpdb->prepare( "SELECT statut FROM {$table} WHERE id = %d", $id ) );

		if ( ! in_array( $statut, [ 'resolu', 'cloture' ], true ) ) {
			wp_safe_redirect( add_query_arg( 'grc_notice', 'archive_error', wp_get_referer() ?: admin_url( 'admin.php?page=grc-demandes' ) ) );
			exit;
		}

		$wpdb->update( $table, [ 'archive' => 1 ], [ 'id' => $id ] );
		GRC_Audit_Log::log( 'demande_archived', 'demande', $id );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=grc-demandes' ) );
		exit;
	}

	public static function handle_unarchive_demande() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_archive_demande_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}
		global $wpdb;
		$wpdb->update( $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes', [ 'archive' => 0 ], [ 'id' => $id ] );
		GRC_Audit_Log::log( 'demande_unarchived', 'demande', $id );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=grc-demandes' ) );
		exit;
	}
}
