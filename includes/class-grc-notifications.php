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

	public static function send_rdv_pending( string $email, string $debut ) {
		$subject = '[Mairie de Berre-les-Alpes] Votre demande de rendez-vous est enregistrée';
		$date_formatee = date_i18n( 'l d F Y à H:i', strtotime( $debut ) );
		$body = sprintf(
			"Bonjour,\n\nVotre demande de rendez-vous pour le %s a bien été enregistrée et est en attente de validation par nos services.\n\nVous recevrez un email de confirmation ou d'information dès qu'elle aura été traitée.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$date_formatee
		);
		wp_mail( $email, $subject, $body );
	}

	public static function send_rdv_validated( string $email, string $debut ) {
		$subject = '[Mairie de Berre-les-Alpes] Votre rendez-vous est confirmé';
		$date_formatee = date_i18n( 'l d F Y à H:i', strtotime( $debut ) );
		$body = sprintf(
			"Bonjour,\n\nVotre rendez-vous du %s a été validé et est confirmé.\n\nSi vous ne pouvez pas vous présenter, merci de l'annuler depuis votre espace citoyen afin de libérer le créneau pour un autre usager.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$date_formatee
		);
		wp_mail( $email, $subject, $body );
	}

	public static function send_rdv_refused( string $email, string $debut, bool $automatique = false ) {
		$subject = '[Mairie de Berre-les-Alpes] Votre demande de rendez-vous n\'a pas pu être confirmée';
		$date_formatee = date_i18n( 'l d F Y à H:i', strtotime( $debut ) );
		$motif = $automatique
			? "\n\nCette demande n'a pas été traitée dans le délai imparti et a été automatiquement annulée."
			: '';
		$body = sprintf(
			"Bonjour,\n\nNous sommes au regret de vous informer que votre demande de rendez-vous du %s n'a pas pu être confirmée.%s\n\nVous pouvez soumettre une nouvelle demande sur un autre créneau depuis notre site.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$date_formatee,
			$motif
		);
		wp_mail( $email, $subject, $body );
	}

	public static function send_rdv_reminder( string $email, string $debut ) {
		$subject = '[Mairie de Berre-les-Alpes] Rappel : rendez-vous demain';
		$date_formatee = date_i18n( 'l d F Y à H:i', strtotime( $debut ) );
		$body = sprintf(
			"Bonjour,\n\nPetit rappel : vous avez rendez-vous demain, %s.\n\nCordialement,\nMairie de Berre-les-Alpes",
			$date_formatee
		);
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
