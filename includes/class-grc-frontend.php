<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portail citoyen front-office :
 * - [grc_signalement_form] : formulaire public de signalement (compte ou invité)
 * - [grc_mes_demandes]     : suivi des demandes (citoyen connecté ou invité via numéro + email)
 */
class GRC_Frontend {

	public static function init() {
		add_shortcode( 'grc_signalement_form', [ __CLASS__, 'render_signalement_form' ] );
		add_shortcode( 'grc_mes_demandes', [ __CLASS__, 'render_mes_demandes' ] );
		add_shortcode( 'grc_demarche_form', [ __CLASS__, 'render_demarche_form' ] );
		add_shortcode( 'grc_rdv_form', [ __CLASS__, 'render_rdv_form' ] );
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_assets' ] );
	}

	/**
	 * Charge les assets du portail citoyen sur tout le front-office.
	 * On ne conditionne plus au contenu détecté via has_shortcode() : cette
	 * détection s'est révélée peu fiable (constructeurs de page alternatifs,
	 * contenu stocké autrement que dans post_content, timing de $post...).
	 * Le coût de charger ces deux petits fichiers partout est négligeable.
	 */
	/**
	 * Configuration du fournisseur de captcha actuellement sélectionné
	 * (Réglages GRC → Anti-robot à l'inscription). Centralise les paramètres
	 * propres à chaque fournisseur pour éviter la duplication entre le rendu
	 * du widget et son chargement de script.
	 */
	public static function captcha_config(): array {
		$provider = get_option( 'grc_captcha_provider', 'interne' );

		$providers = [
			'turnstile' => [
				'site_key'   => get_option( 'grc_turnstile_site_key', '' ),
				'script_url' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
				'widget_class' => 'cf-turnstile',
				'response_field' => 'cf-turnstile-response',
			],
			'recaptcha' => [
				'site_key'   => get_option( 'grc_recaptcha_site_key', '' ),
				'script_url' => 'https://www.google.com/recaptcha/api.js',
				'widget_class' => 'g-recaptcha',
				'response_field' => 'g-recaptcha-response',
			],
			'hcaptcha' => [
				'site_key'   => get_option( 'grc_hcaptcha_site_key', '' ),
				'script_url' => 'https://js.hcaptcha.com/1/api.js',
				'widget_class' => 'h-captcha',
				'response_field' => 'h-captcha-response',
			],
		];

		if ( 'interne' === $provider || empty( $providers[ $provider ]['site_key'] ) ) {
			return [ 'provider' => 'interne' ];
		}

		return array_merge( [ 'provider' => $provider ], $providers[ $provider ] );
	}

	public static function maybe_enqueue_assets() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style( 'grc-frontend', GRC_PLUGIN_URL . 'assets/frontend.css', [], GRC_VERSION );
		wp_enqueue_script( 'grc-frontend', GRC_PLUGIN_URL . 'assets/frontend.js', [], GRC_VERSION, true );

		$captcha = self::captcha_config();
		if ( 'interne' !== $captcha['provider'] ) {
			wp_enqueue_script( 'grc-captcha-provider', $captcha['script_url'], [], null, true );
		}

		wp_localize_script( 'grc-frontend', 'grcConfig', [
			'restUrl'    => esc_url_raw( rest_url( 'grc/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn' => is_user_logged_in(),
			'sessionTimeoutMinutes' => (int) get_option( 'grc_session_timeout_minutes', 30 ),
			'captchaProvider' => $captcha['provider'],
			'captchaResponseField' => $captcha['response_field'] ?? null,
			'pages'      => [
				'signalement'  => self::page_url( 'grc_page_signalement' ),
				'mesDemandes'  => self::page_url( 'grc_page_mes_demandes' ),
				'demarche'     => self::page_url( 'grc_page_demarche' ),
				'rdv'          => self::page_url( 'grc_page_rdv' ),
			],
		] );
	}

	private static function page_url( string $option_name ): ?string {
		$page_id = (int) get_option( $option_name );
		return $page_id ? get_permalink( $page_id ) : null;
	}

	// ------------------------------------------------------------------
	// Formulaire de signalement
	// ------------------------------------------------------------------

	public static function render_signalement_form( $atts ): string {
		global $wpdb;
		$categories_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';
		$categories = $wpdb->get_results( "SELECT id, nom, parent_id FROM {$categories_table} WHERE actif = 1 ORDER BY ordre, nom" );

		ob_start();
		?>
		<div class="grc-form-wrapper">
			<a href="#grc-titre" class="grc-skip-link">Aller au formulaire de signalement</a>
			<div id="grc-connected-banner" class="grc-connected-banner" style="display:none;">
				Connecté en tant que <strong id="grc-connected-name"></strong> — vos coordonnées seront automatiquement associées à ce signalement.
			</div>

			<div id="grc-login-required-notice" class="grc-connected-banner" style="display:none;background:#fff3cd;color:#664d03;border:1px solid #ffe69c;">
				Vous devez être connecté(e) à votre espace citoyen pour signaler un problème.
				<?php if ( self::page_url( 'grc_page_mes_demandes' ) ) : ?>
					<a href="<?php echo esc_url( self::page_url( 'grc_page_mes_demandes' ) ); ?>">Se connecter ou créer un compte →</a>
				<?php endif; ?>
			</div>

			<form id="grc-signalement-form" class="grc-form" style="display:none;">
				<div class="grc-field">
					<label for="grc-titre">Objet du signalement <span class="required">*</span></label>
					<input type="text" id="grc-titre" name="titre" required maxlength="191">
				</div>

				<div class="grc-field">
					<label for="grc-categorie">Catégorie</label>
					<select id="grc-categorie" name="categorie_id">
						<option value="">— Sélectionner —</option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat->id ); ?>"><?php echo esc_html( $cat->nom ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="grc-field">
					<label for="grc-description">Description <span class="required">*</span></label>
					<textarea id="grc-description" name="description" rows="5" required></textarea>
				</div>

				<div class="grc-field">
					<label for="grc-adresse">Adresse / lieu concerné</label>
					<input type="text" id="grc-adresse" name="adresse_lieu" placeholder="Ex: 12 rue de la Mairie">
					<button type="button" id="grc-geoloc-btn" class="grc-btn-link" style="margin-top:4px;">📍 Actualiser ma position</button>
					<span id="grc-geoloc-status" class="grc-hint" role="status" aria-live="polite"></span>
					<div id="grc-geoloc-map" role="img" aria-label="Carte permettant d'ajuster précisément l'emplacement du signalement. Vous pouvez aussi indiquer l'adresse directement dans le champ ci-dessus si vous ne pouvez pas utiliser la carte." style="display:none;height:260px;border-radius:8px;margin-top:8px;"></div>
					<p id="grc-geoloc-coords" class="grc-hint" role="status" aria-live="polite" style="display:none;"></p>
					<div id="grc-signalements-proches" role="status" aria-live="polite" style="display:none;"></div>
					<input type="hidden" id="grc-latitude" name="latitude">
					<input type="hidden" id="grc-longitude" name="longitude">
				</div>

				<div class="grc-field">
					<label for="grc-photo">Photo (facultatif)</label>
					<input type="file" id="grc-photo" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
				</div>

				<div class="grc-field grc-consent">
					<label>
						<input type="checkbox" name="consent" required>
						J'accepte que mes données soient utilisées pour le traitement de ce signalement, conformément à la politique de confidentialité de la Mairie.
					</label>
				</div>

				<button type="submit" class="grc-btn-submit">Envoyer le signalement</button>

				<div id="grc-form-message" class="grc-form-message" role="status" aria-live="polite" style="display:none;"></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	// ------------------------------------------------------------------
	// Suivi des demandes
	// ------------------------------------------------------------------

	public static function render_mes_demandes( $atts ): string {
		ob_start();
		?>
		<div class="grc-mes-demandes-wrapper">
			<a href="#grc-auth-tabs-first" class="grc-skip-link">Aller au formulaire de connexion</a>

			<div id="grc-auth-forms" class="grc-auth-forms">
				<div class="grc-auth-tabs">
					<button type="button" id="grc-auth-tabs-first" class="grc-auth-tab grc-auth-tab--active" data-tab="login">Se connecter</button>
					<button type="button" class="grc-auth-tab" data-tab="register">Créer un compte</button>
					<button type="button" class="grc-auth-tab" data-tab="guest">Suivi invité</button>
				</div>

				<form id="grc-citoyen-login-form" class="grc-form grc-auth-panel">
					<div class="grc-field">
						<label for="grc-login-email">Email</label>
						<input type="email" id="grc-login-email" name="email" required>
					</div>
					<div class="grc-field">
						<label for="grc-login-password">Mot de passe</label>
						<input type="password" id="grc-login-password" name="password" required>
					</div>
					<button type="submit" class="grc-btn-submit">Se connecter</button>
					<div class="grc-form-message" role="status" aria-live="polite" style="display:none;"></div>
				</form>

				<form id="grc-citoyen-register-form" class="grc-form grc-auth-panel" style="display:none;">
					<div class="grc-field">
						<label for="grc-reg-prenom">Prénom</label>
						<input type="text" id="grc-reg-prenom" name="prenom">
					</div>
					<div class="grc-field">
						<label for="grc-reg-nom">Nom</label>
						<input type="text" id="grc-reg-nom" name="nom">
					</div>
					<div class="grc-field">
						<label for="grc-reg-email">Email</label>
						<input type="email" id="grc-reg-email" name="email" required>
					</div>
					<div class="grc-field">
						<label for="grc-reg-password">Mot de passe (8 caractères minimum)</label>
						<input type="password" id="grc-reg-password" name="password" minlength="8" required>
					</div>

					<div style="position:absolute;left:-9999px;" aria-hidden="true">
						<label for="grc-reg-site-web">Ne pas remplir ce champ</label>
						<input type="text" id="grc-reg-site-web" name="site_web" tabindex="-1" autocomplete="off">
					</div>

					<?php $captcha = self::captcha_config(); ?>
					<?php if ( 'interne' !== $captcha['provider'] ) : ?>
						<div class="grc-field">
							<div class="<?php echo esc_attr( $captcha['widget_class'] ); ?>" data-sitekey="<?php echo esc_attr( $captcha['site_key'] ); ?>"></div>
						</div>
					<?php else : ?>
						<div class="grc-field">
							<label for="grc-reg-captcha">Vérification anti-robot</label>
							<p id="grc-captcha-question" class="grc-hint" role="status" aria-live="polite">Chargement...</p>
							<input type="text" id="grc-reg-captcha" name="captcha_reponse" required inputmode="numeric" style="max-width:100px;">
							<input type="hidden" id="grc-captcha-token" name="captcha_token">
						</div>
					<?php endif; ?>

					<button type="submit" class="grc-btn-submit">Créer mon compte</button>
					<div class="grc-form-message" role="status" aria-live="polite" style="display:none;"></div>
				</form>

				<form id="grc-guest-lookup-form" class="grc-form grc-auth-panel" style="display:none;">
					<p class="grc-hint">Retrouvez votre demande avec son numéro de suivi et votre email.</p>
					<div class="grc-field">
						<label for="grc-lookup-numero">Numéro de suivi</label>
						<input type="text" id="grc-lookup-numero" name="numero_suivi" placeholder="GRC-2026-XXXXXX" required>
					</div>
					<div class="grc-field">
						<label for="grc-lookup-email">Email</label>
						<input type="email" id="grc-lookup-email" name="email" required>
					</div>
					<button type="submit" class="grc-btn-submit">Rechercher</button>
				</form>
			</div>

			<div id="grc-citoyen-connecte" class="grc-citoyen-connecte" style="display:none;">
				<div class="grc-liste-toolbar">
					<h3>Mes demandes</h3>
					<div class="grc-liste-controls">
						<select class="grc-statut-filter" data-target="demandes">
							<option value="">Tous les statuts</option>
							<option value="nouveau">Nouveau</option>
							<option value="en_cours">En cours</option>
							<option value="assigne">Assigné</option>
							<option value="resolu">Résolu</option>
							<option value="cloture">Clôturé</option>
							<option value="reouvert">Réouvert</option>
						</select>
						<button type="button" class="grc-vue-toggle" data-target="demandes" title="Changer d'affichage" aria-label="Changer l'affichage des demandes en liste ou en cartes">☰</button>
					</div>
				</div>
				<div id="grc-demandes-liste" class="grc-demandes-liste"><p>Chargement de vos demandes...</p></div>

				<div class="grc-liste-toolbar" style="margin-top:24px;">
					<h3>Mes démarches</h3>
					<div class="grc-liste-controls">
						<select class="grc-statut-filter" data-target="demarches">
							<option value="">Tous les statuts</option>
							<option value="en_attente">En attente</option>
							<option value="en_cours">En cours</option>
							<option value="valide">Validé</option>
							<option value="rejete">Rejeté</option>
							<option value="complement_requis">Complément requis</option>
						</select>
						<button type="button" class="grc-vue-toggle" data-target="demarches" title="Changer d'affichage" aria-label="Changer l'affichage des démarches en liste ou en cartes">☰</button>
					</div>
				</div>
				<div id="grc-demarches-liste" class="grc-demandes-liste"><p>Chargement de vos démarches...</p></div>

				<div class="grc-liste-toolbar" style="margin-top:24px;">
					<h3>Mes rendez-vous</h3>
					<div class="grc-liste-controls">
						<select class="grc-statut-filter" data-target="rdv">
							<option value="">Tous les statuts</option>
							<option value="en_attente">En attente</option>
							<option value="confirme">Confirmé</option>
							<option value="refuse">Refusé</option>
							<option value="annule">Annulé</option>
						</select>
						<button type="button" class="grc-vue-toggle" data-target="rdv" title="Changer d'affichage" aria-label="Changer l'affichage des rendez-vous en liste ou en cartes">☰</button>
					</div>
				</div>
				<div id="grc-rdv-liste" class="grc-demandes-liste"><p>Chargement de vos rendez-vous...</p></div>
			</div>

			<div id="grc-guest-results" class="grc-demandes-liste"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	// ------------------------------------------------------------------
	// Formulaire de démarche administrative (dynamique)
	// ------------------------------------------------------------------

	/**
	 * [grc_demarche_form] ou [grc_demarche_form type="etat-civil"]
	 * Si "type" est fourni, le formulaire est directement affiché pour ce type.
	 * Sinon, un sélecteur de type est affiché en premier (rempli via JS).
	 */
	public static function render_demarche_form( $atts ): string {
		$atts = shortcode_atts( [ 'type' => '' ], $atts, 'grc_demarche_form' );

		ob_start();
		?>
		<div class="grc-demarche-form-wrapper" data-preselect-type="<?php echo esc_attr( $atts['type'] ); ?>">
			<a href="#grc-demarche-type-select" class="grc-skip-link">Aller au formulaire de démarche</a>
			<div id="grc-demarche-connected-banner" class="grc-connected-banner" style="display:none;">
				Connecté en tant que <strong id="grc-demarche-connected-name"></strong> — vos coordonnées seront automatiquement associées à ce dossier.
			</div>

			<form id="grc-demarche-form" class="grc-form">
				<div class="grc-field" id="grc-demarche-type-selector" style="display:none;">
					<label for="grc-demarche-type-select">Type de démarche</label>
					<select id="grc-demarche-type-select">
						<option value="">— Chargement... —</option>
					</select>
				</div>

				<div id="grc-demarche-description" class="grc-hint" style="display:none;"></div>

				<div id="grc-demarche-dynamic-fields"></div>

				<div id="grc-demarche-guest-fields" class="grc-guest-fields">
					<p class="grc-hint">Vous n'êtes pas connecté(e) : renseignez votre email pour suivre votre dossier.</p>
					<div class="grc-field">
						<label for="grc-demarche-email">Email <span class="required">*</span></label>
						<input type="email" id="grc-demarche-email" name="email">
					</div>

					<div style="position:absolute;left:-9999px;" aria-hidden="true">
						<label for="grc-demarche-site-web">Ne pas remplir ce champ</label>
						<input type="text" id="grc-demarche-site-web" name="site_web" tabindex="-1" autocomplete="off">
					</div>

					<?php $demarche_captcha = self::captcha_config(); ?>
					<?php if ( 'interne' !== $demarche_captcha['provider'] ) : ?>
						<div class="grc-field">
							<div class="<?php echo esc_attr( $demarche_captcha['widget_class'] ); ?>" data-sitekey="<?php echo esc_attr( $demarche_captcha['site_key'] ); ?>"></div>
						</div>
					<?php else : ?>
						<div class="grc-field">
							<label for="grc-demarche-captcha">Vérification anti-robot</label>
							<p id="grc-demarche-captcha-question" class="grc-hint" role="status" aria-live="polite">Chargement...</p>
							<input type="text" id="grc-demarche-captcha" required inputmode="numeric" style="max-width:100px;">
							<input type="hidden" id="grc-demarche-captcha-token">
						</div>
					<?php endif; ?>
				</div>

				<button type="submit" class="grc-btn-submit" disabled>Envoyer le dossier</button>
				<div class="grc-form-message" role="status" aria-live="polite" style="display:none;"></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}

	// ------------------------------------------------------------------
	// Formulaire de prise de rendez-vous
	// ------------------------------------------------------------------

	public static function render_rdv_form( $atts ): string {
		global $wpdb;
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$services = $wpdb->get_results( "SELECT id, nom FROM {$services_table} WHERE actif = 1 ORDER BY nom" );

		ob_start();
		?>
		<div class="grc-form-wrapper grc-rdv-wrapper">
			<a href="#grc-rdv-service" class="grc-skip-link">Aller au formulaire de rendez-vous</a>
			<div id="grc-rdv-connected-banner" class="grc-connected-banner" style="display:none;">
				Connecté en tant que <strong id="grc-rdv-connected-name"></strong> — vos coordonnées seront automatiquement associées à ce rendez-vous.
			</div>

			<div id="grc-rdv-login-required-notice" class="grc-connected-banner" style="display:none;background:#fff3cd;color:#664d03;border:1px solid #ffe69c;">
				Vous devez être connecté(e) à votre espace citoyen pour prendre rendez-vous.
				<?php if ( self::page_url( 'grc_page_mes_demandes' ) ) : ?>
					<a href="<?php echo esc_url( self::page_url( 'grc_page_mes_demandes' ) ); ?>">Se connecter ou créer un compte →</a>
				<?php endif; ?>
			</div>

			<form id="grc-rdv-form" class="grc-form" style="display:none;">
				<div class="grc-field">
					<label for="grc-rdv-service">Service concerné <span class="required">*</span></label>
					<select id="grc-rdv-service" required>
						<option value="">— Sélectionner —</option>
						<?php foreach ( $services as $s ) : ?>
							<option value="<?php echo esc_attr( $s->id ); ?>"><?php echo esc_html( $s->nom ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<div class="grc-field" id="grc-rdv-duree-field" style="display:none;">
					<label>Durée du rendez-vous</label>
					<div class="grc-duree-toggle" id="grc-rdv-duree-toggle"></div>
				</div>

				<div class="grc-field" id="grc-rdv-calendar-field" style="display:none;">
					<label>Choisissez une date</label>
					<div class="grc-calendar-legend">
						<span><i class="grc-legend-dot grc-legend-dot--available"></i> Places disponibles</span>
						<span><i class="grc-legend-dot grc-legend-dot--few"></i> Dernières places</span>
						<span><i class="grc-legend-dot grc-legend-dot--full"></i> Complet</span>
					</div>
					<div class="grc-calendar-nav">
						<button type="button" id="grc-cal-prev" class="button">‹</button>
						<span id="grc-cal-month-label"></span>
						<button type="button" id="grc-cal-next" class="button">›</button>
					</div>
					<div id="grc-calendar-grid" class="grc-calendar-grid"></div>
				</div>

				<div class="grc-field" id="grc-rdv-creneaux-field" style="display:none;">
					<label>Créneau disponible <span class="required">*</span></label>
					<div id="grc-rdv-creneaux" class="grc-creneaux-grid"></div>
				</div>

				<div class="grc-field">
					<label for="grc-rdv-motif">Motif du rendez-vous</label>
					<input type="text" id="grc-rdv-motif" placeholder="Ex : renouvellement de carte d'identité">
				</div>

				<button type="submit" class="grc-btn-submit" disabled>Confirmer le rendez-vous</button>
				<div class="grc-form-message" role="status" aria-live="polite" style="display:none;"></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
