<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Double authentification (2FA) obligatoire pour tout utilisateur disposant
 * de capacités GRC (agent, responsable, élu, administrateur — voir
 * GRC_Roles). Contrairement à la 2FA citoyenne (JWT, facultative), celle-ci
 * s'intègre directement au flux de connexion natif WordPress
 * (cookie + nonce), et est imposée : impossible de terminer la connexion
 * sans avoir configuré puis validé un second facteur.
 *
 * Fonctionnement :
 * 1. L'agent saisit son identifiant/mot de passe sur wp-login.php comme
 *    d'habitude. Une fois ces identifiants vérifiés par WordPress, on
 *    intercepte AVANT que la session ne soit établie (filtre 'authenticate',
 *    priorité tardive) : la vraie connexion n'a pas encore eu lieu.
 * 2. Un jeton temporaire (transient, 10 minutes) associe cette tentative à
 *    l'utilisateur, et on redirige vers un écran dédié
 *    (wp-login.php?action=grc_2fa) — soit pour saisir le code (2FA déjà
 *    configurée), soit pour la configurer d'abord (première connexion).
 * 3. Ce n'est qu'après validation du code que wp_set_auth_cookie() est
 *    réellement appelé, complétant la connexion.
 */
class GRC_Agent_2FA {

	const TRANSIENT_PENDING = 'grc_agent_2fa_pending_';
	const TRANSIENT_EMAIL_CODE = 'grc_agent_2fa_email_';
	const TRANSIENT_TOTP_TEMP = 'grc_agent_2fa_totp_temp_';
	const META_METHODE = 'grc_2fa_method';
	const META_SECRET = 'grc_2fa_secret_enc';
	const META_CONFIGUREE = 'grc_2fa_configured';

	public static function init() {
		add_filter( 'authenticate', [ __CLASS__, 'intercepter_apres_mot_de_passe' ], 30, 3 );
		add_action( 'login_form_grc_2fa', [ __CLASS__, 'gerer_ecran_2fa' ] );
		add_action( 'login_enqueue_scripts', [ __CLASS__, 'enqueue_assets' ] );
		add_action( 'wp_mail_failed', [ __CLASS__, 'consigner_echec_email' ] );

		// Reconfiguration depuis son propre profil (changer de méthode,
		// régénérer un secret TOTP après changement de téléphone...).
		add_action( 'show_user_profile', [ __CLASS__, 'afficher_section_profil' ] );
		add_action( 'edit_user_profile', [ __CLASS__, 'afficher_section_profil' ] );
		add_action( 'personal_options_update', [ __CLASS__, 'traiter_sauvegarde_profil' ] );
		add_action( 'edit_user_profile_update', [ __CLASS__, 'traiter_sauvegarde_profil' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_assets_profil' ] );

		// Réinitialisation par un administrateur (agent bloqué : téléphone
		// perdu/cassé, sans quoi il ne peut plus fournir aucun code).
		add_filter( 'user_row_actions', [ __CLASS__, 'ajouter_action_reinitialiser' ], 10, 2 );
		add_action( 'admin_post_grc_2fa_reinitialiser', [ __CLASS__, 'traiter_reinitialisation_admin' ] );

		add_action( 'wp_ajax_grc_2fa_demander_code_profil', [ __CLASS__, 'ajax_demander_code_profil' ] );
		add_action( 'admin_notices', [ __CLASS__, 'afficher_notice_reconfig' ] );
		add_action( 'admin_notices', [ __CLASS__, 'afficher_notice_reinitialisation' ] );
	}

	/** Consigne la raison exacte de tout échec d'envoi d'email du site (visible dans les logs du serveur/wp-content/debug.log si WP_DEBUG_LOG est actif) — utile pour diagnostiquer les échecs de code 2FA par email. */
	public static function consigner_echec_email( WP_Error $erreur ) {
		error_log( 'GRC — échec d\'envoi d\'email (wp_mail) : ' . $erreur->get_error_message() );
	}

	/** Un utilisateur GRC (agent, responsable, élu, administrateur) doit passer par la 2FA. */
	public static function agent_necessite_2fa( WP_User $user ): bool {
		return user_can( $user, 'grc_manage_demandes' ) || user_can( $user, 'grc_view_all' );
	}

	/**
	 * Intercepte juste après que WordPress a validé le couple identifiant/mot
	 * de passe, mais avant l'établissement de la session. Si l'utilisateur
	 * est concerné par la 2FA, bloque la connexion normale et redirige vers
	 * l'écran dédié.
	 */
	public static function intercepter_apres_mot_de_passe( $user, string $username, string $password ) {
		if ( ! ( $user instanceof WP_User ) ) {
			return $user; // Échec d'authentification déjà géré par WordPress — rien à faire ici.
		}
		if ( empty( $password ) ) {
			// Pas une vraie tentative par mot de passe (ex : vérification d'un
			// cookie de session déjà valide) — laisser passer normalement,
			// sous peine de casser les sessions déjà validées par 2FA.
			return $user;
		}
		if ( ! self::agent_necessite_2fa( $user ) ) {
			return $user;
		}

		$pending_token = wp_generate_password( 32, false, false );
		set_transient( self::TRANSIENT_PENDING . $pending_token, $user->ID, 10 * MINUTE_IN_SECONDS );

		$configuree = (bool) get_user_meta( $user->ID, self::META_CONFIGUREE, true );

		if ( $configuree ) {
			$methode = get_user_meta( $user->ID, self::META_METHODE, true ) ?: 'email';
			if ( 'email' === $methode ) {
				self::envoyer_code_email( $user );
			}
		}

		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();

		wp_safe_redirect( add_query_arg( [
			'action'      => 'grc_2fa',
			'token'       => $pending_token,
			'setup'       => $configuree ? '0' : '1',
			'redirect_to' => urlencode( $redirect_to ),
		], wp_login_url() ) );
		exit;
	}

	/**
	 * Le secret TOTP proposé pendant la configuration doit rester le même
	 * d'un affichage à l'autre (ex : l'agent scanne le QR code, se trompe de
	 * chiffre, la page se réaffiche avec une erreur) — sans quoi le QR code
	 * déjà scanné deviendrait invalide à chaque nouvelle tentative.
	 */
	private static function obtenir_secret_temporaire( WP_User $user ): string {
		$cle = self::TRANSIENT_TOTP_TEMP . $user->ID;
		$secret = get_transient( $cle );
		if ( ! $secret ) {
			$secret = GRC_TOTP::generer_secret();
			set_transient( $cle, $secret, 15 * MINUTE_IN_SECONDS );
		}
		return $secret;
	}

	private static function envoyer_code_email( WP_User $user ): bool {
		$code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		set_transient( self::TRANSIENT_EMAIL_CODE . $user->ID, wp_hash_password( $code ), 5 * MINUTE_IN_SECONDS );

		return wp_mail(
			$user->user_email,
			'[Mairie de Berre-les-Alpes] Votre code de connexion',
			"Bonjour,\n\nVotre code de connexion à usage unique : {$code}\n\nCe code expire dans 5 minutes. Si vous n'êtes pas à l'origine de cette tentative de connexion, contactez immédiatement l'administrateur du site.\n\nCordialement,\nMairie de Berre-les-Alpes"
		);
	}

	/** Résout le jeton d'attente en utilisateur, ou termine avec une erreur si invalide/expiré. */
	private static function resoudre_utilisateur_en_attente(): WP_User {
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		$user_id = $token ? get_transient( self::TRANSIENT_PENDING . $token ) : false;

		if ( ! $user_id ) {
			wp_die(
				esc_html__( 'Cette demande de connexion a expiré ou est invalide. Merci de vous reconnecter.', 'grc' ),
				esc_html__( 'Session expirée', 'grc' ),
				[ 'response' => 400, 'link_url' => wp_login_url(), 'link_text' => 'Retour à la connexion' ]
			);
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			wp_die( esc_html__( 'Utilisateur introuvable.', 'grc' ), '', [ 'response' => 400 ] );
		}

		return $user;
	}

	/**
	 * Point d'entrée unique de l'écran 2FA (wp-login.php?action=grc_2fa) :
	 * affiche le formulaire adapté (configuration ou simple code), et traite
	 * sa soumission.
	 */
	public static function gerer_ecran_2fa() {
		$user = self::resoudre_utilisateur_en_attente();
		$token = sanitize_text_field( wp_unslash( $_REQUEST['token'] ) );
		$redirect_to = isset( $_REQUEST['redirect_to'] ) ? esc_url_raw( wp_unslash( $_REQUEST['redirect_to'] ) ) : admin_url();
		$configuree = (bool) get_user_meta( $user->ID, self::META_CONFIGUREE, true );

		// Requête XHR de la page de configuration : "envoyer un code par
		// email" avant même la validation finale du formulaire. Ne nécessite
		// pas de nonce dédié (aucune donnée sensible modifiée, juste un envoi
		// d'email), la seule protection nécessaire est la possession d'un
		// jeton d'attente valide — déjà vérifiée par resoudre_utilisateur_en_attente().
		if ( 'POST' === $_SERVER['REQUEST_METHOD'] && isset( $_POST['grc_2fa_demander_code'] ) ) {
			$envoye = self::envoyer_code_email( $user );
			wp_die( $envoye ? 'ok' : 'echec', '', [ 'response' => $envoye ? 200 : 500 ] );
		}

		$erreur = '';

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( 'grc_2fa_' . $token );

			if ( ! GRC_REST_API::check_rate_limit( 'agent_2fa_verify', 8, 60 ) ) {
				$erreur = 'Trop de tentatives. Merci de patienter une minute avant de réessayer.';
			} elseif ( ! $configuree ) {
				$erreur = self::traiter_configuration( $user, $token, $redirect_to );
			} else {
				$erreur = self::traiter_verification( $user, $token, $redirect_to );
			}
		}

		self::afficher_formulaire( $user, $token, $redirect_to, $configuree, $erreur );
		exit;
	}

	/** Première connexion : l'agent doit choisir et configurer sa méthode avant de continuer. */
	private static function traiter_configuration( WP_User $user, string $token, string $redirect_to ): string {
		$methode = isset( $_POST['methode'] ) ? sanitize_text_field( wp_unslash( $_POST['methode'] ) ) : '';
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( 'totp' === $methode ) {
			$secret = self::obtenir_secret_temporaire( $user );
			if ( ! GRC_TOTP::verifier( $secret, $code ) ) {
				return 'Code invalide. Vérifiez que l\'heure de votre téléphone est synchronisée et réessayez.';
			}
			update_user_meta( $user->ID, self::META_METHODE, 'totp' );
			update_user_meta( $user->ID, self::META_SECRET, GRC_Encryption::encrypt( $secret ) );
			update_user_meta( $user->ID, self::META_CONFIGUREE, 1 );
			delete_transient( self::TRANSIENT_TOTP_TEMP . $user->ID );
		} elseif ( 'email' === $methode ) {
			$hash = get_transient( self::TRANSIENT_EMAIL_CODE . $user->ID );
			if ( ! $hash || ! wp_check_password( $code, $hash ) ) {
				return 'Code invalide ou expiré.';
			}
			update_user_meta( $user->ID, self::META_METHODE, 'email' );
			update_user_meta( $user->ID, self::META_CONFIGUREE, 1 );
			delete_transient( self::TRANSIENT_EMAIL_CODE . $user->ID );
		} else {
			return 'Merci de choisir une méthode.';
		}

		self::finaliser_connexion( $user, $token, $redirect_to );
		return ''; // Jamais atteint (finaliser_connexion() redirige et termine), mais garde le typage cohérent.
	}

	/** Connexions suivantes : l'agent a déjà configuré sa 2FA, on vérifie simplement son code. */
	private static function traiter_verification( WP_User $user, string $token, string $redirect_to ): string {
		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$methode = get_user_meta( $user->ID, self::META_METHODE, true );

		if ( 'totp' === $methode ) {
			$secret_enc = get_user_meta( $user->ID, self::META_SECRET, true );
			$secret = $secret_enc ? GRC_Encryption::decrypt( $secret_enc ) : '';
			if ( ! $secret || ! GRC_TOTP::verifier( $secret, $code ) ) {
				return 'Code invalide.';
			}
		} else {
			$hash = get_transient( self::TRANSIENT_EMAIL_CODE . $user->ID );
			if ( ! $hash || ! wp_check_password( $code, $hash ) ) {
				return 'Code invalide ou expiré.';
			}
			delete_transient( self::TRANSIENT_EMAIL_CODE . $user->ID );
		}

		self::finaliser_connexion( $user, $token, $redirect_to );
		return '';
	}

	/** Établit réellement la session WordPress (jamais fait avant ce point) et redirige. */
	private static function finaliser_connexion( WP_User $user, string $token, string $redirect_to ) {
		delete_transient( self::TRANSIENT_PENDING . $token );
		wp_set_current_user( $user->ID );
		wp_set_auth_cookie( $user->ID, false );
		do_action( 'wp_login', $user->user_login, $user );
		wp_safe_redirect( $redirect_to );
		exit;
	}

	private static function afficher_formulaire( WP_User $user, string $token, string $redirect_to, bool $configuree, string $erreur ) {
		login_header( 'Double authentification', '', null );

		$action_url = add_query_arg( [
			'action'      => 'grc_2fa',
			'token'       => $token,
			'redirect_to' => urlencode( $redirect_to ),
		], wp_login_url() );

		if ( $erreur ) {
			echo '<div id="login_error">' . esc_html( $erreur ) . '</div>';
		}

		if ( ! $configuree ) {
			self::afficher_formulaire_configuration( $user, $action_url, $token );
		} else {
			self::afficher_formulaire_code( $action_url, $token );
		}

		login_footer();
	}

	private static function afficher_formulaire_configuration( WP_User $user, string $action_url, string $token ) {
		$secret = self::obtenir_secret_temporaire( $user );
		$uri = GRC_TOTP::uri_provisionnement( $secret, $user->user_email );
		?>
		<p>La double authentification est obligatoire pour votre compte. Configurez-la une seule fois ci-dessous.</p>

		<div class="grc-2fa-tabs" style="margin-bottom:16px;">
			<button type="button" class="button grc-2fa-tab-btn" data-tab="totp" aria-pressed="true">Application d'authentification</button>
			<button type="button" class="button grc-2fa-tab-btn" data-tab="email" aria-pressed="false">Par email</button>
		</div>

		<div id="grc-2fa-tab-totp">
			<p>Scannez ce QR code avec Google Authenticator, Authy, ou une application équivalente.</p>
			<div id="grc-2fa-agent-qrcode" role="img" aria-label="QR code de configuration de l'application d'authentification"></div>
			<p>Ou saisissez cette clé manuellement : <code><?php echo esc_html( $secret ); ?></code></p>
			<form name="grc_2fa_form" method="post" action="<?php echo esc_url( $action_url ); ?>">
				<?php wp_nonce_field( 'grc_2fa_' . $token ); ?>
				<input type="hidden" name="methode" value="totp">
				<p>
					<label for="grc-2fa-code-totp">Code à 6 chiffres</label>
					<input type="text" name="code" id="grc-2fa-code-totp" class="input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus required>
				</p>
				<p class="submit"><input type="submit" class="button button-primary button-large" value="Valider et se connecter"></p>
			</form>
		</div>

		<div id="grc-2fa-tab-email" style="display:none;">
			<p>Un code de connexion vous sera envoyé par email à chaque connexion.</p>
			<button type="button" class="button" id="grc-2fa-envoyer-code-email">Recevoir un code par email</button>
			<form name="grc_2fa_email_form" method="post" action="<?php echo esc_url( $action_url ); ?>" style="margin-top:12px;">
				<?php wp_nonce_field( 'grc_2fa_' . $token ); ?>
				<input type="hidden" name="methode" value="email">
				<p>
					<label for="grc-2fa-code-email">Code reçu par email</label>
					<input type="text" name="code" id="grc-2fa-code-email" class="input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
				</p>
				<p class="submit"><input type="submit" class="button button-primary button-large" value="Valider et se connecter"></p>
			</form>
		</div>

		<script>
			document.addEventListener( 'DOMContentLoaded', function () {
				new QRCode( document.getElementById( 'grc-2fa-agent-qrcode' ), { text: <?php echo wp_json_encode( $uri ); ?>, width: 180, height: 180 } );

				var tabs = document.querySelectorAll( '.grc-2fa-tab-btn' );
				tabs.forEach( function ( btn ) {
					btn.addEventListener( 'click', function () {
						tabs.forEach( function ( b ) { b.setAttribute( 'aria-pressed', 'false' ); } );
						btn.setAttribute( 'aria-pressed', 'true' );
						document.getElementById( 'grc-2fa-tab-totp' ).style.display = 'totp' === btn.dataset.tab ? 'block' : 'none';
						document.getElementById( 'grc-2fa-tab-email' ).style.display = 'email' === btn.dataset.tab ? 'block' : 'none';
					} );
				} );

				var envoyerBtn = document.getElementById( 'grc-2fa-envoyer-code-email' );
				envoyerBtn.addEventListener( 'click', function () {
					envoyerBtn.disabled = true;
					envoyerBtn.textContent = 'Envoi en cours...';
					fetch( window.location.href, {
						method: 'POST',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: 'grc_2fa_demander_code=1&token=<?php echo rawurlencode( $token ); ?>'
					} ).then( function ( response ) {
						if ( ! response.ok ) {
							throw new Error( 'Échec de l\'envoi (HTTP ' + response.status + ')' );
						}
						envoyerBtn.textContent = 'Code envoyé — vérifiez vos emails (et vos indésirables)';
					} ).catch( function () {
						envoyerBtn.disabled = false;
						envoyerBtn.textContent = 'Échec de l\'envoi — réessayer';
					} );
				} );
			} );
		</script>
		<?php
	}

	private static function afficher_formulaire_code( string $action_url, string $token ) {
		?>
		<p>Un code de connexion à usage unique vous a été envoyé, ou est disponible dans votre application d'authentification.</p>
		<form name="grc_2fa_form" method="post" action="<?php echo esc_url( $action_url ); ?>">
			<?php wp_nonce_field( 'grc_2fa_' . $token ); ?>
			<p>
				<label for="grc-2fa-code">Code à 6 chiffres</label>
				<input type="text" name="code" id="grc-2fa-code" class="input" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" autofocus required>
			</p>
			<p class="submit"><input type="submit" class="button button-primary button-large" value="Valider et se connecter"></p>
		</form>
		<?php
	}

	public static function enqueue_assets() {
		if ( isset( $_GET['action'] ) && 'grc_2fa' === $_GET['action'] ) {
			wp_enqueue_script( 'grc-qrcodejs', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', [], '1.0.0', true );
		}
	}

	// =====================================================================
	// Reconfiguration depuis son propre profil WordPress
	// =====================================================================

	public static function enqueue_assets_profil( string $hook ) {
		if ( in_array( $hook, [ 'profile.php', 'user-edit.php' ], true ) ) {
			wp_enqueue_script( 'grc-qrcodejs', 'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js', [], '1.0.0', true );
		}
	}

	/**
	 * Affiche, sur la page de profil WordPress, l'état de la 2FA et :
	 * - pour l'agent lui-même : un moyen de la reconfigurer (changer de
	 *   méthode, régénérer un secret TOTP après changement de téléphone) ;
	 * - pour un administrateur consultant le profil d'un autre agent : un
	 *   simple état + bouton de réinitialisation (voir ajouter_action_reinitialiser()
	 *   pour le cas d'usage : agent bloqué, téléphone perdu).
	 */
	public static function afficher_section_profil( WP_User $user ) {
		if ( ! self::agent_necessite_2fa( $user ) ) {
			return;
		}

		$est_soi_meme = get_current_user_id() === $user->ID;
		$configuree = (bool) get_user_meta( $user->ID, self::META_CONFIGUREE, true );
		$methode = get_user_meta( $user->ID, self::META_METHODE, true );
		$methode_label = 'totp' === $methode ? 'Application d\'authentification' : 'Email';
		?>
		<h2>Double authentification</h2>
		<table class="form-table">
			<tr>
				<th>Statut</th>
				<td>
					<?php if ( $configuree ) : ?>
						<p>✅ Configurée — méthode actuelle : <strong><?php echo esc_html( $methode_label ); ?></strong></p>
					<?php else : ?>
						<p>⚠️ Pas encore configurée — elle le sera à la prochaine connexion (obligatoire).</p>
					<?php endif; ?>

					<?php if ( $est_soi_meme ) : ?>
						<p>
							<button type="button" class="button" id="grc-2fa-profil-toggle">
								<?php echo $configuree ? 'Changer de méthode' : 'Configurer maintenant'; ?>
							</button>
						</p>
						<div id="grc-2fa-profil-form" style="display:none;max-width:480px;">
							<?php self::afficher_formulaire_reconfiguration( $user ); ?>
						</div>
					<?php elseif ( $configuree && current_user_can( 'manage_options' ) ) : ?>
						<p>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_2fa_reinitialiser&user_id=' . $user->ID ), 'grc_2fa_reinitialiser_' . $user->ID ) ); ?>"
							   class="button"
							   onclick="return confirm('Réinitialiser la double authentification de cet agent ? Il devra la reconfigurer entièrement à sa prochaine connexion (utile s\'il a perdu son téléphone).');">
								Réinitialiser sa double authentification
							</a>
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
		<?php
	}

	private static function afficher_formulaire_reconfiguration( WP_User $user ) {
		$secret = self::obtenir_secret_temporaire( $user );
		$uri = GRC_TOTP::uri_provisionnement( $secret, $user->user_email );
		wp_nonce_field( 'grc_2fa_reconfig_' . $user->ID, 'grc_2fa_reconfig_nonce' );
		?>
		<input type="hidden" name="grc_2fa_reconfig_action" value="1">

		<p>
			<label><input type="radio" name="grc_2fa_reconfig_methode" value="totp" checked> Application d'authentification</label><br>
			<label><input type="radio" name="grc_2fa_reconfig_methode" value="email"> Email</label>
		</p>

		<div id="grc-2fa-profil-totp">
			<p>Scannez ce QR code, puis saisissez le code affiché par l'application.</p>
			<div id="grc-2fa-profil-qrcode" role="img" aria-label="QR code de configuration de l'application d'authentification"></div>
			<p>Ou saisissez cette clé manuellement : <code><?php echo esc_html( $secret ); ?></code></p>
		</div>

		<div id="grc-2fa-profil-email" style="display:none;">
			<p><button type="button" class="button" id="grc-2fa-profil-envoyer-email">Recevoir un code par email</button></p>
		</div>

		<p>
			<label for="grc-2fa-profil-code">Code à 6 chiffres</label><br>
			<input type="text" name="grc_2fa_reconfig_code" id="grc-2fa-profil-code" class="regular-text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code">
		</p>
		<p class="description">La confirmation se fait en cliquant sur "Mettre à jour le profil" / "Mettre à jour l'utilisateur" tout en bas de cette page, une fois le code saisi.</p>

		<script>
			document.addEventListener( 'DOMContentLoaded', function () {
				var toggleBtn = document.getElementById( 'grc-2fa-profil-toggle' );
				var formEl = document.getElementById( 'grc-2fa-profil-form' );
				if ( toggleBtn && formEl ) {
					toggleBtn.addEventListener( 'click', function () {
						var ouvert = 'none' !== formEl.style.display;
						formEl.style.display = ouvert ? 'none' : 'block';
					} );
				}

				var qrEl = document.getElementById( 'grc-2fa-profil-qrcode' );
				if ( qrEl && window.QRCode ) {
					new QRCode( qrEl, { text: <?php echo wp_json_encode( $uri ); ?>, width: 180, height: 180 } );
				}

				var radios = document.querySelectorAll( 'input[name="grc_2fa_reconfig_methode"]' );
				radios.forEach( function ( radio ) {
					radio.addEventListener( 'change', function () {
						document.getElementById( 'grc-2fa-profil-totp' ).style.display = 'totp' === radio.value ? 'block' : 'none';
						document.getElementById( 'grc-2fa-profil-email' ).style.display = 'email' === radio.value ? 'block' : 'none';
					} );
				} );

				var envoyerBtn = document.getElementById( 'grc-2fa-profil-envoyer-email' );
				if ( envoyerBtn ) {
					envoyerBtn.addEventListener( 'click', function () {
						envoyerBtn.disabled = true;
						envoyerBtn.textContent = 'Envoi en cours...';
						var donnees = new FormData();
						donnees.append( 'action', 'grc_2fa_demander_code_profil' );
						donnees.append( '_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( 'grc_2fa_demander_code_profil' ) ); ?> );
						fetch( ajaxurl, { method: 'POST', body: donnees, credentials: 'same-origin' } )
							.then( function ( response ) {
								if ( ! response.ok ) { throw new Error(); }
								envoyerBtn.textContent = 'Code envoyé — vérifiez vos emails (et vos indésirables)';
							} )
							.catch( function () {
								envoyerBtn.disabled = false;
								envoyerBtn.textContent = 'Échec de l\'envoi — réessayer';
							} );
					} );
				}
			} );
		</script>
		<?php
	}

	/** Traite la reconfiguration depuis le profil — uniquement en libre-service (jamais pour le compte d'un autre utilisateur). */
	public static function traiter_sauvegarde_profil( int $user_id ) {
		if ( empty( $_POST['grc_2fa_reconfig_action'] ) ) {
			return; // Formulaire de profil soumis sans toucher à la 2FA — rien à faire.
		}
		if ( get_current_user_id() !== $user_id ) {
			return; // Sécurité : jamais reconfigurer la 2FA d'un tiers via ce formulaire.
		}
		check_admin_referer( 'grc_2fa_reconfig_' . $user_id, 'grc_2fa_reconfig_nonce' );

		if ( ! GRC_REST_API::check_rate_limit( 'agent_2fa_reconfig', 8, 60 ) ) {
			set_transient( 'grc_2fa_reconfig_result_' . $user_id, 'trop_de_tentatives', 30 );
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! self::agent_necessite_2fa( $user ) ) {
			return;
		}

		$methode = isset( $_POST['grc_2fa_reconfig_methode'] ) ? sanitize_text_field( wp_unslash( $_POST['grc_2fa_reconfig_methode'] ) ) : '';
		$code = isset( $_POST['grc_2fa_reconfig_code'] ) ? sanitize_text_field( wp_unslash( $_POST['grc_2fa_reconfig_code'] ) ) : '';
		$succes = false;

		if ( 'totp' === $methode ) {
			$secret = self::obtenir_secret_temporaire( $user );
			if ( GRC_TOTP::verifier( $secret, $code ) ) {
				update_user_meta( $user_id, self::META_METHODE, 'totp' );
				update_user_meta( $user_id, self::META_SECRET, GRC_Encryption::encrypt( $secret ) );
				update_user_meta( $user_id, self::META_CONFIGUREE, 1 );
				delete_transient( self::TRANSIENT_TOTP_TEMP . $user_id );
				$succes = true;
			}
		} elseif ( 'email' === $methode ) {
			$hash = get_transient( self::TRANSIENT_EMAIL_CODE . $user_id );
			if ( $hash && wp_check_password( $code, $hash ) ) {
				update_user_meta( $user_id, self::META_METHODE, 'email' );
				update_user_meta( $user_id, self::META_CONFIGUREE, 1 );
				delete_transient( self::TRANSIENT_EMAIL_CODE . $user_id );
				$succes = true;
			}
		}

		// Le hook user_profile_update_errors (seul moyen "standard" d'afficher
		// une erreur sur cette page) se déclenche AVANT personal_options_update
		// dans le cycle de sauvegarde du profil — trop tard pour s'y accrocher
		// depuis ici. On mémorise donc le résultat pour l'afficher au
		// rechargement suivant via une notice admin classique.
		set_transient( 'grc_2fa_reconfig_result_' . $user_id, $succes ? 'ok' : 'echec', 30 );
	}

	public static function afficher_notice_reconfig() {
		$user_id = get_current_user_id();
		$resultat = get_transient( 'grc_2fa_reconfig_result_' . $user_id );
		if ( ! $resultat ) {
			return;
		}
		delete_transient( 'grc_2fa_reconfig_result_' . $user_id );
		if ( 'ok' === $resultat ) {
			echo '<div class="notice notice-success is-dismissible"><p>Double authentification mise à jour avec succès.</p></div>';
		} elseif ( 'trop_de_tentatives' === $resultat ) {
			echo '<div class="notice notice-error is-dismissible"><p>Trop de tentatives. Merci de patienter une minute avant de réessayer.</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>Double authentification : code invalide (ou expiré), la méthode n\'a pas été modifiée.</p></div>';
		}
	}

	/** Point d'entrée AJAX (admin-ajax.php) pour l'envoi du code email depuis la page de profil (utilisateur déjà connecté). */
	public static function ajax_demander_code_profil() {
		check_ajax_referer( 'grc_2fa_demander_code_profil' );
		$user = wp_get_current_user();
		$envoye = self::envoyer_code_email( $user );
		wp_die( $envoye ? 'ok' : 'echec', '', [ 'response' => $envoye ? 200 : 500 ] );
	}

	// =====================================================================
	// Réinitialisation par un administrateur (agent bloqué)
	// =====================================================================

	/** Ajoute un lien "Réinitialiser sa double authentification" sur la liste des utilisateurs (administrateurs uniquement). */
	public static function ajouter_action_reinitialiser( array $actions, WP_User $user ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			return $actions;
		}
		if ( ! self::agent_necessite_2fa( $user ) ) {
			return $actions;
		}
		if ( ! get_user_meta( $user->ID, self::META_CONFIGUREE, true ) ) {
			return $actions; // Rien à réinitialiser.
		}

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=grc_2fa_reinitialiser&user_id=' . $user->ID ),
			'grc_2fa_reinitialiser_' . $user->ID
		);
		$actions['grc_2fa_reinitialiser'] = '<a href="' . esc_url( $url ) . '" onclick="return confirm(\'Réinitialiser la double authentification de cet agent ? Il devra la reconfigurer entièrement à sa prochaine connexion.\');">Réinitialiser sa 2FA</a>';

		return $actions;
	}

	public static function traiter_reinitialisation_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Accès non autorisé.', '', [ 'response' => 403 ] );
		}

		$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;
		check_admin_referer( 'grc_2fa_reinitialiser_' . $user_id );

		delete_user_meta( $user_id, self::META_METHODE );
		delete_user_meta( $user_id, self::META_SECRET );
		delete_user_meta( $user_id, self::META_CONFIGUREE );
		delete_transient( self::TRANSIENT_TOTP_TEMP . $user_id );
		delete_transient( self::TRANSIENT_EMAIL_CODE . $user_id );

		$redirect = wp_get_referer() ?: admin_url( 'users.php' );
		wp_safe_redirect( add_query_arg( 'grc_2fa_reinitialisee', '1', $redirect ) );
		exit;
	}

	public static function afficher_notice_reinitialisation() {
		if ( isset( $_GET['grc_2fa_reinitialisee'] ) && current_user_can( 'manage_options' ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Double authentification réinitialisée pour cet agent — il devra la reconfigurer à sa prochaine connexion.</p></div>';
		}
	}
}
