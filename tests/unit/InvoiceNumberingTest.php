<?php
/**
 * Unit tests for InvoiceNumbering pure helpers.
 *
 * Only the side-effect-free decision logic is unit-tested here. The atomic
 * counter increment/set (which need $wpdb) are covered by the integration
 * test in tests/integration/.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce\Tests\Unit;

use Mathis\FacturX\WooCommerce\InvoiceNumbering;
use PHPUnit\Framework\TestCase;

final class InvoiceNumberingTest extends TestCase {

	/**
	 * The editable "next invoice number" may only move forward: it must be
	 * strictly greater than the last issued number, otherwise it would re-issue
	 * an already-used number (forbidden for French sequential numbering).
	 *
	 * @dataProvider next_number_provider
	 */
	public function test_is_acceptable_next_number( int $requested, int $last, bool $expected, string $why ): void {
		$this->assertSame(
			$expected,
			InvoiceNumbering::is_acceptable_next_number( $requested, $last ),
			$why
		);
	}

	public function next_number_provider(): array {
		return array(
			'continue right after last (248 > 247)' => array( 248, 247, true, 'next = last + 1 is the normal resume case' ),
			'forward jump leaves a gap (300 > 247)' => array( 300, 247, true, 'jumping forward is allowed (merchant choice)' ),
			'equal to last issued (247)'            => array( 247, 247, false, 'would re-issue invoice 247' ),
			'below last issued (200)'               => array( 200, 247, false, 'would re-use a whole range of numbers' ),
			'fresh counter, first number is 1'      => array( 1, 0, true, 'a brand new series starts at 1' ),
			'fresh counter, custom start at 50'     => array( 50, 0, true, 'starting a custom series is allowed' ),
			'zero is never a valid next number'     => array( 0, 0, false, 'invoice numbers start at 1' ),
		);
	}
}
