<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gestion des services (voirie, état civil...) et catégories/sous-catégories
 * (ex: Voirie > Nid de poule, Éclairage > Lampadaire cassé), avec SLA par catégorie.
 */
class GRC_Admin_Services {

	public static function init() {
		add_action( 'admin_post_grc_save_service', [ __CLASS__, 'handle_save_service' ] );
		add_action( 'admin_post_grc_delete_service', [ __CLASS__, 'handle_delete_service' ] );
		add_action( 'admin_post_grc_save_categorie', [ __CLASS__, 'handle_save_categorie' ] );
		add_action( 'admin_post_grc_delete_categorie', [ __CLASS__, 'handle_delete_categorie' ] );
	}

	public static function render() {
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		if ( isset( $_GET['grc_notice'] ) ) {
			self::render_notice( sanitize_text_field( wp_unslash( $_GET['grc_notice'] ) ) );
		}

		global $wpdb;
		$services_table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$categories_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';

		$services   = $wpdb->get_results( "SELECT * FROM {$services_table} ORDER BY nom" );
		$categories = $wpdb->get_results( "SELECT * FROM {$categories_table} ORDER BY ordre, nom" );

		?>
		<div class="wrap">
			<h1>Services & Catégories</h1>

			<div style="display:flex;gap:24px;align-items:flex-start;margin-top:20px;">

				<!-- ============ SERVICES ============ -->
				<div style="flex:1;">
					<h2>Services</h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr><th>Nom</th><th>Email contact</th><th>Actif</th><th>Action</th></tr>
						</thead>
						<tbody>
							<?php foreach ( $services as $s ) : ?>
							<tr>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="grc_save_service">
									<input type="hidden" name="id" value="<?php echo esc_attr( $s->id ); ?>">
									<?php wp_nonce_field( 'grc_save_service' ); ?>
									<td><input type="text" name="nom" value="<?php echo esc_attr( $s->nom ); ?>" style="width:100%;" required></td>
									<td><input type="email" name="email_contact" value="<?php echo esc_attr( $s->email_contact ); ?>" style="width:100%;"></td>
									<td><input type="checkbox" name="actif" value="1" <?php checked( $s->actif, 1 ); ?>></td>
									<td style="white-space:nowrap;">
										<button type="submit" class="button button-small">Enregistrer</button>
										<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_delete_service&id=' . $s->id ), 'grc_delete_service_' . $s->id ) ); ?>" onclick="return confirm('Supprimer ce service ? Les catégories liées perdront leur association.');">Suppr.</a>
									</td>
								</form>
							</tr>
							<?php endforeach; ?>
							<tr>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="grc_save_service">
									<?php wp_nonce_field( 'grc_save_service' ); ?>
									<td><input type="text" name="nom" placeholder="Nouveau service..." style="width:100%;" required></td>
									<td><input type="email" name="email_contact" placeholder="email@berrelesalpes.fr" style="width:100%;"></td>
									<td><input type="checkbox" name="actif" value="1" checked></td>
									<td><button type="submit" class="button button-primary button-small">Ajouter</button></td>
								</form>
							</tr>
						</tbody>
					</table>
				</div>

				<!-- ============ CATÉGORIES ============ -->
				<div style="flex:1;">
					<h2>Catégories</h2>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr><th>Nom</th><th>Service</th><th>SLA (h)</th><th>Ordre</th><th>Actif</th><th>Action</th></tr>
						</thead>
						<tbody>
							<?php foreach ( $categories as $c ) : ?>
							<tr>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="grc_save_categorie">
									<input type="hidden" name="id" value="<?php echo esc_attr( $c->id ); ?>">
									<?php wp_nonce_field( 'grc_save_categorie' ); ?>
									<td><input type="text" name="nom" value="<?php echo esc_attr( $c->nom ); ?>" style="width:100%;" required></td>
									<td>
										<select name="service_id" style="width:100%;">
											<option value="">—</option>
											<?php foreach ( $services as $s ) : ?>
												<option value="<?php echo esc_attr( $s->id ); ?>" <?php selected( (int) $c->service_id, (int) $s->id ); ?>><?php echo esc_html( $s->nom ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td><input type="number" name="sla_heures" value="<?php echo esc_attr( $c->sla_heures ); ?>" style="width:70px;" min="1"></td>
									<td><input type="number" name="ordre" value="<?php echo esc_attr( $c->ordre ); ?>" style="width:60px;" min="0"></td>
									<td><input type="checkbox" name="actif" value="1" <?php checked( $c->actif, 1 ); ?>></td>
									<td style="white-space:nowrap;">
										<button type="submit" class="button button-small">Enregistrer</button>
										<a class="button button-small" style="color:#b32d2e;" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_delete_categorie&id=' . $c->id ), 'grc_delete_categorie_' . $c->id ) ); ?>" onclick="return confirm('Supprimer cette catégorie ?');">Suppr.</a>
									</td>
								</form>
							</tr>
							<?php endforeach; ?>
							<tr>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="grc_save_categorie">
									<?php wp_nonce_field( 'grc_save_categorie' ); ?>
									<td><input type="text" name="nom" placeholder="Nouvelle catégorie..." style="width:100%;" required></td>
									<td>
										<select name="service_id" style="width:100%;">
											<option value="">—</option>
											<?php foreach ( $services as $s ) : ?>
												<option value="<?php echo esc_attr( $s->id ); ?>"><?php echo esc_html( $s->nom ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
									<td><input type="number" name="sla_heures" placeholder="72" style="width:70px;" min="1"></td>
									<td><input type="number" name="ordre" value="0" style="width:60px;" min="0"></td>
									<td><input type="checkbox" name="actif" value="1" checked></td>
									<td><button type="submit" class="button button-primary button-small">Ajouter</button></td>
								</form>
							</tr>
						</tbody>
					</table>
					<p class="description">Le SLA (Service Level Agreement) définit le délai en heures avant qu'une demande de cette catégorie soit signalée "en retard" si elle n'est pas résolue.</p>
				</div>
			</div>
		</div>
		<?php
	}

	private static function render_notice( string $code ) {
		$messages = [
			'service_saved'    => [ 'success', 'Service enregistré.' ],
			'service_deleted'  => [ 'success', 'Service supprimé.' ],
			'categorie_saved'  => [ 'success', 'Catégorie enregistrée.' ],
			'categorie_deleted'=> [ 'success', 'Catégorie supprimée.' ],
			'error'            => [ 'error', 'Une erreur est survenue.' ],
		];
		if ( isset( $messages[ $code ] ) ) {
			[ $type, $text ] = $messages[ $code ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}

	// ------------------------------------------------------------------

	public static function handle_save_service() {
		check_admin_referer( 'grc_save_service' );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';

		$id   = absint( $_POST['id'] ?? 0 );
		$data = [
			'nom'           => sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
			'email_contact' => sanitize_email( wp_unslash( $_POST['email_contact'] ?? '' ) ),
			'actif'         => ! empty( $_POST['actif'] ) ? 1 : 0,
		];

		if ( $id ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
		} else {
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}

		GRC_Audit_Log::log( 'service_saved', 'service', $id );
		wp_safe_redirect( admin_url( 'admin.php?page=grc-services&grc_notice=service_saved' ) );
		exit;
	}

	public static function handle_delete_service() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_delete_service_' . $id );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . GRC_TABLE_PREFIX . 'services', [ 'id' => $id ] );
		GRC_Audit_Log::log( 'service_deleted', 'service', $id );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-services&grc_notice=service_deleted' ) );
		exit;
	}

	public static function handle_save_categorie() {
		check_admin_referer( 'grc_save_categorie' );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';

		$id   = absint( $_POST['id'] ?? 0 );
		$data = [
			'nom'        => sanitize_text_field( wp_unslash( $_POST['nom'] ?? '' ) ),
			'service_id' => ! empty( $_POST['service_id'] ) ? absint( $_POST['service_id'] ) : null,
			'sla_heures' => ! empty( $_POST['sla_heures'] ) ? absint( $_POST['sla_heures'] ) : null,
			'ordre'      => absint( $_POST['ordre'] ?? 0 ),
			'actif'      => ! empty( $_POST['actif'] ) ? 1 : 0,
		];

		if ( $id ) {
			$wpdb->update( $table, $data, [ 'id' => $id ] );
		} else {
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}

		GRC_Audit_Log::log( 'categorie_saved', 'categorie', $id );
		wp_safe_redirect( admin_url( 'admin.php?page=grc-services&grc_notice=categorie_saved' ) );
		exit;
	}

	public static function handle_delete_categorie() {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'grc_delete_categorie_' . $id );
		if ( ! current_user_can( 'grc_manage_settings' ) ) {
			wp_die( 'Permission refusée.' );
		}

		global $wpdb;
		$wpdb->delete( $wpdb->prefix . GRC_TABLE_PREFIX . 'categories', [ 'id' => $id ] );
		GRC_Audit_Log::log( 'categorie_deleted', 'categorie', $id );

		wp_safe_redirect( admin_url( 'admin.php?page=grc-services&grc_notice=categorie_deleted' ) );
		exit;
	}
}
