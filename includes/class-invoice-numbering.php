<?php
/**
 * Invoice numbering — atomic, gap-free, chronological.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Sequential invoice number generator.
 *
 * French legal requirement (art. 242 nonies A CGI): invoice numbers MUST be
 * sequential, chronological, and may NEVER have gaps within a numbering
 * series. Yearly reset is allowed if the year appears in the number itself.
 *
 * Concurrency model:
 *   We do NOT do `$n = get_option(); update_option($n + 1)` — that has an
 *   obvious TOCTOU race: two concurrent requests would both read N, both
 *   write N+1, and both think they own number N+1. Duplicate invoices.
 *
 *   Instead we issue a single MySQL UPDATE that atomically increments the
 *   stored counter AND captures the new value into the connection-local
 *   LAST_INSERT_ID() session variable. InnoDB serializes concurrent
 *   UPDATEs on the same row via a brief X-lock; each session reads back
 *   its own LAST_INSERT_ID(). No duplicates, no gaps.
 *
 *   See https://dev.mysql.com/doc/refman/8.0/en/information-functions.html#function_last-insert-id
 */
final class InvoiceNumbering {

	/**
	 * Reserve the next invoice number AND increment the counter.
	 *
	 * IMPORTANT: this method actually consumes a number. Call it only when
	 * you are about to commit the invoice. If invoice generation may fail
	 * after this call, you MUST recover (rebuild a PDF for the consumed
	 * number) — never silently skip, that would leave a forbidden gap.
	 */
	public static function get_next_invoice_number(): string {
		global $wpdb;

		$year        = self::current_year();
		$counter_key = self::get_counter_key( $year );

		// add_option is a no-op if the option already exists. autoload='no'
		// so it doesn't pollute the autoloaded options cache.
		add_option( $counter_key, '0', '', 'no' );

		// Atomic increment. The CAST is defensive — option_value is stored
		// as LONGTEXT, MySQL needs the explicit cast to do arithmetic.
		//
		// Note on $wpdb->prepare: we whitelist only the option_name; the
		// SQL itself is static. No injection surface.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
                 SET option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)
                 WHERE option_name = %s",
				$counter_key
			)
		);

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$new_number = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

		// Invalidate WP's options cache so subsequent get_option() calls
		// in this same request see the freshly incremented value.
		wp_cache_delete( $counter_key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );

		return self::format_invoice_number( $year, $new_number );
	}

	/**
	 * Preview what the next number WOULD be, without consuming it.
	 *
	 * Safe to call as many times as you want — does not touch the counter.
	 * Useful for the admin preview ("next invoice will be F2026-000042")
	 * but NEVER use this to actually assign a number to an invoice.
	 */
	public static function peek_next_invoice_number(): string {
		$year        = self::current_year();
		$counter_key = self::get_counter_key( $year );
		$current     = (int) get_option( $counter_key, '0' );
		return self::format_invoice_number( $year, $current + 1 );
	}

	/**
	 * Current value of the counter for the current year (0 if not started).
	 *
	 * Diagnostic helper — read-only.
	 */
	public static function get_current_counter_value(): int {
		$year        = self::current_year();
		$counter_key = self::get_counter_key( $year );
		return (int) get_option( $counter_key, '0' );
	}

	/**
	 * Whether a requested "next invoice number" may be applied, given the last
	 * number already issued in the current series.
	 *
	 * The next number must be STRICTLY greater than the last issued one;
	 * anything at or below would re-issue an already-used number, which French
	 * sequential numbering forbids. Pure + side-effect free → unit-tested.
	 *
	 * @param int $requested_next The next number the merchant wants to use.
	 * @param int $last_issued    The current counter value (last number used).
	 */
	public static function is_acceptable_next_number( int $requested_next, int $last_issued ): bool {
		return $requested_next >= $last_issued + 1;
	}

	/**
	 * Force the current year's counter to a given value (the "last used"
	 * number; the next issued number will be $counter + 1).
	 *
	 * Used by the "Prochain numéro de facture" setting, mostly so a merchant
	 * migrating from another tool can resume their existing series.
	 *
	 * SAFETY: the write is an atomic conditional UPDATE that NEVER lowers the
	 * counter. If a concurrent get_next_invoice_number() pushed the counter
	 * past $counter between the caller's check and this write, the WHERE clause
	 * no longer matches and we leave the (higher) value untouched — so this can
	 * never roll the counter back and re-issue a number.
	 *
	 * @param int $counter Desired "last used" value (clamped to >= 0).
	 */
	public static function set_current_counter_value( int $counter ): void {
		global $wpdb;

		$counter     = max( 0, $counter );
		$year        = self::current_year();
		$counter_key = self::get_counter_key( $year );

		// Make sure the row exists (no-op if it already does), autoload='no'.
		add_option( $counter_key, '0', '', 'no' );

		// Atomic, monotonic set: only ever increases (or no-ops). See the
		// method doc for the concurrency rationale.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options}
                 SET option_value = %d
                 WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d",
				$counter,
				$counter_key,
				$counter
			)
		);

		// Drop WP's options cache so the new value is visible immediately.
		wp_cache_delete( $counter_key, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/**
	 * Year in the WordPress timezone.
	 */
	private static function current_year(): int {
		return (int) wp_date( 'Y' );
	}

	/**
	 * Which counter option to use based on the yearly-reset setting.
	 *
	 * If yearly reset is on (default, recommended in France), each calendar
	 * year has its own counter (mathisfx_invoice_counter_2026, _2027, ...).
	 * If off, a single perpetual counter is used (mathisfx_invoice_counter).
	 */
	private static function get_counter_key( int $year ): string {
		$reset_yearly = ( 'yes' === get_option( 'mathisfx_invoice_reset_yearly', 'yes' ) );
		return $reset_yearly
			? 'mathisfx_invoice_counter_' . $year
			: 'mathisfx_invoice_counter';
	}

	/**
	 * Format an invoice number as PREFIX + YEAR + "-" + ZEROPADDED_COUNTER.
	 *
	 * Example with defaults: F + 2026 + "-" + 000042  ->  "F2026-000042"
	 */
	private static function format_invoice_number( int $year, int $counter ): string {
		$prefix  = (string) get_option( 'mathisfx_invoice_prefix', 'F' );
		$padding = (int) get_option( 'mathisfx_invoice_number_padding', 6 );
		$padding = max( 4, min( 10, $padding ) ); // Clamp into the UI-allowed range.

		return sprintf(
			'%s%d-%0' . $padding . 'd',
			$prefix,
			$year,
			$counter
		);
	}
}
