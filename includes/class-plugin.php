<?php
/**
 * Main plugin class — singleton entry point.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Singleton bootstrap for Factur-X for WooCommerce.
 *
 * Keep this class small — orchestration only, no business logic.
 * Feature classes (Settings, CheckoutFields, InvoiceGenerator, ...) are
 * instantiated and wired from init() as we progress through the build.
 */
final class Plugin {

    /**
     * Singleton instance.
     */
    private static ?Plugin $instance = null;

    /**
     * Get (or lazily create) the singleton instance.
     */
    public static function instance(): Plugin {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Private constructor — use instance() instead.
     */
    private function __construct() {
        // Intentionally empty for now.
    }

    /**
     * Boot the plugin. Called once from the plugins_loaded hook.
     *
     * Each feature class wires its own hooks in its own constructor or init() method.
     * Add new feature instantiations here as we work through the prompt's 8 steps.
     */
    public function init(): void {
        // Bail out if WooCommerce isn't active. We test for WooCommerce (the
        // main class, loaded early at plugins_loaded priority 0) — NOT for
        // WC_Settings_Page, which is loaded lazily only when WC Admin is
        // accessed. Testing the wrong class here makes our filters never
        // register on the admin page.
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Étape 2 — Settings tab under WooCommerce → Settings → Factur-X.
        add_filter('woocommerce_get_settings_pages', [$this, 'register_settings_page']);

        // Coming next:
        //   new InvoicePostType();
        //   new InvoiceNumbering();
        //   new CheckoutFields();
        //   new InvoiceGenerator();
        //   ...
    }

    /**
     * Register the Factur-X tab inside WooCommerce → Settings.
     *
     * @param \WC_Settings_Page[] $pages WC settings pages collection.
     * @return \WC_Settings_Page[]
     */
    public function register_settings_page(array $pages): array {
        $pages[] = new Settings();
        return $pages;
    }

    /**
     * Prevent unserialization of the singleton.
     */
    public function __wakeup() {
        throw new \LogicException('Cannot unserialize a singleton.');
    }

    /**
     * Prevent cloning of the singleton.
     */
    private function __clone() {}
}
