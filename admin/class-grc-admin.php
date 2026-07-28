<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GRC_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
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
		echo '<div class="wrap"><h1>Demandes</h1><p><em>Liste détaillée, filtres par service/statut, assignation — à implémenter (V1).</em></p></div>';
	}

	public static function render_rdv() {
		echo '<div class="wrap"><h1>Rendez-vous</h1><p><em>Gestion des créneaux et rendez-vous — à implémenter (V2).</em></p></div>';
	}

	public static function render_services() {
		echo '<div class="wrap"><h1>Services & Catégories</h1><p><em>CRUD des services et catégories/sous-catégories — à implémenter.</em></p></div>';
	}

	public static function render_stats() {
		echo '<div class="wrap"><h1>Statistiques</h1><p><em>Graphiques, répartition par catégorie/quartier, export CSV — à implémenter.</em></p></div>';
	}

	public static function render_settings() {
		?>
		<div class="wrap">
			<h1>Réglages GRC</h1>
			<?php if ( ! defined( 'GRC_ENCRYPTION_KEY' ) || ! defined( 'GRC_JWT_SECRET' ) ) : ?>
				<div class="notice notice-error"><p>Les clés de chiffrement/JWT ne sont pas configurées dans <code>wp-config.php</code>.</p></div>
			<?php else : ?>
				<div class="notice notice-success"><p>Clés de sécurité correctement configurées.</p></div>
			<?php endif; ?>
			<p><em>Durée de rétention RGPD, modèles d'emails, catégories par défaut — à implémenter.</em></p>
		</div>
		<?php
	}
}
