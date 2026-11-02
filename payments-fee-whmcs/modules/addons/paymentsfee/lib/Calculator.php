<?php
/**
 * Payments Fee WHMCS - Pure fee calculator (no WHMCS dependencies).
 *
 * PHP 7.2+ compatible.
 */

namespace PaymentsFee;

class Calculator
{
    const TYPE_FIXED = 'fixed';
    const TYPE_PERCENT = 'percent';
    const TYPE_BOTH = 'both';

    /**
     * Calculates the fee for a base amount.
     *
     * @param array $rule     fee_type, percent, fixed, max_fee, min_amount, gross_up
     *                        (fixed amounts are in the WHMCS default currency).
     * @param float $base     Amount the fee is calculated on, in the client's currency.
     * @param float $rate     Exchange rate of the client's currency vs. the default currency.
     * @param int   $decimals Decimals of the client's currency (0 for CLP, JPY...).
     *
     * @return float Rounded fee (0 when it does not apply).
     */
    public static function compute(array $rule, $base, $rate = 1.0, $decimals = 2)
    {
        $base = (float) $base;
        $rate = ((float) $rate) > 0 ? (float) $rate : 1.0;
        $decimals = max(0, (int) $decimals);

        if ($base <= 0) {
            return 0.0;
        }

        $type = isset($rule['fee_type']) ? $rule['fee_type'] : self::TYPE_PERCENT;
        $percent = isset($rule['percent']) ? (float) $rule['percent'] : 0.0;
        $fixed = (isset($rule['fixed']) ? (float) $rule['fixed'] : 0.0) * $rate;
        $maxFee = (isset($rule['max_fee']) ? (float) $rule['max_fee'] : 0.0) * $rate;
        $minAmount = (isset($rule['min_amount']) ? (float) $rule['min_amount'] : 0.0) * $rate;
        $grossUp = !empty($rule['gross_up']);

        if ($minAmount > 0 && $base < $minAmount) {
            return 0.0;
        }

        $p = ($type === self::TYPE_PERCENT || $type === self::TYPE_BOTH) ? $percent / 100 : 0.0;
        $f = ($type === self::TYPE_FIXED || $type === self::TYPE_BOTH) ? $fixed : 0.0;
        $p = max(0.0, $p);
        $f = max(0.0, $f);

        if ($grossUp && $p > 0 && $p < 1) {
            // Gross-up: the amount charged covers exactly the gateway commission.
            $fee = ($base + $f) / (1 - $p) - $base;
        } else {
            $fee = $base * $p + $f;
        }

        if ($maxFee > 0 && $fee > $maxFee) {
            $fee = $maxFee;
        }
        if ($fee <= 0) {
            return 0.0;
        }

        $factor = pow(10, $decimals);
        if ($grossUp) {
            // Round up so the commission is always covered.
            $fee = ceil(round($fee * $factor, 6)) / $factor;
        } else {
            $fee = round($fee, $decimals);
        }

        return (float) $fee;
    }

    /**
     * Short description of a rule, e.g. "3.49% + $300".
     *
     * @param array    $rule
     * @param callable $moneyFormatter function(float $amount): string
     * @param float    $rate
     * @param string   $decimalSeparator for the percentage
     */
    public static function describe(array $rule, $moneyFormatter, $rate = 1.0, $decimalSeparator = '.')
    {
        $type = isset($rule['fee_type']) ? $rule['fee_type'] : self::TYPE_PERCENT;
        $parts = array();

        if (($type === self::TYPE_PERCENT || $type === self::TYPE_BOTH) && (float) $rule['percent'] > 0) {
            $parts[] = self::formatPercent((float) $rule['percent'], $decimalSeparator) . '%';
        }
        if (($type === self::TYPE_FIXED || $type === self::TYPE_BOTH) && (float) $rule['fixed'] > 0) {
            $parts[] = call_user_func($moneyFormatter, (float) $rule['fixed'] * (float) $rate);
        }

        return implode(' + ', $parts);
    }

    /**
     * 3.5 -> "3.5" (or "3,5"); 2 -> "2"; 3.49 -> "3.49"
     */
    public static function formatPercent($value, $decimalSeparator = '.')
    {
        $s = number_format((float) $value, 4, '.', '');
        $s = rtrim(rtrim($s, '0'), '.');
        return str_replace('.', $decimalSeparator, $s);
    }

    /**
     * Decimals for a WHMCS currency format (tblcurrencies.format).
     * 1 = 1234.56, 2 = 1,234.56, 3 = 1.234,56, 4 = 1,234 (no decimals).
     */
    public static function decimalsForFormat($format)
    {
        return ((int) $format === 4) ? 0 : 2;
    }

    /**
     * Formats an amount using a WHMCS currency format.
     */
    public static function formatMoney($amount, $format, $prefix = '', $suffix = '', $decimals = null)
    {
        $format = (int) $format;
        $d = $decimals === null ? self::decimalsForFormat($format) : max(0, (int) $decimals);
        switch ($format) {
            case 1:
                $n = number_format($amount, $d, '.', '');
                break;
            case 3:
                $n = number_format($amount, $d, ',', '.');
                break;
            case 4:
                $n = number_format($amount, 0, '', ',');
                break;
            case 2:
            default:
                $n = number_format($amount, $d, '.', ',');
                break;
        }
        return trim($prefix . $n . $suffix);
    }
}
