<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once GRC_PLUGIN_DIR . 'includes/lib/fpdf.php';

/**
 * Génère un PDF récapitulatif d'un signalement : informations, citoyen,
 * suivi, et carte du lieu concerné (si le signalement est géolocalisé).
 */
class GRC_PDF_Signalement extends FPDF {

	private $titre_document = '';

	public function Header() {
		$this->SetFillColor( 0x2D, 0x6A, 0xB0 );
		$this->Rect( 0, 0, 210, 22, 'F' );
		$this->SetTextColor( 255, 255, 255 );
		$this->SetFont( 'Helvetica', 'B', 14 );
		$this->SetXY( 10, 6 );
		$this->Cell( 0, 10, self::txt( 'Mairie de Berre-les-Alpes' ), 0, 1 );
		$this->SetFont( 'Helvetica', '', 10 );
		$this->SetXY( 10, 14 );
		$this->Cell( 0, 6, self::txt( $this->titre_document ), 0, 1 );
		$this->SetTextColor( 0, 0, 0 );
		$this->SetY( 28 );
	}

	public function Footer() {
		$this->SetY( -15 );
		$this->SetFont( 'Helvetica', 'I', 8 );
		$this->SetTextColor( 130, 130, 130 );
		$this->Cell( 0, 10, self::txt( 'Document généré le ' . date_i18n( 'd/m/Y à H:i' ) . ' — Page ' . $this->PageNo() ), 0, 0, 'C' );
	}

	public static function generate( int $demande_id ) {
		global $wpdb;
		$demandes_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'demandes';
		$services_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'services';
		$categories_table   = $wpdb->prefix . GRC_TABLE_PREFIX . 'categories';
		$citoyens_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'citoyens';
		$messages_table     = $wpdb->prefix . GRC_TABLE_PREFIX . 'messages';

		$demande = $wpdb->get_row( $wpdb->prepare(
			"SELECT d.*, s.nom AS service_nom, c.nom AS categorie_nom
			 FROM {$demandes_table} d
			 LEFT JOIN {$services_table} s ON s.id = d.service_id
			 LEFT JOIN {$categories_table} c ON c.id = d.categorie_id
			 WHERE d.id = %d",
			$demande_id
		) );

		if ( ! $demande ) {
			return null;
		}

		$citoyen = $demande->citoyen_id ? $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$citoyens_table} WHERE id = %d", $demande->citoyen_id
		) ) : null;

		$statut_labels = [
			'nouveau' => 'Nouveau', 'en_cours' => 'En cours', 'assigne' => 'Assigné',
			'resolu' => 'Résolu', 'cloture' => 'Clôturé', 'reouvert' => 'Réouvert',
		];

		$pdf = new self();
		$pdf->titre_document = 'Signalement ' . $demande->numero_suivi;
		$pdf->AliasNbPages();
		$pdf->AddPage();
		$pdf->SetFont( 'Helvetica', '', 11 );

		// --- Informations générales ---------------------------------------
		$pdf->section_titre( 'Informations générales' );
		$pdf->ligne_champ( 'Numéro de suivi', $demande->numero_suivi );
		$pdf->ligne_champ( 'Titre', $demande->titre );
		$pdf->ligne_champ( 'Statut', $statut_labels[ $demande->statut ] ?? $demande->statut );
		$pdf->ligne_champ( 'Priorité', ucfirst( $demande->priorite ) );
		$pdf->ligne_champ( 'Service', $demande->service_nom ?: '—' );
		$pdf->ligne_champ( 'Catégorie', $demande->categorie_nom ?: '—' );
		$pdf->ligne_champ( 'Créée le', mysql2date( 'd/m/Y à H:i', $demande->created_at ) );
		if ( $demande->resolved_at ) {
			$pdf->ligne_champ( 'Résolue le', mysql2date( 'd/m/Y à H:i', $demande->resolved_at ) );
		}
		if ( $demande->closed_at ) {
			$pdf->ligne_champ( 'Clôturée le', mysql2date( 'd/m/Y à H:i', $demande->closed_at ) );
		}

		// --- Description -----------------------------------------------------
		$pdf->Ln( 3 );
		$pdf->section_titre( 'Description' );
		$pdf->SetFont( 'Helvetica', '', 10 );
		$pdf->MultiCell( 0, 6, self::txt( $demande->description ?: '—' ) );

		// --- Citoyen ---------------------------------------------------------
		$pdf->Ln( 3 );
		$pdf->section_titre( 'Citoyen' );
		if ( $citoyen ) {
			$nom_complet = trim(
				( $citoyen->prenom ? GRC_Encryption::decrypt( $citoyen->prenom ) : '' ) . ' ' .
				( $citoyen->nom ? GRC_Encryption::decrypt( $citoyen->nom ) : '' )
			);
			$pdf->ligne_champ( 'Numéro citoyen', GRC_Citoyen_Helper::numero( (int) $citoyen->id ) );
			$pdf->ligne_champ( 'Nom', $nom_complet ?: '—' );
			$pdf->ligne_champ( 'Email', $citoyen->email ? GRC_Encryption::decrypt( $citoyen->email ) : '—' );
			$pdf->ligne_champ( 'Téléphone', $citoyen->telephone ? GRC_Encryption::decrypt( $citoyen->telephone ) : '—' );
		} else {
			$pdf->ligne_champ( 'Citoyen', 'Non renseigné' );
		}

		// --- Lieu + carte ------------------------------------------------------
		if ( $demande->adresse_lieu || ( $demande->latitude && $demande->longitude ) ) {
			$pdf->Ln( 3 );
			$pdf->section_titre( 'Lieu concerné' );
			if ( $demande->adresse_lieu ) {
				$pdf->ligne_champ( 'Adresse', $demande->adresse_lieu );
			}

			if ( $demande->latitude && $demande->longitude ) {
				$pdf->ligne_champ( 'Coordonnées GPS', round( (float) $demande->latitude, 6 ) . ', ' . round( (float) $demande->longitude, 6 ) );

				$map_path = GRC_Static_Map::get_or_generate( $demande_id, (float) $demande->latitude, (float) $demande->longitude );
				if ( $map_path ) {
					$pdf->Ln( 2 );
					$y_avant = $pdf->GetY();
					if ( $y_avant > 200 ) {
						$pdf->AddPage();
						$y_avant = $pdf->GetY();
					}
					$pdf->Image( $map_path, 55, $y_avant, 100, 100, 'PNG' );
					$pdf->SetY( $y_avant + 104 );
				}
			}
		}

		// --- Historique d'échanges ---------------------------------------------
		$messages = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$messages_table} WHERE demande_id = %d ORDER BY created_at ASC",
			$demande_id
		) );
		if ( ! empty( $messages ) ) {
			$pdf->Ln( 3 );
			$pdf->section_titre( 'Historique des échanges' );
			$pdf->SetFont( 'Helvetica', '', 9 );
			foreach ( $messages as $m ) {
				if ( $m->interne ) {
					continue; // Les notes internes ne figurent pas sur le document remis/exporté.
				}
				$auteur = 'agent' === $m->auteur_type ? 'Agent municipal' : 'Citoyen';
				$pdf->SetFont( 'Helvetica', 'B', 9 );
				$pdf->Cell( 0, 5, self::txt( $auteur . ' — ' . mysql2date( 'd/m/Y H:i', $m->created_at ) ), 0, 1 );
				$pdf->SetFont( 'Helvetica', '', 9 );
				$pdf->MultiCell( 0, 5, self::txt( $m->contenu ) );
				$pdf->Ln( 1 );
			}
		}

		GRC_Audit_Log::log( 'pdf_generated', 'demande', $demande_id, [ 'numero_suivi' => $demande->numero_suivi ] );

		return $pdf->Output( 'S' ); // Retourne le contenu binaire du PDF.
	}

	/**
	 * Convertit une chaîne UTF-8 vers ISO-8859-1 (Latin-1), requis par les
	 * polices standard de FPDF. Remplace utf8_decode(), déprécié depuis
	 * PHP 8.2, par l'équivalent recommandé mb_convert_encoding().
	 */
	protected static function txt( ?string $str ): string {
		return mb_convert_encoding( (string) $str, 'ISO-8859-1', 'UTF-8' );
	}

	protected function section_titre( string $titre ) {
		$this->SetFont( 'Helvetica', 'B', 12 );
		$this->SetTextColor( 0x2D, 0x6A, 0xB0 );
		$this->Cell( 0, 8, self::txt( $titre ), 0, 1 );
		$this->SetTextColor( 0, 0, 0 );
		$this->SetDrawColor( 0xDE, 0xA1, 0x28 );
		$this->SetLineWidth( 0.5 );
		$this->Line( 10, $this->GetY(), 60, $this->GetY() );
		$this->Ln( 3 );
	}

	protected function ligne_champ( string $label, string $valeur ) {
		$this->SetFont( 'Helvetica', 'B', 10 );
		$this->Cell( 45, 6, self::txt( $label . ' :' ), 0, 0 );
		$this->SetFont( 'Helvetica', '', 10 );
		$this->Cell( 0, 6, self::txt( $valeur ), 0, 1 );
	}
}
