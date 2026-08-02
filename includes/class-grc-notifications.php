<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Notifications citoyen/agent (email en V1, SMS branchable en V2 via passerelle externe).
 */
class GRC_Notifications {

	public static function init() {
		add_action( 'grc_demande_statut_changed', [ __CLASS__, 'send_statut_change' ], 10, 3 );
		add_action( 'phpmailer_init', [ __CLASS__, 'configure_smtp' ] );
	}

	/**
	 * Liste centralisée des types de notification pilotables (matrice
	 * Réglages GRC → Email → Matrice des notifications). Chacune est activée
	 * par défaut sauf désactivation explicite.
	 */
	public static function notif_types(): array {
		return [
			'demande_creee_citoyen'          => 'Signalement créé → accusé de réception au citoyen',
			'demande_creee_agents'           => 'Signalement créé → notification aux agents',
			'demande_statut_change_citoyen'  => 'Statut du signalement modifié → notification au citoyen',
			'demande_message_citoyen'        => 'Message d\'un agent sur un signalement → notification au citoyen',
			'demarche_creee_citoyen'         => 'Démarche créée → accusé de réception au citoyen',
			'demarche_creee_agents'          => 'Démarche créée → notification aux agents',
			'demarche_statut_change_citoyen' => 'Statut de la démarche modifié → notification au citoyen',
			'demarche_message_agents'        => 'Message d\'un citoyen sur une démarche → notification aux agents',
			'demarche_message_citoyen'       => 'Message d\'un agent sur une démarche → notification au citoyen',
			'rdv_creee_citoyen'              => 'Rendez-vous demandé → accusé de réception au citoyen',
			'rdv_creee_agents'               => 'Rendez-vous demandé → notification aux agents',
			'rdv_valide_citoyen'             => 'Rendez-vous validé → notification au citoyen',
			'rdv_refuse_citoyen'             => 'Rendez-vous refusé → notification au citoyen',
			'rdv_rappel_citoyen'             => 'Rappel la veille du rendez-vous',
			'agent_assignation'              => 'Demande assignée → notification à l\'agent',
		];
	}

	/**
	 * Version matricielle de notif_types() : une ligne par événement, une
	 * colonne par type de destinataire (Citoyen / Agents). Chaque cellule
	 * référence la clé de notif_types() correspondante, ou null si cette
	 * combinaison événement/destinataire n'existe pas.
	 */
	public static function notif_matrice_structure(): array {
		return [
			'Signalement créé'            => [ 'citoyen' => 'demande_creee_citoyen', 'agents' => 'demande_creee_agents' ],
			'Signalement — statut modifié' => [ 'citoyen' => 'demande_statut_change_citoyen', 'agents' => null ],
			'Signalement — message'        => [ 'citoyen' => 'demande_message_citoyen', 'agents' => null ],
			'Démarche créée'               => [ 'citoyen' => 'demarche_creee_citoyen', 'agents' => 'demarche_creee_agents' ],
			'Démarche — statut modifié'    => [ 'citoyen' => 'demarche_statut_change_citoyen', 'agents' => null ],
			'Démarche — message'           => [ 'citoyen' => 'demarche_message_citoyen', 'agents' => 'demarche_message_agents' ],
			'Rendez-vous demandé'          => [ 'citoyen' => 'rdv_creee_citoyen', 'agents' => 'rdv_creee_agents' ],
			'Rendez-vous validé'           => [ 'citoyen' => 'rdv_valide_citoyen', 'agents' => null ],
			'Rendez-vous refusé'           => [ 'citoyen' => 'rdv_refuse_citoyen', 'agents' => null ],
			'Rendez-vous — rappel'         => [ 'citoyen' => 'rdv_rappel_citoyen', 'agents' => null ],
			'Assignation d\'un dossier'    => [ 'citoyen' => null, 'agents' => 'agent_assignation' ],
		];
	}

	private static function notif_active( string $cle ): bool {
		$matrice = get_option( 'grc_notif_matrix', [] );
		return ! isset( $matrice[ $cle ] ) || ! empty( $matrice[ $cle ] );
	}

	/**
	 * Prénom/nom de l'agent WordPress actuellement connecté (celui qui
	 * déclenche l'action), pour signer les emails déclenchés manuellement
	 * (changement de statut, assignation...). Vide pour les emails générés
	 * automatiquement sans intervention d'un agent (ex: accusé de réception
	 * à la création, ou depuis une tâche cron).
	 */
	private static function balises_agent_courant(): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return [ 'agent_prenom' => '', 'agent_nom' => '' ];
		}
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return [ 'agent_prenom' => '', 'agent_nom' => '' ];
		}
		$prenom = $user->first_name;
		$nom    = $user->last_name;
		if ( ! $prenom && ! $nom ) {
			// À défaut de prénom/nom renseignés dans le profil WordPress,
			// on retombe sur le nom d'affichage en un seul bloc.
			$prenom = $user->display_name;
			$nom    = '';
		}
		return [ 'agent_prenom' => $prenom, 'agent_nom' => $nom ];
	}

	/**
	 * Sous-ensemble des types de notification pour lesquels un modèle
	 * personnalisé peut remplacer le texte par défaut (les emails "système"
	 * composés d'informations structurées, pas les échanges libres entre
	 * agent et citoyen).
	 */
	public static function notif_types_avec_modele(): array {
		$tous = self::notif_types();
		$cles = [
			'demande_creee_citoyen', 'demande_statut_change_citoyen',
			'demarche_creee_citoyen', 'demarche_statut_change_citoyen',
			'rdv_creee_citoyen', 'rdv_valide_citoyen', 'rdv_refuse_citoyen', 'rdv_rappel_citoyen',
		];
		return array_intersect_key( $tous, array_flip( $cles ) );
	}

	/**
	 * Si un modèle personnalisé est associé à ce type de notification, envoie
	 * l'email avec son contenu (balises résolues) et retourne true. Sinon,
	 * retourne false pour laisser l'appelant utiliser le texte par défaut.
	 */
	private static function envoyer_via_modele_personnalise( string $notif_type, string $email, array $balises ): bool {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';
		$modele = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE notif_type = %s", $notif_type ) );

		if ( ! $modele ) {
			return false;
		}

		$sujet = $modele->sujet ? GRC_Admin_Modeles::resolve_balises( $modele->sujet, $balises ) : '[Mairie de Berre-les-Alpes] Notification';
		$corps = GRC_Admin_Modeles::resolve_balises( $modele->contenu, $balises );

		wp_mail( $email, $sujet, $corps );
		return true;
	}

	/**
	 * Configure PHPMailer pour utiliser un serveur SMTP plutôt que la fonction
	 * mail() native de PHP — souvent absente, mal configurée ou bloquée sur
	 * les hébergements mutualisés, ce qui empêche silencieusement l'envoi de
	 * tout email (wp_mail() ne remonte pas toujours d'erreur visible).
	 */
	public static function configure_smtp( $phpmailer ) {
		if ( ! get_option( 'grc_smtp_enabled' ) ) {
			return;
		}

		$host       = get_option( 'grc_smtp_host', '' );
		$port       = (int) get_option( 'grc_smtp_port', 587 );
		$encryption = get_option( 'grc_smtp_encryption', 'tls' );
		$username   = get_option( 'grc_smtp_username', '' );
		$password_encrypted = get_option( 'grc_smtp_password', '' );
		$password   = $password_encrypted ? GRC_Encryption::decrypt( $password_encrypted ) : '';
		$from_email = get_option( 'grc_smtp_from_email', '' );
		$from_name  = get_option( 'grc_smtp_from_name', 'Mairie de Berre-les-Alpes' );

		if ( ! $host ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = $host;
		$phpmailer->Port       = $port;
		$phpmailer->SMTPAuth   = ! empty( $username );
		if ( $username ) {
			$phpmailer->Username = $username;
			$phpmailer->Password = $password;
		}
		if ( 'none' !== $encryption ) {
			$phpmailer->SMTPSecure = $encryption; // 'tls' ou 'ssl'.
		}
		if ( $from_email ) {
			$phpmailer->setFrom( $from_email, $from_name );
		}
	}

	/**
	 * Envoie un email de test pour vérifier la configuration (bouton "Envoyer
	 * un email de test" dans Réglages GRC → Email).
	 */
	public static function send_test_email( string $to ): bool {
		$sujet = '[Mairie de Berre-les-Alpes] Email de test GRC';
		$corps = "Ceci est un email de test envoyé depuis le plugin GRC Citoyenne.\n\nSi vous recevez cet email, la configuration d'envoi fonctionne correctement.\n\nEnvoyé le " . current_time( 'd/m/Y à H:i' ) . '.';
		return wp_mail( $to, $sujet, $corps );
	}

	public static function send_demande_created( int $demande_id, string $email, string $numero_suivi ) {
		if ( ! self::notif_active( 'demande_creee_citoyen' ) ) {
			return;
		}

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.titre, c.prenom, c.nom FROM {$demandes_table} d LEFT JOIN {$citoyens_table} c ON c.id = d.citoyen_id WHERE d.id = %d",
			$demande_id
		) );
		$balises = [
			'numero' => $numero_suivi,
			'titre'  => $row->titre ?? '',
			'prenom' => $row && $row->prenom ? GRC_Encryption::decrypt( $row->prenom ) : '',
			'nom'    => $row && $row->nom ? GRC_Encryption::decrypt( $row->nom ) : '',
			'date'   => date_i18n( 'd/m/Y' ),
			'recap'  => self::build_recap_demande( $demande_id ),
		];
		if ( self::envoyer_via_modele_personnalise( 'demande_creee_citoyen', $email, $balises ) ) {
			return;
		}

		$subject = sprintf( '[Mairie de Berre-les-Alpes] Votre signalement %s a bien été reçu', $numero_suivi );
		$body    = self::render_template( 'demande_created', [
			'numero_suivi' => $numero_suivi,
			'lien_suivi'   => home_url( '/suivi-demande/?numero=' . $numero_suivi ),
			'recap'        => self::build_recap_demande( $demande_id ),
		] );
		wp_mail( $email, $subject, $body );
	}

	public static function send_rdv_pending( string $email, string $debut, string $service_nom = '', string $motif = '', int $rdv_id = 0 ) {
		if ( ! self::notif_active( 'rdv_creee_citoyen' ) ) {
			return;
		}
		$date_formatee = date_i18n( 'l d F Y à H:i', strtotime( $debut ) );

		$recap  = "Récapitulatif de votre demande :\n";
		$recap .= '- Date et heure souhaitées : ' . $date_formatee . "\n";
		if ( $service_nom ) {
			$recap .= '- Service : ' . $service_nom . "\n";
		}
		if ( $motif ) {
			$recap .= '- Motif : ' . $motif . "\n";
		}

		$balises = self::balises_rdv( $rdv_id, $debut, $service_nom );
		$balises['recap'] = $recap;
		if ( self::envoyer_via_modele_personnalise( 'rdv_creee_citoyen', $email, $balises ) ) {
			return;
		}

		$subject = '[Mairie de Berre-les-Alpes] Votre demande de rendez-vous est enregistrée';
		$body = sprintf(
			"Bonjour,\n\nVotre demande de rendez-vous a bien été enregistrée et est en attente de validation par nos services.\n\n%s\nVous recevrez un email de confirmation ou d'information dès qu'elle aura été traitée.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$recap
		);
		wp_mail( $email, $subject, $body );
	}

	public static function send_rdv_validated( string $email, string $debut, int $rdv_id = 0 ) {
		if ( ! self::notif_active( 'rdv_valide_citoyen' ) ) {
			return;
		}
		$date_formatee = date_i18n( 'l d F Y à H:i', strtotime( $debut ) );

		$balises = self::balises_rdv( $rdv_id, $debut );
		if ( self::envoyer_via_modele_personnalise( 'rdv_valide_citoyen', $email, $balises ) ) {
			return;
		}

		$subject = '[Mairie de Berre-les-Alpes] Votre rendez-vous est confirmé';
		$body = sprintf(
			"Bonjour,\n\nVotre rendez-vous du %s a été validé et est confirmé.\n\nSi vous ne pouvez pas vous présenter, merci de l'annuler depuis votre espace citoyen afin de libérer le créneau pour un autre usager.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$date_formatee
		);
		wp_mail( $email, $subject, $body );
	}

	public static function send_rdv_refused( string $email, string $debut, bool $automatique = false, int $rdv_id = 0 ) {
		if ( ! self::notif_active( 'rdv_refuse_citoyen' ) ) {
			return;
		}
		$date_formatee = date_i18n( 'l d F Y à H:i', strtotime( $debut ) );
		$motif_texte = $automatique
			? "\n\nCette demande n'a pas été traitée dans le délai imparti et a été automatiquement annulée."
			: '';

		$balises = self::balises_rdv( $rdv_id, $debut );
		$balises['motif_refus'] = trim( $motif_texte );
		if ( self::envoyer_via_modele_personnalise( 'rdv_refuse_citoyen', $email, $balises ) ) {
			return;
		}

		$subject = '[Mairie de Berre-les-Alpes] Votre demande de rendez-vous n\'a pas pu être confirmée';
		$body = sprintf(
			"Bonjour,\n\nNous sommes au regret de vous informer que votre demande de rendez-vous du %s n'a pas pu être confirmée.%s\n\nVous pouvez soumettre une nouvelle demande sur un autre créneau depuis notre site.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$date_formatee,
			$motif_texte
		);
		wp_mail( $email, $subject, $body );
	}

	public static function send_rdv_reminder( string $email, string $debut, int $rdv_id = 0 ) {
		if ( ! self::notif_active( 'rdv_rappel_citoyen' ) ) {
			return;
		}
		$date_formatee = date_i18n( 'l d F Y à H:i', strtotime( $debut ) );

		$balises = self::balises_rdv( $rdv_id, $debut );
		if ( self::envoyer_via_modele_personnalise( 'rdv_rappel_citoyen', $email, $balises ) ) {
			return;
		}

		$subject = '[Mairie de Berre-les-Alpes] Rappel : rendez-vous demain';
		$body = sprintf(
			"Bonjour,\n\nPetit rappel : vous avez rendez-vous demain, %s.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$date_formatee
		);
		wp_mail( $email, $subject, $body );
	}

	/**
	 * Construit les balises disponibles pour les emails automatiques liés à
	 * un rendez-vous. Le prénom/nom du citoyen ne sont résolus que si
	 * $rdv_id est fourni (nécessite une consultation en base).
	 */
	private static function balises_rdv( int $rdv_id, string $debut, string $service_nom = '' ): array {
		$balises = array_merge( self::balises_agent_courant(), [
			'numero'  => '',
			'prenom'  => '',
			'nom'     => '',
			'service' => $service_nom,
			'date'    => date_i18n( 'l d F Y à H:i', strtotime( $debut ) ),
		] );

		if ( $rdv_id ) {
			global $wpdb;
			$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
			$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT r.numero_rdv, c.prenom, c.nom FROM {$rdv_table} r LEFT JOIN {$citoyens_table} c ON c.id = r.citoyen_id WHERE r.id = %d",
				$rdv_id
			) );
			if ( $row ) {
				$balises['numero'] = $row->numero_rdv;
				$balises['prenom'] = $row->prenom ? GRC_Encryption::decrypt( $row->prenom ) : '';
				$balises['nom']    = $row->nom ? GRC_Encryption::decrypt( $row->nom ) : '';
			}
		}

		return $balises;
	}
	public static function send_statut_change( int $demande_id, string $email, string $nouveau_statut ) {
		if ( ! self::notif_active( 'demande_statut_change_citoyen' ) ) {
			return;
		}

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.numero_suivi, d.titre, c.prenom, c.nom FROM {$demandes_table} d LEFT JOIN {$citoyens_table} c ON c.id = d.citoyen_id WHERE d.id = %d",
			$demande_id
		) );
		$labels_courts = [ 'nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'assigne' => 'Assigné', 'resolu' => 'Résolu', 'cloture' => 'Clôturé', 'reouvert' => 'Réouvert' ];
		$balises = array_merge( self::balises_agent_courant(), [
			'numero' => $row->numero_suivi ?? '',
			'titre'  => $row->titre ?? '',
			'prenom' => $row && $row->prenom ? GRC_Encryption::decrypt( $row->prenom ) : '',
			'nom'    => $row && $row->nom ? GRC_Encryption::decrypt( $row->nom ) : '',
			'statut' => $labels_courts[ $nouveau_statut ] ?? $nouveau_statut,
			'date'   => date_i18n( 'd/m/Y' ),
		] );
		if ( self::envoyer_via_modele_personnalise( 'demande_statut_change_citoyen', $email, $balises ) ) {
			return;
		}

		$labels = [
			'en_cours' => 'est en cours de traitement',
			'resolu'   => 'a été résolue',
			'cloture'  => 'a été clôturée',
		];
		$label = $labels[ $nouveau_statut ] ?? "a changé de statut ({$nouveau_statut})";

		$subject = '[Mairie de Berre-les-Alpes] Mise à jour de votre demande';
		$body    = sprintf( "Bonjour,\n\nVotre demande %s.\n\nCordialement,\nMairie de Berre-les-Alpes", $label );

		if ( 'resolu' === $nouveau_statut ) {
			$body .= "\n\nVotre avis nous intéresse : rendez-vous sur la page de suivi de vos demandes pour noter le traitement de ce signalement.";
		}

		wp_mail( $email, $subject, $body );
	}

	private static function build_recap_demande( int $demande_id ): string {
		global $wpdb;
		$demandes_table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$categories_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';
		$services_table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.titre, d.description, d.adresse_lieu, d.created_at, c.nom AS categorie_nom, s.nom AS service_nom
			 FROM {$demandes_table} d
			 LEFT JOIN {$categories_table} c ON c.id = d.categorie_id
			 LEFT JOIN {$services_table} s ON s.id = d.service_id
			 WHERE d.id = %d",
			$demande_id
		) );
		if ( ! $row ) {
			return '';
		}

		$lignes   = [];
		$lignes[] = 'Récapitulatif de votre signalement :';
		$lignes[] = '- Objet : ' . $row->titre;
		if ( $row->categorie_nom ) {
			$lignes[] = '- Catégorie : ' . $row->categorie_nom;
		}
		if ( $row->service_nom ) {
			$lignes[] = '- Service concerné : ' . $row->service_nom;
		}
		if ( $row->adresse_lieu ) {
			$lignes[] = '- Lieu : ' . $row->adresse_lieu;
		}
		$lignes[] = '- Date : ' . mysql2date( 'd/m/Y à H:i', $row->created_at );
		if ( $row->description ) {
			$lignes[] = '- Description : ' . wp_trim_words( $row->description, 40 );
		}

		return implode( "\n", $lignes );
	}

	private static function render_template( string $template, array $vars ): string {
		switch ( $template ) {
			case 'demande_created':
				return sprintf(
					"Bonjour,\n\nNous avons bien reçu votre signalement (référence %s).\n\n%s\n\nVous pouvez suivre son avancement à tout moment ici :\n%s\n\nCordialement,\nMairie de Berre-les-Alpes",
					$vars['numero_suivi'],
					$vars['recap'] ?? '',
					$vars['lien_suivi']
				);
			default:
				return '';
		}
	}

	/**
	 * Accusé de réception d'une démarche, avec récapitulatif des informations
	 * soumises (construit à partir des champs déclarés dans le type de démarche).
	 */
	public static function send_demarche_created( int $demarche_id, string $email, string $numero_dossier ) {
		if ( ! self::notif_active( 'demarche_creee_citoyen' ) ) {
			return;
		}

		$recap = self::build_recap_demarche( $demarche_id );
		$balises = self::balises_demarche( $demarche_id );
		if ( self::envoyer_via_modele_personnalise( 'demarche_creee_citoyen', $email, $balises ) ) {
			return;
		}

		$sujet = sprintf( '[Mairie de Berre-les-Alpes] Votre démarche %s a bien été reçue', $numero_dossier );
		$corps = sprintf(
			"Bonjour,\n\nNous avons bien reçu votre démarche (référence %s).\n\n%s\n\nVous pouvez suivre son avancement à tout moment depuis votre espace citoyen.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$numero_dossier,
			$recap
		);
		wp_mail( $email, $sujet, $corps );
	}

	/**
	 * Notifie le citoyen d'un changement de statut sur sa démarche (validée,
	 * rejetée, complément requis...), avec le récapitulatif du dossier.
	 */
	public static function send_demarche_statut_change( int $demarche_id, string $email, string $nouveau_statut ) {
		if ( ! self::notif_active( 'demarche_statut_change_citoyen' ) ) {
			return;
		}

		$labels = [
			'en_cours'           => 'est en cours de traitement',
			'valide'             => 'a été validée',
			'rejete'             => 'a été rejetée',
			'complement_requis'  => 'nécessite un complément d\'information de votre part',
		];
		$label = $labels[ $nouveau_statut ] ?? "a changé de statut ({$nouveau_statut})";

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$numero_dossier = $wpdb->get_var( $wpdb->prepare( "SELECT numero_dossier FROM {$table} WHERE id = %d", $demarche_id ) );

		$recap = self::build_recap_demarche( $demarche_id );

		$balises = self::balises_demarche( $demarche_id );
		if ( self::envoyer_via_modele_personnalise( 'demarche_statut_change_citoyen', $email, $balises ) ) {
			return;
		}

		$sujet = sprintf( '[Mairie de Berre-les-Alpes] Mise à jour de votre démarche %s', $numero_dossier );
		$corps = sprintf( "Bonjour,\n\nVotre démarche (référence %s) %s.\n\n%s", $numero_dossier, $label, $recap );

		if ( 'complement_requis' === $nouveau_statut ) {
			$corps .= "\n\nMerci de consulter le détail depuis votre espace citoyen et de répondre au message de l'agent pour compléter votre dossier.";
		}
		$corps .= "\n\nCordialement,\nMairie de Berre-les-Alpes";

		wp_mail( $email, $sujet, $corps );
	}

	/**
	 * Construit les balises disponibles pour les emails automatiques liés à
	 * une démarche (numéro, prénom, nom, statut, date).
	 */
	private static function balises_demarche( int $demarche_id ): array {
		global $wpdb;
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$citoyens_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.numero_dossier, d.statut, c.prenom, c.nom FROM {$demarches_table} d
			 LEFT JOIN {$citoyens_table} c ON c.id = d.citoyen_id WHERE d.id = %d",
			$demarche_id
		) );
		$labels_courts = [ 'en_attente' => 'En attente', 'en_cours' => 'En cours', 'valide' => 'Validé', 'rejete' => 'Rejeté', 'complement_requis' => 'Complément requis' ];

		return array_merge( self::balises_agent_courant(), [
			'numero' => $row->numero_dossier ?? '',
			'prenom' => $row && $row->prenom ? GRC_Encryption::decrypt( $row->prenom ) : '',
			'nom'    => $row && $row->nom ? GRC_Encryption::decrypt( $row->nom ) : '',
			'statut' => $row ? ( $labels_courts[ $row->statut ] ?? $row->statut ) : '',
			'date'   => date_i18n( 'd/m/Y' ),
			'recap'  => self::build_recap_demarche( $demarche_id ),
		] );
	}

	/**
	 * Construit un récapitulatif texte lisible des informations soumises pour
	 * une démarche, à partir des libellés déclarés dans le type de démarche
	 * (plutôt que d'afficher les clés techniques du JSON brut).
	 */
	private static function build_recap_demarche( int $demarche_id ): string {
		global $wpdb;
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$types_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.donnees_json, d.created_at, t.nom AS type_nom, t.champs_json
			 FROM {$demarches_table} d
			 LEFT JOIN {$types_table} t ON t.slug = d.type_demarche
			 WHERE d.id = %d",
			$demarche_id
		) );
		if ( ! $row ) {
			return '';
		}

		$donnees = json_decode( $row->donnees_json ?: '{}', true ) ?: [];
		$champs  = json_decode( $row->champs_json ?: '[]', true ) ?: [];

		$labels_par_cle = [];
		foreach ( $champs as $champ ) {
			if ( ! empty( $champ['key'] ) ) {
				$labels_par_cle[ $champ['key'] ] = $champ['label'] ?? $champ['key'];
			}
		}

		$lignes   = [];
		$lignes[] = 'Type de démarche : ' . ( $row->type_nom ?: '—' );
		$lignes[] = 'Date de soumission : ' . mysql2date( 'd/m/Y à H:i', $row->created_at );
		$lignes[] = '';
		$lignes[] = 'Récapitulatif des informations transmises :';

		foreach ( $donnees as $cle => $valeur ) {
			if ( is_array( $valeur ) ) {
				$valeur = implode( ', ', $valeur );
			}
			$label = $labels_par_cle[ $cle ] ?? ucfirst( str_replace( '_', ' ', $cle ) );
			$lignes[] = '- ' . $label . ' : ' . $valeur;
		}

		return implode( "\n", $lignes );
	}

	public static function send_nouveau_message_demande( int $demande_id, string $email ) {
		if ( ! self::notif_active( 'demande_message_citoyen' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$numero_suivi = $wpdb->get_var( $wpdb->prepare( "SELECT numero_suivi FROM {$table} WHERE id = %d", $demande_id ) );

		$sujet = sprintf( '[Mairie de Berre-les-Alpes] Nouveau message sur votre signalement %s', $numero_suivi );
		$corps = sprintf(
			"Bonjour,\n\nUn agent a ajouté un message sur votre signalement (référence %s).\n\nConsultez le détail depuis votre espace citoyen.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$numero_suivi
		);
		wp_mail( $email, $sujet, $corps );
	}

	// ------------------------------------------------------------------
	// Notifications aux agents
	// ------------------------------------------------------------------

	/**
	 * Retourne la liste des adresses à notifier : la liste générale configurée
	 * dans Réglages, plus l'adresse de contact du service concerné si connue.
	 */
	private static function destinataires_agents( ?string $email_service = null ): array {
		$liste = get_option( 'grc_email_agents_notifications', '' );
		$emails = array_filter( array_map( 'trim', explode( ',', $liste ) ), function ( $e ) {
			return is_email( $e );
		} );
		if ( $email_service && is_email( $email_service ) ) {
			$emails[] = $email_service;
		}
		return array_unique( $emails );
	}

	public static function notify_agents_nouvelle_demande( int $demande_id ) {
		if ( ! self::notif_active( 'demande_creee_agents' ) ) {
			return;
		}
		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';

		$demande = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.numero_suivi, d.titre, s.email_contact FROM {$demandes_table} d
			 LEFT JOIN {$services_table} s ON s.id = d.service_id WHERE d.id = %d",
			$demande_id
		) );
		if ( ! $demande ) {
			return;
		}

		$destinataires = self::destinataires_agents( $demande->email_contact );
		if ( empty( $destinataires ) ) {
			return;
		}

		$sujet = sprintf( '[GRC] Nouveau signalement %s', $demande->numero_suivi );
		$corps = sprintf(
			"Un nouveau signalement a été déposé.\n\nRéférence : %s\nObjet : %s\n\nÀ traiter dans l'administration GRC :\n%s",
			$demande->numero_suivi,
			$demande->titre,
			admin_url( 'admin.php?page=grc-demandes&demande_id=' . $demande_id )
		);
		wp_mail( $destinataires, $sujet, $corps );
	}

	public static function notify_agents_nouvelle_demarche( int $demarche_id ) {
		if ( ! self::notif_active( 'demarche_creee_agents' ) ) {
			return;
		}
		global $wpdb;
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$types_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarche_types';

		$demarche = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.numero_dossier, t.nom AS type_nom FROM {$demarches_table} d
			 LEFT JOIN {$types_table} t ON t.slug = d.type_demarche WHERE d.id = %d",
			$demarche_id
		) );
		if ( ! $demarche ) {
			return;
		}

		$destinataires = self::destinataires_agents();
		if ( empty( $destinataires ) ) {
			return;
		}

		$sujet = sprintf( '[GRC] Nouvelle démarche %s', $demarche->numero_dossier );
		$corps = sprintf(
			"Une nouvelle démarche a été soumise.\n\nDossier : %s\nType : %s\n\nÀ traiter dans l'administration GRC :\n%s",
			$demarche->numero_dossier,
			$demarche->type_nom,
			admin_url( 'admin.php?page=grc-demarches&dossier_id=' . $demarche_id )
		);
		wp_mail( $destinataires, $sujet, $corps );
	}

	public static function notify_agents_nouveau_rdv( int $rdv_id ) {
		if ( ! self::notif_active( 'rdv_creee_agents' ) ) {
			return;
		}
		global $wpdb;
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$services_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';

		$rdv = $wpdb->get_row( $wpdb->prepare(
			"SELECT r.numero_rdv, c.debut, s.nom AS service_nom, s.email_contact FROM {$rdv_table} r
			 LEFT JOIN {$creneaux_table} c ON c.id = r.creneau_id
			 LEFT JOIN {$services_table} s ON s.id = r.service_id
			 WHERE r.id = %d",
			$rdv_id
		) );
		if ( ! $rdv ) {
			return;
		}

		$destinataires = self::destinataires_agents( $rdv->email_contact );
		if ( empty( $destinataires ) ) {
			return;
		}

		$sujet = sprintf( '[GRC] Nouvelle demande de rendez-vous %s', $rdv->numero_rdv );
		$corps = sprintf(
			"Une demande de rendez-vous est en attente de validation.\n\nRéférence : %s\nService : %s\nDate souhaitée : %s\n\nÀ valider dans l'administration GRC :\n%s",
			$rdv->numero_rdv,
			$rdv->service_nom ?: '—',
			$rdv->debut ? date_i18n( 'd/m/Y à H:i', strtotime( $rdv->debut ) ) : '—',
			admin_url( 'admin.php?page=grc-rdv' )
		);
		wp_mail( $destinataires, $sujet, $corps );
	}

	/**
	 * Notifie l'agent assigné (à son adresse email WordPress) qu'une demande
	 * lui a été confiée.
	 */
	public static function notify_agent_assignation( int $demande_id, int $agent_wp_user_id ) {
		if ( ! self::notif_active( 'agent_assignation' ) ) {
			return;
		}
		$user = get_userdata( $agent_wp_user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$numero_suivi = $wpdb->get_var( $wpdb->prepare( "SELECT numero_suivi FROM {$demandes_table} WHERE id = %d", $demande_id ) );

		$sujet = sprintf( '[GRC] Demande %s vous a été assignée', $numero_suivi );
		$corps = sprintf(
			"Bonjour %s,\n\nLa demande %s vous a été assignée.\n\nAccéder au dossier :\n%s",
			$user->display_name,
			$numero_suivi,
			admin_url( 'admin.php?page=grc-demandes&demande_id=' . $demande_id )
		);
		wp_mail( $user->user_email, $sujet, $corps );
	}

	/**
	 * Notifie les agents (liste générale) qu'un citoyen a répondu sur un
	 * dossier de démarche, afin qu'un agent puisse relancer le traitement.
	 */
	public static function notify_agents_nouveau_message_demarche( int $demarche_id ) {
		if ( ! self::notif_active( 'demarche_message_agents' ) ) {
			return;
		}
		global $wpdb;
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$numero_dossier = $wpdb->get_var( $wpdb->prepare( "SELECT numero_dossier FROM {$demarches_table} WHERE id = %d", $demarche_id ) );

		$destinataires = self::destinataires_agents();
		if ( empty( $destinataires ) ) {
			return;
		}

		$sujet = sprintf( '[GRC] Nouveau message du citoyen — dossier %s', $numero_dossier );
		$corps = sprintf(
			"Le citoyen a ajouté un message sur son dossier de démarche.\n\nDossier : %s\n\nVoir l'échange :\n%s",
			$numero_dossier,
			admin_url( 'admin.php?page=grc-demarches&dossier_id=' . $demarche_id )
		);
		wp_mail( $destinataires, $sujet, $corps );
	}

	/**
	 * Notifie le citoyen (à l'adresse associée à son dossier) qu'un agent a
	 * répondu sur son dossier de démarche.
	 */
	public static function notify_citoyen_nouveau_message_demarche( int $demarche_id ) {
		if ( ! self::notif_active( 'demarche_message_citoyen' ) ) {
			return;
		}
		global $wpdb;
		$demarches_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$citoyens_table  = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.numero_dossier, c.email FROM {$demarches_table} d
			 LEFT JOIN {$citoyens_table} c ON c.id = d.citoyen_id
			 WHERE d.id = %d",
			$demarche_id
		) );
		if ( ! $row || ! $row->email ) {
			return;
		}

		$email = GRC_Encryption::decrypt( $row->email );
		if ( ! $email ) {
			return;
		}

		$sujet = sprintf( '[Mairie de Berre-les-Alpes] Nouvelle réponse sur votre dossier %s', $row->numero_dossier );
		$corps = sprintf(
			"Bonjour,\n\nUn agent a répondu sur votre dossier de démarche (référence %s).\n\nConsultez la réponse depuis votre espace citoyen.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$row->numero_dossier
		);
		wp_mail( $email, $sujet, $corps );
	}
}
