<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Journalisation des accès/modifications sur les données citoyennes (traçabilité RGPD).
 */
class GRC_Audit_Log {

	public static function log( string $action, ?string $objet_type = null, ?int $objet_id = null, array $details = [] ) {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'audit_log';

		$wpdb->insert( $table, [
			'wp_user_id'   => get_current_user_id() ?: null,
			'action'       => $action,
			'objet_type'   => $objet_type,
			'objet_id'     => $objet_id,
			'details_json' => ! empty( $details ) ? wp_json_encode( $details ) : null,
			'ip_address'   => self::get_client_ip(),
			'created_at'   => current_time( 'mysql' ),
		] );
	}

	private static function get_client_ip(): string {
		foreach ( [ 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) )[0];
				return trim( $ip );
			}
		}
		return '';
	}
}
