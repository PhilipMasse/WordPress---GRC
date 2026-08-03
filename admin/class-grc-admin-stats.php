<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Statistiques avancées : indicateurs clés, graphiques (répartitions et
 * évolution mensuelle), carte des signalements géolocalisés, exports CSV.
 * Utilise Chart.js et Leaflet via CDN (chargés uniquement sur cet écran).
 */
class GRC_Admin_Stats {

	public static function init() {
		add_action( 'admin_post_grc_export_csv', [ __CLASS__, 'handle_export_csv' ] );
	}

	public static function enqueue_assets( string $hook ) {
		$needs_leaflet = false !== strpos( $hook, 'grc-stats' ) || false !== strpos( $hook, 'grc-demandes' );
		if ( ! $needs_leaflet ) {
			return;
		}
		if ( false !== strpos( $hook, 'grc-stats' ) ) {
			wp_enqueue_script( 'grc-chartjs', 'https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js', [], '4.4.0', true );
		}
		wp_enqueue_script( 'grc-leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.js', [], '1.9.4', true );
		wp_enqueue_style( 'grc-leaflet', 'https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.css', [], '1.9.4' );
	}

	public static function render() {
		if ( ! current_user_can( 'grc_view_stats' ) && ! current_user_can( 'grc_view_all' ) ) {
			echo '<div class="wrap"><p>Accès non autorisé.</p></div>';
			return;
		}

		$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? gmdate( 'Y-m-d', strtotime( '-12 months' ) ) ) );
		$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? gmdate( 'Y-m-d' ) ) );

		global $wpdb;
		$demandes_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$demarches_table    = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
		$rdv_table          = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
		$categories_table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';
		$satisfaction_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'satisfaction';

		$bounds_debut = $date_from . ' 00:00:00';
		$bounds_fin   = $date_to . ' 23:59:59';

		// ---------------- KPIs ----------------
		$demandes_kpi = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) as total,
				SUM(CASE WHEN statut IN ('resolu','cloture') THEN 1 ELSE 0 END) as resolues,
				AVG(CASE WHEN resolved_at IS NOT NULL THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) ELSE NULL END) as delai_moyen_h
			 FROM {$demandes_table} WHERE created_at BETWEEN %s AND %s",
			$bounds_debut, $bounds_fin
		) );
		$taux_resolution = $demandes_kpi->total > 0 ? round( $demandes_kpi->resolues / $demandes_kpi->total * 100 ) : 0;
		$delai_moyen_jours = $demandes_kpi->delai_moyen_h ? round( $demandes_kpi->delai_moyen_h / 24, 1 ) : null;

		$demarches_kpi = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) as total, SUM(CASE WHEN statut = 'valide' THEN 1 ELSE 0 END) as validees
			 FROM {$demarches_table} WHERE created_at BETWEEN %s AND %s",
			$bounds_debut, $bounds_fin
		) );
		$taux_validation = $demarches_kpi->total > 0 ? round( $demarches_kpi->validees / $demarches_kpi->total * 100 ) : 0;

		$rdv_kpi = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) as total, SUM(CASE WHEN statut = 'confirme' THEN 1 ELSE 0 END) as confirmes
			 FROM {$rdv_table} WHERE created_at BETWEEN %s AND %s",
			$bounds_debut, $bounds_fin
		) );
		$taux_confirmation = $rdv_kpi->total > 0 ? round( $rdv_kpi->confirmes / $rdv_kpi->total * 100 ) : 0;

		$satisfaction_kpi = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) as total, AVG(note) as moyenne FROM {$satisfaction_table} WHERE created_at BETWEEN %s AND %s",
			$bounds_debut, $bounds_fin
		) );

		// ---------------- Données graphiques ----------------
		$demandes_par_statut = $wpdb->get_results( $wpdb->prepare(
			"SELECT statut, COUNT(*) as total FROM {$demandes_table} WHERE created_at BETWEEN %s AND %s GROUP BY statut",
			$bounds_debut, $bounds_fin
		) );

		$demandes_par_categorie = $wpdb->get_results( $wpdb->prepare(
			"SELECT c.nom, COUNT(*) as total FROM {$demandes_table} d
			 LEFT JOIN {$categories_table} c ON c.id = d.categorie_id
			 WHERE d.created_at BETWEEN %s AND %s
			 GROUP BY d.categorie_id ORDER BY total DESC LIMIT 10",
			$bounds_debut, $bounds_fin
		) );

		$evolution_creees = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(created_at, '%%Y-%%m') as mois, COUNT(*) as total
			 FROM {$demandes_table} WHERE created_at BETWEEN %s AND %s
			 GROUP BY mois ORDER BY mois ASC",
			$bounds_debut, $bounds_fin
		) );
		$evolution_resolues = $wpdb->get_results( $wpdb->prepare(
			"SELECT DATE_FORMAT(resolved_at, '%%Y-%%m') as mois, COUNT(*) as total
			 FROM {$demandes_table} WHERE resolved_at BETWEEN %s AND %s
			 GROUP BY mois ORDER BY mois ASC",
			$bounds_debut, $bounds_fin
		) );

		$demarches_par_statut = $wpdb->get_results( $wpdb->prepare(
			"SELECT statut, COUNT(*) as total FROM {$demarches_table} WHERE created_at BETWEEN %s AND %s GROUP BY statut",
			$bounds_debut, $bounds_fin
		) );

		$rdv_par_statut = $wpdb->get_results( $wpdb->prepare(
			"SELECT statut, COUNT(*) as total FROM {$rdv_table} WHERE created_at BETWEEN %s AND %s GROUP BY statut",
			$bounds_debut, $bounds_fin
		) );

		$satisfaction_repartition = $wpdb->get_results( $wpdb->prepare(
			"SELECT note, COUNT(*) as total FROM {$satisfaction_table} WHERE created_at BETWEEN %s AND %s GROUP BY note ORDER BY note",
			$bounds_debut, $bounds_fin
		) );

		// ---------------- Carte des signalements géolocalisés ----------------
		$demandes_geo = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, numero_suivi, titre, statut, latitude, longitude, adresse_lieu FROM {$demandes_table}
			 WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND created_at BETWEEN %s AND %s
			 LIMIT 500",
			$bounds_debut, $bounds_fin
		) );

		$statut_labels_demande = [
			'nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'assigne' => 'Assigné',
			'resolu' => 'Résolu', 'cloture' => 'Clôturé', 'reouvert' => 'Réouvert',
		];
		$statut_labels_demarche = [
			'en_attente' => 'En attente', 'en_cours' => 'En cours', 'valide' => 'Validé',
			'rejete' => 'Rejeté', 'complement_requis' => 'Complément requis',
		];
		$statut_labels_rdv = [
			'en_attente' => 'En attente', 'confirme' => 'Confirmé', 'refuse' => 'Refusé', 'annule' => 'Annulé',
		];
		$couleurs_statut = [
			'nouveau' => '#2D6AB0', 'en_cours' => '#8a6414', 'assigne' => '#8a6414',
			'resolu' => '#587526', 'cloture' => '#666', 'reouvert' => '#b32d2e',
			'en_attente' => '#2D6AB0', 'valide' => '#587526', 'confirme' => '#587526',
			'rejete' => '#b32d2e', 'refuse' => '#b32d2e', 'annule' => '#666', 'complement_requis' => '#8a6414',
		];

		$to_chart_data = function ( $rows, array $labels_map, array $couleurs ) {
			$data = [ 'labels' => [], 'values' => [], 'colors' => [] ];
			foreach ( $rows as $r ) {
				$data['labels'][] = $labels_map[ $r->statut ] ?? $r->statut;
				$data['values'][] = (int) $r->total;
				$data['colors'][] = $couleurs[ $r->statut ] ?? '#999';
			}
			return $data;
		};

		$chart_demandes_statut  = $to_chart_data( $demandes_par_statut, $statut_labels_demande, $couleurs_statut );
		$chart_demarches_statut = $to_chart_data( $demarches_par_statut, $statut_labels_demarche, $couleurs_statut );
		$chart_rdv_statut       = $to_chart_data( $rdv_par_statut, $statut_labels_rdv, $couleurs_statut );

		$chart_categories = [
			'labels' => array_map( function ( $r ) { return $r->nom ?: 'Sans catégorie'; }, $demandes_par_categorie ),
			'values' => array_map( function ( $r ) { return (int) $r->total; }, $demandes_par_categorie ),
		];

		// Fusionne les deux séries mensuelles (créées / résolues) sur un même axe de mois.
		$mois_set = [];
		foreach ( $evolution_creees as $r ) { $mois_set[ $r->mois ] = true; }
		foreach ( $evolution_resolues as $r ) { $mois_set[ $r->mois ] = true; }
		ksort( $mois_set );
		$mois_labels = array_keys( $mois_set );
		$creees_par_mois = array_column( $evolution_creees, 'total', 'mois' );
		$resolues_par_mois = array_column( $evolution_resolues, 'total', 'mois' );
		$chart_evolution = [
			'labels'   => $mois_labels,
			'creees'   => array_map( function ( $m ) use ( $creees_par_mois ) { return (int) ( $creees_par_mois[ $m ] ?? 0 ); }, $mois_labels ),
			'resolues' => array_map( function ( $m ) use ( $resolues_par_mois ) { return (int) ( $resolues_par_mois[ $m ] ?? 0 ); }, $mois_labels ),
		];

		$chart_satisfaction = [
			'labels' => array_map( function ( $r ) { return $r->note . ' ★'; }, $satisfaction_repartition ),
			'values' => array_map( function ( $r ) { return (int) $r->total; }, $satisfaction_repartition ),
		];

		$points_carte = array_map( function ( $d ) use ( $statut_labels_demande, $couleurs_statut ) {
			return [
				'lat'    => (float) $d->latitude,
				'lng'    => (float) $d->longitude,
				'titre'  => $d->titre,
				'numero' => $d->numero_suivi,
				'statut' => $statut_labels_demande[ $d->statut ] ?? $d->statut,
				'couleur'=> $couleurs_statut[ $d->statut ] ?? '#999',
				'url'    => admin_url( 'admin.php?page=grc-demandes&demande_id=' . $d->id ),
			];
		}, $demandes_geo );

		?>
		<div class="wrap">
			<h1>Statistiques</h1>

			<form method="get" style="margin:16px 0;display:flex;gap:10px;align-items:center;">
				<input type="hidden" name="page" value="grc-stats">
				<label>Du <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>"></label>
				<label>Au <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>"></label>
				<button type="submit" class="button">Filtrer</button>
			</form>

			<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:24px;">
				<div class="card" style="padding:14px 20px;max-width:none;">
					<strong style="font-size:24px;"><?php echo (int) $demandes_kpi->total; ?></strong><br>Signalements
					<div class="description">Taux résolution : <?php echo $taux_resolution; ?>%<?php echo $delai_moyen_jours ? ' · Délai moyen : ' . $delai_moyen_jours . ' j' : ''; ?></div>
				</div>
				<div class="card" style="padding:14px 20px;max-width:none;">
					<strong style="font-size:24px;"><?php echo (int) $demarches_kpi->total; ?></strong><br>Démarches
					<div class="description">Taux validation : <?php echo $taux_validation; ?>%</div>
				</div>
				<div class="card" style="padding:14px 20px;max-width:none;">
					<strong style="font-size:24px;"><?php echo (int) $rdv_kpi->total; ?></strong><br>Rendez-vous
					<div class="description">Taux confirmation : <?php echo $taux_confirmation; ?>%</div>
				</div>
				<div class="card" style="padding:14px 20px;max-width:none;">
					<strong style="font-size:24px;color:#8a6414;"><?php echo $satisfaction_kpi->moyenne ? round( (float) $satisfaction_kpi->moyenne, 1 ) : '—'; ?> / 5</strong><br>Satisfaction
					<div class="description"><?php echo (int) $satisfaction_kpi->total; ?> évaluation(s)</div>
				</div>
			</div>

			<div style="margin-bottom:20px;">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_export_csv&type=demandes&date_from=' . $date_from . '&date_to=' . $date_to ), 'grc_export_csv' ) ); ?>">Exporter les demandes (CSV)</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_export_csv&type=demarches&date_from=' . $date_from . '&date_to=' . $date_to ), 'grc_export_csv' ) ); ?>">Exporter les démarches (CSV)</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=grc_export_csv&type=rdv&date_from=' . $date_from . '&date_to=' . $date_to ), 'grc_export_csv' ) ); ?>">Exporter les rendez-vous (CSV)</a>
			</div>

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;">
				<div class="card" style="padding:16px;max-width:none;">
					<h2>Évolution mensuelle des demandes</h2>
					<canvas id="grc-chart-evolution" height="220"></canvas>
				</div>
				<div class="card" style="padding:16px;max-width:none;">
					<h2>Signalements par catégorie</h2>
					<canvas id="grc-chart-categories" height="220"></canvas>
				</div>
				<div class="card" style="padding:16px;max-width:none;">
					<h2>Signalements par statut</h2>
					<canvas id="grc-chart-demandes-statut" height="220"></canvas>
				</div>
				<div class="card" style="padding:16px;max-width:none;">
					<h2>Démarches par statut</h2>
					<canvas id="grc-chart-demarches-statut" height="220"></canvas>
				</div>
				<div class="card" style="padding:16px;max-width:none;">
					<h2>Rendez-vous par statut</h2>
					<canvas id="grc-chart-rdv-statut" height="220"></canvas>
				</div>
				<div class="card" style="padding:16px;max-width:none;">
					<h2>Répartition satisfaction</h2>
					<canvas id="grc-chart-satisfaction" height="220"></canvas>
				</div>
			</div>

			<div class="card" style="padding:16px;max-width:none;">
				<h2>Carte des signalements géolocalisés (<?php echo count( $points_carte ); ?>)</h2>
				<?php if ( empty( $points_carte ) ) : ?>
					<p class="description">Aucun signalement géolocalisé sur cette période. Les citoyens peuvent activer la géolocalisation depuis le formulaire de signalement (bouton "Utiliser ma position").</p>
				<?php else : ?>
					<div id="grc-map" role="img" aria-label="Carte des signalements géolocalisés. Une liste équivalente au format texte se trouve juste en dessous." style="height:450px;border-radius:8px;"></div>
					<details style="margin-top:12px;">
						<summary style="cursor:pointer;font-weight:600;">Voir la liste des signalements géolocalisés (équivalent texte de la carte)</summary>
						<table class="wp-list-table widefat fixed striped" style="margin-top:10px;">
							<thead><tr><th>N° suivi</th><th>Titre</th><th>Statut</th><th>Coordonnées</th><th></th></tr></thead>
							<tbody>
								<?php foreach ( $points_carte as $p ) : ?>
									<tr>
										<td><code><?php echo esc_html( $p['numero'] ); ?></code></td>
										<td><?php echo esc_html( $p['titre'] ); ?></td>
										<td><?php echo esc_html( $p['statut'] ); ?></td>
										<td><?php echo esc_html( round( $p['lat'], 5 ) . ', ' . round( $p['lng'], 5 ) ); ?></td>
										<td><a href="<?php echo esc_url( $p['url'] ); ?>">Voir la demande</a></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>
			</div>
		</div>

		<script>
		document.addEventListener( 'DOMContentLoaded', function () {
			if ( typeof Chart === 'undefined' ) { return; }

			var GRC_COLORS = { blue: '#2D6AB0', green: '#587526', gold: '#8a6414' };

			new Chart( document.getElementById( 'grc-chart-evolution' ), {
				type: 'line',
				data: {
					labels: <?php echo wp_json_encode( $chart_evolution['labels'] ); ?>,
					datasets: [
						{ label: 'Créées', data: <?php echo wp_json_encode( $chart_evolution['creees'] ); ?>, borderColor: GRC_COLORS.blue, backgroundColor: GRC_COLORS.blue, tension: 0.3 },
						{ label: 'Résolues', data: <?php echo wp_json_encode( $chart_evolution['resolues'] ); ?>, borderColor: GRC_COLORS.green, backgroundColor: GRC_COLORS.green, tension: 0.3 }
					]
				},
				options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
			} );

			new Chart( document.getElementById( 'grc-chart-categories' ), {
				type: 'bar',
				data: {
					labels: <?php echo wp_json_encode( $chart_categories['labels'] ); ?>,
					datasets: [ { label: 'Demandes', data: <?php echo wp_json_encode( $chart_categories['values'] ); ?>, backgroundColor: GRC_COLORS.blue } ]
				},
				options: { responsive: true, indexAxis: 'y', plugins: { legend: { display: false } } }
			} );

			function donut( canvasId, chartData ) {
				new Chart( document.getElementById( canvasId ), {
					type: 'doughnut',
					data: {
						labels: chartData.labels,
						datasets: [ { data: chartData.values, backgroundColor: chartData.colors } ]
					},
					options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
				} );
			}

			donut( 'grc-chart-demandes-statut', <?php echo wp_json_encode( $chart_demandes_statut ); ?> );
			donut( 'grc-chart-demarches-statut', <?php echo wp_json_encode( $chart_demarches_statut ); ?> );
			donut( 'grc-chart-rdv-statut', <?php echo wp_json_encode( $chart_rdv_statut ); ?> );

			new Chart( document.getElementById( 'grc-chart-satisfaction' ), {
				type: 'bar',
				data: {
					labels: <?php echo wp_json_encode( $chart_satisfaction['labels'] ); ?>,
					datasets: [ { label: 'Évaluations', data: <?php echo wp_json_encode( $chart_satisfaction['values'] ); ?>, backgroundColor: GRC_COLORS.gold } ]
				},
				options: { responsive: true, plugins: { legend: { display: false } } }
			} );

			var points = <?php echo wp_json_encode( $points_carte ); ?>;
			if ( points.length && typeof L !== 'undefined' && document.getElementById( 'grc-map' ) ) {
				var centre = [ points[0].lat, points[0].lng ];
				var map = L.map( 'grc-map' ).setView( centre, 13 );
				L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
					attribution: '© OpenStreetMap contributors',
					maxZoom: 19
				} ).addTo( map );

				var bounds = [];
				points.forEach( function ( p ) {
					var marker = L.circleMarker( [ p.lat, p.lng ], {
						radius: 8, fillColor: p.couleur, color: '#fff', weight: 2, fillOpacity: 0.9
					} ).addTo( map );
					marker.bindPopup( '<strong>' + p.numero + '</strong><br>' + p.titre + '<br>' + p.statut + '<br><a href="' + p.url + '">Voir la demande</a>' );
					bounds.push( [ p.lat, p.lng ] );
				} );
				if ( bounds.length > 1 ) {
					map.fitBounds( bounds, { padding: [ 30, 30 ] } );
				}
			}
		} );
		</script>
		<?php
	}

	// ------------------------------------------------------------------

	public static function handle_export_csv() {
		check_admin_referer( 'grc_export_csv' );
		if ( ! current_user_can( 'grc_view_stats' ) && ! current_user_can( 'grc_view_all' ) ) {
			wp_die( 'Permission refusée.' );
		}

		$type      = sanitize_key( $_GET['type'] ?? '' );
		$date_from = sanitize_text_field( wp_unslash( $_GET['date_from'] ?? '' ) ) . ' 00:00:00';
		$date_to   = sanitize_text_field( wp_unslash( $_GET['date_to'] ?? '' ) ) . ' 23:59:59';

		global $wpdb;

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="grc-' . $type . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$output = fopen( 'php://output', 'w' );
		fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) ); // BOM UTF-8 pour Excel.

		if ( 'demandes' === $type ) {
			$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC", $date_from, $date_to ) );
			fputcsv( $output, [ 'Numéro', 'Titre', 'Statut', 'Priorité', 'Adresse', 'Créée le', 'Résolue le', 'Clôturée le' ] );
			foreach ( $rows as $r ) {
				fputcsv( $output, [ $r->numero_suivi, $r->titre, $r->statut, $r->priorite, $r->adresse_lieu, $r->created_at, $r->resolved_at, $r->closed_at ] );
			}
		} elseif ( 'demarches' === $type ) {
			$table = $wpdb->prefix . GRC_TABLE_PREFIX . 'demarches';
			$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE created_at BETWEEN %s AND %s ORDER BY created_at DESC", $date_from, $date_to ) );
			fputcsv( $output, [ 'Numéro', 'Type', 'Statut', 'Créée le' ] );
			foreach ( $rows as $r ) {
				fputcsv( $output, [ $r->numero_dossier, $r->type_demarche, $r->statut, $r->created_at ] );
			}
		} elseif ( 'rdv' === $type ) {
			$rdv_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'rdv';
			$creneaux_table = $wpdb->prefix . GRC_TABLE_PREFIX . 'creneaux';
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT r.*, c.debut FROM {$rdv_table} r LEFT JOIN {$creneaux_table} c ON c.id = r.creneau_id WHERE r.created_at BETWEEN %s AND %s ORDER BY r.created_at DESC",
				$date_from, $date_to
			) );
			fputcsv( $output, [ 'Numéro', 'Statut', 'Date du RDV', 'Motif', 'Demandé le' ] );
			foreach ( $rows as $r ) {
				fputcsv( $output, [ $r->numero_rdv, $r->statut, $r->debut, $r->motif, $r->created_at ] );
			}
		}

		fclose( $output );
		GRC_Audit_Log::log( 'export_csv', $type, 0, [ 'date_from' => $date_from, 'date_to' => $date_to ] );
		exit;
	}
}
