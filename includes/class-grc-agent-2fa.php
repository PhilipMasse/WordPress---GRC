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

	private static function envoyer_code_email( WP_User $user ) {
		$code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
		set_transient( self::TRANSIENT_EMAIL_CODE . $user->ID, wp_hash_password( $code ), 5 * MINUTE_IN_SECONDS );

		wp_mail(
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
			self::envoyer_code_email( $user );
			wp_die( 'ok', '', [ 'response' => 200 ] );
		}

		$erreur = '';

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) {
			check_admin_referer( 'grc_2fa_' . $token );

			if ( ! $configuree ) {
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
					} ).then( function () {
						envoyerBtn.textContent = 'Code envoyé — vérifiez vos emails';
					} ).catch( function () {
						envoyerBtn.disabled = false;
						envoyerBtn.textContent = 'Recevoir un code par email';
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
}
