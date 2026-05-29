<?php
/**
 * Admin download + regenerate endpoints.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes the two privileged HTTP entry points exposed by Etape 6:
 *
 *   GET /wp-admin/admin-post.php?action=mathisfx_download_invoice&invoice_id=...&_wpnonce=...
 *   GET /wp-admin/admin-post.php?action=mathisfx_regenerate_invoice&order_id=...&_wpnonce=...
 *
 * Both endpoints require:
 *   - A valid per-resource WP nonce.
 *   - The current user to have the 'manage_woocommerce' capability.
 *
 * The download endpoint streams the binary PDF straight from disk with
 * `application/pdf` + `Content-Disposition: attachment`. The regenerate
 * endpoint wipes the existing invoice meta on the order, re-runs
 * InvoiceGenerator::generate_for_order(), and redirects back to the
 * order edit screen with a query flag for the success/error notice.
 */
final class AdminDownload {

	/**
	 * Wire up admin-post handlers.
	 */
	public function __construct() {
		add_action( 'admin_post_mathisfx_download_invoice', array( $this, 'serve_download' ) );
		add_action( 'admin_post_mathisfx_regenerate_invoice', array( $this, 'handle_regenerate' ) );

		// Admin notice shown after a regenerate round-trip.
		add_action( 'admin_notices', array( $this, 'maybe_show_regenerate_notice' ) );
	}

	/* ----------------------------------------------------------------- */
	/* URL helpers                                                        */
	/* ----------------------------------------------------------------- */

	/**
	 * Build a nonced download URL for the given invoice CPT post id.
	 */
	public static function get_download_url( int $invoice_post_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'mathisfx_download_invoice',
					'invoice_id' => $invoice_post_id,
				),
				admin_url( 'admin-post.php' )
			),
			'mathisfx_download_' . $invoice_post_id
		);
	}

	/**
	 * Build a nonced regenerate URL for the given order id.
	 */
	public static function get_regenerate_url( int $order_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'mathisfx_regenerate_invoice',
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'mathisfx_regenerate_' . $order_id
		);
	}

	/* ----------------------------------------------------------------- */
	/* Download                                                           */
	/* ----------------------------------------------------------------- */

	/**
	 * Stream the PDF for an invoice CPT post, with proper headers.
	 */
	public function serve_download(): void {
		$invoice_id = isset( $_GET['invoice_id'] ) ? absint( $_GET['invoice_id'] ) : 0;
		check_admin_referer( 'mathisfx_download_' . $invoice_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'factur-x-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$post = get_post( $invoice_id );
		if ( ! $post || $post->post_type !== InvoicePostType::POST_TYPE ) {
			wp_die( esc_html__( 'Facture introuvable.', 'factur-x-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		$rel_path   = (string) get_post_meta( $invoice_id, '_mathisfx_pdf_path', true );
		$upload_dir = wp_upload_dir();
		$abs_path   = trailingslashit( (string) $upload_dir['basedir'] ) . $rel_path;
		$abs_path   = wp_normalize_path( $abs_path );

		if ( $rel_path === '' || ! file_exists( $abs_path ) || ! is_readable( $abs_path ) ) {
			wp_die( esc_html__( 'Le fichier PDF est introuvable sur le serveur.', 'factur-x-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		$filename = basename( $abs_path );

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $abs_path ) );
		header( 'X-Content-Type-Options: nosniff' );

		// Discard any output buffers that might have accumulated.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		readfile( $abs_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming a binary file download.
		exit;
	}

	/* ----------------------------------------------------------------- */
	/* Regenerate                                                         */
	/* ----------------------------------------------------------------- */

	/**
	 * Drop the order's existing invoice meta and re-run the generator.
	 *
	 * The previous CPT post + PDF on disk are intentionally NOT deleted —
	 * they remain as an audit trail (French law: invoices issued must be
	 * archived 10 years, even superseded ones).
	 */
	public function handle_regenerate(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		check_admin_referer( 'mathisfx_regenerate_' . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'factur-x-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Commande introuvable.', 'factur-x-for-woocommerce' ), '', array( 'response' => 404 ) );
		}

		// Forget the previous invoice link so generate_for_order issues fresh.
		$order->delete_meta_data( '_mathisfx_invoice_number' );
		$order->delete_meta_data( '_mathisfx_invoice_post_id' );
		$order->delete_meta_data( '_mathisfx_invoice_pdf_path' );
		$order->save();

		$result_flag = 'success';
		try {
			( new InvoiceGenerator() )->generate_for_order( $order );
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional failure diagnostics, debug-only.
				error_log( '[mathisfx] Manual regenerate failed for order #' . $order_id . ': ' . $e->getMessage() );
			}
			$result_flag = 'error';
		}

		$redirect = add_query_arg(
			array(
				'page'                 => 'wc-orders',
				'action'               => 'edit',
				'id'                   => $order_id,
				'mathisfx_regenerated' => $result_flag,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Show a one-shot notice on the order edit screen after regenerate.
	 */
	public function maybe_show_regenerate_notice(): void {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['mathisfx_regenerated'] ) ) {
			return;
		}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$flag = sanitize_text_field( wp_unslash( (string) $_GET['mathisfx_regenerated'] ) );

		if ( $flag === 'error' ) {
			printf(
				'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
				esc_html__( 'La régénération de la facture a échoué. Consultez le journal de débogage pour le détail.', 'factur-x-for-woocommerce' )
			);
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Facture régénérée avec succès. L\'ancien PDF reste archivé.', 'factur-x-for-woocommerce' )
		);
	}
}
