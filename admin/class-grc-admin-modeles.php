<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Modèles de messages types (réponses pré-rédigées) que les agents peuvent
 * insérer en un clic lorsqu'ils répondent à un signalement ou une démarche —
 * accusé de réception, demande de complément, etc.
 */
class GRC_Admin_Modeles {

	public static function init() {
		add_action( 'admin_post_grc_save_modele', [ __CLASS__, 'handle_save' ] );
		add_action( 'admin_post_grc_delete_modele', [ __CLASS__, 'handle_delete' ] );
		add_action( 'admin_post_grc_creer_modele_defaut', [ __CLASS__, 'handle_creer_modele_defaut' ] );
		add_action( 'admin_post_grc_creer_tous_modeles_defaut', [ __CLASS__, 'handle_creer_tous_modeles_defaut' ] );
	}

	/**
	 * Contenu par défaut proposé pour chaque email automatique personnalisable
	 * — reprend le texte actuellement intégré au plugin, réécrit avec les
	 * balises, pour que l'admin ait un point de départ prêt à l'emploi plutôt
	 * qu'une page blanche.
	 */
	private static function modele_par_defaut( string $notif_type ): ?array {
		$defauts = [
			'demande_creee_citoyen' => [
				'titre'   => 'Accusé de réception — Signalement',
				'sujet'   => 'Votre signalement {numero} a bien été reçu',
				'contenu' => "Bonjour {prenom},\n\nNous avons bien reçu votre signalement (référence {numero}).\n\n{recap}\n\nVous pouvez suivre son avancement à tout moment depuis votre espace citoyen.\n\nCordialement,\nMairie de Berre-les-Alpes",
			],
			'demande_statut_change_citoyen' => [
				'titre'   => 'Mise à jour de statut — Signalement',
				'sujet'   => 'Mise à jour de votre signalement {numero}',
				'contenu' => "Bonjour {prenom},\n\nVotre signalement (référence {numero}) est maintenant au statut : {statut}.\n\nCordialement,\nMairie de Berre-les-Alpes",
			],
			'demarche_creee_citoyen' => [
				'titre'   => 'Accusé de réception — Démarche',
				'sujet'   => 'Votre démarche {numero} a bien été reçue',
				'contenu' => "Bonjour {prenom},\n\nNous avons bien reçu votre démarche (référence {numero}).\n\n{recap}\n\nVous pouvez suivre son avancement à tout moment depuis votre espace citoyen.\n\nCordialement,\nMairie de Berre-les-Alpes",
			],
			'demarche_statut_change_citoyen' => [
				'titre'   => 'Mise à jour de statut — Démarche',
				'sujet'   => 'Mise à jour de votre démarche {numero}',
				'contenu' => "Bonjour {prenom},\n\nVotre démarche (référence {numero}) est maintenant au statut : {statut}.\n\n{recap}\n\nCordialement,\nMairie de Berre-les-Alpes",
			],
			'rdv_creee_citoyen' => [
				'titre'   => 'Accusé de réception — Rendez-vous',
				'sujet'   => 'Votre demande de rendez-vous est enregistrée',
				'contenu' => "Bonjour {prenom},\n\nVotre demande de rendez-vous a bien été enregistrée et est en attente de validation par nos services.\n\n{recap}\n\nVous recevrez un email dès qu'elle aura été traitée.\n\nCordialement,\nMairie de Berre-les-Alpes",
			],
			'rdv_valide_citoyen' => [
				'titre'   => 'Confirmation — Rendez-vous validé',
				'sujet'   => 'Votre rendez-vous du {date} est confirmé',
				'contenu' => "Bonjour {prenom},\n\nVotre rendez-vous du {date} a été validé et est confirmé.\n\nSi vous ne pouvez pas vous présenter, merci de l'annuler depuis votre espace citoyen afin de libérer le créneau pour un autre usager.\n\nCordialement,\nMairie de Berre-les-Alpes",
			],
			'rdv_refuse_citoyen' => [
				'titre'   => 'Information — Rendez-vous non confirmé',
				'sujet'   => 'Votre demande de rendez-vous n\'a pas pu être confirmée',
				'contenu' => "Bonjour {prenom},\n\nNous sommes au regret de vous informer que votre demande de rendez-vous du {date} n'a pas pu être confirmée.\n\nVous pouvez soumettre une nouvelle demande sur un autre créneau depuis notre site.\n\nCordialement,\nMairie de Berre-les-Alpes",
			],
			'rdv_rappel_citoyen' => [
				'titre'   => 'Rappel — Rendez-vous le lendemain',
				'sujet'   => 'Rappel : rendez-vous demain',
				'contenu' => "Bonjour {prenom},\n\nPetit rappel : vous avez rendez-vous demain, {date}.\n\nCordialement,\nMairie de Berre-les-Alpes",
			],
		];

		return $defauts[ $notif_type ] ?? null;
	}

	public static function handle_creer_modele_defaut() {
		check_admin_referer( 'grc_creer_modele_defaut' );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$notif_type = sanitize_key( $_GET['notif_type'] ?? '' );
		self::inserer_modele_defaut_si_absent( $notif_type );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-modeles&grc_notice=saved' ) );
		exit;
	}

	public static function handle_creer_tous_modeles_defaut() {
		check_admin_referer( 'grc_creer_tous_modeles_defaut' );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		foreach ( array_keys( GRC_Notifications::notif_types_avec_modele() ) as $notif_type ) {
			self::inserer_modele_defaut_si_absent( $notif_type );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=grc-modeles&grc_notice=saved' ) );
		exit;
	}

	private static function inserer_modele_defaut_si_absent( string $notif_type ) {
		$defaut = self::modele_par_defaut( $notif_type );
		if ( ! $defaut ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';
		$existant = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE notif_type = %s", $notif_type ) );
		if ( $existant ) {
			return; // Un modèle est déjà associé à ce type : on ne l'écrase pas.
		}

		$contexte = ( 0 === strpos( $notif_type, 'demande' ) ) ? 'demande' : ( ( 0 === strpos( $notif_type, 'demarche' ) ) ? 'demarche' : 'tous' );

		$wpdb->insert( $table, [
			'titre'      => $defaut['titre'],
			'sujet'      => $defaut['sujet'],
			'contenu'    => $defaut['contenu'],
			'contexte'   => $contexte,
			'notif_type' => $notif_type,
			'ordre'      => 0,
		] );
		GRC_Audit_Log::log( 'modele_message_created', 'modele_message', (int) $wpdb->insert_id, [ 'notif_type' => $notif_type, 'genere_par_defaut' => true ] );
	}

	public static function render() {
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		$edit_id = isset( $_GET['modele_id'] ) ? absint( $_GET['modele_id'] ) : 0;

		if ( isset( $_GET['grc_notice'] ) ) {
			$messages = [
				'saved'   => [ 'success', 'Modèle enregistré.' ],
				'deleted' => [ 'success', 'Modèle supprimé.' ],
			];
			$code = sanitize_text_field( wp_unslash( $_GET['grc_notice'] ) );
			if ( isset( $messages[ $code ] ) ) {
				[ $type, $text ] = $messages[ $code ];
				printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';

		$modele_edite = $edit_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $edit_id ) ) : null;
		$modeles = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY ordre ASC, titre ASC" );

		$contexte_labels = [ 'tous' => 'Signalements et démarches', 'demande' => 'Signalements uniquement', 'demarche' => 'Démarches uniquement' ];

		$notif_deja_utilises = [];
		foreach ( $modeles as $m ) {
			if ( $m->notif_type ) {
				$notif_deja_utilises[ $m->notif_type ] = $m->id;
			}
		}

		?>
		<div class="wrap">
			<h1>Modèles de messages</h1>
			<p class="description">Réponses pré-rédigées que vous pouvez insérer en un clic lors d'un échange avec un citoyen (accusé de réception, demande de complément, information...).</p>

			<?php
			$notif_types_dispo = array_keys( GRC_Notifications::notif_types_avec_modele() );
			$manquants = array_diff( $notif_types_dispo, array_keys( $notif_deja_utilises ) );
			?>
			<?php if ( ! empty( $manquants ) ) : ?>
				<div class="notice notice-info" style="padding:12px 16px;">
					<p><strong><?php echo count( $manquants ); ?> email(s) automatique(s)</strong> n'ont pas encore de modèle personnalisé associé (ils utilisent le texte par défaut intégré au plugin).</p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_creer_tous_modeles_defaut' ), 'grc_creer_tous_modeles_defaut' ) ); ?>">Créer tous les modèles par défaut manquants</a>
					</p>
					<p class="description">Chaque modèle créé reprend le texte par défaut du plugin — vous pourrez ensuite le personnaliser librement depuis la liste ci-dessous.</p>
				</div>
			<?php endif; ?>

			<div style="display:flex;gap:24px;align-items:flex-start;margin-top:16px;">
				<div style="flex:1;max-width:480px;">
					<div class="card" style="padding:16px;">
						<h2><?php echo $modele_edite ? 'Modifier le modèle' : 'Nouveau modèle'; ?></h2>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="grc_save_modele">
							<input type="hidden" name="id" value="<?php echo esc_attr( $modele_edite->id ?? 0 ); ?>">
							<?php wp_nonce_field( 'grc_save_modele' ); ?>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Titre (repère interne)</label>
							<input type="text" name="titre" value="<?php echo esc_attr( $modele_edite->titre ?? '' ); ?>" required style="width:100%;margin-bottom:10px;" placeholder="Ex : Accusé de réception">

							<label style="display:block;font-weight:600;margin-bottom:4px;">Contexte</label>
							<select name="contexte" style="width:100%;margin-bottom:10px;">
								<?php foreach ( $contexte_labels as $val => $label ) : ?>
									<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $modele_edite->contexte ?? 'tous', $val ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Utiliser comme contenu d'un email automatique</label>
							<select name="notif_type" style="width:100%;margin-bottom:6px;">
								<option value="">— Aucun (insertion manuelle uniquement) —</option>
								<?php foreach ( GRC_Notifications::notif_types_avec_modele() as $cle => $label ) : ?>
									<?php $deja_pris = isset( $notif_deja_utilises[ $cle ] ) && $notif_deja_utilises[ $cle ] != ( $modele_edite->id ?? 0 ); ?>
									<option value="<?php echo esc_attr( $cle ); ?>" <?php selected( $modele_edite->notif_type ?? '', $cle ); ?>>
										<?php echo esc_html( $label ); ?><?php echo $deja_pris ? ' (remplacera le modèle actuellement associé)' : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description" style="margin-bottom:10px;">Si sélectionné, ce modèle remplace le texte par défaut de cet email automatique (un seul modèle par type). Sinon, ce modèle reste disponible pour insertion manuelle par les agents.</p>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Sujet de l'email (si utilisé comme email automatique)</label>
							<input type="text" name="sujet" value="<?php echo esc_attr( $modele_edite->sujet ?? '' ); ?>" style="width:100%;margin-bottom:10px;" placeholder="Ex : Votre signalement {numero} a bien été reçu">

							<label style="display:block;font-weight:600;margin-bottom:4px;">Contenu du message</label>
							<textarea name="contenu" rows="6" required style="width:100%;margin-bottom:6px;"><?php echo esc_textarea( $modele_edite->contenu ?? '' ); ?></textarea>
							<p class="description" style="margin-bottom:10px;">
								Balises disponibles, remplacées automatiquement par les informations du dossier au moment de l'insertion :
								<?php foreach ( self::balises_disponibles() as $balise => $desc ) : ?>
									<br><code><?php echo esc_html( $balise ); ?></code> — <?php echo esc_html( $desc ); ?>
								<?php endforeach; ?>
							</p>

							<label style="display:block;font-weight:600;margin-bottom:4px;">Ordre d'affichage</label>
							<input type="number" name="ordre" value="<?php echo esc_attr( $modele_edite->ordre ?? 0 ); ?>" style="width:100px;margin-bottom:14px;">

							<button type="submit" class="button button-primary"><?php echo $modele_edite ? 'Enregistrer les modifications' : 'Créer le modèle'; ?></button>
							<?php if ( $modele_edite ) : ?>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-modeles' ) ); ?>" class="button">Annuler</a>
							<?php endif; ?>
						</form>
					</div>
				</div>

				<div style="flex:2;">
					<table class="wp-list-table widefat fixed striped">
						<thead><tr><th>Titre</th><th>Contexte</th><th>Email automatique</th><th>Aperçu</th><th>Action</th></tr></thead>
						<tbody>
							<?php if ( empty( $modeles ) ) : ?>
								<tr><td colspan="5">Aucun modèle créé pour le moment.</td></tr>
							<?php endif; ?>
							<?php $notif_labels = GRC_Notifications::notif_types_avec_modele(); ?>
							<?php foreach ( $modeles as $m ) : ?>
								<tr>
									<td><?php echo esc_html( $m->titre ); ?></td>
									<td><?php echo esc_html( $contexte_labels[ $m->contexte ] ?? $m->contexte ); ?></td>
									<td><?php echo $m->notif_type ? '✅ ' . esc_html( $notif_labels[ $m->notif_type ] ?? $m->notif_type ) : '—'; ?></td>
									<td><?php echo esc_html( wp_trim_words( $m->contenu, 12 ) ); ?></td>
									<td style="white-space:nowrap;">
										<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=grc-modeles&modele_id=' . $m->id ) ); ?>">Modifier</a>
										<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_delete_modele&id=' . $m->id ), 'grc_delete_modele_' . $m->id ) ); ?>" onclick="return confirm('Supprimer ce modèle ?');">Suppr.</a>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	public static function handle_save() {
		check_admin_referer( 'grc_save_modele' );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$id       = absint( $_POST['id'] ?? 0 );
		$titre    = sanitize_text_field( wp_unslash( $_POST['titre'] ?? '' ) );
		$sujet    = sanitize_text_field( wp_unslash( $_POST['sujet'] ?? '' ) );
		$contenu  = sanitize_textarea_field( wp_unslash( $_POST['contenu'] ?? '' ) );
		$contexte = sanitize_key( $_POST['contexte'] ?? 'tous' );
		$ordre    = absint( $_POST['ordre'] ?? 0 );
		$notif_type = sanitize_key( $_POST['notif_type'] ?? '' );

		if ( $notif_type && ! array_key_exists( $notif_type, GRC_Notifications::notif_types_avec_modele() ) ) {
			$notif_type = '';
		}

		if ( ! in_array( $contexte, [ 'tous', 'demande', 'demarche' ], true ) ) {
			$contexte = 'tous';
		}

		if ( ! $titre || ! $contenu ) {
			wp_safe_redirect( admin_url( 'admin.php?page=grc-modeles' ) );
			exit;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';

		// Un seul modèle peut être associé à un type d'email automatique donné :
		// on retire l'association de tout autre modèle qui l'aurait actuellement.
		if ( $notif_type ) {
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET notif_type = NULL WHERE notif_type = %s AND id != %d",
				$notif_type,
				$id
			) );
		}

		$data = [ 'titre' => $titre, 'sujet' => $sujet ?: null, 'contenu' => $contenu, 'contexte' => $contexte, 'ordre' => $ordre, 'notif_type' => $notif_type ?: null ];

		if ( $id ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
			GRC_Audit_Log::log( 'modele_message_updated', 'modele_message', $id );
		} else {
			$wpdb->insert( $table, $data );
			GRC_Audit_Log::log( 'modele_message_created', 'modele_message', (int) $wpdb->insert_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=grc-modeles&grc_notice=saved' ) );
		exit;
	}

	public static function handle_delete() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_delete_modele_' . $id );
		if ( ! current_user_can( 'grc_manage_demandes' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages', [ 'id' => $id ] );
		GRC_Audit_Log::log( 'modele_message_deleted', 'modele_message', $id );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-modeles&grc_notice=deleted' ) );
		exit;
	}

	/**
	 * Retourne les modèles applicables à un contexte donné ('demande' ou
	 * 'demarche'), pour alimenter le sélecteur d'insertion rapide.
	 */
	/**
	 * Remplace les balises {xxx} d'un modèle par les valeurs réelles du dossier
	 * concerné (numéro, nom du citoyen, statut...). Les balises non fournies
	 * dans $donnees sont laissées telles quelles pour rester visibles.
	 */
	public static function resolve_balises( string $contenu, array $donnees ): string {
		$remplacements = [];
		foreach ( $donnees as $cle => $valeur ) {
			$remplacements[ '{' . $cle . '}' ] = (string) $valeur;
		}
		return strtr( $contenu, $remplacements );
	}

	/**
	 * Liste des balises disponibles, affichée en aide dans l'écran de gestion
	 * des modèles.
	 */
	public static function balises_disponibles(): array {
		return [
			'{numero}'       => 'Numéro de suivi (signalement) ou de dossier (démarche)',
			'{titre}'        => 'Objet du signalement (signalements uniquement)',
			'{prenom}'       => 'Prénom du citoyen',
			'{nom}'          => 'Nom du citoyen',
			'{statut}'       => 'Statut actuel',
			'{service}'      => 'Service concerné',
			'{date}'         => 'Date du jour',
			'{agent_prenom}' => 'Prénom de l\'agent connecté qui déclenche l\'action',
			'{agent_nom}'    => 'Nom de l\'agent connecté qui déclenche l\'action',
		];
	}

	public static function get_modeles_pour( string $contexte ): array {
		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'modeles_messages';
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT id, titre, contenu FROM {$table} WHERE contexte = 'tous' OR contexte = %s ORDER BY ordre ASC, titre ASC",
			$contexte
		) );
	}
}
