<?php
/**
 * "Facture" column in the WooCommerce Orders list (HPOS + legacy).
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Adds a single column to the orders list showing the invoice number and
 * a one-click download link when an invoice has been generated.
 *
 * Wired for both modes:
 *   - HPOS:   manage_woocommerce_page_wc-orders_columns
 *             manage_woocommerce_page_wc-orders_custom_column
 *   - Legacy: manage_edit-shop_order_columns
 *             manage_shop_order_posts_custom_column
 */
final class AdminOrders {

	/**
	 * Internal column key. Prefix-disambiguated as usual.
	 */
	private const COLUMN_KEY = 'mathisfx_invoice';

	/**
	 * Wire up both column-set filters and both rendering actions.
	 */
	public function __construct() {
		// Legacy post-based shop_order list.
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_column_legacy' ), 10, 2 );

		// HPOS-based wc-orders list.
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_column_hpos' ), 10, 2 );
	}

	/**
	 * Insert the "Facture" column after the order total.
	 *
	 * Falls back to appending at the end if 'order_total' isn't found
	 * (e.g. plugins that rearrange columns).
	 */
	public function add_column( array $columns ): array {
		$insert_after = 'order_total';
		$new          = array();
		$inserted     = false;

		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === $insert_after ) {
				$new[ self::COLUMN_KEY ] = __( 'Facture', 'facturflow-invoices-for-woocommerce' );
				$inserted                = true;
			}
		}

		if ( ! $inserted ) {
			$new[ self::COLUMN_KEY ] = __( 'Facture', 'facturflow-invoices-for-woocommerce' );
		}

		return $new;
	}

	/**
	 * Render for legacy storage (action signature: column, post_id).
	 */
	public function render_column_legacy( string $column, int $post_id ): void {
		if ( $column !== self::COLUMN_KEY ) {
			return;
		}
		$order = wc_get_order( $post_id );
		if ( $order instanceof \WC_Order ) {
			$this->render_cell( $order );
		}
	}

	/**
	 * Render for HPOS (action signature: column, WC_Order).
	 */
	public function render_column_hpos( string $column, $order ): void {
		if ( $column !== self::COLUMN_KEY ) {
			return;
		}
		if ( $order instanceof \WC_Order ) {
			$this->render_cell( $order );
		}
	}

	/**
	 * Cell content — invoice number + download link, or em-dash.
	 */
	private function render_cell( \WC_Order $order ): void {
		$invoice_number  = (string) $order->get_meta( '_mathisfx_invoice_number' );
		$invoice_post_id = (int) $order->get_meta( '_mathisfx_invoice_post_id' );

		if ( $invoice_number === '' || $invoice_post_id <= 0 ) {
			echo '<span style="color:#999">—</span>';
			return;
		}

		$download_url = AdminDownload::get_download_url( $invoice_post_id );
		printf(
			'<a href="%s" title="%s">%s <span aria-hidden="true">⬇</span></a>',
			esc_url( $download_url ),
			esc_attr__( 'Télécharger le PDF Factur-X', 'facturflow-invoices-for-woocommerce' ),
			esc_html( $invoice_number )
		);
	}
}
