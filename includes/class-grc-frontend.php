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
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_assets' ] );
	}

	/**
	 * Charge les assets du portail citoyen sur tout le front-office.
	 * On ne conditionne plus au contenu détecté via has_shortcode() : cette
	 * détection s'est révélée peu fiable (constructeurs de page alternatifs,
	 * contenu stocké autrement que dans post_content, timing de $post...).
	 * Le coût de charger ces deux petits fichiers partout est négligeable.
	 */
	public static function maybe_enqueue_assets() {
		if ( is_admin() ) {
			return;
		}

		wp_enqueue_style( 'grc-frontend', GRC_PLUGIN_URL . 'assets/frontend.css', [], GRC_VERSION );
		wp_enqueue_script( 'grc-frontend', GRC_PLUGIN_URL . 'assets/frontend.js', [], GRC_VERSION, true );

		wp_localize_script( 'grc-frontend', 'grcConfig', [
			'restUrl'    => esc_url_raw( rest_url( 'grc/v1' ) ),
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'isLoggedIn' => is_user_logged_in(),
		] );
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
			<div id="grc-connected-banner" class="grc-connected-banner" style="display:none;">
				Connecté en tant que <strong id="grc-connected-name"></strong> — vos coordonnées seront automatiquement associées à ce signalement.
			</div>
			<form id="grc-signalement-form" class="grc-form">
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
				</div>

				<div class="grc-field">
					<label for="grc-photo">Photo (facultatif)</label>
					<input type="file" id="grc-photo" name="photo" accept="image/jpeg,image/png,image/webp,image/gif">
				</div>

				<div id="grc-guest-fields" class="grc-guest-fields">
					<p class="grc-hint">Vous n'êtes pas connecté(e) : renseignez votre email pour suivre votre demande.</p>
					<div class="grc-field">
						<label for="grc-prenom">Prénom</label>
						<input type="text" id="grc-prenom" name="prenom">
					</div>
					<div class="grc-field">
						<label for="grc-nom">Nom</label>
						<input type="text" id="grc-nom" name="nom">
					</div>
					<div class="grc-field">
						<label for="grc-email">Email <span class="required">*</span></label>
						<input type="email" id="grc-email" name="email">
					</div>
					<div class="grc-field">
						<label for="grc-telephone">Téléphone</label>
						<input type="tel" id="grc-telephone" name="telephone">
					</div>
				</div>

				<div class="grc-field grc-consent">
					<label>
						<input type="checkbox" name="consent" required>
						J'accepte que mes données soient utilisées pour le traitement de ce signalement, conformément à la politique de confidentialité de la Mairie.
					</label>
				</div>

				<button type="submit" class="grc-btn-submit">Envoyer le signalement</button>

				<div id="grc-form-message" class="grc-form-message" style="display:none;"></div>
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

			<div id="grc-auth-forms" class="grc-auth-forms">
				<div class="grc-auth-tabs">
					<button type="button" class="grc-auth-tab grc-auth-tab--active" data-tab="login">Se connecter</button>
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
					<div class="grc-form-message" style="display:none;"></div>
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
					<button type="submit" class="grc-btn-submit">Créer mon compte</button>
					<div class="grc-form-message" style="display:none;"></div>
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
				<div class="grc-citoyen-header">
					<span id="grc-citoyen-nom"></span>
					<span>
						<button type="button" id="grc-citoyen-profil-toggle" class="grc-btn-link">Mon profil</button>
						<button type="button" id="grc-citoyen-logout" class="grc-btn-link">Se déconnecter</button>
					</span>
				</div>

				<div id="grc-citoyen-profil" class="grc-citoyen-profil" style="display:none;">
					<form id="grc-profil-form" class="grc-form">
						<div class="grc-field">
							<label for="grc-profil-prenom">Prénom</label>
							<input type="text" id="grc-profil-prenom" name="prenom">
						</div>
						<div class="grc-field">
							<label for="grc-profil-nom">Nom</label>
							<input type="text" id="grc-profil-nom" name="nom">
						</div>
						<div class="grc-field">
							<label for="grc-profil-email">Email</label>
							<input type="email" id="grc-profil-email" name="email">
						</div>
						<div class="grc-field">
							<label for="grc-profil-telephone">Téléphone</label>
							<input type="tel" id="grc-profil-telephone" name="telephone">
						</div>
						<button type="submit" class="grc-btn-submit">Enregistrer</button>
						<div class="grc-form-message" style="display:none;"></div>
					</form>

					<hr style="margin:20px 0;border:none;border-top:1px solid #eee;">

					<form id="grc-password-form" class="grc-form">
						<h4 style="margin-bottom:10px;">Changer de mot de passe</h4>
						<div class="grc-field">
							<label for="grc-current-password">Mot de passe actuel</label>
							<input type="password" id="grc-current-password" name="current_password" required>
						</div>
						<div class="grc-field">
							<label for="grc-new-password">Nouveau mot de passe (8 caractères minimum)</label>
							<input type="password" id="grc-new-password" name="new_password" minlength="8" required>
						</div>
						<button type="submit" class="grc-btn-submit">Changer le mot de passe</button>
						<div class="grc-form-message" style="display:none;"></div>
					</form>
				</div>

				<div id="grc-demandes-liste" class="grc-demandes-liste"><p>Chargement de vos demandes...</p></div>
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
				</div>

				<button type="submit" class="grc-btn-submit" disabled>Envoyer le dossier</button>
				<div class="grc-form-message" style="display:none;"></div>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
