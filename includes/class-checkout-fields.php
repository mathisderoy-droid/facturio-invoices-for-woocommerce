<?php
/**
 * Checkout fields — adds the B2B section to the classic WC checkout form.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Injects the "Je commande pour mon entreprise" checkbox + B2B fields
 * into the classic WooCommerce checkout form, and validates them on
 * submission.
 *
 * Renders only on the classic checkout (the [woocommerce_checkout]
 * shortcode page). For block checkout support, a separate adapter using
 * the WC Block API would be added in V0.5+.
 *
 * Fields rendered:
 *   - mathisfx_is_b2b       — checkbox toggling visibility of the section
 *   - mathisfx_company_name — legal name of the buying entity
 *   - mathisfx_siret        — 14-digit SIRET (Luhn-checked server-side)
 *   - mathisfx_vat          — French intra-EU VAT number (format-checked)
 *   - mathisfx_ape_code     — APE/NAF activity code (optional)
 */
final class CheckoutFields {

    /**
     * Wire up the hooks.
     */
    public function __construct() {
        add_action('wp_enqueue_scripts',                 [$this, 'enqueue_assets']);
        add_action('woocommerce_after_checkout_billing_form', [$this, 'render_fields']);
        add_action('woocommerce_checkout_process',       [$this, 'validate_fields']);
    }

    /**
     * Enqueue the toggle JS and section CSS — only on the checkout page.
     *
     * Loading these globally would waste bandwidth and risk style/script
     * collisions on other pages.
     */
    public function enqueue_assets(): void {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'mathisfx-checkout',
            MATHISFX_PLUGIN_URL . 'assets/css/checkout.css',
            [],
            MATHISFX_VERSION
        );

        wp_enqueue_script(
            'mathisfx-checkout',
            MATHISFX_PLUGIN_URL . 'assets/js/checkout.js',
            [],
            MATHISFX_VERSION,
            true // load in footer
        );
    }

    /**
     * Render the B2B section right after the billing form.
     *
     * The fields are initially hidden via inline style="display:none" so
     * users who don't tick the checkbox see a clean form. The JS toggles
     * visibility based on the checkbox state.
     *
     * @param \WC_Checkout $checkout Provided by the hook (not always present
     *                               on every WC version, hence the fallback).
     */
    public function render_fields($checkout = null): void {
        if (!$checkout instanceof \WC_Checkout) {
            $checkout = WC()->checkout();
        }

        ?>
        <div id="mathisfx-checkout-b2b" class="mathisfx-checkout-b2b">
            <p class="form-row mathisfx-b2b-toggle">
                <label class="checkbox">
                    <input type="checkbox" name="mathisfx_is_b2b" id="mathisfx_is_b2b" value="1" <?php checked('1', $checkout->get_value('mathisfx_is_b2b')); ?> />
                    <span><?php esc_html_e('Je commande pour mon entreprise', 'factur-x-for-woocommerce'); ?></span>
                </label>
            </p>

            <div id="mathisfx_b2b_fields" class="mathisfx-b2b-fields" aria-hidden="true">
                <h3><?php esc_html_e('Informations de votre entreprise', 'factur-x-for-woocommerce'); ?></h3>
                <p class="mathisfx-b2b-help">
                    <?php esc_html_e('Ces informations seront utilisées pour générer une facture Factur-X conforme à la réforme française 2026.', 'factur-x-for-woocommerce'); ?>
                </p>

                <?php
                woocommerce_form_field(
                    'mathisfx_company_name',
                    [
                        'type'         => 'text',
                        'label'        => __('Raison sociale', 'factur-x-for-woocommerce'),
                        'required'     => false,
                        'class'        => ['form-row-wide'],
                        'autocomplete' => 'organization',
                    ],
                    $checkout->get_value('mathisfx_company_name')
                );

                woocommerce_form_field(
                    'mathisfx_siret',
                    [
                        'type'              => 'text',
                        'label'             => __('SIRET', 'factur-x-for-woocommerce'),
                        'placeholder'       => __('14 chiffres', 'factur-x-for-woocommerce'),
                        'required'          => false,
                        'class'             => ['form-row-first'],
                        'custom_attributes' => [
                            'inputmode' => 'numeric',
                            'maxlength' => '17', // tolerate spaces during typing
                        ],
                    ],
                    $checkout->get_value('mathisfx_siret')
                );

                woocommerce_form_field(
                    'mathisfx_vat',
                    [
                        'type'        => 'text',
                        'label'       => __('Numéro de TVA intracommunautaire', 'factur-x-for-woocommerce'),
                        'placeholder' => 'FR00000000000',
                        'required'    => false,
                        'class'       => ['form-row-last'],
                    ],
                    $checkout->get_value('mathisfx_vat')
                );

                woocommerce_form_field(
                    'mathisfx_ape_code',
                    [
                        'type'        => 'text',
                        'label'       => __('Code APE / NAF (optionnel)', 'factur-x-for-woocommerce'),
                        'placeholder' => __('Ex. 6202A', 'factur-x-for-woocommerce'),
                        'required'    => false,
                        'class'       => ['form-row-wide'],
                    ],
                    $checkout->get_value('mathisfx_ape_code')
                );
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Server-side validation, hooked on woocommerce_checkout_process.
     *
     * Runs only if the B2B checkbox is ticked. Adds errors via wc_add_notice
     * which WC then displays at the top of the checkout and prevents order
     * submission.
     *
     * Note on $_POST access: WC verifies the checkout nonce
     * ('woocommerce-process_checkout') before firing this hook, so we don't
     * re-verify a nonce here — but we still unslash and sanitize every value.
     *
     * phpcs:disable WordPress.Security.NonceVerification.Missing
     */
    public function validate_fields(): void {
        if (!isset($_POST['mathisfx_is_b2b']) || $_POST['mathisfx_is_b2b'] !== '1') {
            return;
        }

        // 1. Raison sociale: required when B2B is checked.
        $company_name = isset($_POST['mathisfx_company_name'])
            ? trim(sanitize_text_field(wp_unslash($_POST['mathisfx_company_name'])))
            : '';
        if ($company_name === '') {
            wc_add_notice(
                __('Veuillez saisir la raison sociale de votre entreprise.', 'factur-x-for-woocommerce'),
                'error'
            );
        }

        // 2. SIRET: required + Luhn-valid.
        $siret_raw = isset($_POST['mathisfx_siret'])
            ? sanitize_text_field(wp_unslash($_POST['mathisfx_siret']))
            : '';
        $siret = preg_replace('/\D+/', '', $siret_raw);

        if ($siret === '') {
            wc_add_notice(
                __('Veuillez saisir le numéro SIRET de votre entreprise.', 'factur-x-for-woocommerce'),
                'error'
            );
        } elseif (!SiretValidator::is_valid_format($siret)) {
            wc_add_notice(
                __('Le numéro SIRET saisi est invalide (14 chiffres requis, échec du contrôle Luhn). Vérifiez la saisie.', 'factur-x-for-woocommerce'),
                'error'
            );
        }

        // 3. TVA intra: optional, but if filled it must match FR format.
        $vat_raw = isset($_POST['mathisfx_vat'])
            ? sanitize_text_field(wp_unslash($_POST['mathisfx_vat']))
            : '';
        $vat = ViesValidator::normalize($vat_raw);

        if ($vat !== '' && !ViesValidator::is_valid_french_format($vat)) {
            wc_add_notice(
                __('Le numéro de TVA intracommunautaire doit suivre le format français : FR suivi de 11 caractères.', 'factur-x-for-woocommerce'),
                'error'
            );
        }
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing
}
