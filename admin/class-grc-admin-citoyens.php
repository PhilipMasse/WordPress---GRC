<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tableau de bord citoyen : liste des citoyens et fiche complète d'un citoyen
 * (coordonnées + historique de toutes ses demandes, démarches et rendez-vous).
 * Utile notamment pour distinguer des homonymes grâce au numéro unique.
 */
class GRC_Admin_Citoyens {

	public static function render() {
		if ( ! current_user_can( 'grc_manage_demandes' ) && ! current_user_can( 'grc_view_all' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		$citoyen_id = isset( $_GET['citoyen_id'] ) ? absint( $_GET['citoyen_id'] ) : 0;
		if ( $citoyen_id ) {
			self::render_fiche( $citoyen_id );
			return;
		}

		self::render_liste();
	}

	// ------------------------------------------------------------------
	// Liste des citoyens
	// ------------------------------------------------------------------

	private static function render_liste() {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$recherche = sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) );
		$paged     = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page  = 25;
		$offset    = ( $paged - 1 ) * $per_page;

		$where  = '1=1';
		$params = [];

		if ( '' !== $recherche ) {
			$numero_id = GRC_Citoyen_Helper::parse_numero( $recherche );
			if ( $numero_id && preg_match( '/^(CIT-)?\d+$/i', trim( $recherche ) ) ) {
				// Recherche par numéro citoyen (ex: CIT-000042 ou 42).
				$where    = 'id = %d';
				$params[] = $numero_id;
			} elseif ( is_email( $recherche ) ) {
				// Recherche par email exact (via le hash, l'email étant chiffré).
				$where    = 'email_hash = %s';
				$params[] = GRC_Encryption::search_hash( $recherche );
			} else {
				$where = '1=0'; // Recherche par nom non supportée (données chiffrées) : voir note ci-dessous.
			}
		}

		$total_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

		$list_sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, [ $per_page, $offset ] ) ) );

		?>
		<div class="wrap">
			<h1>Citoyens</h1>

			<form method="get" style="margin:16px 0;display:flex;gap:10px;align-items:center;">
				<input type="hidden" name="page" value="grc-citoyens">
				<input type="text" name="s" value="<?php echo esc_attr( $recherche ); ?>" placeholder="Numéro (CIT-000042) ou email exact..." style="width:280px;">
				<button type="submit" class="button">Rechercher</button>
				<?php if ( $recherche ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-citoyens' ) ); ?>" class="button">Réinitialiser</a>
				<?php endif; ?>
				<span class="description">La recherche par nom n'est pas possible : les noms sont chiffrés en base pour la protection des données personnelles. Utilisez le numéro citoyen ou l'email exact.</span>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th>Numéro</th><th>Nom</th><th>Email</th><th>Type</th><th>Inscrit le</th><th>Action</th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="6">Aucun citoyen trouvé.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $c ) : ?>
						<?php
						$nom_complet = trim(
							( $c->prenom ? GRC_Encryption::decrypt( $c->prenom ) : '' ) . ' ' .
							( $c->nom ? GRC_Encryption::decrypt( $c->nom ) : '' )
						);
						$email = $c->email ? GRC_Encryption::decrypt( $c->email ) : '';
						?>
						<tr>
							<td><code><?php echo esc_html( GRC_Citoyen_Helper::numero( (int) $c->id ) ); ?></code></td>
							<td><?php echo esc_html( $nom_complet ?: '—' ); ?></td>
							<td><?php echo esc_html( $email ?: '—' ); ?></td>
							<td><?php echo $c->is_guest ? 'Invité' : ( $c->password_hash ? 'Inscrit' : 'Invité' ); ?></td>
							<td><?php echo esc_html( mysql2date( 'd/m/Y', $c->created_at ) ); ?></td>
							<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $c->id ) ); ?>">Voir la fiche</a></td>
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

	// ------------------------------------------------------------------
	// Fiche citoyen complète
	// ------------------------------------------------------------------

	private static function render_fiche( int $citoyen_id ) {
		global $wpdb;
		$citoyens_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$demandes_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$types_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$rdv_table       = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$services_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$satisfaction_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'satisfaction';

		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$citoyens_table} WHERE id = %d", $citoyen_id ) );
		if ( ! $citoyen ) {
			echo '<div class="wrap"><p>Citoyen introuvable.</p><p><a href="' . esc_url( admin_url( 'admin.php?page=grc-citoyens' ) ) . '">&larr; Retour à la liste</a></p></div>';
			return;
		}

		GRC_Audit_Log::log( 'citoyen_viewed_admin', 'citoyen', $citoyen_id );

		$nom       = $citoyen->nom ? GRC_Encryption::decrypt( $citoyen->nom ) : '';
		$prenom    = $citoyen->prenom ? GRC_Encryption::decrypt( $citoyen->prenom ) : '';
		$email     = $citoyen->email ? GRC_Encryption::decrypt( $citoyen->email ) : '';
		$telephone = $citoyen->telephone ? GRC_Encryption::decrypt( $citoyen->telephone ) : '';
		$adresse   = $citoyen->adresse ? GRC_Encryption::decrypt( $citoyen->adresse ) : '';

		$demandes = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.*, s.nom AS service_nom, sat.note AS satisfaction_note
			 FROM {$demandes_table} d
			 LEFT JOIN {$services_table} s ON s.id = d.service_id
			 LEFT JOIN {$satisfaction_table} sat ON sat.demande_id = d.id
			 WHERE d.citoyen_id = %d ORDER BY d.created_at DESC",
			$citoyen_id
		) );

		$demarches = $wpdb->get_results( $wpdb->prepare(
			"SELECT dm.*, t.nom AS type_nom FROM {$demarches_table} dm
			 LEFT JOIN {$types_table} t ON t.slug = dm.type_demarche
			 WHERE dm.citoyen_id = %d ORDER BY dm.created_at DESC",
			$citoyen_id
		) );

		$rdv_list = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.*, c.debut, s.nom AS service_nom FROM {$rdv_table} r
			 LEFT JOIN {$creneaux_table} c ON c.id = r.creneau_id
			 LEFT JOIN {$services_table} s ON s.id = r.service_id
			 WHERE r.citoyen_id = %d ORDER BY c.debut DESC",
			$citoyen_id
		) );

		$statut_demande_labels = [
			'nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'assigne' => 'Assigné',
			'resolu' => 'Résolu', 'cloture' => 'Clôturé', 'reouvert' => 'Réouvert',
		];
		$statut_demarche_labels = [
			'en_attente' => 'En attente', 'en_cours' => 'En cours', 'valide' => 'Validé',
			'rejete' => 'Rejeté', 'complement_requis' => 'Complément requis',
		];
		$statut_rdv_labels = [
			'en_attente' => 'En attente', 'confirme' => 'Confirmé', 'refuse' => 'Refusé', 'annule' => 'Annulé',
		];

		?>
		<div class="wrap">
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-citoyens' ) ); ?>">&larr; Retour à la liste des citoyens</a></p>
			<h1>
				<?php echo esc_html( trim( "$prenom $nom" ) ?: 'Citoyen' ); ?>
				<code style="font-size:16px;font-weight:400;color:#888;"><?php echo esc_html( GRC_Citoyen_Helper::numero( $citoyen_id ) ); ?></code>
			</h1>

			<div style="display:flex;gap:24px;align-items:flex-start;margin-top:16px;">
				<div style="flex:1;">
					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Coordonnées</h2>
						<table class="widefat">
							<tr><td style="width:140px;font-weight:600;">Email</td><td><?php echo esc_html( $email ?: '—' ); ?></td></tr>
							<tr><td style="font-weight:600;">Téléphone</td><td><?php echo esc_html( $telephone ?: '—' ); ?></td></tr>
							<tr><td style="font-weight:600;">Adresse</td><td><?php echo esc_html( $adresse ?: '—' ); ?></td></tr>
							<tr><td style="font-weight:600;">Type de compte</td><td><?php echo $citoyen->password_hash ? 'Compte inscrit' : 'Invité (sans compte)'; ?></td></tr>
							<tr><td style="font-weight:600;">Consentement RGPD</td><td><?php echo $citoyen->consentement_rgpd ? '✅ Oui' : '—'; ?></td></tr>
							<tr><td style="font-weight:600;">Inscrit depuis le</td><td><?php echo esc_html( mysql2date( 'd/m/Y', $citoyen->created_at ) ); ?></td></tr>
						</table>
					</div>

					<div class="card" style="padding:16px;">
						<h2>Résumé</h2>
						<p><strong><?php echo count( $demandes ); ?></strong> signalement(s)</p>
						<p><strong><?php echo count( $demarches ); ?></strong> démarche(s)</p>
						<p><strong><?php echo count( $rdv_list ); ?></strong> rendez-vous</p>
					</div>
				</div>

				<div style="flex:2;">
					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Demandes / Signalements</h2>
						<?php if ( empty( $demandes ) ) : ?>
							<p><em>Aucune demande.</em></p>
						<?php else : ?>
							<table class="wp-list-table widefat fixed striped">
								<thead><tr><th>N° suivi</th><th>Titre</th><th>Service</th><th>Statut</th><th>Note</th><th>Créée le</th><th></th></tr></thead>
								<tbody>
									<?php foreach ( $demandes as $d ) : ?>
										<tr>
											<td><code><?php echo esc_html( $d->numero_suivi ); ?></code></td>
											<td><?php echo esc_html( $d->titre ); ?></td>
											<td><?php echo esc_html( $d->service_nom ?: '—' ); ?></td>
											<td><?php echo esc_html( $statut_demande_labels[ $d->statut ] ?? $d->statut ); ?></td>
											<td><?php echo $d->satisfaction_note ? str_repeat( '★', (int) $d->satisfaction_note ) : '—'; ?></td>
											<td><?php echo esc_html( mysql2date( 'd/m/Y', $d->created_at ) ); ?></td>
											<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demandes&demande_id=' . $d->id ) ); ?>">Voir</a></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>

					<div class="card" style="padding:16px;margin-bottom:16px;">
						<h2>Démarches</h2>
						<?php if ( empty( $demarches ) ) : ?>
							<p><em>Aucune démarche.</em></p>
						<?php else : ?>
							<table class="wp-list-table widefat fixed striped">
								<thead><tr><th>Type</th><th>Statut</th><th>Soumise le</th><th></th></tr></thead>
								<tbody>
									<?php foreach ( $demarches as $d ) : ?>
										<tr>
											<td><?php echo esc_html( $d->type_nom ?: $d->type_demarche ); ?></td>
											<td><?php echo esc_html( $statut_demarche_labels[ $d->statut ] ?? $d->statut ); ?></td>
											<td><?php echo esc_html( mysql2date( 'd/m/Y', $d->created_at ) ); ?></td>
											<td><a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-demarches&dossier_id=' . $d->id ) ); ?>">Voir</a></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>

					<div class="card" style="padding:16px;">
						<h2>Rendez-vous</h2>
						<?php if ( empty( $rdv_list ) ) : ?>
							<p><em>Aucun rendez-vous.</em></p>
						<?php else : ?>
							<table class="wp-list-table widefat fixed striped">
								<thead><tr><th>Service</th><th>Date</th><th>Statut</th></tr></thead>
								<tbody>
									<?php foreach ( $rdv_list as $r ) : ?>
										<tr>
											<td><?php echo esc_html( $r->service_nom ?: '—' ); ?></td>
											<td><?php echo $r->debut ? esc_html( mysql2date( 'd/m/Y H:i', $r->debut ) ) : '—'; ?></td>
											<td><?php echo esc_html( $statut_rdv_labels[ $r->statut ] ?? $r->statut ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<?php
	}
}
