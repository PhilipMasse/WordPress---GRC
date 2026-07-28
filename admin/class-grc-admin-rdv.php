<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administration des rendez-vous : gestion des créneaux disponibles par service
 * (création unitaire ou génération en masse) et suivi des rendez-vous pris.
 */
class GRC_Admin_RDV {

	public static function init() {
		add_action( 'admin_post_grc_generate_creneaux', [ __CLASS__, 'handle_generate_creneaux' ] );
		add_action( 'admin_post_grc_delete_creneau', [ __CLASS__, 'handle_delete_creneau' ] );
		add_action( 'admin_post_grc_cancel_rdv', [ __CLASS__, 'handle_cancel_rdv' ] );
	}

	public static function render() {
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		if ( isset( $_GET['grc_notice'] ) ) {
			self::render_notice( sanitize_text_field( wp_unslash( $_GET['grc_notice'] ) ) );
		}

		$tab = sanitize_key( $_GET['tab'] ?? 'rdv' );

		echo '<div class="wrap"><h1>Rendez-vous</h1>';
		echo '<nav class="nav-tab-wrapper">';
		printf(
			'<a href="%s" class="nav-tab%s">Rendez-vous</a>',
			esc_url( admin_url( 'admin.php?page=grc-rdv&tab=rdv' ) ),
			'rdv' === $tab ? ' nav-tab-active' : ''
		);
		printf(
			'<a href="%s" class="nav-tab%s">Créneaux</a>',
			esc_url( admin_url( 'admin.php?page=grc-rdv&tab=creneaux' ) ),
			'creneaux' === $tab ? ' nav-tab-active' : ''
		);
		echo '</nav>';

		if ( 'creneaux' === $tab ) {
			self::render_creneaux_tab();
		} else {
			self::render_rdv_tab();
		}
		echo '</div>';
	}

	private static function render_notice( string $code ) {
		$messages = [
			'creneau_deleted'    => [ 'success', 'Créneau supprimé.' ],
			'creneaux_generated' => [ 'success', 'Créneaux générés avec succès.' ],
			'rdv_cancelled'      => [ 'success', 'Rendez-vous annulé.' ],
			'error'              => [ 'error', 'Une erreur est survenue.' ],
		];
		if ( isset( $messages[ $code ] ) ) {
			[ $type, $text ] = $messages[ $code ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	// ------------------------------------------------------------------
	// Onglet Rendez-vous
	// ------------------------------------------------------------------

	private static function render_rdv_tab() {
		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$filtre_service = absint( $_GET['service_id'] ?? 0 );
		$filtre_statut  = sanitize_text_field( wp_unslash( $_GET['statut'] ?? '' ) );

		$where  = [ '1=1' ];
		$params = [];
		if ( $filtre_service ) {
			$where[]  = 'r.service_id = %d';
			$params[] = $filtre_service;
		}
		if ( $filtre_statut ) {
			$where[]  = 'r.statut = %s';
			$params[] = $filtre_statut;
		}
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT r.*, c.debut, c.fin, s.nom AS service_nom, ci.nom AS c_nom, ci.prenom AS c_prenom
			FROM {$rdv_table} r
			LEFT JOIN {$creneaux_table} c ON c.id = r.creneau_id
			LEFT JOIN {$services_table} s ON s.id = r.service_id
			LEFT JOIN {$citoyens_table} ci ON ci.id = r.citoyen_id
			WHERE {$where_sql}
			ORDER BY c.debut DESC LIMIT 100";
		$rows = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_results( $sql );

		$services = $wpdb->get_results( "SELECT id, nom FROM {$services_table} WHERE actif = 1 ORDER BY nom" );

		?>
		<form method="get" style="margin:16px 0;display:flex;gap:10px;">
			<input type="hidden" name="page" value="grc-rdv">
			<select name="service_id">
				<option value="0">Tous les services</option>
				<?php foreach ( $services as $s ) : ?>
					<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $filtre_service, (int) $s->id ); ?>><?php echo esc_html( $s->nom ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="statut">
				<option value="">Tous les statuts</option>
				<option value="confirme" <?php selected( $filtre_statut, 'confirme' ); ?>>Confirmé</option>
				<option value="annule" <?php selected( $filtre_statut, 'annule' ); ?>>Annulé</option>
			</select>
			<button type="submit" class="button">Filtrer</button>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>Citoyen</th><th>Service</th><th>Date</th><th>Motif</th><th>Statut</th><th>Action</th></tr></thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6">Aucun rendez-vous trouvé.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$nom_complet = trim(
						( $r->c_prenom ? GRC_Encryption::decrypt( $r->c_prenom ) : '' ) . ' ' .
						( $r->c_nom ? GRC_Encryption::decrypt( $r->c_nom ) : '' )
					);
					?>
					<tr>
						<td><?php echo esc_html( $nom_complet ?: '—' ); ?></td>
						<td><?php echo esc_html( $r->service_nom ?: '—' ); ?></td>
						<td><?php echo $r->debut ? esc_html( mysql2date( 'd/m/Y H:i', $r->debut ) ) : '—'; ?></td>
						<td><?php echo esc_html( $r->motif ?: '—' ); ?></td>
						<td><?php echo 'confirme' === $r->statut ? '<span style="color:#587526;">Confirmé</span>' : '<span style="color:#b32d2e;">Annulé</span>'; ?></td>
						<td>
							<?php if ( 'confirme' === $r->statut ) : ?>
								<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_cancel_rdv&id=' . $r->id ), 'grc_cancel_rdv_' . $r->id ) ); ?>" onclick="return confirm('Annuler ce rendez-vous ?');">Annuler</a>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ------------------------------------------------------------------
	// Onglet Créneaux
	// ------------------------------------------------------------------

	private static function render_creneaux_tab() {
		global $wpdb;
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';

		$services = $wpdb->get_results( "SELECT id, nom FROM {$services_table} WHERE actif = 1 ORDER BY nom" );

		$filtre_service = absint( $_GET['service_id'] ?? ( $services[0]->id ?? 0 ) );

		$creneaux = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$creneaux_table} WHERE service_id = %d AND debut > %s ORDER BY debut ASC LIMIT 100",
			$filtre_service,
			current_time( 'mysql' )
		) );

		?>
		<div style="display:flex;gap:24px;align-items:flex-start;margin-top:16px;">
			<div style="flex:1;">
				<h2>Générer des créneaux récurrents</h2>
				<div class="card" style="padding:16px;max-width:420px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="grc_generate_creneaux">
						<?php wp_nonce_field( 'grc_generate_creneaux' ); ?>

						<label style="display:block;font-weight:600;margin-bottom:4px;">Service</label>
						<select name="service_id" style="width:100%;margin-bottom:10px;">
							<?php foreach ( $services as $s ) : ?>
								<option value="<?php echo esc_attr( $s->id ); ?>"><?php echo esc_html( $s->nom ); ?></option>
							<?php endforeach; ?>
						</select>

						<label style="display:block;font-weight:600;margin-bottom:4px;">Période</label>
						<div style="display:flex;gap:8px;margin-bottom:10px;">
							<input type="date" name="date_debut" required style="flex:1;">
							<input type="date" name="date_fin" required style="flex:1;">
						</div>

						<label style="display:block;font-weight:600;margin-bottom:4px;">Jours de la semaine</label>
						<div style="margin-bottom:10px;">
							<?php foreach ( [ 1 => 'Lun', 2 => 'Mar', 3 => 'Mer', 4 => 'Jeu', 5 => 'Ven', 6 => 'Sam', 0 => 'Dim' ] as $val => $label ) : ?>
								<label style="margin-right:8px;"><input type="checkbox" name="jours[]" value="<?php echo esc_attr( $val ); ?>" <?php checked( $val >= 1 && $val <= 5 ); ?>> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</div>

						<label style="display:block;font-weight:600;margin-bottom:4px;">Créneaux horaires</label>
						<div style="display:flex;gap:8px;margin-bottom:10px;align-items:center;">
							<input type="time" name="heure_debut" value="09:00" required style="flex:1;">
							<span>à</span>
							<input type="time" name="heure_fin" value="17:00" required style="flex:1;">
						</div>

						<div style="display:flex;gap:8px;margin-bottom:10px;">
							<div style="flex:1;">
								<label style="display:block;font-weight:600;margin-bottom:4px;">Durée (min)</label>
								<input type="number" name="duree_minutes" value="30" min="5" style="width:100%;">
							</div>
							<div style="flex:1;">
								<label style="display:block;font-weight:600;margin-bottom:4px;">Capacité/créneau</label>
								<input type="number" name="capacite" value="1" min="1" style="width:100%;">
							</div>
						</div>

						<p class="description">Les pauses méridiennes ne sont pas gérées automatiquement : générez deux plages (ex : 9h-12h puis 14h-17h) si besoin.</p>
						<p class="description" style="color:#b32d2e;">⚠️ Si vous proposez des rendez-vous de 30 min <strong>et</strong> 1h pour ce service, générez les deux durées sur des <strong>plages horaires distinctes</strong> (ex : 30 min le matin, 1h l'après-midi). Générer les deux durées sur le même créneau horaire créerait un risque de double réservation.</p>

						<button type="submit" class="button button-primary">Générer les créneaux</button>
					</form>
				</div>
			</div>

			<div style="flex:1;">
				<h2>Créneaux à venir</h2>
				<form method="get" style="margin-bottom:12px;">
					<input type="hidden" name="page" value="grc-rdv">
					<input type="hidden" name="tab" value="creneaux">
					<select name="service_id" onchange="this.form.submit()">
						<?php foreach ( $services as $s ) : ?>
							<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $filtre_service, (int) $s->id ); ?>><?php echo esc_html( $s->nom ); ?></option>
						<?php endforeach; ?>
					</select>
				</form>

				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th>Début</th><th>Fin</th><th>Places</th><th>Action</th></tr></thead>
					<tbody>
						<?php if ( empty( $creneaux ) ) : ?>
							<tr><td colspan="4">Aucun créneau à venir pour ce service.</td></tr>
						<?php endif; ?>
						<?php foreach ( $creneaux as $c ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'd/m/Y H:i', $c->debut ) ); ?></td>
								<td><?php echo esc_html( mysql2date( 'H:i', $c->fin ) ); ?></td>
								<td><?php echo (int) $c->reserve; ?> / <?php echo (int) $c->capacite; ?></td>
								<td>
									<?php if ( 0 === (int) $c->reserve ) : ?>
										<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_delete_creneau&id=' . $c->id ), 'grc_delete_creneau_' . $c->id ) ); ?>" onclick="return confirm('Supprimer ce créneau ?');">Suppr.</a>
									<?php else : ?>
										<em style="color:#888;font-size:12px;">Réservé</em>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// ------------------------------------------------------------------
	// Handlers
	// ------------------------------------------------------------------

	public static function handle_generate_creneaux() {
		check_admin_referer( 'grc_generate_creneaux' );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$service_id    = absint( $_POST['service_id'] ?? 0 );
		$date_debut    = sanitize_text_field( wp_unslash( $_POST['date_debut'] ?? '' ) );
		$date_fin      = sanitize_text_field( wp_unslash( $_POST['date_fin'] ?? '' ) );
		$jours         = array_map( 'absint', $_POST['jours'] ?? [] );
		$heure_debut   = sanitize_text_field( wp_unslash( $_POST['heure_debut'] ?? '09:00' ) );
		$heure_fin     = sanitize_text_field( wp_unslash( $_POST['heure_fin'] ?? '17:00' ) );
		$duree_minutes = max( 5, absint( $_POST['duree_minutes'] ?? 30 ) );
		$capacite      = max( 1, absint( $_POST['capacite'] ?? 1 ) );

		if ( ! $service_id || ! $date_debut || ! $date_fin || empty( $jours ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&tab=creneaux&grc_notice=error' ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';

		$current = strtotime( $date_debut );
		$end     = strtotime( $date_fin );
		$count   = 0;
		$max_iterations = 366; // Garde-fou : pas plus d'un an de génération en une fois.

		while ( $current <= $end && $max_iterations-- > 0 ) {
			$jour_semaine = (int) gmdate( 'w', $current );
			if ( in_array( $jour_semaine, $jours, true ) ) {
				$jour_str         = gmdate( 'Y-m-d', $current );
				$slot_start       = strtotime( $jour_str . ' ' . $heure_debut );
				$slot_end_of_day  = strtotime( $jour_str . ' ' . $heure_fin );

				while ( $slot_start < $slot_end_of_day ) {
					$slot_end = $slot_start + ( $duree_minutes * 60 );
					if ( $slot_end > $slot_end_of_day ) {
						break;
					}
					$wpdb->insert( $table, [
						'service_id' => $service_id,
						'debut'      => gmdate( 'Y-m-d H:i:s', $slot_start ),
						'fin'        => gmdate( 'Y-m-d H:i:s', $slot_end ),
						'capacite'   => $capacite,
						'reserve'    => 0,
					] );
					$count++;
					$slot_start = $slot_end;
				}
			}
			$current = strtotime( '+1 day', $current );
		}

		GRC_Audit_Log::log( 'creneaux_generated', 'service', $service_id, [ 'nombre' => $count ] );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&tab=creneaux&service_id=' . $service_id . '&grc_notice=creneaux_generated' ) );
		exit;
	}

	public static function handle_delete_creneau() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_delete_creneau_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		// Sécurité : on ne supprime jamais un créneau déjà réservé.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND reserve = 0", $id ) );
		GRC_Audit_Log::log( 'creneau_deleted', 'creneau', $id );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&tab=creneaux&grc_notice=creneau_deleted' ) );
		exit;
	}

	public static function handle_cancel_rdv() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_cancel_rdv_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';

		$rdv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rdv_table} WHERE id = %d", $id ) );
		if ( $rdv && 'confirme' === $rdv->statut ) {
			$wpdb->update( $rdv_table, [ 'statut' => 'annule' ], [ 'id' => $id ] );
			$wpdb->query( $wpdb->prepare( "UPDATE {$creneaux_table} SET reserve = GREATEST(0, reserve - 1) WHERE id = %d", $rdv->creneau_id ) );
			GRC_Audit_Log::log( 'rdv_cancelled_admin', 'rdv', $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&grc_notice=rdv_cancelled' ) );
		exit;
	}
}
