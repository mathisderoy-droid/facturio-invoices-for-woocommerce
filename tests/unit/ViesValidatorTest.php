<?php
/**
 * Unit tests for ViesValidator format/normalisation helpers (pure).
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce\Tests\Unit;

use Mathis\FacturX\WooCommerce\ViesValidator;
use PHPUnit\Framework\TestCase;

final class ViesValidatorTest extends TestCase {

	/**
	 * @dataProvider valid_fr_vat_provider
	 */
	public function test_valid_french_vat_passes( string $vat ): void {
		$this->assertTrue( ViesValidator::is_valid_french_format( $vat ) );
	}

	public function valid_fr_vat_provider(): array {
		return array(
			'pennylane plain'       => array( 'FR66825215296' ),
			'lowercase + spaces'    => array( 'fr 66 825215296' ),
		);
	}

	/**
	 * @dataProvider invalid_fr_vat_provider
	 */
	public function test_invalid_french_vat_is_rejected( string $vat, string $why ): void {
		$this->assertFalse( ViesValidator::is_valid_french_format( $vat ), $why );
	}

	public function invalid_fr_vat_provider(): array {
		return array(
			'too short'         => array( 'FR123', 'not 11 chars after FR' ),
			'missing fr prefix' => array( '66825215296', 'no FR country prefix' ),
			'reserved letter I' => array( 'FRI6825215296', 'I is reserved in the 2-char key' ),
		);
	}

	/**
	 * normalize() uppercases and strips all whitespace.
	 */
	public function test_normalize(): void {
		$this->assertSame( 'FR66825215296', ViesValidator::normalize( '  fr 66 825 215 296 ' ) );
		$this->assertSame( 'DE123456789', ViesValidator::normalize( 'de123456789' ) );
	}

	/**
	 * Foreign (non-FR) numbers are out of scope for the FR format check.
	 */
	public function test_non_french_prefix_is_not_french_format(): void {
		$this->assertFalse( ViesValidator::is_valid_french_format( 'DE123456789' ) );
	}
}
