<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rôles WordPress custom pour la GRC + capacités associées.
 */
class GRC_Roles {

	public static function init() {
		register_activation_hook( GRC_PLUGIN_FILE, [ __CLASS__, 'register_roles' ] );

		// S'assure que les rôles existent même après mise à jour (pas seulement activation).
		if ( ! get_role( 'grc_agent' ) ) {
			self::register_roles();
		}
	}

	public static function register_roles() {
		add_role( 'grc_agent', 'Agent GRC', [
			'read'                 => true,
			'grc_view_own_service' => true,
			'grc_manage_demandes'  => true,
		] );

		add_role( 'grc_responsable', 'Responsable de service GRC', [
			'read'                  => true,
			'grc_view_own_service'  => true,
			'grc_manage_demandes'   => true,
			'grc_assign_demandes'   => true,
			'grc_view_stats'        => true,
		] );

		add_role( 'grc_elu', 'Élu GRC', [
			'read'           => true,
			'grc_view_all'   => true,
			'grc_view_stats' => true,
		] );

		// L'administrateur WP obtient toutes les capacités GRC.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			foreach ( [ 'grc_view_all', 'grc_manage_demandes', 'grc_assign_demandes', 'grc_view_stats', 'grc_manage_settings' ] as $cap ) {
				$admin->add_cap( $cap );
			}
		}
	}

	/**
	 * Vérifie si l'utilisateur courant (ou donné) peut agir sur une demande d'un service donné.
	 */
	public static function can_manage_service( int $service_id, ?int $user_id = null ): bool {
		$user_id = $user_id ?: get_current_user_id();
		if ( user_can( $user_id, 'grc_view_all' ) ) {
			return true;
		}
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'agents';
		$agent_service = $wpdb->get_var( $wpdb->prepare(
			"SELECT service_id FROM {$table} WHERE wp_user_id = %d AND actif = 1",
			$user_id
		) );
		return $agent_service && (int) $agent_service === $service_id;
	}
}
