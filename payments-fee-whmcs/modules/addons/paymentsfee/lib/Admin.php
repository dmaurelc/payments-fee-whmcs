<?php
/**
 * Payments Fee WHMCS - Admin area addon page and invoice box.
 */

namespace PaymentsFee;

use WHMCS\Database\Capsule;

class Admin
{
    public static function render(array $vars)
    {
        $link = $vars['modulelink'];
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';
        $flash = '';

        if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token('WHMCS.admin.default');
            $flash = self::handleSave();
        } elseif ($action === 'recalc') {
            check_token('WHMCS.admin.default');
            self::handleRecalc();
            return;
        }

        $tab = isset($_GET['tab']) ? (string) $_GET['tab'] : 'rules';

        echo self::styles();
        echo self::envNotice();
        echo $flash;

        echo '<ul class="nav nav-tabs pf-tabs">';
        $tabs = array('rules' => Lang::t('tab_rules'), 'simulator' => Lang::t('tab_simulator'), 'log' => Lang::t('tab_log'));
        foreach ($tabs as $key => $label) {
            $active = $tab === $key ? ' class="active"' : '';
            echo '<li' . $active . '><a href="' . self::e($link . '&tab=' . $key) . '">' . self::e($label) . '</a></li>';
        }
        echo '</ul><div class="pf-panel">';

        if ($tab === 'simulator') {
            self::renderSimulator();
        } elseif ($tab === 'log') {
            self::renderLog();
        } else {
            self::renderRules($link);
        }

        echo '</div>';
    }

    // ------------------------------------------------------------------ rules

    private static function renderRules($link)
    {
        $gateways = Repository::activeGateways();
        $rules = Repository::rules();
        $default = Repository::currency(0);

        if (!$gateways) {
            echo '<div class="alert alert-warning">' . self::e(Lang::t('no_gateways')) . '</div>';
            return;
        }

        echo '<p class="text-muted">' . self::e(Lang::t('rules_currency_note', array('currency' => $default->code))) . '</p>';

        $placeholder = (string) Repository::setting('fee_label');
        if (trim($placeholder) === '') {
            $placeholder = Lang::t('fee_item_default');
        }

        echo '<form method="post" action="' . self::e($link . '&action=save') . '">';
        echo generate_token('form');
        echo '<div class="table-responsive"><table class="table table-striped pf-table"><thead><tr>'
            . '<th>' . self::e(Lang::t('col_gateway')) . '</th>'
            . '<th>' . self::e(Lang::t('col_enabled')) . '</th>'
            . '<th>' . self::e(Lang::t('col_type')) . '</th>'
            . '<th>' . self::e(Lang::t('col_percent')) . '</th>'
            . '<th>' . self::e(Lang::t('col_fixed')) . '</th>'
            . '<th>' . self::e(Lang::t('col_max')) . '</th>'
            . '<th>' . self::e(Lang::t('col_min')) . '</th>'
            . '<th title="' . self::e(Lang::t('col_gross_up_title')) . '">' . self::e(Lang::t('col_gross_up')) . '</th>'
            . '<th title="' . self::e(Lang::t('col_taxed_title')) . '">' . self::e(Lang::t('col_taxed')) . '</th>'
            . '<th>' . self::e(Lang::t('col_label')) . '</th></tr></thead><tbody>';

        foreach ($gateways as $gw => $name) {
            $r = isset($rules[$gw]) ? $rules[$gw] : array(
                'enabled' => false, 'fee_type' => 'percent', 'percent' => 0, 'fixed' => 0,
                'max_fee' => 0, 'min_amount' => 0, 'gross_up' => false, 'taxed' => false, 'label' => '',
            );
            $p = 'rules[' . self::e($gw) . ']';

            echo '<tr class="pf-row">';
            echo '<td><strong>' . self::e($name) . '</strong><br><code>' . self::e($gw) . '</code></td>';
            echo '<td><input type="checkbox" name="' . $p . '[enabled]" value="1"' . ($r['enabled'] ? ' checked' : '') . '></td>';
            echo '<td><select name="' . $p . '[fee_type]" class="form-control input-sm pf-type">';
            foreach (array('percent' => 'type_percent', 'fixed' => 'type_fixed', 'both' => 'type_both') as $k => $l) {
                echo '<option value="' . $k . '"' . ($r['fee_type'] === $k ? ' selected' : '') . '>' . self::e(Lang::t($l)) . '</option>';
            }
            echo '</select></td>';
            echo '<td class="pf-col-percent"><div class="input-group input-group-sm"><input type="text" class="form-control" name="' . $p . '[percent]" value="' . self::e(self::n($r['percent'])) . '"><span class="input-group-addon">%</span></div></td>';
            echo '<td class="pf-col-fixed"><input type="text" class="form-control input-sm" name="' . $p . '[fixed]" value="' . self::e(self::n($r['fixed'])) . '"></td>';
            echo '<td><input type="text" class="form-control input-sm" name="' . $p . '[max_fee]" value="' . self::e(self::n($r['max_fee'])) . '" placeholder="' . self::e(Lang::t('gw_no_cap')) . '"></td>';
            echo '<td><input type="text" class="form-control input-sm" name="' . $p . '[min_amount]" value="' . self::e(self::n($r['min_amount'])) . '" placeholder="' . self::e(Lang::t('gw_always')) . '"></td>';
            echo '<td class="pf-col-percent"><input type="checkbox" name="' . $p . '[gross_up]" value="1"' . ($r['gross_up'] ? ' checked' : '') . '></td>';
            echo '<td><input type="checkbox" name="' . $p . '[taxed]" value="1"' . ($r['taxed'] ? ' checked' : '') . '></td>';
            echo '<td><input type="text" class="form-control input-sm" name="' . $p . '[label]" value="' . self::e($r['label']) . '" placeholder="' . self::e(Lang::t('placeholder_default', array('text' => $placeholder))) . '"></td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
        echo '<p class="text-muted small">' . self::e(Lang::t('vars_note')) . '</p>';
        echo '<button type="submit" class="btn btn-primary">' . self::e(Lang::t('save')) . '</button>';
        echo '</form>';
        echo self::rulesScript();
    }

    private static function handleSave()
    {
        $gateways = Repository::activeGateways();
        $posted = isset($_POST['rules']) && is_array($_POST['rules']) ? $_POST['rules'] : array();
        $count = 0;

        foreach ($gateways as $gw => $name) {
            $data = isset($posted[$gw]) && is_array($posted[$gw]) ? $posted[$gw] : array();
            Repository::saveRule($gw, $data);
            $count++;
        }

        FeeService::log('Fee rules updated (' . $count . ' gateways).');
        return '<div class="alert alert-success">' . self::e(Lang::t('rules_saved')) . '</div>';
    }

    // ------------------------------------------------------------------ simulator

    private static function renderSimulator()
    {
        $currencies = Repository::allCurrencies();
        $currencyId = isset($_GET['currency']) ? (int) $_GET['currency'] : 0;
        $currency = Repository::currency($currencyId);
        $amount = isset($_GET['amount']) ? Repository::num($_GET['amount'], 0) : 0.0;

        echo '<form method="get" class="form-inline" style="margin-bottom:15px">';
        echo '<input type="hidden" name="module" value="' . Repository::MODULE . '"><input type="hidden" name="tab" value="simulator">';
        echo '<label>' . self::e(Lang::t('sim_amount')) . '&nbsp;</label><input type="text" name="amount" class="form-control" value="' . self::e($amount > 0 ? self::n($amount) : '') . '" placeholder="' . self::e(Lang::t('sim_example')) . '">&nbsp;';
        echo '<select name="currency" class="form-control">';
        foreach ($currencies as $c) {
            echo '<option value="' . (int) $c->id . '"' . ((int) $c->id === (int) $currency->id ? ' selected' : '') . '>' . self::e($c->code) . '</option>';
        }
        echo '</select>&nbsp;<button class="btn btn-default">' . self::e(Lang::t('sim_calculate')) . '</button></form>';

        if ($amount <= 0) {
            echo '<p class="text-muted">' . self::e(Lang::t('sim_intro')) . '</p>';
            return;
        }

        echo '<table class="table table-striped"><thead><tr><th>' . self::e(Lang::t('col_gateway')) . '</th><th>' . self::e(Lang::t('sim_rule'))
            . '</th><th class="text-right">' . self::e(Lang::t('sim_fee')) . '</th><th class="text-right">' . self::e(Lang::t('sim_total')) . '</th></tr></thead><tbody>';
        foreach (Repository::activeGateways() as $gw => $name) {
            $rule = Repository::activeRule($gw);
            if (!$rule) {
                echo '<tr><td>' . self::e($name) . '</td><td class="text-muted">' . self::e(Lang::t('sim_no_fee')) . '</td><td class="text-right">—</td><td class="text-right">' . self::e(Repository::money($amount, $currency)) . '</td></tr>';
                continue;
            }
            $fee = Calculator::compute($rule, $amount, $currency->rate, $currency->decimals);
            $desc = Calculator::describe($rule, function ($a) use ($currency) {
                return Repository::money($a, $currency);
            }, $currency->rate, Lang::decimalSeparator());
            if ($rule['gross_up']) {
                $desc .= ' (' . Lang::t('sim_gross_up') . ')';
            }
            echo '<tr><td>' . self::e($name) . '</td><td>' . self::e($desc) . '</td><td class="text-right">'
                . self::e(Repository::money($fee, $currency)) . '</td><td class="text-right"><strong>'
                . self::e(Repository::money($amount + $fee, $currency)) . '</strong></td></tr>';
        }
        echo '</tbody></table>';
        echo '<p class="text-muted small">' . self::e(Lang::t('sim_tax_note')) . '</p>';
    }

    // ------------------------------------------------------------------ log

    private static function renderLog()
    {
        $rows = Repository::recentRecords(100);
        if (!count($rows)) {
            echo '<p class="text-muted">' . self::e(Lang::t('log_empty')) . '</p>';
            return;
        }
        echo '<table class="table table-striped"><thead><tr><th>' . self::e(Lang::t('log_invoice')) . '</th><th>' . self::e(Lang::t('col_gateway'))
            . '</th><th class="text-right">' . self::e(Lang::t('log_base')) . '</th><th class="text-right">' . self::e(Lang::t('sim_fee'))
            . '</th><th>' . self::e(Lang::t('log_source')) . '</th><th>' . self::e(Lang::t('log_date')) . '</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $ctxKey = 'ctx_' . $r->context;
            $ctx = Lang::t($ctxKey);
            if ($ctx === $ctxKey) {
                $ctx = $r->context;
            }
            echo '<tr><td><a href="invoices.php?action=edit&id=' . (int) $r->invoiceid . '">#' . (int) $r->invoiceid . '</a></td>'
                . '<td>' . self::e($r->gateway) . '</td>'
                . '<td class="text-right">' . self::e(self::n($r->base)) . '</td>'
                . '<td class="text-right">' . ((float) $r->fee > 0 ? self::e(self::n($r->fee)) : '<span class="text-muted">0</span>') . '</td>'
                . '<td>' . self::e($ctx) . '</td><td>' . self::e($r->updated_at) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    // ------------------------------------------------------------------ manual recalculation

    private static function handleRecalc()
    {
        $invoiceId = isset($_REQUEST['invoiceid']) ? (int) $_REQUEST['invoiceid'] : 0;
        $res = FeeService::recalculate($invoiceId, FeeService::CTX_MANUAL);
        header('Location: invoices.php?action=edit&id=' . $invoiceId . '&pfmsg=' . urlencode($res['message']));
        exit;
    }

    /**
     * Box shown on the admin invoice view.
     */
    public static function invoiceBox(array $vars)
    {
        $invoiceId = isset($vars['invoiceid']) ? (int) $vars['invoiceid'] : 0;
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            return '';
        }

        $record = Repository::invoiceRecord($invoiceId);
        $currency = Repository::currencyForClient($invoice->userid);
        $editable = FeeService::isEditable($invoice, FeeService::CTX_MANUAL);

        $html = '<div class="alert alert-info" style="margin-top:10px"><strong>' . self::e(Lang::t('box_title')) . '</strong> ';
        if ($record && (float) $record->fee > 0) {
            $html .= self::e(Repository::money((float) $record->fee, $currency)) . ' (' . self::e(Repository::gatewayName($record->gateway)) . ')';
            if ($record->gateway !== $invoice->paymentmethod) {
                $html .= ' — <span class="text-danger">' . self::e(Lang::t('box_other_gateway', array('gateway' => Repository::gatewayName($invoice->paymentmethod)))) . '</span>';
            }
        } else {
            $html .= self::e(Lang::t('box_none'));
        }

        if ($editable) {
            $url = 'addonmodules.php?module=' . Repository::MODULE . '&action=recalc&invoiceid=' . $invoiceId . '&token=' . urlencode(generate_token('plain'));
            $html .= ' &nbsp;<a class="btn btn-xs btn-default" href="' . self::e($url) . '">' . self::e(Lang::t('box_recalculate')) . '</a>';
        } elseif ($invoice->status === 'Unpaid') {
            $html .= ' <span class="text-muted">' . self::e(Lang::t('box_locked')) . '</span>';
        }

        if (!empty($_GET['pfmsg'])) {
            $html .= '<br><em>' . self::e((string) $_GET['pfmsg']) . '</em>';
        }
        return $html . '</div>';
    }

    // ------------------------------------------------------------------ helpers

    private static function envNotice()
    {
        $version = Env::version();
        $immutable = Env::invoicesImmutable();
        $canMutate = Env::canMutatePublished();

        $html = '<div class="alert pf-env">';
        $html .= self::e(Lang::t('env_version', array('version' => $version ?: Lang::t('env_unknown')))) . ' ';
        if ($immutable) {
            $html .= '<strong>' . self::e(Lang::t('env_immutable')) . '</strong> ';
        }
        $html .= self::e($canMutate ? Lang::t('env_recalc') : Lang::t('env_keep'));
        $html .= ' <a href="configaddonmods.php#' . Repository::MODULE . '">' . self::e(Lang::t('env_settings')) . '</a></div>';
        return $html;
    }

    private static function rulesScript()
    {
        return <<<'HTML'
<script>
(function () {
  function sync(row) {
    var type = row.querySelector('.pf-type').value;
    row.querySelectorAll('.pf-col-percent input').forEach(function (el) { el.disabled = (type === 'fixed'); });
    row.querySelectorAll('.pf-col-fixed input').forEach(function (el) { el.disabled = (type === 'percent'); });
  }
  document.querySelectorAll('.pf-row').forEach(function (row) {
    sync(row);
    row.querySelector('.pf-type').addEventListener('change', function () { sync(row); });
  });
  // Disabled inputs are not submitted; re-enable them on save to keep their values.
  var table = document.querySelector('.pf-table');
  var form = table && table.closest('form');
  if (form) form.addEventListener('submit', function () {
    form.querySelectorAll('input:disabled').forEach(function (el) { el.disabled = false; });
  });
})();
</script>
HTML;
    }

    private static function styles()
    {
        return '<style>.pf-tabs{margin-top:10px}.pf-panel{padding:15px 0}.pf-table td{vertical-align:middle!important}'
            . '.pf-table input[type=text]{min-width:80px}.pf-table .input-group{min-width:110px}.pf-env{background:#f5f5f5;border:1px solid #ddd}</style>';
    }

    private static function n($value)
    {
        $v = (float) $value;
        if ($v == 0) {
            return '0';
        }
        return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.');
    }

    public static function e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
