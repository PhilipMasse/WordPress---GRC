<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endpoints REST pour la prise de rendez-vous.
 */
class GRC_REST_RDV {

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/rdv/creneaux', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'list_creneaux' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'service_id' => [ 'required' => true, 'type' => 'integer' ],
			],
		] );

		register_rest_route( $ns, '/rdv', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'book' ],
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		] );
	}

	public static function list_creneaux( WP_REST_Request $request ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE service_id = %d AND debut > %s AND reserve < capacite ORDER BY debut ASC LIMIT 50",
			absint( $request->get_param( 'service_id' ) ),
			current_time( 'mysql' )
		) );

		return array_map( function ( $c ) {
			return [
				'id'               => (int) $c->id,
				'debut'            => $c->debut,
				'fin'              => $c->fin,
				'places_restantes' => (int) $c->capacite - (int) $c->reserve,
			];
		}, $rows );
	}

	public static function book( WP_REST_Request $request ) {
		global $wpdb;
		$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
		$rdv_table      = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$creneau_id = absint( $request->get_param( 'creneau_id' ) );

		// Verrouillage optimiste : on ne réserve que si une place reste disponible.
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$creneaux_table} SET reserve = reserve + 1 WHERE id = %d AND reserve < capacite",
			$creneau_id
		) );

		if ( ! $updated ) {
			return new WP_Error( 'grc_creneau_full', 'Ce créneau n\'est plus disponible.', [ 'status' => 409 ] );
		}

		$citoyen_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$citoyens_table} WHERE wp_user_id = %d", get_current_user_id() ) );
		$creneau    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$creneaux_table} WHERE id = %d", $creneau_id ) );

		$wpdb->insert( $rdv_table, [
			'citoyen_id' => $citoyen_id,
			'service_id' => $creneau->service_id,
			'creneau_id' => $creneau_id,
			'motif'      => sanitize_text_field( $request->get_param( 'motif' ) ?? '' ),
			'statut'     => 'confirme',
			'created_at' => current_time( 'mysql' ),
		] );

		GRC_Audit_Log::log( 'rdv_created', 'rdv', (int) $wpdb->insert_id );

		return [ 'success' => true, 'id' => (int) $wpdb->insert_id ];
	}
}
