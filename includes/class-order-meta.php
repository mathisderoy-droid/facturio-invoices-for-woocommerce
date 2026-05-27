<?php
/**
 * Order meta — persists B2B checkout fields onto the WooCommerce order.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Saves the B2B fields submitted at checkout into the order's meta data.
 *
 * Uses $order->update_meta_data() / $order->save() exclusively — HPOS-compatible.
 * Never calls update_post_meta() on the order ID directly (that would silently
 * break when HPOS is enabled, which is the default for new WC installs since
 * version 8.2).
 *
 * Meta keys are prefixed with an underscore (_mathisfx_*) to mark them as
 * internal — WC convention is that underscore-prefixed meta is hidden from
 * the default "Custom Fields" UI in the order edit screen.
 */
final class OrderMeta {

    /**
     * Map of $_POST keys to order meta keys.
     */
    private const FIELD_MAP = [
        'mathisfx_company_name' => '_mathisfx_company_name',
        'mathisfx_siret'        => '_mathisfx_siret',
        'mathisfx_vat'          => '_mathisfx_vat',
        'mathisfx_ape_code'     => '_mathisfx_ape_code',
    ];

    /**
     * Wire up the hooks.
     */
    public function __construct() {
        add_action('woocommerce_checkout_create_order', [$this, 'save_b2b_fields'], 10, 2);
        add_action('add_meta_boxes',                    [$this, 'register_metabox']);
    }

    /**
     * Register the "Informations B2B" sidebar metabox on the order screen.
     *
     * Uses wc_get_page_screen_id() which returns the correct screen id for
     * both legacy storage ('shop_order') and HPOS ('woocommerce_page_wc-orders').
     */
    public function register_metabox(): void {
        if (!function_exists('wc_get_page_screen_id')) {
            return;
        }

        add_meta_box(
            'mathisfx_order_b2b_meta',
            __('Informations B2B', 'factur-x-for-woocommerce'),
            [$this, 'render_metabox'],
            wc_get_page_screen_id('shop-order'),
            'side',
            'default'
        );
    }

    /**
     * Render the metabox contents.
     *
     * In HPOS the second argument is a WC_Order, in legacy storage it is a
     * WP_Post — we accept both for cross-mode compatibility.
     *
     * @param \WP_Post|\WC_Order $post_or_order
     */
    public function render_metabox($post_or_order): void {
        $order = $post_or_order instanceof \WC_Order
            ? $post_or_order
            : wc_get_order($post_or_order->ID ?? 0);

        if (!$order instanceof \WC_Order) {
            return;
        }

        if ('yes' !== $order->get_meta('_mathisfx_is_b2b')) {
            echo '<p>' . esc_html__('Commande B2C (particulier). Pas de Factur-X applicable.', 'factur-x-for-woocommerce') . '</p>';
            return;
        }

        $rows = [
            __('Raison sociale', 'factur-x-for-woocommerce') => $order->get_meta('_mathisfx_company_name'),
            __('SIRET', 'factur-x-for-woocommerce')          => $order->get_meta('_mathisfx_siret'),
            __('TVA intra', 'factur-x-for-woocommerce')      => $order->get_meta('_mathisfx_vat'),
            __('Code APE', 'factur-x-for-woocommerce')       => $order->get_meta('_mathisfx_ape_code'),
        ];

        echo '<table class="widefat striped" style="margin-top:0;"><tbody>';
        foreach ($rows as $label => $value) {
            echo '<tr>';
            echo '<th style="text-align:left;padding:6px;">' . esc_html($label) . '</th>';
            echo '<td style="padding:6px;">' . esc_html($value !== '' ? $value : '—') . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Persist the B2B fields onto the order.
     *
     * Fires during order creation, BEFORE the order is written to the DB.
     * Setting meta here means it is saved atomically with the rest of the
     * order — no separate UPDATE round-trip.
     *
     * phpcs:disable WordPress.Security.NonceVerification.Missing
     *   -- WC verifies the checkout nonce before firing this hook.
     *
     * @param \WC_Order $order Order being created.
     * @param array     $data  Posted form data after WC sanitization.
     */
    public function save_b2b_fields(\WC_Order $order, array $data): void {
        // Bail out if the B2B checkbox is not ticked.
        if (!isset($_POST['mathisfx_is_b2b']) || $_POST['mathisfx_is_b2b'] !== '1') {
            return;
        }

        $order->update_meta_data('_mathisfx_is_b2b', 'yes');

        foreach (self::FIELD_MAP as $post_key => $meta_key) {
            if (!isset($_POST[$post_key])) {
                continue;
            }

            $value = sanitize_text_field(wp_unslash($_POST[$post_key]));

            // Field-specific normalization.
            if ($post_key === 'mathisfx_siret') {
                $value = preg_replace('/\D+/', '', $value);
            } elseif ($post_key === 'mathisfx_vat') {
                $value = ViesValidator::normalize($value);
            } elseif ($post_key === 'mathisfx_ape_code') {
                $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));
            }

            $order->update_meta_data($meta_key, $value);
        }
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing
}
