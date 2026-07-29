<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consultation du journal d'audit (traçabilité RGPD) : qui a fait/consulté quoi,
 * et quand — couvre aussi bien les actions des agents que celles des citoyens.
 */
class GRC_Admin_Audit {

	public static function render() {
		if ( ! current_user_can( 'grc_view_all' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé (réservé aux élus/administrateurs).</p></div>';
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'audit_log';
		$users_table = $wpdb->users;

		$filtre_action = sanitize_text_field( wp_unslash( $_GET['action_filtre'] ?? '' ) );
		$filtre_objet  = sanitize_text_field( wp_unslash( $_GET['objet_type'] ?? '' ) );
		$date_from     = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) );
		$date_to       = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) );
		$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );
		$per_page      = 50;

		$where  = [ '1=1' ];
		$params = [];

		if ( $filtre_action ) {
			$where[]  = 'a.action = %s';
			$params[] = $filtre_action;
		}
		if ( $filtre_objet ) {
			$where[]  = 'a.objet_type = %s';
			$params[] = $filtre_objet;
		}
		if ( $date_from ) {
			$where[]  = 'a.created_at >= %s';
			$params[] = $date_from . ' 00:00:00';
		}
		if ( $date_to ) {
			$where[]  = 'a.created_at <= %s';
			$params[] = $date_to . ' 23:59:59';
		}
		$where_sql = implode( ' AND ', $where );

		$total_sql = "SELECT COUNT(*) FROM {$table} a WHERE {$where_sql}";
		$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $total_sql, $params ) ) : $wpdb->get_var( $total_sql ) );

		$offset   = ( $paged - 1 ) * $per_page;
		$list_sql = "SELECT a.*, u.display_name FROM {$table} a
			LEFT JOIN {$users_table} u ON u.ID = a.wp_user_id
			WHERE {$where_sql}
			ORDER BY a.created_at DESC
			LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, [ $per_page, $offset ] ) ) );

		$actions = $wpdb->get_col( "SELECT DISTINCT action FROM {$table} ORDER BY action" );
		$objets  = $wpdb->get_col( "SELECT DISTINCT objet_type FROM {$table} WHERE objet_type IS NOT NULL ORDER BY objet_type" );

		?>
		<div class="wrap">
			<h1>Journal d'audit</h1>
			<p class="description">Historique de toutes les actions et consultations sensibles, agents comme citoyens : connexions, créations, modifications, changements de statut, archivages, téléchargements de documents, etc.</p>

			<form method="get" style="margin:16px 0;display:flex;gap:10px;flex-wrap:wrap;align-items:center;">
				<input type="hidden" name="page" value="grc-audit">

				<select name="action_filtre">
					<option value="">Toutes les actions</option>
					<?php foreach ( $actions as $a ) : ?>
						<option value="<?php echo esc_attr( $a ); ?>" <?php selected( $filtre_action, $a ); ?>><?php echo esc_html( $a ); ?></option>
					<?php endforeach; ?>
				</select>

				<select name="objet_type">
					<option value="">Tous les objets</option>
					<?php foreach ( $objets as $o ) : ?>
						<option value="<?php echo esc_attr( $o ); ?>" <?php selected( $filtre_objet, $o ); ?>><?php echo esc_html( $o ); ?></option>
					<?php endforeach; ?>
				</select>

				<label>Du <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"></label>
				<label>Au <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"></label>

				<button type="submit" class="button">Filtrer</button>
				<?php if ( $filtre_action || $filtre_objet || $date_from || $date_to ) : ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=grc-audit' ) ); ?>" class="button">Réinitialiser</a>
				<?php endif; ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead><tr><th style="width:150px;">Date</th><th>Action</th><th>Objet</th><th>Citoyen</th><th>Auteur</th><th>IP</th><th>Détails</th></tr></thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="7">Aucune entrée trouvée.</td></tr>
					<?php endif; ?>
					<?php foreach ( $rows as $row ) : ?>
						<?php
						$details = $row->details_json ? json_decode( $row->details_json, true ) : null;
						$objet_link  = self::format_objet_link( $row->objet_type, (int) $row->objet_id, $details );
						$citoyen_link = self::format_citoyen_link( $details );
						$auteur = $row->display_name ?: ( $row->wp_user_id ? 'Utilisateur #' . $row->wp_user_id : 'Citoyen / invité' );
						?>
						<tr>
							<td><?php echo esc_html( mysql2date( 'd/m/Y H:i:s', $row->created_at ) ); ?></td>
							<td><code><?php echo esc_html( $row->action ); ?></code></td>
							<td><?php echo $objet_link; // déjà échappé dans le helper ?></td>
							<td><?php echo $citoyen_link; // déjà échappé dans le helper ?></td>
							<td><?php echo esc_html( $auteur ); ?></td>
							<td><code style="font-size:11px;"><?php echo esc_html( $row->ip_address ?: '—' ); ?></code></td>
							<td><?php echo self::format_details( $details ); // déjà échappé dans le helper ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php
			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) :
				?>
				<div class="tablenav"><div class="tablenav-pages">
					<?php
					echo paginate_links( [
						'base'      => add_query_arg( 'paged', '%#%' ),
						'format'    => '',
						'prev_text' => '«',
						'next_text' => '»',
						'total'     => $total_pages,
						'current'   => $paged,
					] );
					?>
				</div></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Résout un objet audité (demande, démarche, rdv, citoyen...) en un lien
	 * lisible vers sa fiche/détail, plutôt que d'afficher un ID technique brut.
	 */
	private static function format_objet_link( ?string $type, int $id, ?array $details ): string {
		if ( ! $type || ! $id ) {
			return '—';
		}
		global $wpdb;

		switch ( $type ) {
			case 'demande':
				$numero = $details['numero_suivi'] ?? $wpdb->get_var( $wpdb->prepare(
					"SELECT numero_suivi FROM {$wpdb->prefix}" . GRC_TABLE_PREFIX . "demandes WHERE id = %d", $id
				) );
				$url = admin_url( 'admin.php?page=grc-demandes&demande_id=' . $id );
				return '<a href="' . esc_url( $url ) . '">' . esc_html( $numero ?: ( 'demande #' . $id ) ) . '</a>';

			case 'demarche':
				$numero = $details['numero_dossier'] ?? $wpdb->get_var( $wpdb->prepare(
					"SELECT numero_dossier FROM {$wpdb->prefix}" . GRC_TABLE_PREFIX . "demarches WHERE id = %d", $id
				) );
				$url = admin_url( 'admin.php?page=grc-demarches&dossier_id=' . $id );
				return '<a href="' . esc_url( $url ) . '">' . esc_html( $numero ?: ( 'démarche #' . $id ) ) . '</a>';

			case 'rdv':
				$numero = $details['numero_rdv'] ?? $wpdb->get_var( $wpdb->prepare(
					"SELECT numero_rdv FROM {$wpdb->prefix}" . GRC_TABLE_PREFIX . "rdv WHERE id = %d", $id
				) );
				return '<code>' . esc_html( $numero ?: ( 'rdv #' . $id ) ) . '</code>';

			case 'citoyen':
				$url = admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $id );
				return '<a href="' . esc_url( $url ) . '">' . esc_html( GRC_Citoyen_Helper::numero( $id ) ) . '</a>';

			default:
				return esc_html( $type . ' #' . $id );
		}
	}

	/**
	 * Affiche un lien vers la fiche du citoyen concerné par l'action, si le
	 * détail enregistré contient un citoyen_id.
	 */
	private static function format_citoyen_link( ?array $details ): string {
		if ( empty( $details['citoyen_id'] ) ) {
			return '—';
		}
		$id  = (int) $details['citoyen_id'];
		$url = admin_url( 'admin.php?page=grc-citoyens&citoyen_id=' . $id );
		return '<a href="' . esc_url( $url ) . '">' . esc_html( GRC_Citoyen_Helper::numero( $id ) ) . '</a>';
	}

	/**
	 * Formate les détails enregistrés (tableau associatif) en liste lisible
	 * "clé : valeur" plutôt qu'un bloc JSON brut, en excluant les clés déjà
	 * affichées ailleurs (numero_suivi, numero_dossier, numero_rdv, citoyen_id).
	 */
	private static function format_details( ?array $details ): string {
		if ( ! $details ) {
			return '—';
		}

		$labels = [
			'ancien_statut'          => 'Ancien statut',
			'nouveau_statut'         => 'Nouveau statut',
			'agent_nom'              => 'Agent',
			'agent_id'               => 'ID agent',
			'service_id'             => 'ID service',
			'note'                   => 'Note',
			'reason'                 => 'Raison',
			'fichier'                => 'Fichier',
			'attachment_id'          => 'ID pièce jointe',
			'interne'                => 'Note interne',
			'auteur_type'            => 'Auteur',
			'type'                   => 'Type',
			'nombre'                 => 'Nombre',
			'date_debut'             => 'Du',
			'date_fin'               => 'Au',
			'delai_validation_heures'=> 'Délai (h)',
			'automatique'            => 'Automatique',
			'avec_commentaire'       => 'Avec commentaire',
			'username'               => 'Identifiant saisi',
		];

		$masquer = [ 'numero_suivi', 'numero_dossier', 'numero_rdv', 'citoyen_id' ];

		$lignes = [];
		foreach ( $details as $cle => $valeur ) {
			if ( in_array( $cle, $masquer, true ) || null === $valeur || '' === $valeur ) {
				continue;
			}
			if ( is_bool( $valeur ) ) {
				$valeur = $valeur ? 'Oui' : 'Non';
			}
			$label = $labels[ $cle ] ?? ucfirst( str_replace( '_', ' ', $cle ) );
			$lignes[] = '<strong>' . esc_html( $label ) . '</strong> : ' . esc_html( (string) $valeur );
		}

		if ( empty( $lignes ) ) {
			return '—';
		}

		return '<span style="font-size:12px;">' . implode( '<br>', $lignes ) . '</span>';
	}
}
