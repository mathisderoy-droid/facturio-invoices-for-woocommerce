<?php
/**
 * "Facture Factur-X" metabox on the WooCommerce order edit screen.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a sidebar metabox with the invoice metadata + Download +
 * Regenerate actions. Distinct from the "Informations B2B" metabox
 * registered in OrderMeta (Etape 4A) — the two live side by side.
 *
 * HPOS-compatible: uses wc_get_page_screen_id() so the same metabox
 * is registered on both the legacy `shop_order` post screen and the
 * new `woocommerce_page_wc-orders` HPOS edit screen.
 */
final class AdminOrderMetabox {

	/**
	 * Hook the metabox at a slightly later priority than OrderMeta's
	 * "Informations B2B" so they stack predictably in the sidebar.
	 */
	public function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'register' ), 20 );
	}

	public function register(): void {
		if ( ! function_exists( 'wc_get_page_screen_id' ) ) {
			return;
		}
		add_meta_box(
			'mathisfx_order_invoice',
			__( 'Facture Factur-X', 'facturflow-invoices-for-woocommerce' ),
			array( $this, 'render' ),
			wc_get_page_screen_id( 'shop-order' ),
			'side',
			'high'
		);
	}

	/**
	 * @param \WP_Post|\WC_Order $post_or_order
	 */
	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof \WC_Order
			? $post_or_order
			: wc_get_order( $post_or_order->ID ?? 0 );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$invoice_number  = (string) $order->get_meta( '_mathisfx_invoice_number' );
		$invoice_post_id = (int) $order->get_meta( '_mathisfx_invoice_post_id' );

		echo '<div class="mathisfx-invoice-metabox">';

		if ( $invoice_number === '' || $invoice_post_id <= 0 ) {
			$this->render_empty_state( $order );
		} else {
			$this->render_filled_state( $order, $invoice_number, $invoice_post_id );
		}

		echo '</div>';
	}

	/**
	 * No invoice yet — show a one-click "Generate now" button.
	 */
	private function render_empty_state( \WC_Order $order ): void {
		echo '<p>' . esc_html__( 'Aucune facture générée pour cette commande.', 'facturflow-invoices-for-woocommerce' ) . '</p>';

		$url = AdminDownload::get_regenerate_url( $order->get_id() );
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Générer la facture maintenant', 'facturflow-invoices-for-woocommerce' )
		);
		echo '<p class="description">' . esc_html__( 'Consomme le prochain numéro de facture et crée le PDF Factur-X conforme EN 16931.', 'facturflow-invoices-for-woocommerce' ) . '</p>';
	}

	/**
	 * Invoice exists — show metadata + Download + Regenerate.
	 */
	private function render_filled_state( \WC_Order $order, string $invoice_number, int $invoice_post_id ): void {
		$generated_at = (string) get_post_meta( $invoice_post_id, '_mathisfx_generated_at', true );
		$filesize     = (int) get_post_meta( $invoice_post_id, '_mathisfx_filesize', true );

		echo '<table class="widefat striped" style="margin-top:0;border:0;"><tbody>';
		printf(
			'<tr><th style="text-align:left;padding:6px;width:90px;">%s</th><td style="padding:6px;"><strong>%s</strong></td></tr>',
			esc_html__( 'Numéro', 'facturflow-invoices-for-woocommerce' ),
			esc_html( $invoice_number )
		);
		if ( $generated_at !== '' ) {
			printf(
				'<tr><th style="text-align:left;padding:6px;">%s</th><td style="padding:6px;">%s</td></tr>',
				esc_html__( 'Émise le', 'facturflow-invoices-for-woocommerce' ),
				esc_html( $generated_at )
			);
		}
		if ( $filesize > 0 ) {
			printf(
				'<tr><th style="text-align:left;padding:6px;">%s</th><td style="padding:6px;">%s</td></tr>',
				esc_html__( 'Taille', 'facturflow-invoices-for-woocommerce' ),
				esc_html( size_format( $filesize ) )
			);
		}
		echo '</tbody></table>';

		$download_url   = AdminDownload::get_download_url( $invoice_post_id );
		$regenerate_url = AdminDownload::get_regenerate_url( $order->get_id() );

		printf(
			'<p style="margin-top:1em;"><a href="%s" class="button button-primary" style="width:100%%;text-align:center;">%s</a></p>',
			esc_url( $download_url ),
			esc_html__( 'Télécharger le PDF', 'facturflow-invoices-for-woocommerce' )
		);
		printf(
			'<p><a href="%s" class="button" style="width:100%%;text-align:center;" onclick="return confirm(\'%s\');">%s</a></p>',
			esc_url( $regenerate_url ),
			esc_attr__( 'La facture actuelle est conservée comme archive (obligation légale). Un nouveau numéro sera attribué à la nouvelle version. Continuer ?', 'facturflow-invoices-for-woocommerce' ),
			esc_html__( 'Régénérer la facture', 'facturflow-invoices-for-woocommerce' )
		);
	}
}
