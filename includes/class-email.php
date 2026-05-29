<?php
/**
 * Email — attaches the Factur-X PDF to the relevant WooCommerce emails.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Hooks woocommerce_email_attachments to ship the generated Factur-X PDF
 * to the customer alongside the standard WooCommerce order email.
 *
 * Target emails (filterable via `mathisfx_invoice_email_ids`):
 *   - customer_completed_order  — "Votre commande est terminée"
 *   - customer_processing_order — "Votre commande est en cours"
 *   - customer_invoice          — manual invoice email a merchant can send
 *
 * Both order emails are targeted because the auto-generation trigger is
 * configurable (processing OR completed). Whichever fires, the invoice
 * already exists on disk because InvoiceGenerator runs at priority 5 on
 * the same status hook (before WC builds the email at priority 10).
 *
 * Attachment is conditional: if no invoice exists for the order yet
 * (e.g. seller info incomplete, generation disabled, or this email is
 * not the configured trigger), nothing is attached — we never break the
 * email by pointing at a missing file.
 */
final class Email {

    /**
     * Wire the attachment filter. 4 args: attachments, email_id, object, email.
     */
    public function __construct() {
        add_filter('woocommerce_email_attachments', [$this, 'attach_invoice'], 10, 4);
    }

    /**
     * Append the order's Factur-X PDF path to the email attachments.
     *
     * @param mixed                 $attachments Array of file paths (defensive: may arrive non-array).
     * @param string                $email_id    WC email identifier.
     * @param mixed                 $object      Usually the WC_Order, but WC passes other objects for some emails.
     * @param \WC_Email|null        $email       The email instance (unused, kept for signature completeness).
     * @return array
     */
    public function attach_invoice($attachments, $email_id, $object, $email = null): array {
        if (!is_array($attachments)) {
            $attachments = [];
        }

        $target_ids = (array) apply_filters('mathisfx_invoice_email_ids', [
            'customer_completed_order',
            'customer_processing_order',
            'customer_invoice',
        ]);

        if (!in_array($email_id, $target_ids, true)) {
            return $attachments;
        }

        if (!$object instanceof \WC_Order) {
            return $attachments;
        }

        $path = $this->resolve_invoice_path($object);
        if ($path !== '' && file_exists($path) && is_readable($path)) {
            $attachments[] = $path;
        }

        return $attachments;
    }

    /**
     * Resolve the absolute path to the order's Factur-X PDF, or '' if none.
     */
    private function resolve_invoice_path(\WC_Order $order): string {
        $rel = (string) $order->get_meta('_mathisfx_invoice_pdf_path');
        if ($rel === '') {
            return '';
        }

        $upload = wp_upload_dir();
        if (!empty($upload['error'])) {
            return '';
        }

        return wp_normalize_path(trailingslashit((string) $upload['basedir']) . $rel);
    }
}
