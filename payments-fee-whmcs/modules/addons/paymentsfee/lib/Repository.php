<?php
/**
 * Payments Fee WHMCS - Data access.
 */

namespace PaymentsFee;

use WHMCS\Database\Capsule;

class Repository
{
    const MODULE = 'paymentsfee';
    const TABLE_RULES = 'mod_paymentsfee_rules';
    const TABLE_INVOICES = 'mod_paymentsfee_invoices';

    /** Legacy name used by the pre-release "recargopagos" builds. */
    const LEGACY_MODULE = 'recargopagos';

    /** Default addon settings. */
    private static $defaults = array(
        'fee_label' => '',
        'show_detail' => 'on',
        'base_mode' => 'total',
        'skip_addfunds' => 'on',
        'skip_masspay' => 'on',
        'exempt_clients' => '',
        'published_mode' => 'auto',
        'show_in_checkout' => 'on',
    );

    private static $settingsCache = null;

    // ------------------------------------------------------------------ schema

    public static function createSchema()
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE_RULES)) {
            $schema->create(self::TABLE_RULES, function ($table) {
                $table->increments('id');
                $table->string('gateway', 64)->unique();
                $table->boolean('enabled')->default(0);
                $table->string('fee_type', 10)->default('percent');
                $table->decimal('percent', 8, 4)->default(0);
                $table->decimal('fixed', 16, 4)->default(0);
                $table->decimal('max_fee', 16, 4)->default(0);
                $table->decimal('min_amount', 16, 4)->default(0);
                $table->boolean('gross_up')->default(0);
                $table->boolean('taxed')->default(0);
                $table->string('label', 255)->nullable();
                $table->timestamps();
            });
        }

        if (!$schema->hasTable(self::TABLE_INVOICES)) {
            $schema->create(self::TABLE_INVOICES, function ($table) {
                $table->integer('invoiceid')->unsigned()->primary();
                $table->integer('itemid')->unsigned()->nullable();
                $table->string('gateway', 64)->default('');
                $table->decimal('fee', 16, 2)->default(0);
                $table->decimal('base', 16, 2)->default(0);
                $table->string('context', 32)->default('');
                $table->dateTime('updated_at')->nullable();
            });
        }
    }

    /**
     * Copies rules, settings and the invoice log from the pre-release
     * "recargopagos" module, if present and this module has no rules yet.
     *
     * @return int number of migrated rules
     */
    public static function migrateLegacy()
    {
        $schema = Capsule::schema();
        $legacyRules = 'mod_' . self::LEGACY_MODULE . '_rules';
        $legacyInvoices = 'mod_' . self::LEGACY_MODULE . '_invoices';
        if (!$schema->hasTable($legacyRules) || Capsule::table(self::TABLE_RULES)->exists()) {
            return 0;
        }

        $count = 0;
        foreach (Capsule::table($legacyRules)->get() as $row) {
            $data = (array) $row;
            unset($data['id']);
            Capsule::table(self::TABLE_RULES)->insert($data);
            $count++;
        }

        if ($schema->hasTable($legacyInvoices) && !Capsule::table(self::TABLE_INVOICES)->exists()) {
            foreach (Capsule::table($legacyInvoices)->get() as $row) {
                Capsule::table(self::TABLE_INVOICES)->insert((array) $row);
            }
        }

        $map = array(
            'descripcion' => 'fee_label', 'mostrar_detalle' => 'show_detail', 'base_calculo' => 'base_mode',
            'omitir_addfunds' => 'skip_addfunds', 'omitir_masspay' => 'skip_masspay',
            'clientes_exentos' => 'exempt_clients', 'modo_publicadas' => 'published_mode',
            'mostrar_en_checkout' => 'show_in_checkout',
        );
        $values = array('saldo' => 'balance', 'recalcular' => 'recalculate', 'mantener' => 'keep');
        foreach (Capsule::table('tbladdonmodules')->where('module', self::LEGACY_MODULE)->get() as $row) {
            if (!isset($map[$row->setting])) {
                continue;
            }
            $value = isset($values[$row->value]) ? $values[$row->value] : $row->value;
            if ($row->setting === 'descripcion' && $value === 'Recargo por pago con {gateway}') {
                $value = ''; // the old default: let the language file decide
            }
            Capsule::table('tbladdonmodules')->where('module', self::MODULE)->where('setting', $map[$row->setting])->delete();
            Capsule::table('tbladdonmodules')->insert(array('module' => self::MODULE, 'setting' => $map[$row->setting], 'value' => (string) $value));
        }
        self::resetCache();

        return $count;
    }

    // ------------------------------------------------------------------ settings

    public static function settings()
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $stored = array();
        $rows = Capsule::table('tbladdonmodules')->where('module', self::MODULE)->get();
        foreach ($rows as $row) {
            $stored[$row->setting] = $row->value;
        }

        $settings = self::$defaults;
        foreach ($stored as $key => $value) {
            $settings[$key] = $value;
        }

        self::$settingsCache = $settings;
        return $settings;
    }

    public static function setting($key)
    {
        $s = self::settings();
        return isset($s[$key]) ? $s[$key] : null;
    }

    public static function flag($key)
    {
        $v = self::setting($key);
        return $v === 'on' || $v === '1' || $v === 1 || $v === true || $v === 'yes';
    }

    public static function resetCache()
    {
        self::$settingsCache = null;
    }

    public static function exemptClientIds()
    {
        $raw = (string) self::setting('exempt_clients');
        $ids = array();
        foreach (preg_split('/[\s,;]+/', $raw) as $part) {
            if (ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }
        return $ids;
    }

    // ------------------------------------------------------------------ gateways

    /**
     * @return array gateway => display name, in WHMCS order
     */
    public static function activeGateways()
    {
        $rows = Capsule::table('tblpaymentgateways')
            ->where('setting', 'name')
            ->orderBy('order')
            ->get();

        $out = array();
        foreach ($rows as $row) {
            $out[$row->gateway] = $row->value;
        }
        return $out;
    }

    public static function gatewayName($gateway)
    {
        $name = Capsule::table('tblpaymentgateways')
            ->where('gateway', $gateway)
            ->where('setting', 'name')
            ->value('value');
        return $name ? $name : $gateway;
    }

    // ------------------------------------------------------------------ rules

    public static function rules()
    {
        $out = array();
        foreach (Capsule::table(self::TABLE_RULES)->get() as $row) {
            $out[$row->gateway] = self::ruleToArray($row);
        }
        return $out;
    }

    /**
     * @return array|null Active rule for the gateway, or null.
     */
    public static function activeRule($gateway)
    {
        if ($gateway === null || $gateway === '') {
            return null;
        }
        $row = Capsule::table(self::TABLE_RULES)->where('gateway', $gateway)->first();
        if (!$row || !(int) $row->enabled) {
            return null;
        }
        return self::ruleToArray($row);
    }

    public static function saveRule($gateway, array $data)
    {
        $type = isset($data['fee_type']) ? $data['fee_type'] : 'percent';
        if (!in_array($type, array(Calculator::TYPE_FIXED, Calculator::TYPE_PERCENT, Calculator::TYPE_BOTH), true)) {
            $type = Calculator::TYPE_PERCENT;
        }

        $values = array(
            'enabled' => !empty($data['enabled']) ? 1 : 0,
            'fee_type' => $type,
            'percent' => self::num(isset($data['percent']) ? $data['percent'] : 0, 0, 99.9999),
            'fixed' => self::num(isset($data['fixed']) ? $data['fixed'] : 0, 0),
            'max_fee' => self::num(isset($data['max_fee']) ? $data['max_fee'] : 0, 0),
            'min_amount' => self::num(isset($data['min_amount']) ? $data['min_amount'] : 0, 0),
            'gross_up' => !empty($data['gross_up']) ? 1 : 0,
            'taxed' => !empty($data['taxed']) ? 1 : 0,
            'label' => isset($data['label']) ? mb_substr(trim((string) $data['label']), 0, 255) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        );

        $exists = Capsule::table(self::TABLE_RULES)->where('gateway', $gateway)->exists();
        if ($exists) {
            Capsule::table(self::TABLE_RULES)->where('gateway', $gateway)->update($values);
        } else {
            $values['gateway'] = $gateway;
            $values['created_at'] = $values['updated_at'];
            Capsule::table(self::TABLE_RULES)->insert($values);
        }
    }

    private static function ruleToArray($row)
    {
        return array(
            'gateway' => $row->gateway,
            'enabled' => (int) $row->enabled === 1,
            'fee_type' => $row->fee_type,
            'percent' => (float) $row->percent,
            'fixed' => (float) $row->fixed,
            'max_fee' => (float) $row->max_fee,
            'min_amount' => (float) $row->min_amount,
            'gross_up' => (int) $row->gross_up === 1,
            'taxed' => (int) $row->taxed === 1,
            'label' => (string) $row->label,
        );
    }

    /**
     * Accepts "3,49", "3.49", "1.500" (thousands) and "1.500,50".
     */
    public static function num($value, $min = null, $max = null)
    {
        $v = trim((string) $value);
        if ($v === '') {
            $n = 0.0;
        } else {
            $v = str_replace(array(' ', '$'), '', $v);
            if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
                // 1.500,50 -> 1500.50
                $v = str_replace('.', '', $v);
                $v = str_replace(',', '.', $v);
            } elseif (strpos($v, ',') !== false) {
                $v = str_replace(',', '.', $v);
            } elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $v)) {
                // 1.500 or 12.000.000 -> thousands separator
                $v = str_replace('.', '', $v);
            }
            $n = (float) $v;
        }
        if ($min !== null && $n < $min) {
            $n = (float) $min;
        }
        if ($max !== null && $n > $max) {
            $n = (float) $max;
        }
        return $n;
    }

    // ------------------------------------------------------------------ currencies

    /**
     * @return object with id, code, prefix, suffix, format, rate, decimals
     */
    public static function currencyForClient($userId)
    {
        $currencyId = (int) Capsule::table('tblclients')->where('id', $userId)->value('currency');
        return self::currency($currencyId);
    }

    public static function currency($currencyId = 0)
    {
        $row = null;
        if ($currencyId) {
            $row = Capsule::table('tblcurrencies')->where('id', $currencyId)->first();
        }
        if (!$row) {
            $row = Capsule::table('tblcurrencies')->where('default', 1)->first();
        }
        if (!$row) {
            $row = (object) array('id' => 0, 'code' => '', 'prefix' => '$', 'suffix' => '', 'format' => 2, 'rate' => 1);
        }
        $row->rate = (float) $row->rate > 0 ? (float) $row->rate : 1.0;
        $row->decimals = Calculator::decimalsForFormat($row->format);
        // Zero-decimal currencies: round the fee to whole units even if the format shows ",00".
        if (in_array(strtoupper((string) $row->code), array('CLP', 'PYG', 'JPY', 'KRW', 'VND', 'ISK', 'UGX', 'XAF', 'XOF'), true)) {
            $row->decimals = 0;
        }
        return $row;
    }

    public static function allCurrencies()
    {
        $out = array();
        foreach (Capsule::table('tblcurrencies')->orderBy('id')->get() as $row) {
            $out[] = self::currency($row->id);
        }
        return $out;
    }

    public static function money($amount, $currency)
    {
        $decimals = isset($currency->decimals) ? $currency->decimals : null;
        return Calculator::formatMoney($amount, $currency->format, $currency->prefix, $currency->suffix, $decimals);
    }

    // ------------------------------------------------------------------ per-invoice log

    public static function invoiceRecord($invoiceId)
    {
        return Capsule::table(self::TABLE_INVOICES)->where('invoiceid', $invoiceId)->first();
    }

    public static function saveInvoiceRecord($invoiceId, $itemId, $gateway, $fee, $base, $context)
    {
        $values = array(
            'itemid' => $itemId ? (int) $itemId : null,
            'gateway' => (string) $gateway,
            'fee' => (float) $fee,
            'base' => (float) $base,
            'context' => (string) $context,
            'updated_at' => date('Y-m-d H:i:s'),
        );
        if (Capsule::table(self::TABLE_INVOICES)->where('invoiceid', $invoiceId)->exists()) {
            Capsule::table(self::TABLE_INVOICES)->where('invoiceid', $invoiceId)->update($values);
        } else {
            $values['invoiceid'] = (int) $invoiceId;
            Capsule::table(self::TABLE_INVOICES)->insert($values);
        }
    }

    public static function recentRecords($limit = 50)
    {
        return Capsule::table(self::TABLE_INVOICES)
            ->orderBy('updated_at', 'desc')
            ->limit($limit)
            ->get();
    }
}
