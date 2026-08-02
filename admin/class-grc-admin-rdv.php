<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Administration des rendez-vous : horaires hebdomadaires par service (avec
 * pause méridienne), gestion des absences, et suivi des rendez-vous pris.
 * Les créneaux individuels ne sont plus jamais gérés à la main : ils sont
 * générés automatiquement en arrière-plan à partir du modèle hebdomadaire
 * (voir GRC_Creneaux_Generator).
 */
class GRC_Admin_RDV {

	const JOURS = [ 1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 0 => 'Dimanche' ];

	public static function init() {
		add_action( 'admin_post_grc_save_disponibilites', [ __CLASS__, 'handle_save_disponibilites' ] );
		add_action( 'admin_post_grc_save_absence', [ __CLASS__, 'handle_save_absence' ] );
		add_action( 'admin_post_grc_delete_absence', [ __CLASS__, 'handle_delete_absence' ] );
		add_action( 'admin_post_grc_cancel_rdv', [ __CLASS__, 'handle_cancel_rdv' ] );
		add_action( 'admin_post_grc_validate_rdv', [ __CLASS__, 'handle_validate_rdv' ] );
		add_action( 'admin_post_grc_refuse_rdv', [ __CLASS__, 'handle_refuse_rdv' ] );
		add_action( 'admin_post_grc_archive_rdv', [ __CLASS__, 'handle_archive_rdv' ] );
		add_action( 'admin_post_grc_unarchive_rdv', [ __CLASS__, 'handle_unarchive_rdv' ] );
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
			'<a href="%s" class="nav-tab%s">Disponibilités</a>',
			esc_url( admin_url( 'admin.php?page=grc-rdv&tab=disponibilites' ) ),
			'disponibilites' === $tab ? ' nav-tab-active' : ''
		);
		echo '</nav>';

		if ( 'disponibilites' === $tab ) {
			self::render_disponibilites_tab();
		} else {
			self::render_rdv_tab();
		}
		echo '</div>';
	}

	private static function render_notice( string $code ) {
		$messages = [
			'disponibilites_saved' => [ 'success', 'Horaires enregistrés. Les créneaux à venir ont été mis à jour.' ],
			'absence_saved'        => [ 'success', 'Absence enregistrée.' ],
			'absence_deleted'      => [ 'success', 'Absence supprimée.' ],
			'rdv_cancelled'        => [ 'success', 'Rendez-vous annulé.' ],
			'rdv_validated'        => [ 'success', 'Rendez-vous validé et confirmé.' ],
			'rdv_refused'          => [ 'success', 'Rendez-vous refusé.' ],
			'error'                => [ 'error', 'Une erreur est survenue.' ],
			'archive_error'        => [ 'error', 'Un rendez-vous "En attente" ne peut pas être archivé (validez ou refusez-le d\'abord).' ],
		];
		if ( isset( $messages[ $code ] ) ) {
			[ $type, $text ] = $messages[ $code ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}

		$affectes = isset( $_GET['rdv_affectes'] ) ? absint( $_GET['rdv_affectes'] ) : 0;
		if ( $affectes > 0 ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Attention :</strong> %d rendez-vous déjà confirmé(s) tombent dans cette période d\'absence. Ils n\'ont pas été annulés automatiquement — vérifiez l\'onglet "Rendez-vous" et annulez-les manuellement en prévenant les citoyens concernés.</p></div>',
				(int) $affectes
			);
		}
	}

	// ------------------------------------------------------------------
	// Onglet Rendez-vous (inchangé)
	// ------------------------------------------------------------------

	private static function render_rdv_tab() {
		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$filtre_service = absint( $_GET['service_id'] ?? 0 );
		$filtre_statut  = sanitize_text_field( wp_unslash( $_GET['statut'] ?? '' ) );
		$vue_archive    = sanitize_key( $_GET['vue'] ?? 'actives' );

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
		if ( 'archivees' === $vue_archive ) {
			$where[] = 'r.archive = 1';
		} elseif ( 'toutes' !== $vue_archive ) {
			$where[] = 'r.archive = 0';
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
				<option value="en_attente" <?php selected( $filtre_statut, 'en_attente' ); ?>>En attente</option>
				<option value="confirme" <?php selected( $filtre_statut, 'confirme' ); ?>>Confirmé</option>
				<option value="refuse" <?php selected( $filtre_statut, 'refuse' ); ?>>Refusé</option>
				<option value="annule" <?php selected( $filtre_statut, 'annule' ); ?>>Annulé</option>
			</select>
			<select name="vue">
				<option value="actives" <?php selected( $vue_archive, 'actives' ); ?>>Actifs (masquer les archives)</option>
				<option value="archivees" <?php selected( $vue_archive, 'archivees' ); ?>>Archivés uniquement</option>
				<option value="toutes" <?php selected( $vue_archive, 'toutes' ); ?>>Tous</option>
			</select>
			<button type="submit" class="button">Filtrer</button>
			<?php if ( $filtre_service || $filtre_statut || 'actives' !== $vue_archive ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-rdv' ) ); ?>" class="button">Réinitialiser</a>
			<?php endif; ?>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead><tr><th>N° RDV</th><th>Citoyen</th><th>Service</th><th>Date</th><th>Motif</th><th>Statut</th><th>Action</th></tr></thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="7">Aucun rendez-vous trouvé.</td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<?php
					$nom_complet = trim(
						( $r->c_prenom ? GRC_Encryption::decrypt( $r->c_prenom ) : '' ) . ' ' .
						( $r->c_nom ? GRC_Encryption::decrypt( $r->c_nom ) : '' )
					);
					$badges = [
						'en_attente' => '<span style="color:#8a6414;font-weight:600;">En attente</span>',
						'confirme'   => '<span style="color:#587526;">Confirmé</span>',
						'refuse'     => '<span style="color:#b32d2e;">Refusé</span>',
						'annule'     => '<span style="color:#888;">Annulé</span>',
					];
					?>
					<tr>
						<td><code><?php echo esc_html( $r->numero_rdv ?: '#' . $r->id ); ?></code></td>
						<td>
							<?php if ( $r->citoyen_id ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $r->citoyen_id ) ); ?>"><?php echo esc_html( $nom_complet ?: '—' ); ?></a>
								<br><code style="font-size:11px;color:#888;"><?php echo esc_html( GRC_Citoyen_Helper::numero( (int) $r->citoyen_id ) ); ?></code>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $r->service_nom ?: '—' ); ?></td>
						<td><?php echo $r->debut ? esc_html( mysql2date( 'd/m/Y H:i', $r->debut ) ) : '—'; ?></td>
						<td><?php echo esc_html( $r->motif ?: '—' ); ?></td>
						<td><?php echo $badges[ $r->statut ] ?? esc_html( $r->statut ); ?></td>
						<td style="white-space:nowrap;">
							<?php if ( 'en_attente' === $r->statut ) : ?>
								<a class="button button-small button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_validate_rdv&id=' . $r->id ), 'grc_validate_rdv_' . $r->id ) ); ?>">Valider</a>
								<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_refuse_rdv&id=' . $r->id ), 'grc_refuse_rdv_' . $r->id ) ); ?>" onclick="return confirm('Refuser ce rendez-vous ?');">Refuser</a>
							<?php elseif ( 'confirme' === $r->statut ) : ?>
								<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_cancel_rdv&id=' . $r->id ), 'grc_cancel_rdv_' . $r->id ) ); ?>" onclick="return confirm('Annuler ce rendez-vous ?');">Annuler</a>
							<?php endif; ?>
							<?php if ( $r->archive ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_unarchive_rdv&id=' . $r->id ), 'grc_archive_rdv_' . $r->id ) ); ?>">Désarchiver</a>
							<?php elseif ( 'en_attente' !== $r->statut ) : ?>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_archive_rdv&id=' . $r->id ), 'grc_archive_rdv_' . $r->id ) ); ?>">Archiver</a>
							<?php else : ?>
								<span class="button button-small" style="opacity:0.4;cursor:not-allowed;" title="Un rendez-vous En attente ne peut pas être archivé">Archiver</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	// ------------------------------------------------------------------
	// Onglet Disponibilités (horaires hebdomadaires + absences)
	// ------------------------------------------------------------------

	private static function render_disponibilites_tab() {
		global $wpdb;
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$dispo_table    = $wpdb->prefix . GRC_TABLE_PREFIX . 'disponibilites';
		$absences_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'absences';

		$services = $wpdb->get_results( "SELECT id, nom FROM {$services_table} WHERE actif = 1 ORDER BY nom" );
		$filtre_service = absint( $_GET['service_id'] ?? ( $services[0]->id ?? 0 ) );

		$dispos_existantes = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$dispo_table} WHERE service_id = %d", $filtre_service
		) );
		$dispos_par_jour = [];
		foreach ( $dispos_existantes as $d ) {
			$dispos_par_jour[ (int) $d->jour_semaine ] = $d;
		}

		$absences = $wpdb->get_results( $wpdb->prepare(
			"SELECT a.*, s.nom AS service_nom FROM {$absences_table} a
			 LEFT JOIN {$services_table} s ON s.id = a.service_id
			 WHERE a.date_fin >= %s AND ( a.service_id = %d OR a.service_id IS NULL )
			 ORDER BY a.date_debut ASC",
			current_time( 'Y-m-d' ),
			$filtre_service
		) );

		// Déclenche la génération immédiatement (au lieu d'attendre la première
		// visite citoyenne) et affiche un compteur de confirmation directe.
		GRC_Creneaux_Generator::generate_range( $filtre_service, current_time( 'Y-m-d' ), gmdate( 'Y-m-d', strtotime( '+90 days' ) ) );
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$nb_creneaux = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$creneaux_table} WHERE service_id = %d AND debut > %s",
			$filtre_service,
			current_time( 'mysql' )
		) );

		?>
		<form method="get" style="margin:16px 0;">
			<input type="hidden" name="page" value="grc-rdv">
			<input type="hidden" name="tab" value="disponibilites">
			<label style="font-weight:600;">Service : </label>
			<select name="service_id" onchange="this.form.submit()">
				<?php foreach ( $services as $s ) : ?>
					<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $filtre_service, (int) $s->id ); ?>><?php echo esc_html( $s->nom ); ?></option>
				<?php endforeach; ?>
			</select>
		</form>

		<div class="notice notice-<?php echo $nb_creneaux > 0 ? 'success' : 'warning'; ?> inline" style="margin:0 0 16px;padding:10px 14px;">
			<?php if ( $nb_creneaux > 0 ) : ?>
				<p><strong><?php echo $nb_creneaux; ?></strong> créneaux disponibles générés pour ce service sur les 90 prochains jours.</p>
			<?php else : ?>
				<p><strong>Aucun créneau généré</strong> pour ce service actuellement. Vérifiez qu'au moins un jour est coché "Actif" ci-dessous, puis enregistrez.</p>
			<?php endif; ?>
		</div>

		<div style="display:flex;gap:24px;align-items:flex-start;">
			<div style="flex:2;">
				<h2>Horaires d'ouverture</h2>
				<p class="description">Définissez, pour chaque jour de la semaine, la plage horaire et la pause méridienne (facultative). Les créneaux sont générés automatiquement en arrière-plan selon ces horaires.</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="grc_save_disponibilites">
					<input type="hidden" name="service_id" value="<?php echo esc_attr( $filtre_service ); ?>">
					<?php wp_nonce_field( 'grc_save_disponibilites_' . $filtre_service ); ?>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th style="width:30px;">Actif</th>
								<th>Jour</th>
								<th>Début</th>
								<th>Fin</th>
								<th>Pause de</th>
								<th>Pause à</th>
								<th>Durée créneau</th>
								<th>Capacité</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( self::JOURS as $num => $label ) : ?>
								<?php $d = $dispos_par_jour[ $num ] ?? null; ?>
								<tr>
									<td><input type="checkbox" name="jours[<?php echo $num; ?>][actif]" value="1" <?php checked( $d ? (int) $d->actif : 0, 1 ); ?>></td>
									<td><strong><?php echo esc_html( $label ); ?></strong></td>
									<td><input type="time" name="jours[<?php echo $num; ?>][debut]" value="<?php echo esc_attr( $d->heure_debut ?? '09:00' ); ?>"></td>
									<td><input type="time" name="jours[<?php echo $num; ?>][fin]" value="<?php echo esc_attr( $d->heure_fin ?? '17:00' ); ?>"></td>
									<td><input type="time" name="jours[<?php echo $num; ?>][pause_debut]" value="<?php echo esc_attr( $d->pause_debut ?? '12:00' ); ?>"></td>
									<td><input type="time" name="jours[<?php echo $num; ?>][pause_fin]" value="<?php echo esc_attr( $d->pause_fin ?? '14:00' ); ?>"></td>
									<td>
										<select name="jours[<?php echo $num; ?>][duree]">
											<?php foreach ( [ 15, 30, 45, 60 ] as $m ) : ?>
												<option value="<?php echo $m; ?>" <?php selected( $d ? (int) $d->duree_minutes : 30, $m ); ?>><?php echo $m; ?> min</option>
											<?php endforeach; ?>
										</select>
									</td>
									<td><input type="number" name="jours[<?php echo $num; ?>][capacite]" value="<?php echo esc_attr( $d->capacite ?? 1 ); ?>" min="1" style="width:60px;"></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p class="description">Laissez la pause vide (ou identique début/fin) si le service ne ferme pas à midi.</p>
					<button type="submit" class="button button-primary" style="margin-top:12px;">Enregistrer les horaires</button>
				</form>
			</div>

			<div style="flex:1;">
				<h2>Absences</h2>
				<p class="description">Bloque la prise de rendez-vous sur une période (congé, fermeture exceptionnelle...). Les créneaux non réservés de cette période sont supprimés ; les rendez-vous déjà confirmés doivent être annulés manuellement.</p>

				<div class="card" style="padding:16px;margin-bottom:16px;">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="grc_save_absence">
						<?php wp_nonce_field( 'grc_save_absence' ); ?>

						<label style="display:block;font-weight:600;margin-bottom:4px;">Portée</label>
						<select name="service_id" style="width:100%;margin-bottom:10px;">
							<option value="">Tous les services (mairie fermée)</option>
							<?php foreach ( $services as $s ) : ?>
								<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( $filtre_service, (int) $s->id ); ?>><?php echo esc_html( $s->nom ); ?></option>
							<?php endforeach; ?>
						</select>

						<label style="display:block;font-weight:600;margin-bottom:4px;">Période</label>
						<div style="display:flex;gap:8px;margin-bottom:10px;">
							<input type="date" name="date_debut" required style="flex:1;">
							<input type="date" name="date_fin" required style="flex:1;">
						</div>

						<label style="display:block;font-weight:600;margin-bottom:4px;">Motif</label>
						<input type="text" name="motif" placeholder="Ex : congés, formation..." style="width:100%;margin-bottom:10px;">

						<button type="submit" class="button button-primary">Ajouter l'absence</button>
					</form>
				</div>

				<table class="wp-list-table widefat fixed striped">
					<thead><tr><th>Période</th><th>Portée</th><th>Motif</th><th></th></tr></thead>
					<tbody>
						<?php if ( empty( $absences ) ) : ?>
							<tr><td colspan="4">Aucune absence à venir.</td></tr>
						<?php endif; ?>
						<?php foreach ( $absences as $a ) : ?>
							<tr>
								<td><?php echo esc_html( mysql2date( 'd/m/Y', $a->date_debut ) ); ?> → <?php echo esc_html( mysql2date( 'd/m/Y', $a->date_fin ) ); ?></td>
								<td><?php echo esc_html( $a->service_nom ?: 'Toute la mairie' ); ?></td>
								<td><?php echo esc_html( $a->motif ?: '—' ); ?></td>
								<td><a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_delete_absence&id=' . $a->id ), 'grc_delete_absence_' . $a->id ) ); ?>" onclick="return confirm('Supprimer cette absence ?');">Suppr.</a></td>
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

	public static function handle_save_disponibilites() {
		$service_id = absint( $_POST['service_id'] ?? 0 );
		check_admin_referer( 'grc_save_disponibilites_' . $service_id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'disponibilites';
		$jours = $_POST['jours'] ?? [];

		foreach ( self::JOURS as $num => $label ) {
			$j = $jours[ $num ] ?? [];

			$actif       = ! empty( $j['actif'] ) ? 1 : 0;
			$heure_debut = sanitize_text_field( wp_unslash( $j['debut'] ?? '09:00' ) );
			$heure_fin   = sanitize_text_field( wp_unslash( $j['fin'] ?? '17:00' ) );
			$pause_debut = sanitize_text_field( wp_unslash( $j['pause_debut'] ?? '' ) );
			$pause_fin   = sanitize_text_field( wp_unslash( $j['pause_fin'] ?? '' ) );
			$duree       = max( 5, absint( $j['duree'] ?? 30 ) );
			$capacite    = max( 1, absint( $j['capacite'] ?? 1 ) );

			// Pas de pause si les deux heures sont identiques ou l'une des deux vide.
			if ( ! $pause_debut || ! $pause_fin || $pause_debut === $pause_fin ) {
				$pause_debut = null;
				$pause_fin   = null;
			}

			$existing = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE service_id = %d AND jour_semaine = %d",
				$service_id,
				$num
			) );

			$data = [
				'heure_debut'   => $heure_debut,
				'heure_fin'     => $heure_fin,
				'pause_debut'   => $pause_debut,
				'pause_fin'     => $pause_fin,
				'duree_minutes' => $duree,
				'capacite'      => $capacite,
				'actif'         => $actif,
			];

			if ( $existing ) {
				$wpdb->update( $table, $data, [ 'id' => $existing ] );
			} else {
				$wpdb->insert( $table, array_merge( $data, [ 'service_id' => $service_id, 'jour_semaine' => $num ] ) );
			}
		}

		// Le modèle a changé : on nettoie les créneaux futurs non réservés pour
		// que la prochaine consultation régénère selon le nouvel horaire.
		GRC_Creneaux_Generator::purge_unreserved_future( $service_id );
		GRC_Creneaux_Generator::generate_range( $service_id, current_time( 'Y-m-d' ), gmdate( 'Y-m-d', strtotime( '+90 days' ) ) );

		GRC_Audit_Log::log( 'disponibilites_saved', 'service', $service_id );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&tab=disponibilites&service_id=' . $service_id . '&grc_notice=disponibilites_saved' ) );
		exit;
	}

	public static function handle_save_absence() {
		check_admin_referer( 'grc_save_absence' );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$service_id = ! empty( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : null;
		$date_debut = sanitize_text_field( wp_unslash( $_POST['date_debut'] ?? '' ) );
		$date_fin   = sanitize_text_field( wp_unslash( $_POST['date_fin'] ?? '' ) );
		$motif      = sanitize_text_field( wp_unslash( $_POST['motif'] ?? '' ) );

		if ( ! $date_debut || ! $date_fin || $date_fin < $date_debut ) {
			wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&tab=disponibilites&grc_notice=error' ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'absences';
		$wpdb->insert( $table, [
			'service_id' => $service_id,
			'date_debut' => $date_debut,
			'date_fin'   => $date_fin,
			'motif'      => $motif,
			'created_at' => current_time( 'mysql' ),
		] );

		$rdv_affectes = GRC_Creneaux_Generator::purge_unreserved_range( $service_id, $date_debut, $date_fin );

		GRC_Audit_Log::log( 'absence_saved', 'service', $service_id ?: 0, [ 'date_debut' => $date_debut, 'date_fin' => $date_fin ] );

		$redirect = admin_url( 'admin.php?page=grc-rdv&tab=disponibilites&grc_notice=absence_saved' );
		if ( $rdv_affectes > 0 ) {
			$redirect .= '&rdv_affectes=' . $rdv_affectes;
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_delete_absence() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_delete_absence_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . GRC_TABLE_PREFIX . 'absences', [ 'id' => $id ] );
		GRC_Audit_Log::log( 'absence_deleted', 'absence', $id );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&tab=disponibilites&grc_notice=absence_deleted' ) );
		exit;
	}

	public static function handle_validate_rdv() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_validate_rdv_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$rdv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rdv_table} WHERE id = %d", $id ) );
		if ( $rdv && 'en_attente' === $rdv->statut ) {
			$wpdb->update( $rdv_table, [ 'statut' => 'confirme' ], [ 'id' => $id ] );
			GRC_Audit_Log::log( 'rdv_validated', 'rdv', $id, [
				'numero_rdv' => $rdv->numero_rdv,
				'citoyen_id' => $rdv->citoyen_id,
			] );

			$creneau = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$creneaux_table} WHERE id = %d", $rdv->creneau_id ) );
			$email   = self::get_rdv_email( $rdv->citoyen_id );
			if ( $email && $creneau ) {
				GRC_Notifications::send_rdv_validated( $email, $creneau->debut, $id );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&grc_notice=rdv_validated' ) );
		exit;
	}

	public static function handle_refuse_rdv() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_refuse_rdv_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		self::refuse_rdv( $id, false );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&grc_notice=rdv_refused' ) );
		exit;
	}

	/**
	 * Refuse un rendez-vous (manuellement par un agent, ou automatiquement par
	 * le cron après expiration du délai de validation) : libère le créneau et
	 * notifie le citoyen.
	 */
	public static function refuse_rdv( int $id, bool $automatique ) {
		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';

		$rdv = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$rdv_table} WHERE id = %d", $id ) );
		if ( ! $rdv || 'en_attente' !== $rdv->statut ) {
			return;
		}

		$wpdb->update( $rdv_table, [ 'statut' => 'refuse' ], [ 'id' => $id ] );
		$wpdb->query( $wpdb->prepare( "UPDATE {$creneaux_table} SET reserve = GREATEST(0, reserve - 1) WHERE id = %d", $rdv->creneau_id ) );

		GRC_Audit_Log::log( $automatique ? 'rdv_refused_auto' : 'rdv_refused', 'rdv', $id, [
			'numero_rdv' => $rdv->numero_rdv,
			'citoyen_id' => $rdv->citoyen_id,
			'automatique' => $automatique,
		] );

		$creneau = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$creneaux_table} WHERE id = %d", $rdv->creneau_id ) );
		$email   = self::get_rdv_email( $rdv->citoyen_id );
		if ( $email && $creneau ) {
			GRC_Notifications::send_rdv_refused( $email, $creneau->debut, $automatique, $id );
		}
	}

	private static function get_rdv_email( ?int $citoyen_id ): ?string {
		if ( ! $citoyen_id ) {
			return null;
		}
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$encrypted = $wpdb->get_var( $wpdb->prepare( "SELECT email FROM {$table} WHERE id = %d", $citoyen_id ) );
		return $encrypted ? GRC_Encryption::decrypt( $encrypted ) : null;
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
		if ( $rdv && in_array( $rdv->statut, [ 'confirme', 'en_attente' ], true ) ) {
			$wpdb->update( $rdv_table, [ 'statut' => 'annule' ], [ 'id' => $id ] );
			$wpdb->query( $wpdb->prepare( "UPDATE {$creneaux_table} SET reserve = GREATEST(0, reserve - 1) WHERE id = %d", $rdv->creneau_id ) );
			GRC_Audit_Log::log( 'rdv_cancelled_admin', 'rdv', $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv&grc_notice=rdv_cancelled' ) );
		exit;
	}

	public static function handle_archive_rdv() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_archive_rdv_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}
		global $wpdb;
		$table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$statut = $wpdb->get_var( $wpdb->prepare( "SELECT statut FROM {$table} WHERE id = %d", $id ) );

		if ( 'en_attente' === $statut ) {
			wp_safe_redirect( add_query_arg( 'grc_notice', 'archive_error', wp_get_referer() ?: admin_url( 'admin.php?page=grc-rdv' ) ) );
			exit;
		}

		$wpdb->update( $table, [ 'archive' => 1 ], [ 'id' => $id ] );
		GRC_Audit_Log::log( 'rdv_archived', 'rdv', $id );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=grc-rdv' ) );
		exit;
	}

	public static function handle_unarchive_rdv() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_archive_rdv_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}
		global $wpdb;
		$wpdb->update( $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv', [ 'archive' => 0 ], [ 'id' => $id ] );
		GRC_Audit_Log::log( 'rdv_unarchived', 'rdv', $id );
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=grc-rdv' ) );
		exit;
	}
}
