<?php
/**
 * Plugin Name:          Factur-X for WooCommerce
 * Plugin URI:           https://github.com/mathisderoy/factur-x-for-woocommerce
 * Description:          Génère des factures Factur-X conformes à la réforme française 2026 depuis WooCommerce (PDF/A-3 + XML CII embarqué, profil EN 16931).
 * Version:              0.1.0
 * Requires at least:    6.0
 * Requires PHP:         8.0
 * Author:               Mathis Deroy
 * Author URI:           https://github.com/mathisderoy
 * License:              GPL v2 or later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          factur-x-for-woocommerce
 * Domain Path:          /languages
 * WC requires at least: 9.0
 * WC tested up to:      10.8
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

// Block direct file access.
defined( 'ABSPATH' ) || exit;

// Plugin constants — all prefixed with MATHISFX_ to avoid collisions.
define( 'MATHISFX_VERSION', '0.1.0' );
define( 'MATHISFX_PLUGIN_FILE', __FILE__ );
define( 'MATHISFX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MATHISFX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MATHISFX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/*
 * Autoloaders.
 *
 * Two are supported so the same source works in development and in the
 * distributed build:
 *   - vendor-prefixed/autoload.php : the Strauss-scoped dependencies, only
 *     present in the WP.org build zip (produced by bin/build.sh on Linux).
 *     Loaded FIRST so scoped classes win over any other plugin's copies.
 *   - vendor/autoload.php          : our own classmap (includes/) and, in
 *     development, the unscoped dependencies installed by `composer install`.
 *
 * In dev only vendor/ exists → unscoped libs are used (fine locally).
 * In the build vendor-prefixed/ exists → conflict-proof scoped libs are used.
 */
foreach (
	array(
		__DIR__ . '/vendor-prefixed/autoload.php',
		__DIR__ . '/vendor/autoload.php',
	) as $mathisfx_autoload
) {
	if ( file_exists( $mathisfx_autoload ) ) {
		require_once $mathisfx_autoload;
	}
}

/*
 * Declare High-Performance Order Storage (HPOS) compatibility.
 *
 * Mandatory hook for any modern WooCommerce plugin that touches orders.
 * Without this declaration, WC shows an "incompatible plugin" notice in admin
 * and may force-disable HPOS for the merchant.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MATHISFX_PLUGIN_FILE,
				true
			);
		}
	}
);

/*
 * Boot the plugin once all plugins are loaded.
 *
 * If Composer dependencies are missing (autoloader present but the namespace
 * class isn't found), display an admin notice and exit gracefully instead of
 * fatal-erroring.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( \Mathis\FacturX\WooCommerce\Plugin::class ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					echo esc_html__(
						'Factur-X for WooCommerce : dépendances Composer manquantes. Exécutez "composer install" dans le dossier du plugin.',
						'factur-x-for-woocommerce'
					);
					echo '</p></div>';
				}
			);
			return;
		}

		\Mathis\FacturX\WooCommerce\Plugin::instance()->init();
	}
);
