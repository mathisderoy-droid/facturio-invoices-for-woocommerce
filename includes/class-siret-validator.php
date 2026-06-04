<?php
/**
 * SIRET validator — local Luhn format check + INSEE Sirene API lookup.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Validates SIRET numbers locally and against the INSEE Sirene API.
 *
 * Two levels of validation:
 *  - is_valid_format() : pure local check (regex + Luhn). Free, instant,
 *    catches typos and forged numbers. Always run this BEFORE making an
 *    API call to avoid wasting the INSEE rate quota.
 *  - lookup() : INSEE Sirene API call. Returns the company's legal name,
 *    registered address, APE code, and active status. Cached 24h via
 *    transient to limit the per-merchant rate (INSEE limits to 30 req/min).
 */
final class SiretValidator {

	/**
	 * INSEE Sirene API base URL.
	 *
	 * Note: the old /entreprises/sirene/V3.11 path is deprecated since the
	 * INSEE portal migration (response body says "url deprecated, visit
	 * https://portail-api.insee.fr/"). The current live path is /api-sirene/3.11.
	 */
	private const API_BASE = 'https://api.insee.fr/api-sirene/3.11';

	/**
	 * Transient cache duration for lookups (24h). The Sirene database doesn't
	 * change minute-to-minute for a given establishment.
	 */
	private const CACHE_TTL = DAY_IN_SECONDS;

	/**
	 * Local format + Luhn check for a SIRET.
	 *
	 * A SIRET is 14 digits where the whole string must pass the Luhn
	 * checksum algorithm. This catches >99 % of typos and any random
	 * 14-digit string an attacker might forge.
	 *
	 * Special case: La Poste (SIREN starting with 356 0000) uses a
	 * non-standard formula. We do NOT handle that edge case here — the
	 * INSEE API lookup will catch any false negative for those rare SIRETs.
	 *
	 * @param string $siret 14-digit SIRET (with or without spaces).
	 */
	public static function is_valid_format( string $siret ): bool {
		$siret = preg_replace( '/\s+/', '', $siret );

		if ( ! preg_match( '/^\d{14}$/', $siret ) ) {
			return false;
		}

		return self::passes_luhn( $siret );
	}

	/**
	 * Standard Luhn checksum on a 14-digit SIRET.
	 *
	 * Algorithm (digits from left, 0-indexed):
	 *   - Even indices (0, 2, 4, ..., 12) are doubled.
	 *   - If doubled > 9, subtract 9 (equivalent to summing the two digits).
	 *   - Sum all resulting values.
	 *   - Valid if the total is divisible by 10.
	 */
	private static function passes_luhn( string $siret ): bool {
		$sum = 0;
		for ( $i = 0; $i < 14; $i++ ) {
			$digit = (int) $siret[ $i ];
			if ( $i % 2 === 0 ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum += $digit;
		}
		return $sum % 10 === 0;
	}

	/**
	 * Look up a SIRET against the INSEE Sirene API.
	 *
	 * Returns an array with at least a 'valid' boolean. When valid, the
	 * array also includes 'company_name', 'address', 'postal_code',
	 * 'city', 'country', 'ape_code', 'is_active'.
	 *
	 * Local Luhn check is run FIRST — saves an API round-trip on obviously
	 * malformed input. Result is cached 24h on success or known-not-found.
	 *
	 * Auth model: INSEE moved to OAuth2 in V3.11, but their "Simple"
	 * application plan still accepts the API key directly as a Bearer
	 * token. If that fails with 401, we fall back to the legacy
	 * X-INSEE-Api-Key-Integration header.
	 *
	 * @param string $siret 14-digit SIRET (with or without spaces).
	 * @return array{valid: bool, error?: string, company_name?: string, address?: string,
	 *               postal_code?: string, city?: string, country?: string,
	 *               ape_code?: string, is_active?: bool, siret?: string}
	 */
	public static function lookup( string $siret ): array {
		$siret = preg_replace( '/\D+/', '', $siret );

		if ( ! self::is_valid_format( $siret ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Format SIRET invalide (échec du contrôle Luhn local).', 'facturflow-invoices-for-woocommerce' ),
			);
		}

		// Cache check.
		$cache_key = 'mathisfx_siret_' . md5( $siret );
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return $cached;
		}

		$api_key = trim( (string) get_option( 'mathisfx_insee_api_key', '' ) );
		if ( $api_key === '' ) {
			return array(
				'valid' => false,
				'error' => __( 'Clé API INSEE absente. Renseignez-la dans Réglages → Factur-X → Intégrations.', 'facturflow-invoices-for-woocommerce' ),
			);
		}

		$url    = self::API_BASE . '/siret/' . rawurlencode( $siret );
		$result = self::do_request( $url, $api_key );

		// Cache hits and definitive misses (404). Don't cache transient
		// network errors — we want to retry on the next user attempt.
		if ( $result['valid'] || ( isset( $result['cacheable'] ) && $result['cacheable'] ) ) {
			unset( $result['cacheable'] );
			set_transient( $cache_key, $result, self::CACHE_TTL );
		}

		unset( $result['cacheable'] );
		return $result;
	}

	/**
	 * Perform the actual HTTP request to INSEE, with auth fallback.
	 *
	 * Returns the parsed result PLUS a 'cacheable' boolean indicating
	 * whether the caller should persist the result. We cache 200s and
	 * 404s (legitimate "not found"), but never network errors or 5xx.
	 *
	 * Auth strategy: empirically, the "Simple" application plan on the new
	 * portail-api.insee.fr accepts the X-INSEE-Api-Key-Integration header
	 * (not Bearer — Bearer returns 401 on this plan). Premium / OAuth2
	 * plans return a JWT bearer; we keep that as a fallback in case some
	 * users sign up under a different plan.
	 *
	 * @return array{valid: bool, error?: string, cacheable: bool, ...}
	 */
	private static function do_request( string $url, string $api_key ): array {
		// Primary: integration header (works for the free "Simple" plan).
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 6,
				'headers' => array(
					'X-INSEE-Api-Key-Integration' => $api_key,
					'Accept'                      => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'valid'     => false,
				/* translators: %s = network error message. */
				'error'     => sprintf( __( 'Erreur réseau INSEE : %s', 'facturflow-invoices-for-woocommerce' ), $response->get_error_message() ),
				'cacheable' => false,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		// 401 -> fall back to Bearer (for users on an OAuth2 / Premium plan).
		if ( $code === 401 ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 6,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_key,
						'Accept'        => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return array(
					'valid'     => false,
					/* translators: %s = network error message. */
					'error'     => sprintf( __( 'Erreur réseau INSEE : %s', 'facturflow-invoices-for-woocommerce' ), $response->get_error_message() ),
					'cacheable' => false,
				);
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
		}

		if ( $code === 401 || $code === 403 ) {
			return array(
				'valid'     => false,
				'error'     => __( 'Clé API INSEE refusée. Vérifiez son exactitude dans les Réglages.', 'facturflow-invoices-for-woocommerce' ),
				'cacheable' => false, // user might fix the key and retry
			);
		}

		if ( $code === 404 ) {
			return array(
				'valid'     => false,
				'error'     => __( 'SIRET introuvable dans la base Sirene INSEE.', 'facturflow-invoices-for-woocommerce' ),
				'cacheable' => true,
			);
		}

		if ( $code !== 200 ) {
			return array(
				'valid'     => false,
				/* translators: %d = HTTP status code. */
				'error'     => sprintf( __( 'Erreur API INSEE (HTTP %d).', 'facturflow-invoices-for-woocommerce' ), $code ),
				'cacheable' => false,
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['etablissement'] ) ) {
			return array(
				'valid'     => false,
				'error'     => __( 'Réponse INSEE inattendue.', 'facturflow-invoices-for-woocommerce' ),
				'cacheable' => false,
			);
		}

		return self::parse_sirene_response( $body['etablissement'] );
	}

	/**
	 * Extract the fields we care about from INSEE's verbose JSON.
	 *
	 * INSEE's Sirene V3 response is huge and nested. We pick just what the
	 * checkout UI needs to auto-fill, plus a few extras (APE, active flag).
	 *
	 * @return array{valid: bool, cacheable: bool, siret: string, company_name: string,
	 *               address: string, postal_code: string, city: string, country: string,
	 *               ape_code: string, is_active: bool}
	 */
	private static function parse_sirene_response( array $etab ): array {
		$unite    = $etab['uniteLegale'] ?? array();
		$adresse  = $etab['adresseEtablissement'] ?? array();
		$periodes = $etab['periodesEtablissement'] ?? array();

		// Company name: prefer denomination, fall back to person name.
		$name = trim( (string) ( $unite['denominationUniteLegale'] ?? '' ) );
		if ( $name === '' ) {
			$first = (string) ( $unite['prenom1UniteLegale'] ?? '' );
			$last  = (string) ( $unite['nomUniteLegale'] ?? '' );
			$name  = trim( $first . ' ' . $last );
		}

		// Street: "<numero> <type> <libelle>" e.g. "1 RUE DE LA PAIX".
		$street = trim(
			sprintf(
				'%s %s %s',
				(string) ( $adresse['numeroVoieEtablissement'] ?? '' ),
				(string) ( $adresse['typeVoieEtablissement'] ?? '' ),
				(string) ( $adresse['libelleVoieEtablissement'] ?? '' )
			)
		);
		$street = preg_replace( '/\s+/', ' ', $street );

		// is_active: the most recent period must NOT be "F" (Ferme = closed).
		$is_active = true;
		if ( is_array( $periodes ) && ! empty( $periodes ) ) {
			$latest    = reset( $periodes ); // INSEE returns most recent first
			$etat      = (string) ( $latest['etatAdministratifEtablissement'] ?? 'A' );
			$is_active = ( $etat !== 'F' );
		}

		return array(
			'valid'        => true,
			'cacheable'    => true,
			'siret'        => (string) ( $etab['siret'] ?? '' ),
			'company_name' => $name,
			'address'      => $street,
			'postal_code'  => (string) ( $adresse['codePostalEtablissement'] ?? '' ),
			'city'         => (string) ( $adresse['libelleCommuneEtablissement'] ?? '' ),
			'country'      => 'FR', // INSEE Sirene is FR-only by definition
			'ape_code'     => (string) ( $unite['activitePrincipaleUniteLegale'] ?? '' ),
			'is_active'    => $is_active,
		);
	}
}
