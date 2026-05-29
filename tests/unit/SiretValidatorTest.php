<?php
/**
 * Unit tests for SiretValidator::is_valid_format() (pure Luhn check).
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce\Tests\Unit;

use Mathis\FacturX\WooCommerce\SiretValidator;
use PHPUnit\Framework\TestCase;

final class SiretValidatorTest extends TestCase {

	/**
	 * Real, registered SIRETs that must pass the Luhn checksum.
	 *
	 * 82521529600013 is Pennylane's head office — empirically valid
	 * (INSEE returned data for it during manual testing, and lookup()
	 * runs the Luhn check before any API call).
	 *
	 * @dataProvider valid_siret_provider
	 */
	public function test_valid_siret_passes( string $siret ): void {
		$this->assertTrue( SiretValidator::is_valid_format( $siret ) );
	}

	public function valid_siret_provider(): array {
		return array(
			'pennylane plain'        => array( '82521529600013' ),
			'pennylane with spaces'  => array( '825 215 296 00013' ),
		);
	}

	/**
	 * Numbers that must be rejected.
	 *
	 * @dataProvider invalid_siret_provider
	 */
	public function test_invalid_siret_is_rejected( string $siret, string $why ): void {
		$this->assertFalse( SiretValidator::is_valid_format( $siret ), $why );
	}

	public function invalid_siret_provider(): array {
		return array(
			'broken luhn (last digit -1)' => array( '82521529600012', 'tampered checksum must fail' ),
			'too short'                   => array( '1234', 'fewer than 14 digits' ),
			'too long'                    => array( '825215296000130', '15 digits' ),
			'contains a letter'           => array( '8252152960001X', 'non-digit char' ),
			'empty string'                => array( '', 'empty input' ),
		);
	}

	/**
	 * Spaces are tolerated and stripped before validation.
	 */
	public function test_spaces_are_stripped_before_check(): void {
		$this->assertTrue(
			SiretValidator::is_valid_format( '  82521529600013  ' ),
			'leading/trailing spaces should be ignored'
		);
	}
}
