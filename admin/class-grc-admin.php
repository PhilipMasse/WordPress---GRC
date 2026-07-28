<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GRC_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_grc_download_piece', [ __CLASS__, 'handle_download_piece' ] );
		GRC_Admin_Demandes::init();
		GRC_Admin_Services::init();
		GRC_Admin_Demarches::init();
		GRC_Admin_RDV::init();
	}

	/**
	 * Téléchargement d'une pièce jointe depuis l'administration, via admin-post.php
	 * plutôt que l'API REST : un lien <a href> classique ne peut pas transporter
	 * l'en-tête Authorization (JWT) ni le nonce REST — l'authentification par
	 * cookie WordPress classique (déjà active dans l'admin) fonctionne ici nativement.
	 */
	public static function handle_download_piece() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_download_piece_' . $id );

		if ( ! current_user_can( 'grc_manage_demandes' ) && ! current_user_can( 'grc_view_all' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'pieces_jointes';
		$piece = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		if ( ! $piece ) {
			wp_die( 'Fichier introuvable.' );
		}

		$upload_dir = wp_upload_dir();
		$full_path  = trailingslashit( $upload_dir['basedir'] ) . $piece->chemin_fichier;

		if ( ! file_exists( $full_path ) ) {
			wp_die( 'Le fichier n\'existe plus sur le serveur.' );
		}

		GRC_Audit_Log::log( 'piece_jointe_downloaded_admin', $piece->demande_id ? 'demande' : 'demarche', (int) ( $piece->demande_id ?: $piece->demarche_id ), [ 'attachment_id' => $id ] );

		header( 'Content-Type: ' . $piece->mime_type );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $piece->nom_original ) . '"' );
		header( 'Content-Length: ' . filesize( $full_path ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $full_path );
		exit;
	}

	/**
	 * Génère l'URL de téléchargement admin (avec nonce) pour une pièce jointe donnée.
	 */
	public static function get_download_url( int $piece_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=grc_download_piece&id=' . $piece_id ),
			'grc_download_piece_' . $piece_id
		);
	}

	public static function register_menu() {
		add_menu_page(
			'GRC Citoyenne',
			'GRC Citoyenne',
			'grc_manage_demandes',
			'grc-dashboard',
			[ __CLASS__, 'render_dashboard' ],
			'dashicons-groups',
			26
		);

		add_submenu_page( 'grc-dashboard', 'Tableau de bord', 'Tableau de bord', 'grc_manage_demandes', 'grc-dashboard', [ __CLASS__, 'render_dashboard' ] );
		add_submenu_page( 'grc-dashboard', 'Demandes', 'Demandes', 'grc_manage_demandes', 'grc-demandes', [ __CLASS__, 'render_demandes' ] );
		add_submenu_page( 'grc-dashboard', 'Rendez-vous', 'Rendez-vous', 'grc_manage_demandes', 'grc-rdv', [ __CLASS__, 'render_rdv' ] );
		add_submenu_page( 'grc-dashboard', 'Services & Catégories', 'Services & Catégories', 'grc_manage_settings', 'grc-services', [ __CLASS__, 'render_services' ] );
		add_submenu_page( 'grc-dashboard', 'Types de démarches', 'Types de démarches', 'grc_manage_settings', 'grc-demarche-types', [ __CLASS__, 'render_demarche_types' ] );
		add_submenu_page( 'grc-dashboard', 'Démarches', 'Démarches', 'grc_manage_demandes', 'grc-demarches', [ __CLASS__, 'render_demarches' ] );
		add_submenu_page( 'grc-dashboard', 'Statistiques', 'Statistiques', 'grc_view_stats', 'grc-stats', [ __CLASS__, 'render_stats' ] );
		add_submenu_page( 'grc-dashboard', 'Réglages', 'Réglages', 'grc_manage_settings', 'grc-settings', [ __CLASS__, 'render_settings' ] );
	}

	public static function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'grc-' ) === false ) {
			return;
		}
		wp_enqueue_style( 'grc-admin', GRC_PLUGIN_URL . 'assets/admin.css', [], GRC_VERSION );
		wp_enqueue_script( 'grc-admin', GRC_PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], GRC_VERSION, true );
	}

	public static function render_dashboard() {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$stats = $wpdb->get_row( "SELECT
			COUNT(*) as total,
			SUM(CASE WHEN statut = 'nouveau' THEN 1 ELSE 0 END) as nouveau,
			SUM(CASE WHEN statut = 'en_cours' THEN 1 ELSE 0 END) as en_cours,
			SUM(CASE WHEN statut = 'resolu' THEN 1 ELSE 0 END) as resolu,
			SUM(CASE WHEN date_limite_sla < NOW() AND statut NOT IN ('resolu','cloture') THEN 1 ELSE 0 END) as en_retard
			FROM {$table}" );
		?>
		<div class="wrap">
			<h1>Tableau de bord GRC</h1>
			<div class="grc-stats-cards" style="display:flex;gap:16px;margin-top:20px;">
				<div class="card" style="padding:16px 24px;"><strong><?php echo esc_html( $stats->total ?? 0 ); ?></strong><br>Total demandes</div>
				<div class="card" style="padding:16px 24px;"><strong><?php echo esc_html( $stats->nouveau ?? 0 ); ?></strong><br>Nouvelles</div>
				<div class="card" style="padding:16px 24px;"><strong><?php echo esc_html( $stats->en_cours ?? 0 ); ?></strong><br>En cours</div>
				<div class="card" style="padding:16px 24px;"><strong><?php echo esc_html( $stats->resolu ?? 0 ); ?></strong><br>Résolues</div>
				<div class="card" style="padding:16px 24px;border-left:4px solid #DEA128;"><strong><?php echo esc_html( $stats->en_retard ?? 0 ); ?></strong><br>En retard SLA</div>
			</div>
			<p style="margin-top:20px;"><em>Module en développement — liste détaillée des demandes, filtres et actions à venir dans la section « Demandes ».</em></p>
		</div>
		<?php
	}

	public static function render_demandes() {
		GRC_Admin_Demandes::render();
	}

	public static function render_rdv() {
		GRC_Admin_RDV::render();
	}

	public static function render_services() {
		GRC_Admin_Services::render();
	}

	public static function render_demarches() {
		GRC_Admin_Demarches::render();
	}

	public static function render_demarche_types() {
		GRC_Admin_Demarches::render_types();
	}

	public static function render_stats() {
		global $wpdb;
		$satisfaction_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'satisfaction';
		$row = $wpdb->get_row( "SELECT COUNT(*) as total, AVG(note) as moyenne FROM {$satisfaction_table}" );
		$repartition = $wpdb->get_results( "SELECT note, COUNT(*) as total FROM {$satisfaction_table} GROUP BY note ORDER BY note DESC" );
		?>
		<div class="wrap">
			<h1>Statistiques</h1>

			<h2>Satisfaction citoyenne</h2>
			<?php if ( empty( $row->total ) ) : ?>
				<p><em>Aucune évaluation pour le moment.</em></p>
			<?php else : ?>
				<p><strong style="font-size:28px;color:#DEA128;"><?php echo esc_html( round( (float) $row->moyenne, 1 ) ); ?> / 5</strong> — basé sur <?php echo (int) $row->total; ?> évaluation(s)</p>
				<table class="wp-list-table widefat fixed striped" style="max-width:300px;">
					<?php foreach ( $repartition as $r ) : ?>
						<tr><td><?php echo str_repeat( '★', (int) $r->note ); ?></td><td><?php echo (int) $r->total; ?></td></tr>
					<?php endforeach; ?>
				</table>
			<?php endif; ?>

			<p style="margin-top:24px;"><em>Graphiques par catégorie/quartier, cartographie thermique, export CSV — à implémenter.</em></p>
		</div>
		<?php
	}

	public static function render_settings() {
		$status = get_option( 'grc_init_status' );
		?>
		<div class="wrap">
			<h1>Réglages GRC</h1>
			<?php if ( 'ok' !== $status ) : ?>
				<?php if ( 'missing_keys_but_defined_later' === $status ) : ?>
					<div class="notice notice-error"><p>Les clés sont définies dans <code>wp-config.php</code> mais après la ligne <code>require_once wp-settings.php</code> — déplacez-les avant. Voir le message en haut de l'administration pour le détail.</p></div>
				<?php else : ?>
					<div class="notice notice-error"><p>Les clés de chiffrement/JWT ne sont pas configurées dans <code>wp-config.php</code>.</p></div>
				<?php endif; ?>
			<?php else : ?>
				<div class="notice notice-success"><p>Clés de sécurité correctement configurées et chargées.</p></div>
			<?php endif; ?>
			<p><em>Durée de rétention RGPD, modèles d'emails, catégories par défaut — à implémenter.</em></p>
		</div>
		<?php
	}
}
