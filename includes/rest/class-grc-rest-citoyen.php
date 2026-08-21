<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Authentification des citoyens — système indépendant des comptes WordPress (wp_users).
 * Les agents/élus/admin continuent d'utiliser wp_authenticate() (voir GRC_REST_Auth).
 * Ici, un citoyen est authentifié via wp_grc_citoyens.password_hash (wp_hash_password,
 * même mécanisme de hachage que WordPress mais table séparée).
 */
class GRC_REST_Citoyen {

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/citoyen/register', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'register' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/2fa/verifier', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'verifier_2fa' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/mot-de-passe-oublie', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'mot_de_passe_oublie' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/reinitialiser-mot-de-passe', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'reinitialiser_mot_de_passe' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/2fa/totp/demarrer', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'totp_demarrer_activation' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== self::get_authenticated_citoyen_id( $request );
			},
		] );

		register_rest_route( $ns, '/citoyen/2fa/totp/confirmer', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'totp_confirmer_activation' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== self::get_authenticated_citoyen_id( $request );
			},
		] );

		register_rest_route( $ns, '/citoyen/2fa/email/activer', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'activer_2fa_email' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== self::get_authenticated_citoyen_id( $request );
			},
		] );

		// Volontairement pas de route "/citoyen/2fa/desactiver" : une fois
		// activée, la double authentification d'un citoyen ne peut plus être
		// désactivée, seulement changée de méthode (voir activer_2fa_email/
		// totp, qui basculent déjà sans condition vers la nouvelle méthode).

		register_rest_route( $ns, '/captcha', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'get_captcha' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/login', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'login' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/refresh', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'refresh' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/citoyen/me', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'me' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== self::get_authenticated_citoyen_id( $request );
			},
		] );

		register_rest_route( $ns, '/citoyen/me', [
			'methods'             => 'PUT',
			'callback'            => [ __CLASS__, 'update_me' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== self::get_authenticated_citoyen_id( $request );
			},
		] );

		register_rest_route( $ns, '/citoyen/password', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'change_password' ],
			'permission_callback' => function ( WP_REST_Request $request ) {
				return null !== self::get_authenticated_citoyen_id( $request );
			},
		] );
	}

	/**
	 * Inscription : crée un compte citoyen avec mot de passe, ou "complète" une
	 * fiche invité existante (créée lors d'un signalement précédent) si l'email
	 * correspond déjà à un citoyen sans mot de passe.
	 */
	public static function get_captcha( WP_REST_Request $request ) {
		return GRC_Captcha::generate();
	}

	public static function verify_captcha_provider( string $provider, ?string $token ): bool {
		if ( ! $token ) {
			return false;
		}

		$config = [
			'turnstile' => [
				'url'    => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
				'secret' => get_option( 'grc_turnstile_secret_key', '' ),
			],
			'recaptcha' => [
				'url'    => 'https://www.google.com/recaptcha/api/siteverify',
				'secret' => get_option( 'grc_recaptcha_secret_key', '' ),
			],
			'hcaptcha' => [
				'url'    => 'https://hcaptcha.com/siteverify',
				'secret' => get_option( 'grc_hcaptcha_secret_key', '' ),
			],
		];

		if ( ! isset( $config[ $provider ] ) || ! $config[ $provider ]['secret'] ) {
			return false;
		}

		$response = wp_remote_post( $config[ $provider ]['url'], [
			'timeout' => 8,
			'body'    => [
				'secret'   => $config[ $provider ]['secret'],
				'response' => $token,
				'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $body['success'] );
	}

	public static function register( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'citoyen_register', 10, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez plus tard.', [ 'status' => 429 ] );
		}

		// Honeypot : champ invisible que seuls les robots remplissent habituellement.
		if ( ! empty( $request->get_param( 'site_web' ) ) ) {
			return new WP_Error( 'grc_invalid_captcha', 'Vérification anti-robot invalide.', [ 'status' => 400 ] );
		}

		$captcha_provider = get_option( 'grc_captcha_provider', 'interne' );
		if ( 'interne' !== $captcha_provider ) {
			if ( ! self::verify_captcha_provider( $captcha_provider, $request->get_param( 'captcha_provider_token' ) ) ) {
				return new WP_Error( 'grc_invalid_captcha', 'Vérification anti-robot incorrecte ou expirée. Merci de réessayer.', [ 'status' => 400 ] );
			}
		} elseif ( ! GRC_Captcha::verify( $request->get_param( 'captcha_token' ), $request->get_param( 'captcha_reponse' ) ) ) {
			return new WP_Error( 'grc_invalid_captcha', 'Vérification anti-robot incorrecte ou expirée. Merci de réessayer.', [ 'status' => 400 ] );
		}

		$email    = sanitize_email( $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );
		$nom      = sanitize_text_field( $request->get_param( 'nom' ) ?? '' );
		$prenom   = sanitize_text_field( $request->get_param( 'prenom' ) ?? '' );
		$telephone = sanitize_text_field( $request->get_param( 'telephone' ) ?? '' );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'grc_invalid_email', 'Adresse email invalide.', [ 'status' => 400 ] );
		}
		if ( strlen( $password ) < 8 ) {
			return new WP_Error( 'grc_weak_password', 'Le mot de passe doit contenir au moins 8 caractères.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$hash  = GRC_Encryption::search_hash( $email );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", $hash ) );

		if ( $existing && ! empty( $existing->password_hash ) ) {
			return new WP_Error( 'grc_email_taken', 'Un compte existe déjà avec cet email.', [ 'status' => 409 ] );
		}

		$password_hash = wp_hash_password( $password );

		if ( $existing ) {
			// Fiche invité existante (créée lors d'un signalement) : on la complète.
			$wpdb->update( $table, [
				'password_hash' => $password_hash,
				'is_guest'      => 0,
				'nom'           => $nom ? GRC_Encryption::encrypt( $nom ) : $existing->nom,
				'prenom'        => $prenom ? GRC_Encryption::encrypt( $prenom ) : $existing->prenom,
				'telephone'     => $telephone ? GRC_Encryption::encrypt( $telephone ) : $existing->telephone,
				'telephone_hash'=> $telephone ? GRC_Encryption::search_hash( $telephone ) : $existing->telephone_hash,
			], [ 'id' => $existing->id ] );
			$citoyen_id = (int) $existing->id;
		} else {
			$wpdb->insert( $table, [
				'nom'            => GRC_Encryption::encrypt( $nom ),
				'prenom'         => GRC_Encryption::encrypt( $prenom ),
				'email'          => GRC_Encryption::encrypt( $email ),
				'email_hash'     => $hash,
				'telephone'      => $telephone ? GRC_Encryption::encrypt( $telephone ) : null,
				'telephone_hash' => $telephone ? GRC_Encryption::search_hash( $telephone ) : null,
				'password_hash'  => $password_hash,
				'is_guest'       => 0,
				'created_at'     => current_time( 'mysql' ),
			] );
			$citoyen_id = (int) $wpdb->insert_id;
		}

		GRC_Audit_Log::log( 'citoyen_registered', 'citoyen', $citoyen_id );

		return self::issue_tokens( $citoyen_id );
	}

	public static function login( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'citoyen_login', 8, 60 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez dans une minute.', [ 'status' => 429 ] );
		}

		$email    = sanitize_email( $request->get_param( 'email' ) );
		$password = (string) $request->get_param( 'password' );

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$hash  = GRC_Encryption::search_hash( $email );

		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", $hash ) );

		if ( ! $citoyen || empty( $citoyen->password_hash ) || ! wp_check_password( $password, $citoyen->password_hash ) ) {
			GRC_Audit_Log::log( 'citoyen_login_failed', 'citoyen', $citoyen->id ?? null );
			return new WP_Error( 'grc_login_failed', 'Identifiants invalides.', [ 'status' => 401 ] );
		}

		if ( ! empty( $citoyen->two_factor_method ) ) {
			GRC_Audit_Log::log( 'citoyen_login_2fa_required', 'citoyen', (int) $citoyen->id );
			return self::demarrer_verification_2fa( $citoyen );
		}

		GRC_Audit_Log::log( 'citoyen_login_success', 'citoyen', (int) $citoyen->id );

		return self::issue_tokens( (int) $citoyen->id );
	}

	/**
	 * Émet un token temporaire (5 minutes, non utilisable pour l'API) attestant
	 * que le mot de passe a été vérifié, en attente du second facteur. Pour la
	 * méthode email, envoie également le code à l'instant.
	 */
	private static function demarrer_verification_2fa( object $citoyen ) {
		$pending_token = GRC_JWT::issue( (int) $citoyen->id, 300, [ 'type' => 'citoyen_2fa_pending' ] );

		if ( 'email' === $citoyen->two_factor_method ) {
			$code = str_pad( (string) wp_rand( 0, 999999 ), 6, '0', STR_PAD_LEFT );
			set_transient( 'grc_2fa_email_' . $citoyen->id, wp_hash_password( $code ), 5 * MINUTE_IN_SECONDS );

			$email = $citoyen->email ? GRC_Encryption::decrypt( $citoyen->email ) : null;
			if ( $email ) {
				wp_mail(
					$email,
					'[Mairie de Berre-les-Alpes] Votre code de connexion',
					"Bonjour,\n\nVotre code de connexion à usage unique : {$code}\n\nCe code expire dans 5 minutes. Si vous n'êtes pas à l'origine de cette tentative de connexion, ignorez cet email et envisagez de changer votre mot de passe.\n\nCordialement,\nMairie de Berre-les-Alpes"
				);
			}
		}

		return [
			'requires_2fa'  => true,
			'method'        => $citoyen->two_factor_method,
			'pending_token' => $pending_token,
		];
	}

	/**
	 * Second temps de la connexion : vérifie le code (email ou TOTP) et émet
	 * les tokens définitifs si valide.
	 */
	public static function verifier_2fa( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'citoyen_2fa_verify', 8, 60 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez dans une minute.', [ 'status' => 429 ] );
		}

		$pending_token = (string) $request->get_param( 'pending_token' );
		$code          = (string) $request->get_param( 'code' );

		$payload = GRC_JWT::verify( $pending_token );
		if ( is_wp_error( $payload ) || ( $payload['type'] ?? '' ) !== 'citoyen_2fa_pending' ) {
			return new WP_Error( 'grc_invalid_pending_token', 'Session de connexion expirée, merci de vous reconnecter.', [ 'status' => 401 ] );
		}

		$citoyen_id = (int) $payload['sub'];

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $citoyen_id ) );
		if ( ! $citoyen || empty( $citoyen->two_factor_method ) ) {
			return new WP_Error( 'grc_invalid_pending_token', 'Session de connexion expirée, merci de vous reconnecter.', [ 'status' => 401 ] );
		}

		$valide = false;
		if ( 'totp' === $citoyen->two_factor_method && $citoyen->totp_secret ) {
			$secret = GRC_Encryption::decrypt( $citoyen->totp_secret );
			$valide = $secret && GRC_TOTP::verifier( $secret, $code );
		} elseif ( 'email' === $citoyen->two_factor_method ) {
			$hash_attendu = get_transient( 'grc_2fa_email_' . $citoyen_id );
			$valide = $hash_attendu && wp_check_password( $code, $hash_attendu );
			if ( $valide ) {
				delete_transient( 'grc_2fa_email_' . $citoyen_id ); // Usage unique.
			}
		}

		if ( ! $valide ) {
			GRC_Audit_Log::log( 'citoyen_login_2fa_failed', 'citoyen', $citoyen_id );
			return new WP_Error( 'grc_invalid_2fa_code', 'Code invalide ou expiré.', [ 'status' => 401 ] );
		}

		GRC_Audit_Log::log( 'citoyen_login_success', 'citoyen', $citoyen_id, [ 'via_2fa' => $citoyen->two_factor_method ] );

		return self::issue_tokens( $citoyen_id );
	}

	public static function refresh( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'api_tokens';

		$token_hash = hash( 'sha256', (string) $request->get_param( 'refresh_token' ) );
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE refresh_token_hash = %s AND revoked = 0 AND expires_at > %s AND device_label = %s",
			$token_hash,
			current_time( 'mysql', true ), // GMT, cohérent avec gmdate() utilisé au moment du stockage.
			'citoyen'
		) );

		if ( ! $row ) {
			return new WP_Error( 'grc_invalid_refresh', 'Refresh token invalide ou expiré.', [ 'status' => 401 ] );
		}

		$access_token = GRC_JWT::issue( (int) $row->wp_user_id, 3600, [ 'type' => 'citoyen' ] );

		return [ 'access_token' => $access_token, 'expires_in' => 3600 ];
	}

	// ------------------------------------------------------------------
	// Mot de passe oublié
	// ------------------------------------------------------------------

	public static function mot_de_passe_oublie( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'citoyen_mdp_oublie', 5, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de demandes, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$email = sanitize_email( $request->get_param( 'email' ) );

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email_hash = %s", GRC_Encryption::search_hash( $email ) ) );

		// Réponse identique que le compte existe ou non, pour ne pas
		// permettre à un tiers de déduire quels emails sont enregistrés.
		if ( $citoyen ) {
			$raw_token = bin2hex( random_bytes( 32 ) );
			$wpdb->update( $table, [
				'reset_token_hash'    => hash( 'sha256', $raw_token ),
				'reset_token_expires' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			], [ 'id' => $citoyen->id ] );

			$lien = self::lien_reinitialisation( $raw_token );
			$email_reel = $citoyen->email ? GRC_Encryption::decrypt( $citoyen->email ) : null;
			if ( $email_reel ) {
				wp_mail(
					$email_reel,
					'[Mairie de Berre-les-Alpes] Réinitialisation de votre mot de passe',
					"Bonjour,\n\nUne demande de réinitialisation de mot de passe a été effectuée pour votre espace citoyen.\n\nPour choisir un nouveau mot de passe, cliquez sur ce lien (valable 1 heure) :\n{$lien}\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez cet email : votre mot de passe actuel reste inchangé.\n\nCordialement,\nMairie de Berre-les-Alpes"
				);
			}
			GRC_Audit_Log::log( 'citoyen_password_reset_requested', 'citoyen', (int) $citoyen->id );
		}

		return [ 'message' => 'Si un compte existe avec cet email, un lien de réinitialisation vient de lui être envoyé.' ];
	}

	private static function lien_reinitialisation( string $raw_token ): string {
		$page_url = GRC_Frontend::page_url( 'grc_page_mes_demandes' );
		$base = $page_url ?: home_url( '/' );
		return add_query_arg( 'reset_token', $raw_token, $base );
	}

	public static function reinitialiser_mot_de_passe( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'citoyen_reset_mdp', 10, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$token = (string) $request->get_param( 'token' );
		$nouveau_mdp = (string) $request->get_param( 'mot_de_passe' );

		if ( strlen( $nouveau_mdp ) < 8 ) {
			return new WP_Error( 'grc_password_too_short', 'Le mot de passe doit contenir au moins 8 caractères.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$citoyen = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE reset_token_hash = %s AND reset_token_expires > %s",
			hash( 'sha256', $token ),
			current_time( 'mysql', true ) // GMT, cohérent avec gmdate() utilisé au moment du stockage.
		) );

		if ( ! $citoyen ) {
			return new WP_Error( 'grc_invalid_reset_token', 'Ce lien de réinitialisation est invalide ou a expiré.', [ 'status' => 400 ] );
		}

		$wpdb->update( $table, [
			'password_hash'       => wp_hash_password( $nouveau_mdp ),
			'reset_token_hash'    => null,
			'reset_token_expires' => null,
		], [ 'id' => $citoyen->id ] );

		// Toute session existante est invalidée par précaution (le mot de
		// passe a pu être compromis, d'où la réinitialisation).
		$tokens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'api_tokens';
		$wpdb->update( $tokens_table, [ 'revoked' => 1 ], [ 'wp_user_id' => $citoyen->id, 'device_label' => 'citoyen' ] );

		GRC_Audit_Log::log( 'citoyen_password_reset_completed', 'citoyen', (int) $citoyen->id );

		return [ 'message' => 'Votre mot de passe a été mis à jour. Vous pouvez maintenant vous connecter.' ];
	}

	// ------------------------------------------------------------------
	// Double authentification (2FA)
	// ------------------------------------------------------------------

	/**
	 * Démarre l'activation du TOTP : génère un secret (non encore enregistré
	 * tant que le citoyen n'a pas confirmé avec un code valide) et retourne
	 * l'URI à afficher en QR code.
	 */
	public static function totp_demarrer_activation( WP_REST_Request $request ) {
		$citoyen_id = self::get_authenticated_citoyen_id( $request );
		if ( ! $citoyen_id ) {
			return new WP_Error( 'grc_unauthorized', 'Non authentifié.', [ 'status' => 401 ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT email FROM {$table} WHERE id = %d", $citoyen_id ) );
		$email = $citoyen && $citoyen->email ? GRC_Encryption::decrypt( $citoyen->email ) : ( 'citoyen-' . $citoyen_id );

		$secret = GRC_TOTP::generer_secret();
		// Stocké temporairement (10 min) le temps de la confirmation ; n'est
		// écrit sur le compte qu'après vérification d'un premier code valide.
		set_transient( 'grc_totp_setup_' . $citoyen_id, $secret, 10 * MINUTE_IN_SECONDS );

		return [
			'secret' => $secret,
			'uri'    => GRC_TOTP::uri_provisionnement( $secret, $email ),
		];
	}

	public static function totp_confirmer_activation( WP_REST_Request $request ) {
		$citoyen_id = self::get_authenticated_citoyen_id( $request );
		if ( ! $citoyen_id ) {
			return new WP_Error( 'grc_unauthorized', 'Non authentifié.', [ 'status' => 401 ] );
		}

		$secret = get_transient( 'grc_totp_setup_' . $citoyen_id );
		$code   = (string) $request->get_param( 'code' );

		if ( ! $secret || ! GRC_TOTP::verifier( $secret, $code ) ) {
			return new WP_Error( 'grc_invalid_2fa_code', 'Code invalide. Vérifiez l\'heure de votre appareil et réessayez.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$wpdb->update( $table, [
			'two_factor_method' => 'totp',
			'totp_secret'       => GRC_Encryption::encrypt( $secret ),
		], [ 'id' => $citoyen_id ] );
		delete_transient( 'grc_totp_setup_' . $citoyen_id );

		GRC_Audit_Log::log( 'citoyen_2fa_enabled', 'citoyen', $citoyen_id, [ 'methode' => 'totp' ] );

		return [ 'message' => 'La double authentification par application est activée.' ];
	}

	public static function activer_2fa_email( WP_REST_Request $request ) {
		$citoyen_id = self::get_authenticated_citoyen_id( $request );
		if ( ! $citoyen_id ) {
			return new WP_Error( 'grc_unauthorized', 'Non authentifié.', [ 'status' => 401 ] );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$wpdb->update( $table, [ 'two_factor_method' => 'email', 'totp_secret' => null ], [ 'id' => $citoyen_id ] );

		GRC_Audit_Log::log( 'citoyen_2fa_enabled', 'citoyen', $citoyen_id, [ 'methode' => 'email' ] );

		return [ 'message' => 'La double authentification par email est activée.' ];
	}

	public static function me( WP_REST_Request $request ) {
		$citoyen_id = self::get_authenticated_citoyen_id( $request );

		global $wpdb;
		$table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $citoyen_id ) );

		if ( ! $citoyen ) {
			return new WP_Error( 'grc_not_found', 'Citoyen introuvable.', [ 'status' => 404 ] );
		}

		return [
			'id'      => (int) $citoyen->id,
			'nom'     => $citoyen->nom ? GRC_Encryption::decrypt( $citoyen->nom ) : null,
			'prenom'  => $citoyen->prenom ? GRC_Encryption::decrypt( $citoyen->prenom ) : null,
			'email'   => $citoyen->email ? GRC_Encryption::decrypt( $citoyen->email ) : null,
			'two_factor_method' => $citoyen->two_factor_method,
			'telephone' => $citoyen->telephone ? GRC_Encryption::decrypt( $citoyen->telephone ) : null,
		];
	}

	/**
	 * Met à jour les informations personnelles du citoyen (nom, prénom, téléphone, email).
	 * Le changement d'email met aussi à jour le hash de recherche.
	 */
	public static function update_me( WP_REST_Request $request ) {
		$citoyen_id = self::get_authenticated_citoyen_id( $request );

		$nom       = $request->get_param( 'nom' );
		$prenom    = $request->get_param( 'prenom' );
		$telephone = $request->get_param( 'telephone' );
		$email     = $request->get_param( 'email' );

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$data  = [];

		if ( null !== $nom ) {
			$data['nom'] = GRC_Encryption::encrypt( sanitize_text_field( $nom ) );
		}
		if ( null !== $prenom ) {
			$data['prenom'] = GRC_Encryption::encrypt( sanitize_text_field( $prenom ) );
		}
		if ( null !== $telephone ) {
			$telephone = sanitize_text_field( $telephone );
			$data['telephone']      = $telephone ? GRC_Encryption::encrypt( $telephone ) : null;
			$data['telephone_hash'] = $telephone ? GRC_Encryption::search_hash( $telephone ) : null;
		}
		if ( null !== $email && is_email( $email ) ) {
			$email = sanitize_email( $email );
			$hash  = GRC_Encryption::search_hash( $email );

			// Vérifie qu'aucun autre citoyen n'utilise déjà cet email.
			$conflict = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$table} WHERE email_hash = %s AND id != %d",
				$hash,
				$citoyen_id
			) );
			if ( $conflict ) {
				return new WP_Error( 'grc_email_taken', 'Cet email est déjà utilisé par un autre compte.', [ 'status' => 409 ] );
			}

			$data['email']      = GRC_Encryption::encrypt( $email );
			$data['email_hash'] = $hash;
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'grc_no_data', 'Aucune donnée à mettre à jour.', [ 'status' => 400 ] );
		}

		$wpdb->update( $table, $data, [ 'id' => $citoyen_id ] );
		GRC_Audit_Log::log( 'citoyen_profile_updated', 'citoyen', $citoyen_id );

		return self::me( $request );
	}

	/**
	 * Change le mot de passe du citoyen, après vérification de l'ancien mot de passe.
	 */
	public static function change_password( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'citoyen_password', 5, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$citoyen_id = self::get_authenticated_citoyen_id( $request );
		$current    = (string) $request->get_param( 'current_password' );
		$new        = (string) $request->get_param( 'new_password' );

		if ( strlen( $new ) < 8 ) {
			return new WP_Error( 'grc_weak_password', 'Le nouveau mot de passe doit contenir au moins 8 caractères.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$citoyen = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $citoyen_id ) );

		if ( ! $citoyen || empty( $citoyen->password_hash ) || ! wp_check_password( $current, $citoyen->password_hash ) ) {
			return new WP_Error( 'grc_wrong_password', 'Mot de passe actuel incorrect.', [ 'status' => 401 ] );
		}

		$wpdb->update( $table, [ 'password_hash' => wp_hash_password( $new ) ], [ 'id' => $citoyen_id ] );
		GRC_Audit_Log::log( 'citoyen_password_changed', 'citoyen', $citoyen_id );

		return [ 'success' => true ];
	}

	/**
	 * Récupère l'ID citoyen authentifié via un JWT de type "citoyen" (voir middleware GRC_REST_API).
	 * Retourne null si non authentifié ou si le token est de type "agent".
	 */
	public static function get_authenticated_citoyen_id( WP_REST_Request $request ): ?int {
		$payload = $request->get_param( '_grc_jwt_payload' );
		if ( is_array( $payload ) && isset( $payload['type'] ) && 'citoyen' === $payload['type'] ) {
			return (int) $payload['sub'];
		}
		return null;
	}

	private static function issue_tokens( int $citoyen_id ): array {
		$access_token  = GRC_JWT::issue( $citoyen_id, 3600, [ 'type' => 'citoyen' ] );
		$refresh_token = self::issue_refresh_token( $citoyen_id );

		return [
			'access_token'  => $access_token,
			'refresh_token' => $refresh_token,
			'expires_in'    => 3600,
			'citoyen_id'    => $citoyen_id,
		];
	}

	private static function issue_refresh_token( int $citoyen_id ): string {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'api_tokens';

		$raw_token  = bin2hex( random_bytes( 32 ) );
		$token_hash = hash( 'sha256', $raw_token );

		// Réutilise wp_user_id pour stocker le citoyen_id ; device_label='citoyen' distingue le type de token
		// (voir aussi GRC_REST_Auth pour les tokens agents, qui n'utilisent pas ce marqueur).
		$wpdb->insert( $table, [
			'wp_user_id'         => $citoyen_id,
			'refresh_token_hash' => $token_hash,
			'device_label'       => 'citoyen',
			'expires_at'         => gmdate( 'Y-m-d H:i:s', time() + ( 90 * DAY_IN_SECONDS ) ),
			'created_at'         => current_time( 'mysql' ),
		] );

		return $raw_token;
	}
}
