<?php
/**
 * PDF Renderer — produces the human-readable PDF half of every Factur-X invoice.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

use TCPDF;

defined( 'ABSPATH' ) || exit;

/**
 * Empty-header/footer subclass of TCPDF.
 *
 * TCPDF's default Header() and Footer() implementations occasionally emit a
 * "Powered by TCPDF" attribution line on the rendered page (the exact
 * mechanism varies between versions / configs). setPrintHeader(false) +
 * setPrintFooter(false) alone don't always suppress it. The simplest
 * robust way is to override both methods to do absolutely nothing.
 */
final class SilentTcpdf extends TCPDF {
	public function Header() {}     // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- TCPDF API
	public function Footer() {}     // phpcs:ignore PSR1.Methods.CamelCapsMethodName -- TCPDF API
}

/**
 * Renders a standard PDF (NOT yet PDF/A-3) from a WC_Order + invoice number.
 *
 * The PDF/A-3 hybrid embedding (PDF + XML CII = Factur-X) happens in Etape 5C
 * via horstoeko\zugferd's ZugferdDocumentPdfBuilder, which takes the PDF
 * produced here and the XML produced by XmlBuilder and merges the two.
 *
 * Why this split:
 *   - PDF rendering is a presentation concern (template, fonts, layout).
 *   - PDF/A-3 + XMP + AFRelationship metadata is a packaging concern.
 *   - Keeping them in distinct classes lets us swap renderers later
 *     (e.g. for theme-able templates, or mPDF instead of TCPDF) without
 *     touching the packaging layer.
 */
final class PdfRenderer {

	/**
	 * Render the invoice PDF and return it as a binary string.
	 *
	 * @param \WC_Order $order          The order to invoice.
	 * @param string    $invoice_number Pre-allocated invoice number.
	 * @return string                   PDF binary content (suitable for
	 *                                  file_put_contents() or embedding).
	 */
	public static function render( \WC_Order $order, string $invoice_number ): string {
		$vars = self::collect_template_vars( $order, $invoice_number );
		$html = self::render_template( 'default', $vars );

		$pdf = self::make_tcpdf( $order, $invoice_number, $vars['seller'] );
		$pdf->AddPage();
		$pdf->writeHTML( $html, true, false, true, false, '' );

		return (string) $pdf->Output( '', 'S' ); // 'S' returns the PDF as a string
	}

	/* ----------------------------------------------------------------- */
	/* TCPDF setup                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Instantiate and configure the TCPDF object.
	 *
	 * The metadata (Title/Author/Subject/Creator) is written into the PDF
	 * dictionary; in 5C horstoeko's PdfBuilder will also overlay PDF/A-3
	 * XMP metadata declaring the Factur-X profile.
	 */
	private static function make_tcpdf( \WC_Order $order, string $invoice_number, array $seller ): TCPDF {
		// 7th constructor param = $pdfa. TCPDF accepts integer values:
		//   1 -> PDF/A-1B (most restrictive, no embedded files allowed)
		//   3 -> PDF/A-3B (allows embedded files — required by Factur-X)
		// We pick 3 because horstoeko's PdfBuilder then attaches the
		// factur-x.xml inside this PDF/A-3 envelope, producing a true
		// Factur-X hybrid. PDF/A-3 mode in TCPDF forces font embedding,
		// sRGB output intent, no transparency, and the PDF/A
		// identification XMP block — all required by FNFE-MPE validation.
		$pdf = new SilentTcpdf( 'P', 'mm', 'A4', true, 'UTF-8', false, 3 );

		$pdf->SetCreator( 'Factur-X for WooCommerce v' . MATHISFX_VERSION );
		$pdf->SetAuthor( $seller['company_name'] ?: 'Factur-X for WooCommerce' );
		$pdf->SetTitle(
			sprintf(
			/* translators: %s = invoice number */
				__( 'Facture %s', 'factur-x-for-woocommerce' ),
				$invoice_number
			)
		);
		$pdf->SetSubject(
			sprintf(
			/* translators: %s = order number */
				__( 'Facture WooCommerce commande #%s', 'factur-x-for-woocommerce' ),
				$order->get_order_number()
			)
		);

		// No default TCPDF header / footer (we render our own in the HTML).
		$pdf->setPrintHeader( false );
		$pdf->setPrintFooter( false );

		$pdf->SetMargins( 15, 15, 15 );
		$pdf->SetAutoPageBreak( true, 15 );

		$pdf->SetFont( 'helvetica', '', 9 );

		return $pdf;
	}

	/* ----------------------------------------------------------------- */
	/* Template loading                                                   */
	/* ----------------------------------------------------------------- */

	/**
	 * Render a template file with the given variables and return the HTML.
	 *
	 * Filterable via `mathisfx_invoice_template_path` so future versions /
	 * Pro features can override the template per-merchant.
	 */
	private static function render_template( string $template_name, array $vars ): string {
		$default_path = MATHISFX_PLUGIN_DIR . 'templates/invoice/' . $template_name . '.php';
		$path         = apply_filters( 'mathisfx_invoice_template_path', $default_path, $template_name, $vars );

		if ( ! file_exists( $path ) ) {
			return '';
		}

		// Extract vars into the template scope. extract is normally a code
		// smell but template engines are exactly its legitimate use case.
		// Controlled template-variable injection: keys are fixed and internal
		// (built by collect_template_vars), never user-supplied.
		extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract

		ob_start();
		include $path;
		return (string) ob_get_clean();
	}

	/* ----------------------------------------------------------------- */
	/* Data extraction                                                    */
	/* ----------------------------------------------------------------- */

	/**
	 * Build the full $vars array passed to the template.
	 */
	private static function collect_template_vars( \WC_Order $order, string $invoice_number ): array {
		$issue_date = $order->get_date_completed() ?: $order->get_date_created();
		// Force French date format on the invoice even when the site locale
		// is en_US (we are explicitly producing a French legal document).
		// Filterable in case a merchant wants their own format.
		$date_format = (string) apply_filters( 'mathisfx_invoice_date_format', 'd/m/Y' );
		$issue_str   = $issue_date ? wp_date( $date_format, $issue_date->getTimestamp() ) : '';

		return array(
			'seller'        => self::get_seller_data(),
			'buyer'         => self::get_buyer_data( $order ),
			'invoice'       => array(
				'number'             => $invoice_number,
				'issue_date_display' => $issue_str,
				'due_date_display'   => '', // V0.5 will add payment terms
				'currency_symbol'    => self::currency_symbol( $order->get_currency() ),
			),
			'lines'         => self::get_lines( $order ),
			'tax_breakdown' => self::get_tax_breakdown( $order ),
			'totals'        => self::get_totals( $order ),
			'appearance'    => self::get_appearance_data(),
		);
	}

	/**
	 * Logo (local file path, suitable for TCPDF + PDF/A-3) and primary color.
	 *
	 * Returns an empty logo_path when no logo is set, or when the attachment
	 * record is broken (deleted file, wrong type, etc.). The template handles
	 * the empty case by simply not rendering the <img>.
	 *
	 * @return array{logo_path: string, primary_color: string}
	 */
	private static function get_appearance_data(): array {
		$color = (string) get_option( 'mathisfx_primary_color', '#2271b1' );
		if ( sanitize_hex_color( $color ) === null ) {
			$color = '#2271b1';
		}

		$logo_id   = (int) get_option( 'mathisfx_logo_attachment_id', 0 );
		$logo_path = '';
		if ( $logo_id > 0 && function_exists( 'get_attached_file' ) ) {
			$candidate = get_attached_file( $logo_id );
			if ( is_string( $candidate ) && $candidate !== '' && file_exists( $candidate ) ) {
				$logo_path = $candidate;
			}
		}

		return array(
			'logo_path'     => $logo_path,
			'primary_color' => $color,
		);
	}

	private static function get_seller_data(): array {
		return array(
			'company_name'   => (string) get_option( 'mathisfx_seller_company_name', '' ),
			'siret'          => (string) get_option( 'mathisfx_seller_siret', '' ),
			'vat'            => (string) get_option( 'mathisfx_seller_vat', '' ),
			'address'        => (string) get_option( 'mathisfx_seller_address', '' ),
			'postal_code'    => (string) get_option( 'mathisfx_seller_postal_code', '' ),
			'city'           => (string) get_option( 'mathisfx_seller_city', '' ),
			'country'        => (string) get_option( 'mathisfx_seller_country', 'FR' ),
			'ape'            => (string) get_option( 'mathisfx_seller_ape_code', '' ),
			'legal_mentions' => (string) get_option( 'mathisfx_legal_mentions', '' ),
		);
	}

	private static function get_buyer_data( \WC_Order $order ): array {
		$is_b2b = ( 'yes' === $order->get_meta( '_mathisfx_is_b2b' ) );

		if ( $is_b2b ) {
			$name  = (string) $order->get_meta( '_mathisfx_company_name' );
			$siret = (string) $order->get_meta( '_mathisfx_siret' );
			$vat   = (string) $order->get_meta( '_mathisfx_vat' );
		} else {
			$name  = trim( sprintf( '%s %s', $order->get_billing_first_name(), $order->get_billing_last_name() ) );
			$siret = '';
			$vat   = '';
		}

		$address_lines = array_values(
			array_filter(
				array(
					$order->get_billing_address_1(),
					$order->get_billing_address_2(),
				)
			)
		);

		return array(
			'name'          => $name,
			'siret'         => $siret,
			'vat'           => $vat,
			'address_lines' => $address_lines,
			'postal_code'   => $order->get_billing_postcode(),
			'city'          => $order->get_billing_city(),
			'country'       => $order->get_billing_country() ?: 'FR',
			'is_b2b'        => $is_b2b,
		);
	}

	/**
	 * Map WC tax-rate IDs to their exact percentage (read from the order's
	 * tax items). Same authoritative approach as XmlBuilder — the displayed
	 * rate must match the embedded XML. (Duplicated here for V0.1; the V0.5
	 * DTO refactor will share a single rate resolver — see DECISIONS.md.)
	 *
	 * @return array<int,float>
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
	 * Exact VAT rate for one line, read from WC (not derived from amounts,
	 * which rounds 5.5 % to 5.51 %). Falls back to derivation only if no
	 * stored percent is usable.
	 *
	 * @param \WC_Order_Item_Product|\WC_Order_Item_Shipping $item
	 * @param array<int,float>                               $rate_map
	 */
	private static function line_rate( $item, array $rate_map ): float {
		$taxes  = $item->get_taxes();
		$totals = ( isset( $taxes['total'] ) && is_array( $taxes['total'] ) ) ? $taxes['total'] : array();

		foreach ( $totals as $rate_id => $amount ) {
			if ( '' === $amount || null === $amount || 0.0 === (float) $amount ) {
				continue;
			}
			if ( isset( $rate_map[ (int) $rate_id ] ) ) {
				return $rate_map[ (int) $rate_id ];
			}
		}

		$net = (float) $item->get_total();
		$tax = (float) $item->get_total_tax();
		return $tax > 0.0 && $net > 0.0 ? round( ( $tax / $net ) * 100, 2 ) : 0.0;
	}

	private static function get_lines( \WC_Order $order ): array {
		$out      = array();
		$rate_map = self::get_rate_map( $order );

		foreach ( $order->get_items() as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$line_total = (float) $item->get_total();
			$quantity   = (float) $item->get_quantity();
			$unit_price = $quantity > 0 ? $line_total / $quantity : 0.0;
			$vat_rate   = self::line_rate( $item, $rate_map );

			$product = $item->get_product();
			$sku     = $product instanceof \WC_Product ? $product->get_sku() : '';

			$out[] = array(
				'name'       => $item->get_name(),
				'sku'        => $sku,
				'quantity'   => $quantity,
				'unit_price' => $unit_price,
				'vat_rate'   => $vat_rate,
				'line_total' => $line_total,
			);
		}

		foreach ( $order->get_items( 'shipping' ) as $shipping ) {
			/** @var \WC_Order_Item_Shipping $shipping */
			$line_total = (float) $shipping->get_total();
			if ( $line_total <= 0 ) {
				continue;
			}
			$vat_rate = self::line_rate( $shipping, $rate_map );

			$out[] = array(
				'name'       => $shipping->get_name() ?: __( 'Livraison', 'factur-x-for-woocommerce' ),
				'sku'        => '',
				'quantity'   => 1,
				'unit_price' => $line_total,
				'vat_rate'   => $vat_rate,
				'line_total' => $line_total,
			);
		}

		return $out;
	}

	private static function get_tax_breakdown( \WC_Order $order ): array {
		$buckets  = array();
		$rate_map = self::get_rate_map( $order );

		$accumulate = function ( float $net, float $tax, float $rate ) use ( &$buckets ) {
			if ( $net <= 0.0 ) {
				return;
			}
			// Round per line before summing, to match the XML breakdown
			// (and BR-CO-10) so the displayed per-rate amounts are consistent.
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

		return array_values( $buckets );
	}

	private static function get_totals( \WC_Order $order ): array {
		$line_total = (float) $order->get_total() - (float) $order->get_total_tax();
		$tax_total  = (float) $order->get_total_tax();
		$grand      = (float) $order->get_total();

		return array(
			'line_total'           => round( $line_total, 2 ),
			'tax_total'            => round( $tax_total, 2 ),
			'grand_total'          => round( $grand, 2 ),
			'due_payable'          => round( $grand, 2 ), // no prepay in V0.1
			'payment_method_title' => $order->get_payment_method_title() ?: $order->get_payment_method(),
		);
	}

	/**
	 * Minimal currency-code -> symbol mapping covering EUR/USD/GBP/CHF.
	 *
	 * For unknown codes we fall back to WC's helper. Most WC FR shops are
	 * EUR so this almost always hits the first branch.
	 */
	private static function currency_symbol( string $code ): string {
		$map = array(
			'EUR' => '€',
			'USD' => '$',
			'GBP' => '£',
			'CHF' => 'CHF',
		);
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		if ( function_exists( 'get_woocommerce_currency_symbol' ) ) {
			$symbol = get_woocommerce_currency_symbol( $code );
			if ( $symbol ) {
				// WC returns HTML entities (e.g. &euro;) — decode for TCPDF.
				return html_entity_decode( $symbol, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			}
		}
		return $code;
	}
}
