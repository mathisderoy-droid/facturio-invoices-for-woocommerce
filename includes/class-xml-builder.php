<?php
/**
 * XML Builder — turns a WC_Order into a Factur-X CII XML (profile EN 16931).
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

use DateTime;
use horstoeko\zugferd\ZugferdDocumentBuilder;
use horstoeko\zugferd\ZugferdProfiles;
use horstoeko\zugferd\codelists\ZugferdInvoiceType;
use horstoeko\zugferd\codelists\ZugferdUnitCodes;
use horstoeko\zugferd\codelists\ZugferdVatCategoryCodes;
use horstoeko\zugferd\codelists\ZugferdPaymentMeans;

defined( 'ABSPATH' ) || exit;

/**
 * Produces the Cross Industry Invoice (CII) XML embedded in our Factur-X PDFs.
 *
 * Profile: EN 16931 (the European semantic core, mandatory for B2B FR from
 * Sept. 2026). The richer EXTENDED-CTC-FR profile required for routing to
 * Plateformes Agréées is reserved for V0.5 Pro.
 *
 * Stateless: one public static method, no instance state. The order is
 * passed in, the XML comes out. Tax math and currency rounding happen here
 * (using bccomp would be safer but Local doesn't ship bcmath; round-half-even
 * via round($x, 2, PHP_ROUND_HALF_EVEN) is good enough for invoice amounts
 * which never go below cents).
 *
 * V0.1 simplifications documented in DECISIONS.md:
 *   - Single effective VAT rate per line (covers >99 % of e-commerce orders).
 *   - Coupons modeled as negative line items rather than document-level
 *     allowances (less semantically correct but valid EN 16931 output).
 *   - Currency hard-coded by reading $order->get_currency() — no FX conversion.
 *   - Factur-X 1.08 sub-lines / bundles ignored.
 */
final class XmlBuilder {

	/**
	 * Build the CII XML for the given order.
	 *
	 * @param \WC_Order $order          The completed WooCommerce order.
	 * @param string    $invoice_number Pre-allocated invoice number (e.g. "F2026-000042").
	 * @return string                   UTF-8 encoded CII XML, ready to embed in PDF/A-3.
	 */
	public static function build( \WC_Order $order, string $invoice_number ): string {
		return self::build_document( $order, $invoice_number )->getContent();
	}

	/**
	 * Build and return the underlying ZugferdDocumentBuilder instance.
	 *
	 * Exposed so callers (notably the Schematron validator and the PDF
	 * embedding step in Etape 5C) can keep the rich object instead of
	 * the serialized string.
	 *
	 * @param \WC_Order $order          The completed WooCommerce order.
	 * @param string    $invoice_number Pre-allocated invoice number.
	 */
	public static function build_document( \WC_Order $order, string $invoice_number ): ZugferdDocumentBuilder {
		$document = ZugferdDocumentBuilder::createNew( ZugferdProfiles::PROFILE_EN16931 );

		self::apply_metadata( $document, $order, $invoice_number );
		self::apply_seller( $document, $order );
		self::apply_buyer( $document, $order );
		self::apply_delivery( $document, $order );
		self::apply_payment( $document, $order );
		self::apply_payment_terms( $document, $order );
		self::apply_lines( $document, $order );
		self::apply_tax_breakdown_and_summation( $document, $order );

		return $document;
	}

	/**
	 * Populate ApplicableHeaderTradeDelivery with at minimum the actual
	 * delivery date (BT-72).
	 *
	 * Without this, horstoeko emits an empty <ApplicableHeaderTradeDelivery/>
	 * element, which the FNFE-MPE validator (PEPPOL-EN16931-R008) refuses
	 * as a non-empty-elements violation.
	 *
	 * For a WooCommerce order the closest semantic to "delivery date" is
	 * the order completion date (when the merchant moved the order to
	 * "terminée" — physical shipment or service rendered). Falls back to
	 * the creation date for orders that are completed instantly.
	 */
	private static function apply_delivery( ZugferdDocumentBuilder $document, \WC_Order $order ): void {
		$date = $order->get_date_completed() ?: $order->get_date_created();
		if ( ! $date ) {
			return;
		}
		$php_date = new DateTime( $date->format( 'Y-m-d' ) );
		$document->setDocumentSupplyChainEvent( $php_date );
	}

	/* ----------------------------------------------------------------- */
	/* VAT category selection                                             */
	/* ----------------------------------------------------------------- */

	/**
	 * Choose the UNTDID 5305 VAT category code for a given rate.
	 *
	 * BR-S-05 in EN 16931 mandates that category "S" (Standard rated) can
	 * only be used when the rate is strictly greater than zero. Using "S"
	 * with 0 % was the first FNFE-MPE validator failure we hit.
	 *
	 * V0.1 mapping:
	 *   - rate > 0      -> 'S' (Standard rated)
	 *   - rate == 0     -> 'E' (Exempt from tax)
	 *
	 * V0.5 will refine the 0 % branch:
	 *   - Buyer in EU but outside FR + B2B with valid VAT -> 'AE' (Reverse charge)
	 *   - Buyer outside EU                                -> 'G' (Export)
	 *   - True French exemption (e.g. franchise en base)  -> 'E' (current default)
	 *   - Intra-community supply of goods                 -> 'K'
	 */
	private static function vat_category_for_rate( float $rate ): string {
		if ( $rate > 0 ) {
			return ZugferdVatCategoryCodes::STAN_RATE;
		}
		return ZugferdVatCategoryCodes::EXEM_FROM_TAX;
	}

	/**
	 * Exemption reason text required when emitting category 'E' (Exempt).
	 *
	 * BR-E-10 in EN 16931 mandates that a VAT category "Exempt from VAT"
	 * MUST carry either an exemption reason text (BT-120) or an exemption
	 * reason code (BT-121). Without one of the two, the document is
	 * non-conformant — even if the rate is correctly 0 %.
	 *
	 * Default text covers the most common French case: franchise en base
	 * de TVA (auto-entrepreneurs, micro-entreprises under the threshold).
	 * Merchants in other exemption regimes (reverse charge intra-EU,
	 * export, etc.) can override via the `mathisfx_vat_exemption_reason`
	 * filter, or via a Settings field in a future release.
	 *
	 * Returns null for non-exempt categories — most invoices never need
	 * this branch.
	 */
	private static function exemption_reason_for( string $category ): ?string {
		if ( $category !== ZugferdVatCategoryCodes::EXEM_FROM_TAX ) {
			return null;
		}
		$default = __( 'TVA non applicable, art. 293 B du CGI', 'factur-x-for-woocommerce' );
		return (string) apply_filters( 'mathisfx_vat_exemption_reason', $default );
	}

	/* ----------------------------------------------------------------- */
	/* Document metadata                                                  */
	/* ----------------------------------------------------------------- */

	/**
	 * Header info: invoice number, type (380 = commercial invoice), date, currency.
	 *
	 * Issue date defaults to the order completion date when set, otherwise
	 * the creation date. Both are WC_DateTime in the site's timezone, which
	 * is what EN 16931 expects (BT-2 is a calendar date with no timezone).
	 */
	private static function apply_metadata( ZugferdDocumentBuilder $document, \WC_Order $order, string $invoice_number ): void {
		$issue_date = $order->get_date_completed() ?: $order->get_date_created();
		$php_date   = $issue_date ? new DateTime( $issue_date->format( 'Y-m-d' ) ) : new DateTime();

		$document->setDocumentInformation(
			$invoice_number,
			ZugferdInvoiceType::INVOICE, // 380
			$php_date,
			$order->get_currency()
		);

		// Reference the originating WC order so the buyer can match it back
		// (BT-13 Purchase order reference). Most ERPs hook on this for
		// 3-way matching.
		$document->setDocumentBuyerOrderReferencedDocument(
			(string) $order->get_order_number()
		);
	}

	/* ----------------------------------------------------------------- */
	/* Seller (from plugin settings)                                      */
	/* ----------------------------------------------------------------- */

	/**
	 * Pull seller info from `mathisfx_seller_*` options.
	 *
	 * Note on SIREN vs SIRET: EN 16931 BT-30 (legal registration identifier)
	 * expects the SIREN (9 digits) with scheme 0002. The merchant stored a
	 * SIRET (14 digits) in settings — we strip down to the first 9 digits.
	 */
	private static function apply_seller( ZugferdDocumentBuilder $document, \WC_Order $order ): void {
		$name        = (string) get_option( 'mathisfx_seller_company_name', '' );
		$siret       = preg_replace( '/\D+/', '', (string) get_option( 'mathisfx_seller_siret', '' ) );
		$siren       = substr( $siret, 0, 9 );
		$vat         = (string) get_option( 'mathisfx_seller_vat', '' );
		$address     = (string) get_option( 'mathisfx_seller_address', '' );
		$postal_code = (string) get_option( 'mathisfx_seller_postal_code', '' );
		$city        = (string) get_option( 'mathisfx_seller_city', '' );
		$country     = (string) get_option( 'mathisfx_seller_country', 'FR' );

		$document->setDocumentSeller( $name );

		// BT-30 — Legal registration via SIREN (scheme 0002 in ISO/IEC 6523).
		if ( $siren !== '' ) {
			$document->setDocumentSellerLegalOrganisation( $siren, '0002', $name );
		}

		// BT-31 — VAT identifier.
		if ( $vat !== '' ) {
			$document->addDocumentSellerTaxRegistration( 'VA', $vat );
		}

		// Postal address (BT-35 to BT-40). All five lines optional individually,
		// but the country code is mandatory in EN 16931.
		$document->setDocumentSellerAddress(
			$address,
			'',
			'',
			$postal_code,
			$city,
			$country
		);
	}

	/* ----------------------------------------------------------------- */
	/* Buyer (B2B from order meta, B2C from billing fields)               */
	/* ----------------------------------------------------------------- */

	/**
	 * Apply buyer info — B2B path uses the meta we saved at Etape 4A,
	 * B2C path falls back to the standard WC billing fields.
	 */
	private static function apply_buyer( ZugferdDocumentBuilder $document, \WC_Order $order ): void {
		$is_b2b = ( 'yes' === $order->get_meta( '_mathisfx_is_b2b' ) );

		if ( $is_b2b ) {
			$name  = (string) $order->get_meta( '_mathisfx_company_name' );
			$siret = preg_replace( '/\D+/', '', (string) $order->get_meta( '_mathisfx_siret' ) );
			$vat   = (string) $order->get_meta( '_mathisfx_vat' );
		} else {
			$name  = trim( sprintf( '%s %s', $order->get_billing_first_name(), $order->get_billing_last_name() ) );
			$siret = '';
			$vat   = '';
		}

		// Customer-side reference id — order number is what the merchant
		// and the buyer can both look up.
		$document->setDocumentBuyer( $name, 'ORDER-' . $order->get_order_number() );

		$siren = substr( $siret, 0, 9 );
		if ( $siren !== '' ) {
			$document->setDocumentBuyerLegalOrganisation( $siren, '0002', $name );
		}

		if ( $vat !== '' ) {
			$document->addDocumentBuyerTaxRegistration( 'VA', $vat );
		}

		$document->setDocumentBuyerAddress(
			$order->get_billing_address_1(),
			$order->get_billing_address_2(),
			'',
			$order->get_billing_postcode(),
			$order->get_billing_city(),
			$order->get_billing_country() ?: 'FR'
		);
	}

	/* ----------------------------------------------------------------- */
	/* Payment method                                                     */
	/* ----------------------------------------------------------------- */

	/**
	 * Map the WC payment method to a UNTDID 4461 code.
	 *
	 * The common WC gateways translate to:
	 *   - cod                  -> 10 (in cash / on delivery)
	 *   - bacs (bank transfer) -> 58 (SEPA credit transfer)
	 *   - cheque               -> 20 (cheque)
	 *   - stripe / cards       -> 48 (bank card)
	 *   - paypal               -> 42 (payment to bank account)
	 *
	 * Unknown gateways default to "Other" (1).
	 */
	private static function apply_payment( ZugferdDocumentBuilder $document, \WC_Order $order ): void {
		$gateway = $order->get_payment_method();

		$map = array(
			'cod'    => ZugferdPaymentMeans::UNTDID_4461_10, // cash
			'bacs'   => ZugferdPaymentMeans::UNTDID_4461_58, // SEPA credit transfer
			'cheque' => ZugferdPaymentMeans::UNTDID_4461_20, // cheque
			'stripe' => ZugferdPaymentMeans::UNTDID_4461_48, // card
			'square' => ZugferdPaymentMeans::UNTDID_4461_48,
			'paypal' => ZugferdPaymentMeans::UNTDID_4461_42,
		);

		$code = $map[ $gateway ] ?? ZugferdPaymentMeans::UNTDID_4461_1; // Instrument not defined

		$title = $order->get_payment_method_title() ?: $gateway;
		$document->addDocumentPaymentMean( $code, $title );
	}

	/**
	 * Add a payment term (BR-CO-25 requires either a due date or terms text).
	 *
	 * For V0.1 we always emit a description; the merchant can override the
	 * default phrasing later via a Settings field. No specific due date is
	 * set — "Paiement à réception" implies immediate payment, which is the
	 * dominant pattern for e-commerce.
	 */
	private static function apply_payment_terms( ZugferdDocumentBuilder $document, \WC_Order $order ): void {
		$description = (string) apply_filters(
			'mathisfx_payment_terms',
			__( 'Paiement à réception de la facture.', 'factur-x-for-woocommerce' ),
			$order
		);

		$document->addDocumentPaymentTerm( $description, null, null, null );
	}

	/* ----------------------------------------------------------------- */
	/* Line items                                                         */
	/* ----------------------------------------------------------------- */

	/**
	 * Build the line items from $order->get_items() (products) and
	 * $order->get_items('shipping') (shipping costs).
	 *
	 * Each line carries:
	 *   - position id (1..N, sequential)
	 *   - name + sku
	 *   - billed quantity (with unit code C62 = piece for products,
	 *     and we use C62 for shipping too — semantically not perfect
	 *     but EN 16931 accepts it and merchants don't notice)
	 *   - net unit price + line total
	 *   - one VAT category + rate derived from the line's tax total
	 */
	private static function apply_lines( ZugferdDocumentBuilder $document, \WC_Order $order ): void {
		$position = 0;
		$rate_map = self::get_rate_map( $order );

		// Product lines.
		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			++$position;
			$line_subtotal = (float) $item->get_total();      // ex-VAT
			$quantity      = (float) $item->get_quantity();
			$unit_price    = $quantity > 0 ? round( $line_subtotal / $quantity, 4 ) : 0.0;
			$rate          = self::line_rate( $item, $rate_map );

			$product = $item->get_product();
			$sku     = $product instanceof \WC_Product ? (string) $product->get_sku() : '';

			$document
				->addNewPosition( (string) $position )
				->setDocumentPositionProductDetails(
					$item->get_name(),
					'',
					$sku !== '' ? $sku : null,
					null,
					null,
					null
				)
				->setDocumentPositionNetPrice( $unit_price )
				->setDocumentPositionQuantity( $quantity, ZugferdUnitCodes::REC20_ONE )
				->addDocumentPositionTax(
					// EN 16931 explicitly forbids the ExemptionReason element at line
					// level — it is only valid in the document-level VAT breakdown.
					// We pass categoryCode + typeCode + rate only here.
					self::vat_category_for_rate( $rate ),
					'VAT',
					$rate
				)
				->setDocumentPositionLineSummation( round( $line_subtotal, 2 ) );
		}

		// Shipping as its own line (only if shipping cost is non-zero).
		foreach ( $order->get_items( 'shipping' ) as $shipping_item ) {
			/** @var \WC_Order_Item_Shipping $shipping_item */
			$line_subtotal = (float) $shipping_item->get_total();
			if ( $line_subtotal <= 0 ) {
				continue;
			}
			++$position;
			$rate = self::line_rate( $shipping_item, $rate_map );

			$document
				->addNewPosition( (string) $position )
				->setDocumentPositionProductDetails( $shipping_item->get_name() ?: __( 'Livraison', 'factur-x-for-woocommerce' ) )
				->setDocumentPositionNetPrice( round( $line_subtotal, 4 ) )
				->setDocumentPositionQuantity( 1.0, ZugferdUnitCodes::REC20_ONE )
				->addDocumentPositionTax(
					self::vat_category_for_rate( $rate ),
					'VAT',
					$rate
				)
				->setDocumentPositionLineSummation( round( $line_subtotal, 2 ) );
		}
	}

	/**
	 * Effective VAT rate (percent) for a line, derived from totals.
	 *
	 * EN 16931 BT-152 wants a percentage like 20.00 or 5.50, NOT a decimal.
	 */
	private static function rate_for_line( float $net, float $tax ): float {
		if ( $net <= 0.0 ) {
			return 0.0;
		}
		return round( ( $tax / $net ) * 100, 2 );
	}

	/**
	 * Map WooCommerce tax-rate IDs to their exact percentage.
	 *
	 * Read straight from the order's stored tax items (the rate as applied
	 * at checkout). This is authoritative, unlike deriving the rate from
	 * amounts — derivation rounds 5.5 % to 5.51 % because WC rounds each
	 * line's net and tax to 2 decimals first.
	 *
	 * @return array<int,float> rate_id => percent (e.g. 20.0, 5.5)
	 */
	private static function get_rate_map( \WC_Order $order ): array {
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
	 * amount-derived rate only if WC stored no usable percent. A line with
	 * no effective tax returns 0.0 (handled as exempt downstream).
	 *
	 * @param \WC_Order_Item_Product|\WC_Order_Item_Shipping $item
	 * @param array<int,float>                               $rate_map
	 */
	private static function line_rate( $item, array $rate_map ): float {
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

		// The line carries tax but no usable stored percent (rare): derive
		// from amounts rather than mislabel it exempt. Otherwise: exempt (0 %).
		$net = (float) $item->get_total();
		$tax = (float) $item->get_total_tax();
		return $tax > 0.0 ? self::rate_for_line( $net, $tax ) : 0.0;
	}

	/* ----------------------------------------------------------------- */
	/* Tax breakdown + totals                                             */
	/* ----------------------------------------------------------------- */

	/**
	 * Aggregate VAT by rate across the entire order, then write:
	 *   - one <ApplicableTradeTax> entry per distinct rate
	 *   - the final <SpecifiedTradeSettlementHeaderMonetarySummation>
	 *
	 * Why aggregate ourselves rather than read $order->get_taxes(): WC's
	 * tax items index by tax_rate_id, which doesn't map 1:1 to the percent
	 * we need in EN 16931. Computing from per-line subtotal/tax pairs is
	 * cleaner and matches the line-level rate we already declared above.
	 */
	private static function apply_tax_breakdown_and_summation( ZugferdDocumentBuilder $document, \WC_Order $order ): void {
		// Bucket: rate(%) => [ basis (ex-VAT total), tax_amount ]
		$buckets  = array();
		$rate_map = self::get_rate_map( $order );

		$accumulate = function ( float $net, float $tax, float $rate ) use ( &$buckets ): void {
			if ( $net <= 0.0 ) {
				return;
			}
			// Round per line BEFORE summing. BR-CO-10 requires the document
			// line-total (BT-106) to equal the sum of each line's already-
			// rounded net amount (BT-131). Summing raw nets then rounding once
			// drifts by a cent (e.g. 160.7267 -> 160.73 vs 160.72).
			$net = round( $net, 2 );
			$tax = round( $tax, 2 );
			$key = (string) $rate;
			if ( ! isset( $buckets[ $key ] ) ) {
				$buckets[ $key ] = array(
					'rate'  => $rate,
					'basis' => 0.0,
					'tax'   => 0.0,
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
			$accumulate( (float) $shipping->get_total(), (float) $shipping->get_total_tax(), self::line_rate( $shipping, $rate_map ) );
		}

		$line_total = 0.0;
		$tax_total  = 0.0;
		foreach ( $buckets as $b ) {
			$line_total += $b['basis'];
			$tax_total  += $b['tax'];

			$category = self::vat_category_for_rate( $b['rate'] );
			$document->addDocumentTax(
				$category,
				'VAT',
				round( $b['basis'], 2 ),
				round( $b['tax'], 2 ),
				$b['rate'],
				self::exemption_reason_for( $category )
			);
		}

		$line_total = round( $line_total, 2 );
		$tax_total  = round( $tax_total, 2 );
		$grand      = round( $line_total + $tax_total, 2 );

		// BT-106..BT-115 — document-level monetary summation.
		$document->setDocumentSummation(
			$grand,         // BT-112 Grand total (incl. tax)
			$grand,         // BT-115 Amount due for payment (no prepay in V0.1)
			$line_total,    // BT-106 Sum of line totals
			0.0,            // BT-108 Sum of charges
			0.0,            // BT-107 Sum of allowances
			$line_total,    // BT-109 Tax basis total
			$tax_total,     // BT-110 Tax total amount
			0.0,            // BT-114 Rounding amount
			0.0             // BT-113 Paid amount
		);
	}
}
