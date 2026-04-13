<?php

namespace HTWarehouse\Services;

defined('ABSPATH') || exit;

/**
 * Decimal-aware number helpers using bcmath when available,
 * falling back to float epsilon for environments without it.
 */
class NumberHelper
{

    /**
     * Compare two numeric strings: is $value <= 0?
     *
     * @param string $value
     * @return bool
     */
    public static function isZeroOrNegative(string $value): bool
    {
        if (function_exists('bccomp')) {
            return bccomp(self::normalize($value), '0', 4) <= 0;
        }
        return (float) $value <= 0.0001;
    }

    /**
     * Compare two numeric strings: is $value > 0?
     *
     * @param string $value
     * @return bool
     */
    public static function isPositive(string $value): bool
    {
        if (function_exists('bccomp')) {
            return bccomp(self::normalize($value), '0', 4) > 0;
        }
        return (float) $value > 0.0001;
    }

    /**
     * Normalize a numeric string: strip trailing zeros after decimal point.
     *
     * @param string $value
     * @return string
     */
    public static function normalize(string $value): string
    {
        if (strpos($value, '.') === false) {
            return $value;
        }
        return rtrim(rtrim($value, '0'), '.');
    }

    /**
     * Safely add two numeric values (strings or floats).
     * Uses bcmath when available, falls back to float.
     *
     * @param string|float $a
     * @param string|float $b
     * @return string
     */
    public static function add($a, $b): string
    {
        if (function_exists('bcadd')) {
            return bcadd((string) $a, (string) $b, 4);
        }
        return (string) ((float) $a + (float) $b);
    }

    /**
     * Safely subtract $b from $a.
     *
     * @param string|float $a
     * @param string|float $b
     * @return string
     */
    public static function sub($a, $b): string
    {
        if (function_exists('bcsub')) {
            return bcsub((string) $a, (string) $b, 4);
        }
        return (string) ((float) $a - (float) $b);
    }

    /**
     * Safely multiply $a * $b.
     *
     * @param string|float $a
     * @param string|float $b
     * @return string
     */
    public static function mul($a, $b): string
    {
        if (function_exists('bcmul')) {
            return bcmul((string) $a, (string) $b, 4);
        }
        return (string) ((float) $a * (float) $b);
    }

    /**
     * Safely divide $a / $b.
     *
     * @param string|float $a
     * @param string|float $b
     * @param int          $scale Number of decimal places.
     * @return string
     */
    public static function div($a, $b, int $scale = 4): string
    {
        if (function_exists('bcdiv')) {
            return bcdiv((string) $a, (string) $b, $scale);
        }
        return number_format((float) $a / (float) $b, $scale, '.', '');
    }

    /**
     * Compare two numeric values.
     *
     * @param string|float $a
     * @param string|float $b
     * @param int          $scale
     * @return int  -1 if $a < $b, 0 if equal, 1 if $a > $b
     */
    public static function comp($a, $b, int $scale = 4): int
    {
        if (function_exists('bccomp')) {
            return bccomp((string) $a, (string) $b, $scale);
        }
        $diff = (float) $a - (float) $b;
        if ($diff < -0.0001) return -1;
        if ($diff > 0.0001) return 1;
        return 0;
    }

    /**
     * Recompute amount_paid from the SUM of htw_po_payments.
     *
     * @param \wpdb   $wpdb
     * @param int     $po_id
     * @return float
     */
    public static function computePaidFromPayments(\wpdb $wpdb, int $po_id): float
    {
        return (float) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM {$wpdb->prefix}htw_po_payments WHERE po_id = %d",
            $po_id
        ));
    }
}
