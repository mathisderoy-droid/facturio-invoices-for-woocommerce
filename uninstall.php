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
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete all plugin options (prefixed mathisfx_).
$mathisfx_options = array(
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
	'mathisfx_invoice_number_padding',
	'mathisfx_invoice_reset_yearly',
	'mathisfx_invoice_counter',
	'mathisfx_auto_generate',
	'mathisfx_auto_generate_status',
	'mathisfx_insee_api_key',
	'mathisfx_logo_attachment_id',
	'mathisfx_primary_color',
);
foreach ( $mathisfx_options as $mathisfx_option ) {
	delete_option( $mathisfx_option );
	delete_site_option( $mathisfx_option );
}

// One-time uninstall cleanup. Direct queries are appropriate here (no caching
// concern on uninstall) and the only interpolated identifier is a table name
// derived from $wpdb, which cannot be bound via prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Delete every mathisfx_* post meta key (covers both legacy post-meta storage
// and the CPT mathisfx_invoice meta).
$wpdb->query(
	"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE 'mathisfx\\_%' ESCAPE '\\\\'"
);

// HPOS — same cleanup on the WooCommerce custom orders meta table when it exists.
$mathisfx_hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';
$mathisfx_table_exists    = $wpdb->get_var(
	$wpdb->prepare( 'SHOW TABLES LIKE %s', $mathisfx_hpos_meta_table )
);
if ( $mathisfx_table_exists === $mathisfx_hpos_meta_table ) {
	$wpdb->query(
		"DELETE FROM {$mathisfx_hpos_meta_table} WHERE meta_key LIKE 'mathisfx\\_%' ESCAPE '\\\\'"
	);
}
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/*
 * Intentionally NOT deleted:
 *   wp-content/uploads/factur-x/**  (legal invoice archive — must persist).
 *   CPT posts of type mathisfx_invoice  (kept as a fallback audit trail).
 */
