<?php
/**
 * VIES VAT validator — local format check + EU Commission VIES SOAP lookup.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Validates intracommunity VAT numbers.
 *
 * Two levels of validation:
 *  - is_valid_french_format() : pure local regex check on the FR format.
 *    Free, instant, catches typos.
 *  - lookup() : VIES SOAP call to the EU Commission. Returns whether the
 *    VAT exists in any EU member state's registry. Cached 24h.
 *
 * The VIES API is free, doesn't require any key. The trade-off is its
 * reliability: VIES is notorious for going down or returning "MS service
 * unavailable" when a member state's registry is offline. We treat such
 * cases as "validation could not be completed" rather than "invalid".
 */
final class ViesValidator {

	/**
	 * VIES SOAP endpoint (publicly listed in the WSDL).
	 */
	private const SOAP_ENDPOINT = 'https://ec.europa.eu/taxation_customs/vies/services/checkVatService';

	/**
	 * Cache duration for lookups (24h).
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Local format check for a French VAT number.
	 *
	 * Expected form: `FR` + 2 alphanumeric characters (letters or digits, no I or O) + 9 digits.
	 * The 2 leading "key" characters are alphanumeric per EU spec but in
	 * practice almost always digits for French entities.
	 *
	 * @param string $vat VAT number, with or without spaces / casing.
	 */
	public static function is_valid_french_format( string $vat ): bool {
		$vat = self::normalize( $vat );
		return (bool) preg_match( '/^FR[A-HJ-NP-Z0-9]{2}\d{9}$/', $vat );
	}

	/**
	 * Normalize a VAT input: uppercase + strip whitespace.
	 */
	public static function normalize( string $vat ): string {
		return strtoupper( preg_replace( '/\s+/', '', $vat ) );
	}

	/**
	 * Look up a VAT number against the EU VIES registry.
	 *
	 * Returns an array with at least a 'valid' boolean. When VIES returns
	 * trader info (some member states do, others don't), the array also
	 * includes 'company_name' and 'address'.
	 *
	 * Special status: VIES can return MS_UNAVAILABLE (the queried country's
	 * registry is temporarily offline). We surface that as a distinct
	 * 'unavailable' error rather than declaring the VAT invalid.
	 *
	 * @param string $vat VAT number (any EU country, with or without spaces).
	 * @return array{valid: bool, error?: string, unavailable?: bool,
	 *               company_name?: string, address?: string,
	 *               country?: string, vat_number?: string}
	 */
	public static function lookup( string $vat ): array {
		$vat = self::normalize( $vat );

		if ( strlen( $vat ) < 4 ) {
			return array(
				'valid' => false,
				'error' => __( 'Numéro de TVA trop court.', 'facturio-invoices-for-woocommerce' ),
			);
		}

		$country = substr( $vat, 0, 2 );
		$number  = substr( $vat, 2 );

		// Quick country code sanity check (must be 2 uppercase letters).
		if ( ! preg_match( '/^[A-Z]{2}$/', $country ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Préfixe pays invalide.', 'facturio-invoices-for-woocommerce' ),
			);
		}

		// French numbers: reject a malformed value locally instead of paying
		// for a VIES round-trip. An obviously broken input (e.g. "FR123") makes
		// VIES answer slowly or with a fault, which can make the AJAX call slow
		// enough that the browser falls back to a generic "unknown error". A
		// local check gives instant, clear feedback and mirrors the server-side
		// checkout validation (is_valid_french_format).
		if ( 'FR' === $country && ! self::is_valid_french_format( $vat ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Numéro de TVA français mal formé (format attendu : FR + 11 caractères).', 'facturio-invoices-for-woocommerce' ),
			);
		}

		// Cache check.
		$cache_key = 'mathisfx_vies_' . md5( $vat );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$result = self::call_vies( $country, $number );

		// VIES France's registry has a notoriously low concurrent-request
		// ceiling and frequently answers MS_MAX_CONCURRENT_REQ even when its
		// global status is "available". A single retry after a short pause
		// usually lands once a concurrent slot frees up. We retry only on
		// the transient "unavailable" branch, never on a definitive answer.
		if ( ! empty( $result['unavailable'] ) ) {
			$delay_us = (int) apply_filters( 'mathisfx_vies_retry_delay_us', 1200000 ); // 1.2s
			if ( $delay_us > 0 ) {
				usleep( $delay_us );
			}
			$result = self::call_vies( $country, $number );
		}

		// Cache definitive answers (valid OR confirmed invalid). Never cache
		// MS_UNAVAILABLE or network errors — user might retry later.
		if ( ! empty( $result['cacheable'] ) ) {
			unset( $result['cacheable'] );
			set_transient( $cache_key, $result, self::CACHE_TTL );
		}
		unset( $result['cacheable'] );

		return $result;
	}

	/**
	 * Build the SOAP envelope and POST it to VIES.
	 *
	 * Using raw XML over wp_remote_post rather than PHP's SoapClient
	 * extension because (a) SoapClient is not guaranteed to be installed
	 * on every WP host, (b) wp_remote_post gives us consistent timeout and
	 * error handling matching the rest of the plugin, (c) the response is
	 * small enough that regex parsing is fine for our two fields.
	 */
	private static function call_vies( string $country, string $number ): array {
		$body = sprintf(
			'<?xml version="1.0" encoding="UTF-8"?>' .
			'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:tns="urn:ec.europa.eu:taxud:vies:services:checkVat:types">' .
			'<soap:Header/>' .
			'<soap:Body>' .
			'<tns:checkVat>' .
			'<tns:countryCode>%s</tns:countryCode>' .
			'<tns:vatNumber>%s</tns:vatNumber>' .
			'</tns:checkVat>' .
			'</soap:Body>' .
			'</soap:Envelope>',
			esc_html( $country ),
			esc_html( $number )
		);

		$response = wp_remote_post(
			self::SOAP_ENDPOINT,
			array(
				'timeout' => 10,
				'headers' => array(
					'Content-Type' => 'text/xml; charset=UTF-8',
					'SOAPAction'   => '',
				),
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'     => false,
				/* translators: %s = network error message. */
				'error'     => sprintf( __( 'Erreur réseau VIES : %s', 'facturio-invoices-for-woocommerce' ), $response->get_error_message() ),
				'cacheable' => false,
			);
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );

		// VIES can return a SOAP Fault on EITHER HTTP 200 or 500 — the body
		// is what matters, not the HTTP code. Check the fault first.
		$fault = self::detect_soap_fault( $raw_body );
		if ( $fault !== null ) {
			return $fault;
		}

		// Beyond SOAP faults, also bail on plain HTTP errors (rare on VIES,
		// but possible during full service outage).
		if ( $code !== 200 ) {
			return array(
				'valid'     => false,
				/* translators: %d = HTTP status code. */
				'error'     => sprintf( __( 'Erreur API VIES (HTTP %d).', 'facturio-invoices-for-woocommerce' ), $code ),
				'cacheable' => false,
			);
		}

		// Parse the SOAP response. We only need <valid>, <name>, <address>.
		// VIES uses namespace prefixes that vary (none, ns2:, vies:, etc.),
		// so we match optional `prefix:` before each tag name.
		$is_valid = false;
		if ( preg_match( '#<(?:[\w-]+:)?valid\b[^>]*>\s*(true|false)\s*</(?:[\w-]+:)?valid>#i', $raw_body, $match ) ) {
			$is_valid = ( strtolower( $match[1] ) === 'true' );
		} else {
			// Log the actual body for diagnosis next time, then bail.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostics, debug-only.
				error_log( '[mathisfx] VIES response unreadable. Body preview: ' . substr( $raw_body, 0, 1500 ) );
			}
			return array(
				'valid'     => false,
				'error'     => __( 'Réponse VIES illisible.', 'facturio-invoices-for-woocommerce' ),
				'cacheable' => false,
			);
		}

		$name    = self::extract_soap_field( $raw_body, 'name' );
		$address = self::extract_soap_field( $raw_body, 'address' );

		$result = array(
			'valid'        => $is_valid,
			'vat_number'   => $country . $number,
			'country'      => $country,
			'company_name' => $name,
			'address'      => $address,
			'cacheable'    => true,
		);
		// A clean VIES answer of "not valid" must still carry a human-readable
		// message, otherwise the checkout JS falls back to "Erreur inconnue".
		if ( ! $is_valid ) {
			$result['error'] = __( 'Numéro de TVA non reconnu par VIES.', 'facturio-invoices-for-woocommerce' );
		}
		return $result;
	}

	/**
	 * Detect a SOAP fault in the VIES response and translate it.
	 *
	 * VIES surfaces several documented fault codes. Most are TRANSIENT and
	 * must NOT be treated as "VAT is invalid" — the typical sequence is:
	 * the user types a perfectly valid VAT, France's tax registry is
	 * rate-limiting at that exact second, VIES bubbles up MS_MAX_CONCURRENT_REQ,
	 * and a few seconds later the same query would succeed.
	 *
	 * Reference: https://ec.europa.eu/taxation_customs/vies/faqvies.do
	 *
	 * @return array|null Returns a result array if a known fault is present,
	 *                    null if the response is not a SOAP fault (parse normally).
	 */
	private static function detect_soap_fault( string $body ): ?array {
		if ( ! preg_match( '#<(?:[\w-]+:)?faultstring\b[^>]*>([^<]+)</(?:[\w-]+:)?faultstring>#i', $body, $match ) ) {
			return null;
		}

		$fault_code = trim( $match[1] );

		// Transient faults — VIES or a member state registry is temporarily
		// overloaded. We mark the result as 'unavailable' so the JS can show
		// a "retry later" warning instead of a red "invalid" indicator.
		$transient_codes = array(
			'MS_MAX_CONCURRENT_REQ',
			'GLOBAL_MAX_CONCURRENT_REQ',
			'MS_UNAVAILABLE',
			'SERVICE_UNAVAILABLE',
			'SERVER_BUSY',
			'TIMEOUT',
		);
		if ( in_array( $fault_code, $transient_codes, true ) ) {
			return array(
				'valid'       => false,
				'unavailable' => true,
				'error'       => __( 'Service VIES temporairement saturé (taux d\'appels limité côté UE). Réessayez dans quelques secondes.', 'facturio-invoices-for-woocommerce' ),
				'cacheable'   => false,
			);
		}

		// INVALID_INPUT — VIES says the VAT format is malformed. Definitive,
		// worth caching so we don't pester the API with the same broken value.
		if ( $fault_code === 'INVALID_INPUT' ) {
			return array(
				'valid'     => false,
				'error'     => __( 'Numéro de TVA mal formé (refusé par VIES).', 'facturio-invoices-for-woocommerce' ),
				'cacheable' => true,
			);
		}

		// Unknown fault — log it for future telemetry, surface verbatim.
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional diagnostics, debug-only.
			error_log( '[mathisfx] VIES unknown fault code: ' . $fault_code );
		}
		return array(
			'valid'     => false,
			/* translators: %s = VIES SOAP fault code. */
			'error'     => sprintf( __( 'Erreur VIES : %s', 'facturio-invoices-for-woocommerce' ), $fault_code ),
			'cacheable' => false,
		);
	}

	/**
	 * Pull a single SOAP field value out of the response body.
	 *
	 * Tolerates optional namespace prefix on the tag (none, ns2:, vies:, ...).
	 */
	private static function extract_soap_field( string $body, string $field ): string {
		$pattern = '#<(?:[\w-]+:)?' . preg_quote( $field, '#' ) . '\b[^>]*>([^<]*)</(?:[\w-]+:)?' . preg_quote( $field, '#' ) . '>#i';
		if ( preg_match( $pattern, $body, $match ) ) {
			return trim( html_entity_decode( $match[1], ENT_XML1 | ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}
}
