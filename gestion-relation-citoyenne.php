<?php
/**
 * Plugin Name: Gestion de la Relation Citoyenne (GRC)
 * Plugin URI: https://github.com/PhilipMasse/WordPress---GRC
 * Description: Module de Gestion de la Relation Citoyenne pour la Mairie de Berre-les-Alpes : signalements, demandes, rendez-vous, démarches administratives, API REST pour application mobile.
 * Version: 0.1.0
 * Author: Mairie de Berre-les-Alpes
 * Text Domain: grc-citoyenne
 * Requires PHP: 8.1
 * Requires at least: 6.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Accès direct interdit.
}

define( 'GRC_VERSION', '0.1.0' );
define( 'GRC_PLUGIN_FILE', __FILE__ );
define( 'GRC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GRC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GRC_TABLE_PREFIX', 'grc_' );

/**
 * Vérification de la clé de chiffrement obligatoire.
 * La clé DOIT être définie dans wp-config.php, jamais versionnée sur GitHub.
 * Exemple à ajouter dans wp-config.php :
 * define( 'GRC_ENCRYPTION_KEY', 'clé-générée-en-base64-32-octets' );
 * define( 'GRC_JWT_SECRET', 'autre-clé-longue-aléatoire' );
 */
add_action( 'admin_init', function () {
	if ( ! defined( 'GRC_ENCRYPTION_KEY' ) || ! defined( 'GRC_JWT_SECRET' ) ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>GRC :</strong> Les constantes <code>GRC_ENCRYPTION_KEY</code> et <code>GRC_JWT_SECRET</code> doivent être définies dans <code>wp-config.php</code> avant utilisation. Le plugin est désactivé tant que ces clés ne sont pas configurées.</p></div>';
		} );
	}
} );

require_once GRC_PLUGIN_DIR . 'includes/class-grc-encryption.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-jwt.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-activator.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-audit-log.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-roles.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-rest-api.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-notifications.php';
require_once GRC_PLUGIN_DIR . 'includes/class-grc-cron.php';
require_once GRC_PLUGIN_DIR . 'admin/class-grc-admin.php';

register_activation_hook( __FILE__, [ 'GRC_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'GRC_Activator', 'deactivate' ] );

/**
 * Initialisation du plugin.
 */
function grc_init_plugin() {
	if ( ! defined( 'GRC_ENCRYPTION_KEY' ) || ! defined( 'GRC_JWT_SECRET' ) ) {
		return; // On ne charge rien tant que les clés ne sont pas en place.
	}

	GRC_Roles::init();
	GRC_REST_API::init();
	GRC_Notifications::init();
	GRC_Cron::init();

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
