<?php
/**
 * Uninstall script — runs when the plugin is deleted from WP admin (NOT on deactivate).
 *
 * Removes plugin options and order meta keys. Keeps generated invoices in
 * wp-content/uploads/factur-x/ because they are legal documents that must
 * remain accessible after uninstall.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

// Bail if not called from the WP uninstall flow.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// Delete all plugin options (prefixed mathisfx_).
$mathisfx_options = [
    'mathisfx_seller_company_name',
    'mathisfx_seller_siret',
    'mathisfx_seller_vat',
    'mathisfx_seller_address',
    'mathisfx_seller_postal_code',
    'mathisfx_seller_city',
    'mathisfx_seller_country',
    'mathisfx_seller_ape_code',
    'mathisfx_legal_mentions',
    'mathisfx_invoice_prefix',
    'mathisfx_invoice_counter',
    'mathisfx_auto_generate',
    'mathisfx_insee_api_key',
];
foreach ($mathisfx_options as $option) {
    delete_option($option);
    delete_site_option($option);
}

// Delete every mathisfx_* post meta key (covers both legacy post-meta storage
// and the CPT mathisfx_invoice meta).
$wpdb->query(
    "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'mathisfx\\_%' ESCAPE '\\\\'"
);

// HPOS — same cleanup on the WooCommerce custom orders meta table when it exists.
$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
$table_exists    = $wpdb->get_var(
    $wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta_table)
);
if ($table_exists === $hpos_meta_table) {
    $wpdb->query(
        "DELETE FROM {$hpos_meta_table} WHERE meta_key LIKE 'mathisfx\\_%' ESCAPE '\\\\'"
    );
}

/*
 * Intentionally NOT deleted:
 *   wp-content/uploads/factur-x/**  (legal invoice archive — must persist).
 *   CPT posts of type mathisfx_invoice  (kept as a fallback audit trail).
 */
