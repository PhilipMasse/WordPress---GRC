<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GRC_Activator {

	public static function activate() {
		self::create_or_upgrade_tables();
		update_option( 'grc_db_version', GRC_VERSION );
		update_option( 'grc_activated_at', current_time( 'mysql' ) );
		flush_rewrite_rules();
	}

	/**
	 * Filet de sécurité appelé à chaque chargement du plugin (plugins_loaded)
	 * quand la version de schéma enregistrée diffère de GRC_VERSION.
	 * Couvre le cas d'une mise à jour automatique (GitHub Releases) qui ne
	 * déclenche pas register_activation_hook.
	 */
	public static function maybe_upgrade_db() {
		self::create_or_upgrade_tables();
		update_option( 'grc_db_version', GRC_VERSION );
	}

	/**
	 * Crée les tables si elles n'existent pas, ou les met à jour si le schéma a changé.
	 * dbDelta() est idempotent : si une table existe déjà avec la bonne structure,
	 * rien n'est modifié et aucune donnée n'est perdue.
	 */
	private static function create_or_upgrade_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix . GRC_TABLE_PREFIX; // ex: wp_grc_

		$sql = [];

		// --- Citoyens ---------------------------------------------------
		$sql[] = "CREATE TABLE {$p}citoyens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT UNSIGNED NULL,
			nom TEXT NULL,
			prenom TEXT NULL,
			email TEXT NULL,
			email_hash CHAR(64) NULL,
			telephone TEXT NULL,
			telephone_hash CHAR(64) NULL,
			adresse TEXT NULL,
			password_hash VARCHAR(255) NULL,
			consentement_rgpd TINYINT(1) NOT NULL DEFAULT 0,
			consentement_date DATETIME NULL,
			is_guest TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY wp_user_id (wp_user_id),
			KEY email_hash (email_hash),
			KEY telephone_hash (telephone_hash)
		) $charset_collate;";

		// --- Services (voirie, état civil, urbanisme...) -----------------
		$sql[] = "CREATE TABLE {$p}services (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			nom VARCHAR(191) NOT NULL,
			description TEXT NULL,
			email_contact VARCHAR(191) NULL,
			actif TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id)
		) $charset_collate;";

		// --- Agents (liaison user WP <-> service) ------------------------
		$sql[] = "CREATE TABLE {$p}agents (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NULL,
			role VARCHAR(50) NOT NULL DEFAULT 'agent',
			actif TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY wp_user_id (wp_user_id),
			KEY service_id (service_id)
		) $charset_collate;";

		// --- Catégories / sous-catégories ---------------------------------
		$sql[] = "CREATE TABLE {$p}categories (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			parent_id BIGINT UNSIGNED NULL,
			nom VARCHAR(191) NOT NULL,
			service_id BIGINT UNSIGNED NULL,
			sla_heures INT UNSIGNED NULL,
			ordre INT UNSIGNED NOT NULL DEFAULT 0,
			actif TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			KEY parent_id (parent_id),
			KEY service_id (service_id)
		) $charset_collate;";

		// --- Demandes (le ticket principal) ------------------------------
		$sql[] = "CREATE TABLE {$p}demandes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			numero_suivi VARCHAR(20) NOT NULL,
			citoyen_id BIGINT UNSIGNED NULL,
			categorie_id BIGINT UNSIGNED NULL,
			service_id BIGINT UNSIGNED NULL,
			agent_assigne_id BIGINT UNSIGNED NULL,
			titre VARCHAR(191) NOT NULL,
			description LONGTEXT NULL,
			statut VARCHAR(30) NOT NULL DEFAULT 'nouveau',
			priorite VARCHAR(20) NOT NULL DEFAULT 'normale',
			latitude DECIMAL(10,7) NULL,
			longitude DECIMAL(10,7) NULL,
			adresse_lieu TEXT NULL,
			date_limite_sla DATETIME NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			resolved_at DATETIME NULL,
			closed_at DATETIME NULL,
			PRIMARY KEY (id),
			UNIQUE KEY numero_suivi (numero_suivi),
			KEY citoyen_id (citoyen_id),
			KEY categorie_id (categorie_id),
			KEY service_id (service_id),
			KEY agent_assigne_id (agent_assigne_id),
			KEY statut (statut)
		) $charset_collate;";

		// --- Messages (fil de discussion citoyen <-> agent) --------------
		$sql[] = "CREATE TABLE {$p}messages (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			demande_id BIGINT UNSIGNED NOT NULL,
			auteur_type VARCHAR(20) NOT NULL,
			auteur_id BIGINT UNSIGNED NULL,
			contenu LONGTEXT NULL,
			interne TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY demande_id (demande_id)
		) $charset_collate;";

		// --- Pièces jointes -------------------------------------------------
		$sql[] = "CREATE TABLE {$p}pieces_jointes (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			demande_id BIGINT UNSIGNED NULL,
			message_id BIGINT UNSIGNED NULL,
			chemin_fichier VARCHAR(500) NOT NULL,
			nom_original VARCHAR(255) NULL,
			mime_type VARCHAR(100) NULL,
			taille_octets BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY demande_id (demande_id),
			KEY message_id (message_id)
		) $charset_collate;";

		// --- Rendez-vous --------------------------------------------------
		$sql[] = "CREATE TABLE {$p}rdv (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			citoyen_id BIGINT UNSIGNED NULL,
			service_id BIGINT UNSIGNED NULL,
			creneau_id BIGINT UNSIGNED NULL,
			motif VARCHAR(191) NULL,
			statut VARCHAR(30) NOT NULL DEFAULT 'confirme',
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY citoyen_id (citoyen_id),
			KEY service_id (service_id),
			KEY creneau_id (creneau_id)
		) $charset_collate;";

		// --- Créneaux disponibles ------------------------------------------
		$sql[] = "CREATE TABLE {$p}creneaux (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			service_id BIGINT UNSIGNED NOT NULL,
			debut DATETIME NOT NULL,
			fin DATETIME NOT NULL,
			capacite INT UNSIGNED NOT NULL DEFAULT 1,
			reserve INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY (id),
			KEY service_id (service_id),
			KEY debut (debut)
		) $charset_collate;";

		// --- Types de démarches (définitions des champs par type) -----------
		$sql[] = "CREATE TABLE {$p}demarche_types (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			nom VARCHAR(191) NOT NULL,
			slug VARCHAR(100) NOT NULL,
			description TEXT NULL,
			champs_json LONGTEXT NULL,
			actif TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY slug (slug)
		) $charset_collate;";

		// --- Démarches administratives --------------------------------------
		$sql[] = "CREATE TABLE {$p}demarches (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			citoyen_id BIGINT UNSIGNED NULL,
			type_demarche VARCHAR(100) NOT NULL,
			statut VARCHAR(30) NOT NULL DEFAULT 'en_attente',
			donnees_json LONGTEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY citoyen_id (citoyen_id)
		) $charset_collate;";

		// --- Satisfaction ---------------------------------------------------
		$sql[] = "CREATE TABLE {$p}satisfaction (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			demande_id BIGINT UNSIGNED NOT NULL,
			note TINYINT UNSIGNED NULL,
			commentaire TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY demande_id (demande_id)
		) $charset_collate;";

		// --- Audit log (RGPD, traçabilité) -----------------------------------
		$sql[] = "CREATE TABLE {$p}audit_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT UNSIGNED NULL,
			action VARCHAR(100) NOT NULL,
			objet_type VARCHAR(50) NULL,
			objet_id BIGINT UNSIGNED NULL,
			details_json LONGTEXT NULL,
			ip_address VARCHAR(45) NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY objet (objet_type, objet_id),
			KEY created_at (created_at)
		) $charset_collate;";

		// --- Tokens API (refresh tokens app mobile) --------------------------
		$sql[] = "CREATE TABLE {$p}api_tokens (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wp_user_id BIGINT UNSIGNED NOT NULL,
			refresh_token_hash CHAR(64) NOT NULL,
			device_label VARCHAR(191) NULL,
			expires_at DATETIME NOT NULL,
			revoked TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY wp_user_id (wp_user_id),
			KEY refresh_token_hash (refresh_token_hash)
		) $charset_collate;";

		foreach ( $sql as $query ) {
			dbDelta( $query );
		}

		self::seed_default_data( $p );
	}

	/**
	 * Insère les services/catégories par défaut si la table est vide.
	 */
	private static function seed_default_data( string $p ) {
		global $wpdb;

		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}services" );
		if ( $count > 0 ) {
			return;
		}

		$services = [ 'Voirie', 'Éclairage public', 'Propreté', 'Espaces verts', 'Urbanisme', 'État civil' ];
		foreach ( $services as $nom ) {
			$wpdb->insert( "{$p}services", [ 'nom' => $nom ] );
		}
	}

	public static function deactivate() {
		// On ne supprime jamais les données à la désactivation (uniquement à la désinstallation explicite).
		flush_rewrite_rules();
	}
}
