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
			__( 'Paiement à réception de la facture.', 'facturio-invoices-for-woocommerce' ),
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
		$rate_map = TaxCalculator::get_rate_map( $order );

		// Product lines.
		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			++$position;
			$line_subtotal = (float) $item->get_total();      // ex-VAT
			$quantity      = (float) $item->get_quantity();
			$unit_price    = $quantity > 0 ? round( $line_subtotal / $quantity, 4 ) : 0.0;
			$rate          = TaxCalculator::line_rate( $item, $rate_map );

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
					TaxCalculator::category_for_rate( $rate ),
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
			$rate = TaxCalculator::line_rate( $shipping_item, $rate_map );

			$document
				->addNewPosition( (string) $position )
				->setDocumentPositionProductDetails( $shipping_item->get_name() ?: __( 'Livraison', 'facturio-invoices-for-woocommerce' ) )
				->setDocumentPositionNetPrice( round( $line_subtotal, 4 ) )
				->setDocumentPositionQuantity( 1.0, ZugferdUnitCodes::REC20_ONE )
				->addDocumentPositionTax(
					TaxCalculator::category_for_rate( $rate ),
					'VAT',
					$rate
				)
				->setDocumentPositionLineSummation( round( $line_subtotal, 2 ) );
		}
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
		// Single source of truth for the per-rate buckets + rounded totals
		// (shared with PdfRenderer so the XML and the PDF can never disagree).
		$breakdown = TaxCalculator::compute_breakdown( $order );

		// One <ApplicableTradeTax> per distinct rate.
		foreach ( $breakdown['buckets'] as $b ) {
			$document->addDocumentTax(
				$b['category'],
				'VAT',
				round( $b['basis'], 2 ),
				round( $b['tax'], 2 ),
				$b['rate'],
				TaxCalculator::exemption_reason_for( $b['category'] )
			);
		}

		$line_total = $breakdown['line_total'];
		$tax_total  = $breakdown['tax_total'];
		$grand      = $breakdown['grand_total'];

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
