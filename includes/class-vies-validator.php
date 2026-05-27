<?php
/**
 * VIES VAT validator — local format check + (Etape 4B) VIES SOAP/REST call.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Validates intracommunity VAT numbers.
 *
 * For V0.1 we focus on French numbers (the dominant case for our target
 * market: French B2B merchants on WooCommerce). The format check covers
 * the standard `FR XX 999999999` shape. Foreign VAT numbers are accepted
 * verbatim but only the FR ones are format-checked here.
 *
 * The actual VIES API call (verifying the number exists in the EU registry)
 * lives in Etape 4B.
 */
final class ViesValidator {

    /**
     * Local format check for a French VAT number.
     *
     * Expected form: `FR` + 2 alphanumeric characters (letters or digits, no I or O) + 9 digits.
     * The 2 leading "key" characters are alphanumeric per EU spec but in
     * practice almost always digits for French entities.
     *
     * @param string $vat VAT number, with or without spaces / casing.
     */
    public static function is_valid_french_format(string $vat): bool {
        $vat = strtoupper(preg_replace('/\s+/', '', $vat));
        // Two-char key may be letters (excluding I and O which are reserved) or digits, followed by 9 digits.
        return (bool) preg_match('/^FR[A-HJ-NP-Z0-9]{2}\d{9}$/', $vat);
    }

    /**
     * Normalize a VAT input: uppercase + strip whitespace.
     *
     * @param string $vat Raw user input.
     */
    public static function normalize(string $vat): string {
        return strtoupper(preg_replace('/\s+/', '', $vat));
    }
}
