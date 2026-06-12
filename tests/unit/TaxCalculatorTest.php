<?php
/**
 * Unit tests for TaxCalculator pure helpers (no WC order needed).
 *
 * The order-driven parts (get_rate_map, line_rate, compute_breakdown) need a
 * live WC_Order and are exercised end-to-end by generating a real invoice +
 * the FNFE-MPE / inspect-facturx checks. Here we lock the pure decision logic
 * that previously lived (duplicated) in XmlBuilder and PdfRenderer.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce\Tests\Unit;

use Mathis\FacturX\WooCommerce\TaxCalculator;
use PHPUnit\Framework\TestCase;

final class TaxCalculatorTest extends TestCase {

	/**
	 * BR-S-05: only a strictly-positive rate may be category "S"; 0 % -> "E".
	 *
	 * @dataProvider category_provider
	 */
	public function test_category_for_rate( float $rate, string $expected ): void {
		$this->assertSame( $expected, TaxCalculator::category_for_rate( $rate ) );
	}

	public function category_provider(): array {
		return array(
			'standard 20'   => array( 20.0, 'S' ),
			'reduced 5.5'   => array( 5.5, 'S' ),
			'tiny positive' => array( 0.01, 'S' ),
			'zero is exempt' => array( 0.0, 'E' ),
		);
	}

	/**
	 * Exemption reason is required for "E" (BR-E-10) and absent otherwise.
	 */
	public function test_exemption_reason_only_for_exempt(): void {
		$this->assertNull( TaxCalculator::exemption_reason_for( 'S' ) );

		$reason = TaxCalculator::exemption_reason_for( 'E' );
		$this->assertIsString( $reason );
		$this->assertNotSame( '', trim( (string) $reason ) );
	}

	/**
	 * rate_for_line derives a 2-decimal percentage, and never divides by zero.
	 *
	 * @dataProvider rate_for_line_provider
	 */
	public function test_rate_for_line( float $net, float $tax, float $expected ): void {
		$this->assertSame( $expected, TaxCalculator::rate_for_line( $net, $tax ) );
	}

	public function rate_for_line_provider(): array {
		return array(
			'20 percent'      => array( 100.0, 20.0, 20.0 ),
			'5.5 percent'     => array( 100.0, 5.5, 5.5 ),
			'zero net guard'  => array( 0.0, 0.0, 0.0 ),
			'rounds 2 dp'     => array( 3.0, 0.2, 6.67 ), // 0.2/3*100 = 6.666… -> 6.67
		);
	}
}
