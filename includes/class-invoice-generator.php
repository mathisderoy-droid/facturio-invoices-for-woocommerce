<?php
/**
 * Invoice Generator — orchestrates Factur-X production end-to-end.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

use horstoeko\zugferd\ZugferdDocumentPdfBuilder;

defined('ABSPATH') || exit;

/**
 * Glues XmlBuilder + PdfRenderer + horstoeko's PdfBuilder together.
 *
 * Flow on woocommerce_order_status_completed:
 *   1. Skip if auto-generation is disabled, or if an invoice already
 *      exists for this order (idempotent).
 *   2. Validate that the seller has the legally-required fields set
 *      (raison sociale + SIRET). If not, bail out and log — better to
 *      produce no invoice than to produce a non-conformant one.
 *   3. Consume a fresh invoice number via InvoiceNumbering.
 *   4. Build the CII XML (XmlBuilder) and the visual PDF (PdfRenderer).
 *   5. Merge them through horstoeko\zugferd's PdfBuilder, which:
 *        - promotes the PDF to PDF/A-3
 *        - attaches the XML as `factur-x.xml` with AFRelationship Alternative
 *        - injects the Factur-X XMP metadata block (DocumentType, Version,
 *          ConformanceLevel, DocumentFileName)
 *   6. Persist the final PDF to wp-content/uploads/factur-x/{YYYY}/{MM}/.
 *   7. Create a CPT mathisfx_invoice post with meta linking back to the
 *      order, and store the invoice number + post id on the order.
 *
 * Errors after step 3 leave a gap in the numbering sequence. For V0.1
 * we accept this risk and only validate the most common failure case
 * (missing seller info) BEFORE step 3 — a future Pro feature can add
 * gap detection and recovery.
 */
final class InvoiceGenerator {

    /**
     * Wire up the hook.
     */
    public function __construct() {
        add_action('woocommerce_order_status_completed', [$this, 'maybe_generate_invoice'], 10, 2);
    }

    /**
     * Hook callback. Decides whether to generate, swallows exceptions.
     *
     * @param int            $order_id Order id (legacy storage style).
     * @param \WC_Order|null $order    HPOS provides the order object too.
     */
    public function maybe_generate_invoice(int $order_id, $order = null): void {
        if ('yes' !== get_option('mathisfx_auto_generate', 'yes')) {
            return;
        }

        if (!$order instanceof \WC_Order) {
            $order = wc_get_order($order_id);
        }
        if (!$order instanceof \WC_Order) {
            return;
        }

        // Idempotency — if we already issued an invoice for this order, stop.
        if ($order->get_meta('_mathisfx_invoice_number')) {
            return;
        }

        try {
            $this->generate_for_order($order);
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[mathisfx] Invoice generation failed for order #%d: %s',
                $order->get_id(),
                $e->getMessage()
            ));
            // Don't propagate — we don't want a generation failure to
            // block the order status change itself.
        }
    }

    /**
     * Core generation logic, public so it can be called manually
     * (admin "Regenerate" button in Étape 6).
     *
     * Returns the metadata of the generated invoice. Throws on any failure.
     *
     * @return array{invoice_number: string, pdf_path: string, pdf_url: string, post_id: int}
     */
    public function generate_for_order(\WC_Order $order): array {
        $this->require_seller_info();

        $invoice_number = InvoiceNumbering::get_next_invoice_number();

        // Build the XML document and the visual PDF.
        $zugferd_doc = XmlBuilder::build_document($order, $invoice_number);
        $pdf_binary  = PdfRenderer::render($order, $invoice_number);

        // Merge into Factur-X PDF/A-3 with embedded XML.
        $factur_x_binary = $this->merge_into_factur_x($zugferd_doc, $pdf_binary, $invoice_number);

        // Persist to wp-content/uploads/factur-x/YYYY/MM/.
        $paths = $this->save_to_uploads($factur_x_binary, $invoice_number);

        // Create the CPT post for this invoice.
        $post_id = $this->create_invoice_post($order, $invoice_number, $paths, $factur_x_binary);

        // Link the order back to the invoice (HPOS-safe).
        $order->update_meta_data('_mathisfx_invoice_post_id', $post_id);
        $order->update_meta_data('_mathisfx_invoice_number', $invoice_number);
        $order->update_meta_data('_mathisfx_invoice_pdf_path', $paths['rel_path']);
        $order->save();

        return [
            'invoice_number' => $invoice_number,
            'pdf_path'       => $paths['abs_path'],
            'pdf_url'        => $paths['url'],
            'post_id'        => $post_id,
        ];
    }

    /* ----------------------------------------------------------------- */
    /* Validation                                                         */
    /* ----------------------------------------------------------------- */

    /**
     * Refuses to generate if mandatory seller fields are missing.
     *
     * A French invoice MUST contain the seller's legal name and SIRET to
     * be legally valid. Without them we'd produce a non-conformant
     * Factur-X — better to bail out and surface the issue.
     */
    private function require_seller_info(): void {
        $company = trim((string) get_option('mathisfx_seller_company_name', ''));
        $siret   = trim((string) get_option('mathisfx_seller_siret', ''));

        if ($company === '' || $siret === '') {
            throw new \RuntimeException(
                'Coordonnées vendeur incomplètes : remplissez au minimum la raison sociale et le SIRET dans WooCommerce → Réglages → Factur-X.'
            );
        }
    }

    /* ----------------------------------------------------------------- */
    /* PDF/A-3 merge                                                      */
    /* ----------------------------------------------------------------- */

    /**
     * Wrap the PDF and XML into a single Factur-X PDF/A-3 document.
     */
    private function merge_into_factur_x(
        \horstoeko\zugferd\ZugferdDocumentBuilder $zugferd_doc,
        string $pdf_binary,
        string $invoice_number
    ): string {
        $builder = new ZugferdDocumentPdfBuilder($zugferd_doc, $pdf_binary);

        // AFRelationship: Alternative — the XML and the PDF render the same
        // semantic content. This is the value the Factur-X 1.08 spec
        // mandates for the embedded XML attachment.
        $builder->setAttachmentRelationshipTypeToAlternative();

        // Make the attachment visible in the PDF reader's sidebar so the
        // recipient sees the XML attachment without digging through menus.
        $builder->showAttachmentPane();

        // Brand the PDF Creator field with our plugin name + version, so
        // viewers (Adobe, Foxit, browser PDF) display "Factur-X for
        // WooCommerce v0.1.0" in File → Properties → Creator instead of
        // just the underlying horstoeko/TCPDF default. Visible only in the
        // properties pane, never on the rendered page.
        $builder->setAdditionalCreatorTool(
            sprintf('Factur-X for WooCommerce v%s', MATHISFX_VERSION)
        );

        $builder->generateDocument();
        return $builder->downloadString();
    }

    /* ----------------------------------------------------------------- */
    /* File storage                                                       */
    /* ----------------------------------------------------------------- */

    /**
     * Write the final Factur-X PDF into wp-content/uploads/factur-x/YYYY/MM/.
     *
     * @return array{abs_path: string, rel_path: string, url: string, filename: string}
     */
    private function save_to_uploads(string $binary, string $invoice_number): array {
        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            throw new \RuntimeException('wp_upload_dir error: ' . $upload['error']);
        }

        $year     = wp_date('Y');
        $month    = wp_date('m');
        $rel_dir  = sprintf('factur-x/%s/%s', $year, $month);
        $abs_dir  = trailingslashit($upload['basedir']) . $rel_dir;

        if (!wp_mkdir_p($abs_dir)) {
            throw new \RuntimeException("Impossible de créer le dossier {$abs_dir}");
        }

        $filename = sanitize_file_name($invoice_number . '.pdf');
        $abs_path = trailingslashit($abs_dir) . $filename;
        $rel_path = $rel_dir . '/' . $filename;
        $url      = trailingslashit($upload['baseurl']) . $rel_path;

        $bytes_written = file_put_contents($abs_path, $binary);
        if ($bytes_written === false || $bytes_written !== strlen($binary)) {
            throw new \RuntimeException("Échec écriture du PDF dans {$abs_path}");
        }

        return [
            'abs_path' => $abs_path,
            'rel_path' => $rel_path,
            'url'      => $url,
            'filename' => $filename,
        ];
    }

    /* ----------------------------------------------------------------- */
    /* CPT persistence                                                    */
    /* ----------------------------------------------------------------- */

    /**
     * Create one mathisfx_invoice post per generated invoice.
     */
    private function create_invoice_post(
        \WC_Order $order,
        string $invoice_number,
        array $paths,
        string $binary
    ): int {
        $post_id = wp_insert_post([
            'post_type'   => InvoicePostType::POST_TYPE,
            'post_title'  => $invoice_number,
            'post_status' => 'private',
            'post_author' => get_current_user_id() ?: 1,
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            $msg = is_wp_error($post_id) ? $post_id->get_error_message() : 'unknown';
            throw new \RuntimeException("Échec création CPT mathisfx_invoice: {$msg}");
        }

        update_post_meta((int) $post_id, '_mathisfx_order_id',        $order->get_id());
        update_post_meta((int) $post_id, '_mathisfx_invoice_number',  $invoice_number);
        update_post_meta((int) $post_id, '_mathisfx_pdf_path',        $paths['rel_path']);
        update_post_meta((int) $post_id, '_mathisfx_pdf_url',         $paths['url']);
        update_post_meta((int) $post_id, '_mathisfx_generated_at',    current_time('mysql'));
        update_post_meta((int) $post_id, '_mathisfx_sha256',          hash('sha256', $binary));
        update_post_meta((int) $post_id, '_mathisfx_filesize',        strlen($binary));

        return (int) $post_id;
    }
}
