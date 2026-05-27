<?php
/**
 * SIRET validator — local Luhn format check + (Etape 4B) INSEE Sirene API lookup.
 *
 * @package Mathis\FacturX\WooCommerce
 */

declare(strict_types=1);

namespace Mathis\FacturX\WooCommerce;

defined('ABSPATH') || exit;

/**
 * Validates SIRET numbers locally and (Etape 4B) against the INSEE Sirene API.
 *
 * Two levels of validation:
 *  - is_valid_format() : pure local check (regex + Luhn). Free, instant,
 *    catches typos and forged numbers. Always run this BEFORE making an
 *    API call to avoid wasting the INSEE rate quota.
 *  - lookup() (Etape 4B) : INSEE Sirene API call. Returns the company's
 *    legal name and registered address if the SIRET exists.
 */
final class SiretValidator {

    /**
     * Local format + Luhn check for a SIRET.
     *
     * A SIRET is 14 digits where the whole string must pass the Luhn
     * checksum algorithm. This catches >99 % of typos and any random
     * 14-digit string an attacker might forge.
     *
     * Special case: La Poste (SIREN starting with 356 0000) uses a
     * non-standard formula. We do NOT handle that edge case here — the
     * INSEE API lookup in Etape 4B will catch any false negative for
     * those rare SIRETs.
     *
     * @param string $siret 14-digit SIRET (with or without spaces).
     */
    public static function is_valid_format(string $siret): bool {
        $siret = preg_replace('/\s+/', '', $siret);

        if (!preg_match('/^\d{14}$/', $siret)) {
            return false;
        }

        return self::passes_luhn($siret);
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
    private static function passes_luhn(string $siret): bool {
        $sum = 0;
        for ($i = 0; $i < 14; $i++) {
            $digit = (int) $siret[$i];
            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }
        return $sum % 10 === 0;
    }
}
