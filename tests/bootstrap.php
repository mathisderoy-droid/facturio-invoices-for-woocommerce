<?php
/**
 * PHPUnit bootstrap for isolated unit tests (no WordPress runtime).
 *
 * The tested classes guard themselves with `defined('ABSPATH') || exit;`
 * and declare constants using WP constants (DAY_IN_SECONDS). We define
 * just enough here so the classes load outside WordPress, then hand off
 * to Composer's autoloader.
 *
 * Only PURE methods are unit-tested here (SIRET Luhn check, VAT format).
 * Anything that talks to $wpdb, the network or WC is covered by the
 * integration tests (tests/integration/) or by the FNFE-MPE validator.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

// Satisfy the direct-access guard at the top of every plugin class file.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// WordPress time constant referenced in class constants.
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
