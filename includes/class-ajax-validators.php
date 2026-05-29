<?php
/**
 * AJAX endpoints — live SIRET (INSEE) and VAT (VIES) validation during checkout.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Wires two admin-ajax.php endpoints used by the checkout JS on field blur.
 *
 * Endpoints are registered for both authenticated (wp_ajax_) and anonymous
 * (wp_ajax_nopriv_) users — checkout is mostly anonymous, so nopriv is the
 * common path, but a logged-in customer should work too.
 *
 * Every request is gated by a nonce created in CheckoutFields::enqueue_assets()
 * and exposed to JS via wp_localize_script.
 */
final class AjaxValidators {

	/**
	 * Nonce action name. Used both in JS (form-side) and PHP (verification).
	 */
	public const NONCE_ACTION = 'mathisfx_validate_b2b';

	/**
	 * Wire up the four handlers (siret + vat × auth + nopriv).
	 */
	public function __construct() {
		add_action( 'wp_ajax_mathisfx_validate_siret', array( $this, 'ajax_validate_siret' ) );
		add_action( 'wp_ajax_nopriv_mathisfx_validate_siret', array( $this, 'ajax_validate_siret' ) );
		add_action( 'wp_ajax_mathisfx_validate_vat', array( $this, 'ajax_validate_vat' ) );
		add_action( 'wp_ajax_nopriv_mathisfx_validate_vat', array( $this, 'ajax_validate_vat' ) );
	}

	/**
	 * POST /wp-admin/admin-ajax.php?action=mathisfx_validate_siret
	 * Body: nonce=<...>&siret=<14 digits>
	 *
	 * Returns the result of SiretValidator::lookup() as JSON.
	 *
     * phpcs:disable WordPress.Security.NonceVerification.Missing
	 *   -- check_ajax_referer below IS the nonce verification.
	 */
	public function ajax_validate_siret(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$siret = isset( $_POST['siret'] )
			? sanitize_text_field( wp_unslash( $_POST['siret'] ) )
			: '';

		wp_send_json( SiretValidator::lookup( $siret ) );
	}

	/**
	 * POST /wp-admin/admin-ajax.php?action=mathisfx_validate_vat
	 * Body: nonce=<...>&vat=<FR.........>
	 */
	public function ajax_validate_vat(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		$vat = isset( $_POST['vat'] )
			? sanitize_text_field( wp_unslash( $_POST['vat'] ) )
			: '';

		wp_send_json( ViesValidator::lookup( $vat ) );
	}
    // phpcs:enable WordPress.Security.NonceVerification.Missing
}
