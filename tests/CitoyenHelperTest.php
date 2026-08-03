<?php
use PHPUnit\Framework\TestCase;

/**
 * @covers GRC_Citoyen_Helper
 */
final class CitoyenHelperTest extends TestCase {

	public function test_numero_pads_id_to_six_digits(): void {
		$this->assertSame( 'CIT-000042', GRC_Citoyen_Helper::numero( 42 ) );
		$this->assertSame( 'CIT-000001', GRC_Citoyen_Helper::numero( 1 ) );
	}

	public function test_numero_does_not_truncate_large_ids(): void {
		// Un ID à 7 chiffres ou plus doit rester lisible intégralement,
		// pas être tronqué par le padding à 6 caractères.
		$this->assertSame( 'CIT-1234567', GRC_Citoyen_Helper::numero( 1234567 ) );
	}

	public function test_parse_numero_accepts_full_format(): void {
		$this->assertSame( 42, GRC_Citoyen_Helper::parse_numero( 'CIT-000042' ) );
	}

	public function test_parse_numero_accepts_digits_only(): void {
		$this->assertSame( 42, GRC_Citoyen_Helper::parse_numero( '000042' ) );
		$this->assertSame( 42, GRC_Citoyen_Helper::parse_numero( '42' ) );
	}

	public function test_parse_numero_ignores_surrounding_text(): void {
		// Un agent pourrait coller le numéro avec des espaces ou depuis un
		// email cité ("réf CIT-000042 svp") : seuls les chiffres comptent.
		$this->assertSame( 42, GRC_Citoyen_Helper::parse_numero( '  CIT-000042  ' ) );
		$this->assertSame( 42, GRC_Citoyen_Helper::parse_numero( 'réf CIT-000042 svp' ) );
	}

	public function test_parse_numero_returns_null_for_non_numeric_input(): void {
		$this->assertNull( GRC_Citoyen_Helper::parse_numero( 'abc' ) );
		$this->assertNull( GRC_Citoyen_Helper::parse_numero( '' ) );
	}

	public function test_numero_and_parse_numero_are_inverse_operations(): void {
		foreach ( [ 1, 42, 999, 123456, 7654321 ] as $id ) {
			$this->assertSame( $id, GRC_Citoyen_Helper::parse_numero( GRC_Citoyen_Helper::numero( $id ) ) );
		}
	}
}
