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
	}

	public static function send_demande_created( int $demande_id, string $email, string $numero_suivi ) {
		$subject = sprintf( '[Mairie de Berre-les-Alpes] Votre signalement %s a bien été reçu', $numero_suivi );
		$body    = self::render_template( 'demande_created', [
			'numero_suivi' => $numero_suivi,
			'lien_suivi'   => home_url( '/suivi-demande/?numero=' . $numero_suivi ),
		] );
		wp_mail( $email, $subject, $body );
	}

	public static function send_statut_change( int $demande_id, string $email, string $nouveau_statut ) {
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

	private static function render_template( string $template, array $vars ): string {
		switch ( $template ) {
			case 'demande_created':
				return sprintf(
					"Bonjour,\n\nNous avons bien reçu votre signalement (référence %s).\n\nVous pouvez suivre son avancement à tout moment ici :\n%s\n\nCordialement,\nMairie de Berre-les-Alpes",
					$vars['numero_suivi'],
					$vars['lien_suivi']
				);
			default:
				return '';
		}
	}
}
