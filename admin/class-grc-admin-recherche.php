<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recherche globale : un seul champ pour retrouver un signalement, une
 * démarche, un rendez-vous ou un citoyen, sans naviguer entre 4 écrans
 * séparés. Redirige directement si la correspondance est exacte et unique.
 */
class GRC_Admin_Recherche {

	public static function render() {
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		$q = trim( sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ) );

		?>
		<div class="wrap">
			<h1>Recherche</h1>
			<form method="get" style="margin:16px 0;">
				<input type="hidden" name="page" value="grc-recherche">
				<input type="text" name="q" value="<?php echo esc_attr( $q ); ?>" style="width:420px;" placeholder="Numéro (GRC-/DEM-/RDV-/CIT-), email exact, ou mots du titre/type..." autofocus>
				<button type="submit" class="button button-primary">Rechercher</button>
			</form>

			<?php if ( '' === $q ) : ?>
				<p class="description">Recherchez par numéro de suivi (signalement, démarche, rendez-vous), numéro citoyen, email exact, ou mots-clés du titre d'un signalement / type de démarche.</p>
				<p class="description">La recherche par nom n'est pas disponible : les noms sont chiffrés en base pour la protection des données personnelles (voir la fiche citoyen pour une recherche par numéro ou email).</p>
			<?php else : ?>
				<?php self::render_resultats( $q ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function render_resultats( string $q ) {
		global $wpdb;

		// --- 1. Correspondance directe par numéro (préfixes reconnus) -------
		if ( preg_match( '/^(GRC|DEM|RDV)-\d{4}-[A-Z0-9]{6}$/i', $q ) ) {
			self::rediriger_si_trouve_par_numero( strtoupper( $q ) );
		}
		if ( preg_match( '/^(CIT-)?\d+$/i', $q ) && GRC_Citoyen_Helper::parse_numero( $q ) ) {
			$citoyen_id = GRC_Citoyen_Helper::parse_numero( $q );
			$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$citoyens_table} WHERE id = %d", $citoyen_id ) ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $citoyen_id ) );
				exit;
			}
		}

		// --- 2. Email exact (via hash, l'email étant chiffré) ---------------
		if ( is_email( $q ) ) {
			$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
			$citoyen_id = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$citoyens_table} WHERE email_hash = %s", GRC_Encryption::search_hash( $q )
			) );
			if ( $citoyen_id ) {
				wp_safe_redirect( admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $citoyen_id ) );
				exit;
			}
			echo '<p>Aucun citoyen trouvé avec cet email.</p>';
			return;
		}

		// --- 3. Recherche texte libre sur les champs non chiffrés -----------
		self::render_recherche_texte( $q );
	}

	private static function rediriger_si_trouve_par_numero( string $numero ) {
		global $wpdb;

		if ( 0 === strpos( $numero, 'GRC-' ) ) {
			$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE numero_suivi = %s", $numero ) );
			if ( $id ) {
				wp_safe_redirect( admin_url( 'admin.php?page=grc-demandes&demande_id=' . $id ) );
				exit;
			}
		} elseif ( 0 === strpos( $numero, 'DEM-' ) ) {
			$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE numero_dossier = %s", $numero ) );
			if ( $id ) {
				wp_safe_redirect( admin_url( 'admin.php?page=grc-demarches&dossier_id=' . $id ) );
				exit;
			}
		} elseif ( 0 === strpos( $numero, 'RDV-' ) ) {
			$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE numero_rdv = %s", $numero ) );
			if ( $id ) {
				// Pas de fiche RDV dédiée : direction la liste, filtrée visuellement par le numéro affiché.
				wp_safe_redirect( admin_url( 'admin.php?page=grc-rdv' ) );
				exit;
			}
		}
		// Numéro bien formé mais introuvable : on laisse le texte "aucun résultat" du bloc recherche-texte s'afficher.
	}

	private static function render_recherche_texte( string $q ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $q ) . '%';

		$demandes_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$types_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';
		$rdv_table       = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$services_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';

		$demandes = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, numero_suivi, titre, statut, created_at FROM {$demandes_table}
			 WHERE titre LIKE %s OR numero_suivi LIKE %s ORDER BY created_at DESC LIMIT 20",
			$like, $like
		) );

		$demarches = $wpdb->get_results( $wpdb->prepare(
			"SELECT d.id, d.numero_dossier, d.statut, d.created_at, t.nom AS type_nom FROM {$demarches_table} d
			 LEFT JOIN {$types_table} t ON t.slug = d.type_demarche
			 WHERE d.numero_dossier LIKE %s OR t.nom LIKE %s ORDER BY d.created_at DESC LIMIT 20",
			$like, $like
		) );

		$rdv = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.id, r.numero_rdv, r.statut, r.motif, c.debut, s.nom AS service_nom FROM {$rdv_table} r
			 LEFT JOIN {$creneaux_table} c ON c.id = r.creneau_id
			 LEFT JOIN {$services_table} s ON s.id = r.service_id
			 WHERE r.numero_rdv LIKE %s OR r.motif LIKE %s OR s.nom LIKE %s ORDER BY r.created_at DESC LIMIT 20",
			$like, $like, $like
		) );

		$total = count( $demandes ) + count( $demarches ) + count( $rdv );

		if ( 0 === $total ) {
			echo '<p>Aucun résultat pour "' . esc_html( $q ) . '". Essayez un numéro exact, un email exact, ou vérifiez l\'orthographe.</p>';
			return;
		}

		if ( ! empty( $demandes ) ) {
			echo '<h2>Signalements (' . count( $demandes ) . ')</h2>';
			echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">';
			echo '<thead><tr><th>N° suivi</th><th>Titre</th><th>Statut</th><th>Date</th><th></th></tr></thead><tbody>';
			foreach ( $demandes as $d ) {
				printf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">Voir</a></td></tr>',
					esc_html( $d->numero_suivi ),
					esc_html( $d->titre ),
					esc_html( $d->statut ),
					esc_html( mysql2date( 'd/m/Y', $d->created_at ) ),
					esc_url( admin_url( 'admin.php?page=grc-demandes&demande_id=' . $d->id ) )
				);
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $demarches ) ) {
			echo '<h2>Démarches (' . count( $demarches ) . ')</h2>';
			echo '<table class="wp-list-table widefat fixed striped" style="margin-bottom:24px;">';
			echo '<thead><tr><th>N° dossier</th><th>Type</th><th>Statut</th><th>Date</th><th></th></tr></thead><tbody>';
			foreach ( $demarches as $d ) {
				printf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%s">Voir</a></td></tr>',
					esc_html( $d->numero_dossier ),
					esc_html( $d->type_nom ?: '—' ),
					esc_html( $d->statut ),
					esc_html( mysql2date( 'd/m/Y', $d->created_at ) ),
					esc_url( admin_url( 'admin.php?page=grc-demarches&dossier_id=' . $d->id ) )
				);
			}
			echo '</tbody></table>';
		}

		if ( ! empty( $rdv ) ) {
			echo '<h2>Rendez-vous (' . count( $rdv ) . ')</h2>';
			echo '<table class="wp-list-table widefat fixed striped">';
			echo '<thead><tr><th>N° RDV</th><th>Service</th><th>Date</th><th>Motif</th><th>Statut</th></tr></thead><tbody>';
			foreach ( $rdv as $r ) {
				printf(
					'<tr><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( $r->numero_rdv ),
					esc_html( $r->service_nom ?: '—' ),
					$r->debut ? esc_html( mysql2date( 'd/m/Y H:i', $r->debut ) ) : '—',
					esc_html( $r->motif ?: '—' ),
					esc_html( $r->statut )
				);
			}
			echo '</tbody></table>';
			echo '<p class="description">Les rendez-vous n\'ont pas de fiche dédiée : consultez-les depuis <a href="' . esc_url( admin_url( 'admin.php?page=grc-rdv' ) ) . '">GRC Citoyenne → Rendez-vous</a>.</p>';
		}
	}
}
