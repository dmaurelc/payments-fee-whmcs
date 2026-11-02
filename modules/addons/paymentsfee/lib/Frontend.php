<?php
/**
 * Payments Fee WHMCS - Client area output:
 *  - Cart checkout: the configured fee next to each gateway, and the
 *    calculated fee + total to pay for the selected gateway.
 *  - Invoice view: each gateway's fee in the gateway selector.
 */

namespace PaymentsFee;

use WHMCS\Database\Capsule;

class Frontend
{
    public static function footer(array $vars)
    {
        if (!Repository::flag('show_in_checkout')) {
            return '';
        }

        $file = isset($vars['filename']) ? (string) $vars['filename'] : basename($_SERVER['SCRIPT_NAME'], '.php');
        $isCart = $file === 'cart';
        $isInvoice = $file === 'viewinvoice';
        if (!$isCart && !$isInvoice) {
            return '';
        }

        $currency = self::currentCurrency($vars);
        $base = null;
        $invoice = null;

        if ($isCart) {
            $base = self::cartTotal($vars);
        } elseif (!empty($_GET['id'])) {
            $invoice = self::clientInvoice((int) $_GET['id']);
            if ($invoice) {
                $currency = Repository::currencyForClient($invoice->userid);
                $base = self::invoiceBase($invoice);
            }
        }

        $gateways = array();
        foreach (Repository::rules() as $gw => $rule) {
            if (!$rule['enabled']) {
                continue;
            }
            $desc = Calculator::describe($rule, function ($a) use ($currency) {
                return Repository::money($a, $currency);
            }, $currency->rate, Lang::decimalSeparator());
            // ruleText: the configured rule ("+ 5%", "+ $300"), shown next to the gateway.
            $entry = array('label' => '', 'ruleText' => $desc !== '' ? '+ ' . $desc : '', 'fee' => 0, 'feeText' => '', 'totalText' => '');
            if ($base !== null && $base > 0) {
                $fee = Calculator::compute($rule, $base, $currency->rate, $currency->decimals);
                if ($fee <= 0) {
                    continue; // below the minimum: no fee
                }
                $entry['fee'] = $fee;
                $entry['feeText'] = Repository::money($fee, $currency);
                $entry['totalText'] = Repository::money($base + $fee, $currency);
                $entry['label'] = '+ ' . $entry['feeText'];
            } else {
                if ($desc === '') {
                    continue;
                }
                $entry['label'] = '+ ' . $desc;
            }
            $gateways[$gw] = $entry;
        }

        $notice = $invoice ? self::invoiceNotice($invoice) : '';

        if (!$gateways && $notice === '') {
            return '';
        }

        // Data to recalculate in the browser when the cart total is not available.
        $jsRules = array();
        foreach (Repository::rules() as $gw => $rule) {
            if ($rule['enabled']) {
                $jsRules[$gw] = array(
                    't' => $rule['fee_type'], 'p' => $rule['percent'],
                    'f' => $rule['fixed'] * $currency->rate, 'max' => $rule['max_fee'] * $currency->rate,
                    'min' => $rule['min_amount'] * $currency->rate, 'g' => $rule['gross_up'],
                );
            }
        }

        $json = json_encode(array(
            'rules' => $jsRules,
            'cur' => array('d' => $currency->decimals, 'fmt' => (int) $currency->format, 'pre' => (string) $currency->prefix, 'suf' => (string) $currency->suffix),
            'gateways' => $gateways,
            'notice' => $notice,
            'isCart' => $isCart,
            'baseText' => ($base !== null && $base > 0) ? Repository::money($base, $currency) : '',
            'names' => Repository::activeGateways(),
            'i18n' => array('fee' => Lang::t('checkout_fee'), 'total' => Lang::t('checkout_total')),
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        return <<<HTML
<style>
.pf-summary{margin:10px 0 0;font-size:15px;line-height:1.5}
.pf-summary .pf-sum-total{font-weight:700;font-size:1.15em}
.pf-summary .pf-sum-fee{opacity:.85}
</style>
<script>
(function () {
  var cfg = {$json};

  function selectedGateway() {
    var r = document.querySelector('input[type=radio][name="paymentmethod"]:checked');
    if (r) return r.value;
    var s = document.querySelector('select[name="paymentmethod"]');
    return s ? s.value : null;
  }

  function labels() {
    // At checkout the configured rule (+ 5%) goes next to the gateway; the calculated amount goes in the summary.
    document.querySelectorAll('input[type=radio][name="paymentmethod"]').forEach(function (input) {
      var g = cfg.gateways[input.value];
      var txt = g ? (cfg.isCart ? g.ruleText : g.label) : '';
      if (!txt || input.getAttribute('data-rp')) return;
      input.setAttribute('data-rp', '1');
      var holder = input.closest('label') || input.parentNode;
      var span = document.createElement('small');
      span.className = 'pf-fee text-muted';
      span.textContent = ' (' + txt + ')';
      holder.appendChild(span);
    });
    if (!cfg.isCart) document.querySelectorAll('select[name="gateway"] option, select[name="paymentmethod"] option').forEach(function (opt) {
      var g = cfg.gateways[opt.value];
      if (!g || opt.getAttribute('data-rp')) return;
      opt.setAttribute('data-rp', '1');
      opt.textContent = opt.textContent + ' (' + g.label + ')';
    });
  }

  // --- Browser-side calculation (fallback when the server did not provide the total) ---
  function compute(r, base) {
    if (base <= 0 || (r.min > 0 && base < r.min)) return 0;
    var p = (r.t === 'percent' || r.t === 'both') ? r.p / 100 : 0;
    var f = (r.t === 'fixed' || r.t === 'both') ? r.f : 0;
    var fee = (r.g && p > 0 && p < 1) ? (base + f) / (1 - p) - base : base * p + f;
    if (r.max > 0 && fee > r.max) fee = r.max;
    if (fee <= 0) return 0;
    var k = Math.pow(10, cfg.cur.d);
    return r.g ? Math.ceil(Math.round(fee * k * 1e6) / 1e6) / k : Math.round(fee * k) / k;
  }
  function money(n) {
    var d = cfg.cur.fmt === 4 ? 0 : cfg.cur.d, dec = '.', th = ',';
    if (cfg.cur.fmt === 1) th = '';
    if (cfg.cur.fmt === 3) { dec = ','; th = '.'; }
    var parts = n.toFixed(d).split('.');
    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, th);
    return (cfg.cur.pre + parts.join(dec) + cfg.cur.suf).trim();
  }
  function parseAmount(text) {
    var s = String(text).replace(/[^\d.,]/g, '');
    var c = s.lastIndexOf(','), d = s.lastIndexOf('.');
    if (c > -1 && d > -1) s = c > d ? s.replace(/\./g, '').replace(',', '.') : s.replace(/,/g, '');
    else if (c > -1) s = /,\d{3}$/.test(s) ? s.replace(/,/g, '') : s.replace(',', '.');
    else if (d > -1 && /^\d{1,3}(\.\d{3})+$/.test(s)) s = s.replace(/\./g, '');
    return parseFloat(s) || 0;
  }
  function fromDom() {
    if (cfg.baseText) return;
    var el = document.getElementById('totalCartPrice') || document.querySelector('#totalDueToday strong, #totalDueToday span');
    if (!el) return;
    var base = parseAmount(el.textContent);
    if (base <= 0) return;
    cfg.baseText = money(base);
    Object.keys(cfg.rules).forEach(function (gw) {
      var fee = compute(cfg.rules[gw], base);
      var prev = cfg.gateways[gw] || {};
      if (fee > 0) cfg.gateways[gw] = { label: '+ ' + money(fee), ruleText: prev.ruleText || '', fee: fee, feeText: money(fee), totalText: money(base + fee) };
      else delete cfg.gateways[gw];
    });
  }

  // Summary under "Total Due Today": fee and total for the selected gateway.
  function summary() {
    if (!cfg.isCart) return;
    fromDom();
    if (!cfg.baseText) return;
    var box = document.getElementById('totalDueToday');
    var anchor = box;
    if (!anchor) {
      var first = document.querySelector('input[type=radio][name="paymentmethod"]');
      if (!first) return;
      anchor = first.closest('.form-group, .text-center, div') || first.parentNode;
    }
    var el = document.querySelector('.pf-summary');
    if (!el) {
      el = document.createElement('div');
      el.className = 'pf-summary';
      if (box) box.appendChild(el); else anchor.parentNode.insertBefore(el, anchor);
    }
    var gw = selectedGateway();
    var g = gw ? cfg.gateways[gw] : null;
    if (!g || !g.fee) { el.innerHTML = ''; el.style.display = 'none'; return; }
    var name = cfg.names[gw] || gw;
    el.style.display = '';
    el.innerHTML = '<div class="pf-sum-fee">' + esc(cfg.i18n.fee.replace('{gateway}', name)) + ' <strong>' + esc(g.feeText) + '</strong></div>' +
      '<div class="pf-sum-total">' + esc(cfg.i18n.total) + ' ' + esc(g.totalText) + '</div>';
  }

  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  function notice() {
    if (!cfg.notice || document.querySelector('.pf-notice')) return;
    var target = document.querySelector('select[name="gateway"]');
    if (!target) return;
    var div = document.createElement('div');
    div.className = 'pf-notice alert alert-info';
    div.style.marginTop = '10px';
    div.textContent = cfg.notice;
    (target.closest('form') || target.parentNode).appendChild(div);
  }

  function run() { if (cfg.isCart) fromDom(); labels(); summary(); notice(); }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run); else run();
  document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'paymentmethod') summary();
  });
  // iCheck (used by the standard cart) does not always fire "change".
  if (window.jQuery) {
    jQuery(document).on('ifChecked', 'input[name="paymentmethod"]', function () { setTimeout(summary, 0); });
  }
  // Some carts load the gateways via AJAX.
  if (window.MutationObserver) {
    var t; new MutationObserver(function () { clearTimeout(t); t = setTimeout(function () { labels(); summary(); }, 200); })
      .observe(document.body, { childList: true, subtree: true });
  }
})();
</script>
HTML;
    }

    /**
     * Cart total at checkout, from the template variables.
     */
    public static function cartTotal(array $vars)
    {
        foreach (array('total', 'rawtotal', 'totalDueToday') as $key) {
            if (!isset($vars[$key])) {
                continue;
            }
            $v = self::toNumber($vars[$key]);
            if ($v !== null && $v > 0) {
                return $v;
            }
        }
        return null;
    }

    public static function toNumber($value)
    {
        if (is_object($value)) {
            if (method_exists($value, 'toNumeric')) {
                return (float) $value->toNumeric();
            }
            if (method_exists($value, 'toFull')) {
                $value = $value->toFull();
            } elseif (method_exists($value, '__toString')) {
                $value = (string) $value;
            } else {
                return null;
            }
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (!is_string($value)) {
            return null;
        }
        $s = preg_replace('/[^\d.,]/', '', strip_tags($value));
        if ($s === '') {
            return null;
        }
        $lastComma = strrpos($s, ',');
        $lastDot = strrpos($s, '.');
        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {          // 90.000,00
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {                              // 90,000.00
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            // 90,000 (thousands) or 12,50 (decimals)
            $s = preg_match('/,\d{3}$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($lastDot !== false && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);        // 90.000
        }
        return (float) $s;
    }

    private static function clientInvoice($invoiceId)
    {
        $uid = self::clientId();
        if (!$uid) {
            return null;
        }
        return Capsule::table('tblinvoices')->where('id', $invoiceId)->where('userid', $uid)->first();
    }

    /**
     * Invoice base without the fee already included.
     */
    private static function invoiceBase($invoice)
    {
        if ($invoice->status !== 'Unpaid') {
            return null;
        }
        $base = FeeService::baseAmount($invoice);
        $record = Repository::invoiceRecord($invoice->id);
        if ($record && (float) $record->fee > 0) {
            $base -= (float) $record->fee;
        }
        return $base > 0 ? $base : null;
    }

    /**
     * Notice when a published invoice carries a fee calculated for another
     * gateway and cannot be modified (WHMCS 9+).
     */
    private static function invoiceNotice($invoice)
    {
        if ($invoice->status !== 'Unpaid') {
            return '';
        }
        $record = Repository::invoiceRecord($invoice->id);
        if (!$record || (float) $record->fee <= 0 || $record->gateway === $invoice->paymentmethod) {
            return '';
        }
        if (FeeService::isEditable($invoice, FeeService::CTX_GATEWAY)) {
            return '';
        }
        return Lang::t('invoice_notice', array('gateway' => Repository::gatewayName($record->gateway)));
    }

    private static function currentCurrency(array $vars)
    {
        $uid = self::clientId();
        if ($uid) {
            return Repository::currencyForClient($uid);
        }
        if (!empty($vars['activeCurrency']) && is_object($vars['activeCurrency']) && isset($vars['activeCurrency']->id)) {
            return Repository::currency((int) $vars['activeCurrency']->id);
        }
        if (!empty($vars['currency']['id'])) {
            return Repository::currency((int) $vars['currency']['id']);
        }
        if (!empty($_SESSION['currency'])) {
            return Repository::currency((int) $_SESSION['currency']);
        }
        return Repository::currency(0);
    }

    private static function clientId()
    {
        try {
            if (class_exists('\\WHMCS\\Session')) {
                $uid = (int) \WHMCS\Session::get('uid');
                if ($uid) {
                    return $uid;
                }
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return isset($_SESSION['uid']) ? (int) $_SESSION['uid'] : 0;
    }
}
