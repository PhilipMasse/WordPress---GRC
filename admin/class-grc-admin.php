<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GRC_Admin {

	public static function init() {
		add_action( 'admin_menu', [ __CLASS__, 'register_menu' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'admin_post_grc_download_piece', [ __CLASS__, 'handle_download_piece' ] );
		add_action( 'admin_post_grc_save_settings', [ __CLASS__, 'handle_save_settings' ] );
		GRC_Admin_Demandes::init();
		GRC_Admin_Services::init();
		GRC_Admin_Demarches::init();
		GRC_Admin_RDV::init();
		GRC_Admin_Citoyens::init();
		GRC_Admin_Stats::init();
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
		add_submenu_page( 'grc-dashboard', 'Citoyens', 'Citoyens', 'grc_manage_demandes', 'grc-citoyens', [ __CLASS__, 'render_citoyens' ] );
		add_submenu_page( 'grc-dashboard', 'Rendez-vous', 'Rendez-vous', 'grc_manage_demandes', 'grc-rdv', [ __CLASS__, 'render_rdv' ] );
		add_submenu_page( 'grc-dashboard', 'Services & Catégories', 'Services & Catégories', 'grc_manage_settings', 'grc-services', [ __CLASS__, 'render_services' ] );
		add_submenu_page( 'grc-dashboard', 'Types de démarches', 'Types de démarches', 'grc_manage_settings', 'grc-demarche-types', [ __CLASS__, 'render_demarche_types' ] );
		add_submenu_page( 'grc-dashboard', 'Démarches', 'Démarches', 'grc_manage_demandes', 'grc-demarches', [ __CLASS__, 'render_demarches' ] );
		add_submenu_page( 'grc-dashboard', 'Statistiques', 'Statistiques', 'grc_view_stats', 'grc-stats', [ __CLASS__, 'render_stats' ] );
		add_submenu_page( 'grc-dashboard', 'Réglages', 'Réglages', 'grc_manage_settings', 'grc-settings', [ __CLASS__, 'render_settings' ] );
		add_submenu_page( 'grc-dashboard', 'Journal d\'audit', 'Journal d\'audit', 'grc_view_all', 'grc-audit', [ __CLASS__, 'render_audit' ] );
	}

	public static function enqueue_assets( $hook ) {
		if ( strpos( $hook, 'grc-' ) === false ) {
			return;
		}
		wp_enqueue_style( 'grc-admin', GRC_PLUGIN_URL . 'assets/admin.css', [], GRC_VERSION );
		wp_enqueue_script( 'grc-admin', GRC_PLUGIN_URL . 'assets/admin.js', [ 'jquery' ], GRC_VERSION, true );
		wp_localize_script( 'grc-admin', 'grcAdminConfig', [
			'sessionTimeoutMinutes' => (int) get_option( 'grc_session_timeout_minutes', 30 ),
			'logoutUrl'             => wp_logout_url( admin_url( 'admin.php?page=grc-dashboard&grc_notice=session_expired' ) ),
		] );
		GRC_Admin_Stats::enqueue_assets( $hook );
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

	public static function render_citoyens() {
		GRC_Admin_Citoyens::render();
	}

	public static function render_audit() {
		GRC_Admin_Audit::render();
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
		GRC_Admin_Stats::render();
	}

	public static function render_settings() {
		$status = get_option( 'grc_init_status' );

		if ( isset( $_GET['grc_notice'] ) && 'settings_saved' === $_GET['grc_notice'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés.</p></div>';
		}

		$delai_validation  = (int) get_option( 'grc_rdv_delai_validation_heures', 48 );
		$audit_retention   = (int) get_option( 'grc_audit_retention_mois', 12 );
		$session_timeout   = (int) get_option( 'grc_session_timeout_minutes', 30 );
		$captcha_provider  = get_option( 'grc_captcha_provider', 'interne' );
		$turnstile_site    = get_option( 'grc_turnstile_site_key', '' );
		$turnstile_secret  = get_option( 'grc_turnstile_secret_key', '' );
		$recaptcha_site    = get_option( 'grc_recaptcha_site_key', '' );
		$recaptcha_secret  = get_option( 'grc_recaptcha_secret_key', '' );
		$hcaptcha_site     = get_option( 'grc_hcaptcha_site_key', '' );
		$hcaptcha_secret   = get_option( 'grc_hcaptcha_secret_key', '' );
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

			<h2>Rendez-vous</h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="grc_save_settings">
				<?php wp_nonce_field( 'grc_save_settings' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="grc-delai-validation">Délai de validation avant refus automatique</label></th>
						<td>
							<input type="number" id="grc-delai-validation" name="delai_validation_heures" value="<?php echo esc_attr( $delai_validation ); ?>" min="1" style="width:80px;"> heures
							<p class="description">Passé ce délai sans validation manuelle par un agent, une demande de rendez-vous en attente est automatiquement refusée (le citoyen est notifié par email).</p>
						</td>
					</tr>
				</table>

				<h2>Sécurité des sessions (RGPD)</h2>
				<table class="form-table">
					<tr>
						<th><label for="grc-session-timeout">Déconnexion automatique après inactivité</label></th>
						<td>
							<input type="number" id="grc-session-timeout" name="session_timeout_minutes" value="<?php echo esc_attr( $session_timeout ); ?>" min="5" max="60" style="width:80px;"> minutes
							<p class="description">
								La CNIL recommande un verrouillage/déconnexion automatique après une période d'inactivité — 10 minutes maximum pour les postes agents traitant des données sensibles, jusqu'à 30 minutes pour des applications standards (guides pratiques RGPD CNIL). S'applique à l'espace citoyen (<code>[grc_mes_demandes]</code> et pages associées) et à l'administration GRC.
							</p>
							<p class="description">Une alerte s'affiche 1 minute avant la déconnexion, permettant de prolonger la session en cas d'activité réelle non détectée.</p>
						</td>
					</tr>
				</table>

					<h2>Anti-robot à l'inscription</h2>
				<p class="description">
					Par défaut, un simple captcha mathématique auto-hébergé protège l'inscription citoyenne (aucune donnée transmise à un tiers, mais protection limitée face à un robot ciblé, puisque la réponse transite en clair). Vous pouvez choisir un fournisseur tiers plus robuste ci-dessous — tous fonctionnent de façon quasi invisible pour le citoyen.
				</p>
				<table class="form-table">
					<tr>
						<th><label for="grc-captcha-provider">Fournisseur</label></th>
						<td>
							<select id="grc-captcha-provider" name="captcha_provider">
								<option value="interne" <?php selected( $captcha_provider, 'interne' ); ?>>Interne (captcha mathématique, aucun tiers)</option>
								<option value="turnstile" <?php selected( $captcha_provider, 'turnstile' ); ?>>Cloudflare Turnstile</option>
								<option value="recaptcha" <?php selected( $captcha_provider, 'recaptcha' ); ?>>Google reCAPTCHA v2</option>
								<option value="hcaptcha" <?php selected( $captcha_provider, 'hcaptcha' ); ?>>hCaptcha</option>
							</select>
							<p class="description">Service tiers (Turnstile : Cloudflare / États-Unis, reCAPTCHA : Google / États-Unis, hCaptcha : Intuition Machines) : le navigateur du citoyen communique avec ce service lors de la vérification. À mentionner dans votre politique de confidentialité si l'un de ces fournisseurs est sélectionné.</p>
						</td>
					</tr>
					<tr>
						<th>Cloudflare Turnstile</th>
						<td>
							<label>Clé de site <input type="text" name="turnstile_site_key" value="<?php echo esc_attr( $turnstile_site ); ?>" style="width:380px;"></label><br>
							<label>Clé secrète <input type="text" name="turnstile_secret_key" value="<?php echo esc_attr( $turnstile_secret ); ?>" style="width:380px;"></label>
							<p class="description">Gratuit, à obtenir sur <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">dash.cloudflare.com → Turnstile</a>.</p>
						</td>
					</tr>
					<tr>
						<th>Google reCAPTCHA v2</th>
						<td>
							<label>Clé de site <input type="text" name="recaptcha_site_key" value="<?php echo esc_attr( $recaptcha_site ); ?>" style="width:380px;"></label><br>
							<label>Clé secrète <input type="text" name="recaptcha_secret_key" value="<?php echo esc_attr( $recaptcha_secret ); ?>" style="width:380px;"></label>
							<p class="description">Gratuit, à obtenir sur <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">google.com/recaptcha/admin</a> (choisir "Case à cocher reCAPTCHA v2").</p>
						</td>
					</tr>
					<tr>
						<th>hCaptcha</th>
						<td>
							<label>Clé de site <input type="text" name="hcaptcha_site_key" value="<?php echo esc_attr( $hcaptcha_site ); ?>" style="width:380px;"></label><br>
							<label>Clé secrète <input type="text" name="hcaptcha_secret_key" value="<?php echo esc_attr( $hcaptcha_secret ); ?>" style="width:380px;"></label>
							<p class="description">Gratuit, à obtenir sur <a href="https://dashboard.hcaptcha.com/signup" target="_blank" rel="noopener">dashboard.hcaptcha.com</a>.</p>
						</td>
					</tr>
				</table>

				<h2>Pages du portail citoyen</h2>
				<p class="description">Sélectionnez les pages contenant chaque shortcode : ces liens apparaîtront dans la barre de navigation affichée en haut de toutes les pages pour un citoyen connecté.</p>
				<table class="form-table">
					<tr>
						<th><label for="grc-page-signalement">Signalement (<code>[grc_signalement_form]</code>)</label></th>
						<td><?php wp_dropdown_pages( [ 'id' => 'grc-page-signalement', 'name' => 'page_signalement', 'selected' => (int) get_option( 'grc_page_signalement' ), 'show_option_none' => '— Aucune —' ] ); ?></td>
					</tr>
					<tr>
						<th><label for="grc-page-mes-demandes">Mes demandes (<code>[grc_mes_demandes]</code>)</label></th>
						<td><?php wp_dropdown_pages( [ 'id' => 'grc-page-mes-demandes', 'name' => 'page_mes_demandes', 'selected' => (int) get_option( 'grc_page_mes_demandes' ), 'show_option_none' => '— Aucune —' ] ); ?></td>
					</tr>
					<tr>
						<th><label for="grc-page-demarche">Démarches (<code>[grc_demarche_form]</code>)</label></th>
						<td><?php wp_dropdown_pages( [ 'id' => 'grc-page-demarche', 'name' => 'page_demarche', 'selected' => (int) get_option( 'grc_page_demarche' ), 'show_option_none' => '— Aucune —' ] ); ?></td>
					</tr>
					<tr>
						<th><label for="grc-page-rdv">Rendez-vous (<code>[grc_rdv_form]</code>)</label></th>
						<td><?php wp_dropdown_pages( [ 'id' => 'grc-page-rdv', 'name' => 'page_rdv', 'selected' => (int) get_option( 'grc_page_rdv' ), 'show_option_none' => '— Aucune —' ] ); ?></td>
					</tr>
				</table>

				<h2>Journal d'audit (RGPD)</h2>
				<table class="form-table">
					<tr>
						<th><label for="grc-audit-retention">Durée de conservation du journal d'audit</label></th>
						<td>
							<input type="number" id="grc-audit-retention" name="audit_retention_mois" value="<?php echo esc_attr( $audit_retention ); ?>" min="1" max="36" style="width:80px;" onchange="document.getElementById('grc-audit-warning').style.display = (this.value > 12) ? 'block' : 'none';"> mois
							<p class="description">
								La CNIL recommande une conservation des journaux techniques comprise entre <strong>6 mois et 1 an</strong> (recommandation du 8 octobre 2021). Un dépassement au-delà de 12 mois n'est toléré qu'en cas de justification documentée (obligation légale, contentieux en cours, menace de sécurité avérée) — ce n'est pas la règle par défaut.
							</p>
							<p id="grc-audit-warning" class="description" style="color:#b32d2e;<?php echo $audit_retention > 12 ? '' : 'display:none;'; ?>">
								⚠️ Vous dépassez la durée recommandée par la CNIL (12 mois). Assurez-vous de documenter la justification de ce choix dans votre registre des traitements.
							</p>
							<p class="description">Une purge automatique quotidienne supprime les entrées plus anciennes que cette durée. Elle ne s'applique jamais rétroactivement de façon brutale : les entrées sont supprimées progressivement au fur et à mesure qu'elles dépassent le seuil.</p>
						</td>
					</tr>
				</table>

				<button type="submit" class="button button-primary">Enregistrer</button>
			</form>
		</div>
		<?php
	}

	public static function handle_save_settings() {
		check_admin_referer( 'grc_save_settings' );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$delai = max( 1, absint( $_POST['delai_validation_heures'] ?? 48 ) );
		update_option( 'grc_rdv_delai_validation_heures', $delai );

		$audit_retention = min( 36, max( 1, absint( $_POST['audit_retention_mois'] ?? 12 ) ) );
		update_option( 'grc_audit_retention_mois', $audit_retention );

		$session_timeout = min( 60, max( 5, absint( $_POST['session_timeout_minutes'] ?? 30 ) ) );
		update_option( 'grc_session_timeout_minutes', $session_timeout );

		update_option( 'grc_page_signalement', absint( $_POST['page_signalement'] ?? 0 ) );
		update_option( 'grc_page_mes_demandes', absint( $_POST['page_mes_demandes'] ?? 0 ) );
		update_option( 'grc_page_demarche', absint( $_POST['page_demarche'] ?? 0 ) );
		update_option( 'grc_page_rdv', absint( $_POST['page_rdv'] ?? 0 ) );

		update_option( 'grc_turnstile_site_key', sanitize_text_field( wp_unslash( $_POST['turnstile_site_key'] ?? '' ) ) );
		update_option( 'grc_turnstile_secret_key', sanitize_text_field( wp_unslash( $_POST['turnstile_secret_key'] ?? '' ) ) );
		update_option( 'grc_recaptcha_site_key', sanitize_text_field( wp_unslash( $_POST['recaptcha_site_key'] ?? '' ) ) );
		update_option( 'grc_recaptcha_secret_key', sanitize_text_field( wp_unslash( $_POST['recaptcha_secret_key'] ?? '' ) ) );
		update_option( 'grc_hcaptcha_site_key', sanitize_text_field( wp_unslash( $_POST['hcaptcha_site_key'] ?? '' ) ) );
		update_option( 'grc_hcaptcha_secret_key', sanitize_text_field( wp_unslash( $_POST['hcaptcha_secret_key'] ?? '' ) ) );

		$captcha_provider = sanitize_key( $_POST['captcha_provider'] ?? 'interne' );
		if ( ! in_array( $captcha_provider, [ 'interne', 'turnstile', 'recaptcha', 'hcaptcha' ], true ) ) {
			$captcha_provider = 'interne';
		}
		update_option( 'grc_captcha_provider', $captcha_provider );

		GRC_Audit_Log::log( 'settings_saved', 'settings', 0, [
			'delai_validation_heures'   => $delai,
			'audit_retention_mois'      => $audit_retention,
			'session_timeout_minutes'   => $session_timeout,
			'captcha_provider'          => $captcha_provider,
		] );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-settings&grc_notice=settings_saved' ) );
		exit;
	}
}
