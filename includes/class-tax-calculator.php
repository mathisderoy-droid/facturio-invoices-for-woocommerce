<?php
/**
 * Tax Calculator — the single source of truth for VAT logic.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Centralises every VAT decision the plugin makes about an order.
 *
 * WHY THIS EXISTS
 *   Before this class, the rate resolution + per-rate breakdown lived in BOTH
 *   XmlBuilder (the embedded CII XML) and PdfRenderer (the human-readable PDF).
 *   Keeping two copies in sync was the root cause of the two conformance bugs
 *   found during QA (the 5.51 % rounding and the BR-CO-10 per-line rounding):
 *   each had to be fixed twice. Both classes now delegate here, so the XML and
 *   the PDF can never disagree on tax again.
 *
 * Scope: WooCommerce order in, VAT facts out. It does NOT know about Zugferd or
 * TCPDF — it returns plain arrays/values. The VAT *category code* uses the
 * EN 16931 / UNTDID 5305 letters ('S', 'E') directly so this class has no
 * dependency on the (scoped) Zugferd codelist constants.
 */
final class TaxCalculator {

	/** UNTDID 5305 VAT category code: standard rated. */
	public const CATEGORY_STANDARD = 'S';

	/** UNTDID 5305 VAT category code: exempt from VAT. */
	public const CATEGORY_EXEMPT = 'E';

	/**
	 * Choose the VAT category code for a rate.
	 *
	 * BR-S-05: category "S" requires a rate strictly greater than zero. A 0 %
	 * line is therefore mapped to "E" (exempt), not "S".
	 *
	 * V0.5 note: the 0 % branch will later be refined (reverse charge 'AE',
	 * export 'G', intra-community 'K') once buyer geography is modelled.
	 */
	public static function category_for_rate( float $rate ): string {
		return $rate > 0.0 ? self::CATEGORY_STANDARD : self::CATEGORY_EXEMPT;
	}

	/**
	 * Exemption reason text required by BR-E-10 when emitting category "E".
	 *
	 * Returns null for non-exempt categories. Filterable so merchants in other
	 * exemption regimes can override the default (franchise en base de TVA).
	 *
	 * @param string $category One of the CATEGORY_* codes.
	 */
	public static function exemption_reason_for( string $category ): ?string {
		if ( self::CATEGORY_EXEMPT !== $category ) {
			return null;
		}
		$default = __( 'TVA non applicable, art. 293 B du CGI', 'facturio-invoices-for-woocommerce' );
		return (string) apply_filters( 'mathisfx_vat_exemption_reason', $default );
	}

	/**
	 * Effective VAT rate (percent) for a line, derived from its amounts.
	 *
	 * EN 16931 BT-152 wants a percentage like 20.00 or 5.50, NOT a decimal.
	 * This is the fallback when WC stored no usable rate percent; prefer
	 * line_rate() which reads the exact stored rate first.
	 */
	public static function rate_for_line( float $net, float $tax ): float {
		if ( $net <= 0.0 ) {
			return 0.0;
		}
		return round( ( $tax / $net ) * 100, 2 );
	}

	/**
	 * Map WooCommerce tax-rate IDs to their exact stored percentage.
	 *
	 * Read straight from the order's tax items (the rate as applied at
	 * checkout). Authoritative, unlike deriving from amounts — derivation
	 * rounds 5.5 % to 5.51 % because WC rounds each line's net and tax to 2
	 * decimals first.
	 *
	 * @return array<int,float> rate_id => percent (e.g. 20.0, 5.5)
	 */
	public static function get_rate_map( \WC_Order $order ): array {
		$map = array();
		foreach ( $order->get_items( 'tax' ) as $tax_item ) {
			/** @var \WC_Order_Item_Tax $tax_item */
			$map[ (int) $tax_item->get_rate_id() ] = (float) $tax_item->get_rate_percent();
		}
		return $map;
	}

	/**
	 * Exact VAT rate (percent) for a single order line, read from WC.
	 *
	 * Looks at the line's own tax entries, finds the rate that actually
	 * applied, and returns its exact stored percentage. Falls back to the
	 * amount-derived rate only if WC stored no usable percent. A line with no
	 * effective tax returns 0.0 (handled as exempt downstream).
	 *
	 * @param \WC_Order_Item_Product|\WC_Order_Item_Shipping $item
	 * @param array<int,float>                               $rate_map
	 */
	public static function line_rate( $item, array $rate_map ): float {
		$taxes  = $item->get_taxes();
		$totals = ( isset( $taxes['total'] ) && is_array( $taxes['total'] ) ) ? $taxes['total'] : array();

		foreach ( $totals as $rate_id => $amount ) {
			if ( '' === $amount || null === $amount || 0.0 === (float) $amount ) {
				continue; // not the effective rate on this line
			}
			if ( isset( $rate_map[ (int) $rate_id ] ) ) {
				return $rate_map[ (int) $rate_id ];
			}
		}

		// The line carries tax but no usable stored percent (rare): derive from
		// amounts rather than mislabel it exempt. Otherwise: exempt (0 %).
		$net = (float) $item->get_total();
		$tax = (float) $item->get_total_tax();
		return $tax > 0.0 ? self::rate_for_line( $net, $tax ) : 0.0;
	}

	/**
	 * Aggregate VAT across the whole order into per-rate buckets + totals.
	 *
	 * This is THE shared computation behind both the embedded XML's
	 * <ApplicableTradeTax> groups + monetary summation, and the PDF's VAT
	 * summary table. Returning a plain structure keeps it format-agnostic.
	 *
	 * Rounding: each line's net and tax are rounded to 2 decimals BEFORE being
	 * summed. BR-CO-10 requires the document line-total (BT-106) to equal the
	 * sum of each line's already-rounded net (BT-131); summing raw then rounding
	 * once drifts by a cent.
	 *
	 * Line inclusion mirrors what the invoice actually renders:
	 *   - product lines ALWAYS count (even at 0 €, e.g. a 100 % coupon) so a
	 *     fully-discounted order still emits a VAT group (BR-CO-18) and the
	 *     category-E line keeps its matching E group (BR-E-01);
	 *   - a zero-cost shipping line is skipped (it is not rendered as a line).
	 *
	 * @return array{
	 *     buckets: array<int, array{rate: float, category: string, basis: float, tax: float}>,
	 *     line_total: float,
	 *     tax_total: float,
	 *     grand_total: float
	 * }
	 */
	public static function compute_breakdown( \WC_Order $order ): array {
		$rate_map = self::get_rate_map( $order );
		$buckets  = array();

		$accumulate = static function ( float $net, float $tax, float $rate ) use ( &$buckets ): void {
			$net = round( $net, 2 );
			$tax = round( $tax, 2 );
			$key = (string) $rate;
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'rate'     => $rate,
					'category' => self::category_for_rate( $rate ),
					'basis'    => 0.0,
					'tax'      => 0.0,
				);
			}
			$buckets[ $key ]['basis'] += $net;
			$buckets[ $key ]['tax']   += $tax;
		};

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$accumulate( (float) $item->get_total(), (float) $item->get_total_tax(), self::line_rate( $item, $rate_map ) );
		}
		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			/** @var \WC_Order_Item_Shipping $shipping */
			if ( (float) $shipping->get_total() <= 0.0 ) {
				continue;
			}
			$accumulate( (float) $shipping->get_total(), (float) $shipping->get_total_tax(), self::line_rate( $shipping, $rate_map ) );
		}

		$line_total = 0.0;
		$tax_total  = 0.0;
		foreach ( $buckets as $b ) {
			$line_total += $b['basis'];
			$tax_total  += $b['tax'];
		}
		$line_total = round( $line_total, 2 );
		$tax_total  = round( $tax_total, 2 );

		return array(
			'buckets'     => array_values( $buckets ),
			'line_total'  => $line_total,
			'tax_total'   => $tax_total,
			'grand_total' => round( $line_total + $tax_total, 2 ),
		);
	}
}
