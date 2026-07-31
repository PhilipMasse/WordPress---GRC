<?php
/**
 * Plugin Name: Gestion de la Relation Citoyenne (GRC)
 * Plugin URI: https://github.com/PhilipMasse/WordPress---GRC
 * Description: Module de Gestion de la Relation Citoyenne pour la Mairie de Berre-les-Alpes : signalements, demandes, rendez-vous, démarches administratives, API REST pour application mobile.
 * Version: 0.23.0
 * Author: Mairie de Berre-les-Alpes
 * Text Domain: grc-citoyenne
 * Requires PHP: 8.1
 * Requires at least: 6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Accès direct interdit.
}

define( 'GRC_VERSION', '0.23.0' );
define( 'GRC_PLUGIN_FILE', __FILE__ );
define( 'GRC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GRC_TABLE_PREFIX', 'grc_' );

/**
 * Vérification de la clé de chiffrement obligatoire.
 * La clé DOIT être définie dans wp-config.php, jamais versionnée sur GitHub,
 * et surtout AVANT la ligne require_once(ABSPATH . 'wp-settings.php'); qui
 * charge les plugins. Sinon les constantes n'existent pas encore au moment
 * où le plugin s'initialise (hook plugins_loaded), même si elles apparaissent
 * définies plus tard dans la même requête (ex. sur les hooks admin_init/
 * admin_notices, qui s'exécutent après que wp-config.php ait fini de tourner).
 * C'est ce piège précis qui masquait silencieusement l'échec de chargement.
 *
 * Exemple à ajouter dans wp-config.php (avant "That's all, stop editing!") :
 * define( 'GRC_ENCRYPTION_KEY', 'clé-générée-en-base64-32-octets' );
 * define( 'GRC_JWT_SECRET', 'autre-clé-longue-aléatoire' );
 */
add_action( 'admin_notices', function () {
	$status = get_option( 'grc_init_status' );
	if ( 'ok' === $status || false === $status ) {
		return;
	}

	if ( 'missing_keys_but_defined_later' === $status ) {
		echo '<div class="notice notice-error"><p><strong>GRC :</strong> Les constantes <code>GRC_ENCRYPTION_KEY</code> et/ou <code>GRC_JWT_SECRET</code> sont bien définies dans <code>wp-config.php</code>, mais <u>trop tard</u> : elles apparaissent après la ligne <code>require_once(ABSPATH . \'wp-settings.php\');</code> qui charge les plugins. Déplacez ces deux lignes <strong>avant</strong> cette ligne (avant le commentaire "That\'s all, stop editing!"), puis rechargez cette page. Le plugin ne fonctionne pas tant que ce n\'est pas corrigé.</p></div>';
		return;
	}

	echo '<div class="notice notice-error"><p><strong>GRC :</strong> Les constantes <code>GRC_ENCRYPTION_KEY</code> et <code>GRC_JWT_SECRET</code> doivent être définies dans <code>wp-config.php</code> (avant la ligne <code>require_once wp-settings.php</code>) avant utilisation. Le plugin ne fonctionne pas tant que ces clés ne sont pas configurées.</p></div>';
} );

require_once GRC_PLUGIN_DIR . 'includes/class-grc-encryption.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-file-scanner.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-jwt.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-activator.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-audit-log.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-creneaux-generator.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-citoyen-helper.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-roles.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-rest-api.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-notifications.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-cron.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-frontend.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin-demandes.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin-citoyens.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin-audit.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin-stats.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin-services.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin-demarches.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin-rdv.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin.php';

register_activation_hook( __FILE__, [ 'GRC_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'GRC_Activator', 'deactivate' ] );

/**
 * Initialisation du plugin.
 */
function grc_init_plugin() {
	if ( ! defined( 'GRC_ENCRYPTION_KEY' ) || ! defined( 'GRC_JWT_SECRET' ) ) {
		// On enregistre l'échec MAINTENANT (au moment réel du chargement des plugins).
		// On planifie aussi une vérification tardive (admin_init, qui s'exécute après
		// que wp-config.php ait fini de tourner) pour distinguer :
		// - clés réellement absentes de wp-config.php
		// - clés présentes mais définies après require_once wp-settings.php (piège d'ordre)
		update_option( 'grc_init_status', 'missing_keys' );
		add_action( 'admin_init', function () {
			if ( defined( 'GRC_ENCRYPTION_KEY' ) && defined( 'GRC_JWT_SECRET' ) ) {
				update_option( 'grc_init_status', 'missing_keys_but_defined_later' );
			}
		}, 20 );
		return; // On ne charge rien tant que les clés ne sont pas en place à temps.
	}

	update_option( 'grc_init_status', 'ok' );

	// Filet de sécurité : si les tables n'existent pas ou si le schéma a changé
	// (ex. après une mise à jour automatique via GitHub, qui ne déclenche PAS
	// register_activation_hook), on les (re)crée ici. dbDelta() est idempotent :
	// aucune donnée existante n'est touchée si les tables sont déjà à jour.
	if ( get_option( 'grc_db_version' ) !== GRC_VERSION ) {
		GRC_Activator::maybe_upgrade_db();
	}

	GRC_Roles::init();
	GRC_REST_API::init();
	GRC_Notifications::init();
	GRC_Cron::init();
	GRC_Frontend::init();

	if ( is_admin() ) {
		GRC_Admin::init();
	}
}
add_action( 'plugins_loaded', 'grc_init_plugin' );

/**
 * Intégration Plugin Update Checker (auto-updates depuis GitHub Releases).
 * Même mécanisme que le plugin Simple Page Builder.
 */
require_once GRC_PLUGIN_DIR . 'includes/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$grcUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/PhilipMasse/WordPress---GRC/',
	__FILE__,
	'gestion-relation-citoyenne'
);
$grcUpdateChecker->getVcsApi()->enableReleaseAssets();
