<?php
/**
 * Payments Fee WHMCS - WHMCS environment detection.
 *
 * WHMCS 9.0 made published (non-Draft) invoices immutable: line items can no
 * longer be added or removed. This class decides whether the module may touch
 * a published invoice when its gateway changes.
 */

namespace PaymentsFee;

use WHMCS\Database\Capsule;

class Env
{
    private static $version = null;

    /**
     * WHMCS major version (8, 9, ...). 0 if it cannot be determined.
     */
    public static function majorVersion()
    {
        $v = self::version();
        if (preg_match('/^(\d+)\./', $v, $m)) {
            return (int) $m[1];
        }
        return 0;
    }

    public static function version()
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $v = '';
        try {
            if (class_exists('\\WHMCS\\Config\\Setting')) {
                $v = (string) \WHMCS\Config\Setting::getValue('Version');
            }
        } catch (\Throwable $e) {
            $v = '';
        }
        if ($v === '' && isset($GLOBALS['CONFIG']['Version'])) {
            $v = (string) $GLOBALS['CONFIG']['Version'];
        }
        if ($v === '') {
            try {
                $v = (string) Capsule::table('tblconfiguration')->where('setting', 'Version')->value('value');
            } catch (\Throwable $e) {
                $v = '';
            }
        }

        self::$version = $v;
        return $v;
    }

    /**
     * Is $allow_adminarea_invoice_mutation = true; set in configuration.php?
     */
    public static function mutationFlagEnabled()
    {
        try {
            if (class_exists('\\App') && method_exists('\\App', 'getApplicationConfig')) {
                $cfg = \App::getApplicationConfig();
                if ($cfg) {
                    $val = null;
                    if (is_array($cfg) || $cfg instanceof \ArrayAccess) {
                        $val = isset($cfg['allow_adminarea_invoice_mutation']) ? $cfg['allow_adminarea_invoice_mutation'] : null;
                    }
                    if ($val === null && is_object($cfg) && isset($cfg->allow_adminarea_invoice_mutation)) {
                        $val = $cfg->allow_adminarea_invoice_mutation;
                    }
                    if ($val !== null) {
                        return (bool) $val;
                    }
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return !empty($GLOBALS['allow_adminarea_invoice_mutation']);
    }

    /**
     * Are published invoices immutable on this installation?
     */
    public static function invoicesImmutable()
    {
        $major = self::majorVersion();
        if ($major === 0) {
            return true; // when in doubt, play safe
        }
        return $major >= 9 && !self::mutationFlagEnabled();
    }

    /**
     * May the module modify an already published (Unpaid) invoice?
     * Depends on the "published_mode" setting: auto | recalculate | keep.
     */
    public static function canMutatePublished()
    {
        $mode = (string) Repository::setting('published_mode');
        if ($mode === 'recalculate') {
            return true;
        }
        if ($mode === 'keep') {
            return false;
        }
        return !self::invoicesImmutable();
    }
}
