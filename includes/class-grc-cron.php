<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tâches planifiées : escalade des demandes en dépassement de SLA,
 * purge RGPD des données au-delà de la durée de conservation.
 */
class GRC_Cron {

	public static function init() {
		add_action( 'grc_daily_maintenance', [ __CLASS__, 'check_sla_escalation' ] );
		add_action( 'grc_daily_maintenance', [ __CLASS__, 'purge_rgpd_expired' ] );

		if ( ! wp_next_scheduled( 'grc_daily_maintenance' ) ) {
			wp_schedule_event( time(), 'daily', 'grc_daily_maintenance' );
		}
	}

	public static function check_sla_escalation() {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';

		$en_retard = $wpdb->get_results(
			"SELECT * FROM {$table}
			 WHERE date_limite_sla IS NOT NULL
			 AND date_limite_sla < NOW()
			 AND statut NOT IN ('resolu', 'cloture')"
		);

		foreach ( $en_retard as $demande ) {
			do_action( 'grc_sla_depasse', $demande->id );
			GRC_Audit_Log::log( 'sla_depasse', 'demande', $demande->id );
		}
	}

	/**
	 * Purge RGPD : anonymise les fiches citoyens liées à des demandes
	 * clôturées depuis plus de X années (configurable, 3 ans par défaut).
	 */
	public static function purge_rgpd_expired() {
		$retention_years = (int) get_option( 'grc_rgpd_retention_years', 3 );

		global $wpdb;
		$demandes_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$citoyens_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';

		$seuil = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_years} years" ) );

		$citoyens_a_anonymiser = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT c.id FROM {$citoyens_table} c
			 INNER JOIN {$demandes_table} d ON d.citoyen_id = c.id
			 WHERE d.closed_at IS NOT NULL AND d.closed_at < %s
			 AND c.nom != 'ANONYMISE'",
			$seuil
		) );

		foreach ( $citoyens_a_anonymiser as $citoyen_id ) {
			$wpdb->update( $citoyens_table, [
				'nom'            => 'ANONYMISE',
				'prenom'         => null,
				'email'          => null,
				'email_hash'     => null,
				'telephone'      => null,
				'telephone_hash' => null,
				'adresse'        => null,
			], [ 'id' => $citoyen_id ] );
			GRC_Audit_Log::log( 'rgpd_auto_anonymise', 'citoyen', (int) $citoyen_id );
		}
	}
}
