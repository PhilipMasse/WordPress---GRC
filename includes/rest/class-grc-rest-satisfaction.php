<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enquête de satisfaction post-résolution d'une demande.
 * Autorisée uniquement pour une demande résolue/clôturée, une seule fois,
 * par son propriétaire (citoyen JWT) ou en mode invité (email correspondant).
 */
class GRC_REST_Satisfaction {

	public static function register_routes() {
		$ns = GRC_REST_API::NAMESPACE_V1;

		register_rest_route( $ns, '/demandes/(?P<id>\d+)/satisfaction', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'submit' ],
			'permission_callback' => '__return_true', // Autorisation vérifiée dans le callback.
		] );

		register_rest_route( $ns, '/satisfaction/stats', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'stats' ],
			'permission_callback' => function () {
				return current_user_can( 'grc_view_stats' ) || current_user_can( 'grc_view_all' );
			},
		] );
	}

	public static function submit( WP_REST_Request $request ) {
		if ( ! GRC_REST_API::check_rate_limit( 'satisfaction', 10, 3600 ) ) {
			return new WP_Error( 'grc_rate_limited', 'Trop de tentatives, réessayez plus tard.', [ 'status' => 429 ] );
		}

		$demande_id = absint( $request['id'] );
		$note       = absint( $request->get_param( 'note' ) );
		$commentaire = sanitize_textarea_field( $request->get_param( 'commentaire' ) ?? '' );

		if ( $note < 1 || $note > 5 ) {
			return new WP_Error( 'grc_invalid_note', 'La note doit être comprise entre 1 et 5.', [ 'status' => 400 ] );
		}

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$demande = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$demandes_table} WHERE id = %d", $demande_id ) );

		if ( ! $demande ) {
			return new WP_Error( 'grc_not_found', 'Demande introuvable.', [ 'status' => 404 ] );
		}
		if ( ! in_array( $demande->statut, [ 'resolu', 'cloture' ], true ) ) {
			return new WP_Error( 'grc_not_resolved', 'La demande doit être résolue avant de pouvoir être évaluée.', [ 'status' => 400 ] );
		}

		$authorized = self::authorize( $request, $demande );
		if ( is_wp_error( $authorized ) ) {
			return $authorized;
		}

		$satisfaction_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'satisfaction';
		$existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$satisfaction_table} WHERE demande_id = %d", $demande_id ) );
		if ( $existing ) {
			return new WP_Error( 'grc_already_rated', 'Cette demande a déjà été évaluée.', [ 'status' => 409 ] );
		}

		$wpdb->insert( $satisfaction_table, [
			'demande_id'  => $demande_id,
			'note'        => $note,
			'commentaire' => $commentaire,
			'created_at'  => current_time( 'mysql' ),
		] );

		GRC_Audit_Log::log( 'satisfaction_submitted', 'demande', $demande_id, [ 'note' => $note ] );

		return [ 'success' => true ];
	}

	public static function stats() {
		global $wpdb;
		$satisfaction_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'satisfaction';

		$row = $wpdb->get_row( "SELECT COUNT(*) as total, AVG(note) as moyenne FROM {$satisfaction_table}" );
		$repartition = $wpdb->get_results( "SELECT note, COUNT(*) as total FROM {$satisfaction_table} GROUP BY note ORDER BY note" );

		return [
			'total'        => (int) ( $row->total ?? 0 ),
			'moyenne'      => $row->moyenne ? round( (float) $row->moyenne, 2 ) : null,
			'repartition'  => array_map( function ( $r ) {
				return [ 'note' => (int) $r->note, 'total' => (int) $r->total ];
			}, $repartition ),
		];
	}

	private static function authorize( WP_REST_Request $request, $demande ) {
		$citoyen_id = GRC_REST_Citoyen::get_authenticated_citoyen_id( $request );
		if ( $citoyen_id && (int) $citoyen_id === (int) $demande->citoyen_id ) {
			return true;
		}

		$email = sanitize_email( $request->get_param( 'email' ) ?? '' );
		if ( $email && $demande->citoyen_id ) {
			global $wpdb;
			$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
			$hash = GRC_Encryption::search_hash( $email );
			$match = $wpdb->get_var( $wpdb->prepare(
				"SELECT id FROM {$citoyens_table} WHERE id = %d AND email_hash = %s",
				$demande->citoyen_id,
				$hash
			) );
			if ( $match ) {
				return true;
			}
		}

		return new WP_Error( 'grc_forbidden', 'Accès non autorisé à cette demande.', [ 'status' => 403 ] );
	}
}
