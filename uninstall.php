<?php
/**
 * Uninstall script — runs when the plugin is deleted from WP admin (NOT on deactivate).
 *
 * Removes the plugin's CONFIGURATION (settings) and its temporary caches only.
 *
 * It deliberately PRESERVES every business/legal record, because auto-erasing
 * those on a plugin uninstall is the wrong default for an invoicing tool:
 *   - the Factur-X PDF/A-3 files in wp-content/uploads/factur-x/ (these are
 *     legal invoices — France requires multi-year retention);
 *   - the mathisfx_invoice CPT posts and their meta (the in-dashboard index of
 *     those invoices, i.e. the audit trail);
 *   - the B2B fields stored on each order (_mathisfx_siret, _mathisfx_vat, …),
 *     which are part of that order's commercial record;
 *   - the invoice counter option (mathisfx_invoice_counter): keeping it means a
 *     re-install resumes numbering where it stopped, so a freshly re-installed
 *     plugin can never re-issue an already-used, legally-filed invoice number.
 *
 * A merchant who really wants a full wipe can remove those manually.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

// Bail if not called from the WP uninstall flow.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// Delete the plugin settings — everything the merchant entered on the settings
// screen (seller identity, INSEE API key, invoice format, appearance…). The
// running invoice counter is intentionally NOT in this list; see the file
// header for why it must survive an uninstall/re-install cycle.
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

// Remove the plugin's transient caches (INSEE/VIES validation results). They
// live in the options table as _transient_mathisfx_* (+ their _transient_
// timeout_ twins) and are pure throwaway data, so we sweep them by pattern.
// A direct query is appropriate here: this runs once, on uninstall, and the
// only interpolated identifier is a table name derived from $wpdb.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_mathisfx\\_%' ESCAPE '\\\\' OR option_name LIKE '\\_transient\\_timeout\\_mathisfx\\_%' ESCAPE '\\\\'"
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

/*
 * Intentionally NOT deleted (see file header for the rationale):
 *   - wp-content/uploads/factur-x/**           legal invoice PDFs
 *   - mathisfx_invoice CPT posts + their meta  invoice index / audit trail
 *   - order meta (_mathisfx_*)                 B2B fields on each order
 *   - mathisfx_invoice_counter option          so numbering never collides
 */
