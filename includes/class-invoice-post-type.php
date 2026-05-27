<?php
/**
 * Invoice Custom Post Type — internal storage for generated Factur-X invoices.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Registers the `mathisfx_invoice` CPT.
 *
 * Why a CPT and not a custom table:
 *   - WP-native (queryable via WP_Query, indexable via post meta).
 *   - Survives WC HPOS migrations without any plugin work.
 *   - Lets us attach standard postmeta (file path, order link, hash, ...).
 *
 * Why private / hidden:
 *   - These are legal documents, not editorial content. They must NEVER
 *     be publicly addressable, listed in menus, or returned by site search.
 *   - Admin access is exposed instead via the order edit screen metabox
 *     and the orders list column added in Étape 6.
 */
final class InvoicePostType {

    /**
     * Public post type slug. Use this constant everywhere instead of
     * hardcoding the string — it is also the key uninstall.php wipes.
     */
    public const POST_TYPE = 'mathisfx_invoice';

    /**
     * Hook the CPT registration.
     */
    public function __construct() {
        add_action('init', [$this, 'register'], 5);
    }

    /**
     * Register the CPT with WordPress.
     *
     * Every flag here matters — re-enabling any of them turns the CPT into
     * a publicly-listed content type, which we DO NOT want for invoices.
     */
    public function register(): void {
        register_post_type(
            self::POST_TYPE,
            [
                'labels'              => [
                    'name'          => __('Factures Factur-X', 'factur-x-for-woocommerce'),
                    'singular_name' => __('Facture Factur-X', 'factur-x-for-woocommerce'),
                ],
                'public'              => false,
                'publicly_queryable'  => false,
                'show_ui'             => false,
                'show_in_menu'        => false,
                'show_in_nav_menus'   => false,
                'show_in_admin_bar'   => false,
                'show_in_rest'        => false,
                'exclude_from_search' => true,
                'has_archive'         => false,
                'rewrite'             => false,
                'query_var'           => false,
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'supports'            => ['title', 'custom-fields'],
                'can_export'          => true,
                'delete_with_user'    => false,
            ]
        );
    }
}
