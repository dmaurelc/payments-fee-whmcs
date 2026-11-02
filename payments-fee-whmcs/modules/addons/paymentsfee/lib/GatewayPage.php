<?php
/**
 * Payments Fee WHMCS - Fee fields inside
 * Configuration > Payment Gateways (configgateways.php).
 *
 * The fields are injected into each gateway's form and saved with a separate
 * request when "Save Changes" is clicked. They only require permission to
 * manage payment gateways, not access to the addon page.
 */

namespace PaymentsFee;

class GatewayPage
{
    const ACTION = 'paymentsfee_save';

    public static function isGatewayPage()
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
        return $script === 'configgateways.php';
    }

    /**
     * Handles the save request (AdminAreaPage hook). Responds with JSON and exits.
     */
    public static function handleSave()
    {
        if (!self::isGatewayPage()
            || !isset($_POST['pf_action']) || $_POST['pf_action'] !== self::ACTION) {
            return;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');

        try {
            check_token('WHMCS.admin.default');
            Repository::createSchema();

            $gateway = isset($_POST['gateway']) ? (string) $_POST['gateway'] : '';
            $gateways = Repository::activeGateways();
            if (!isset($gateways[$gateway])) {
                echo json_encode(array('ok' => false, 'error' => Lang::t('gw_invalid')));
                exit;
            }

            $data = isset($_POST['pf']) && is_array($_POST['pf']) ? $_POST['pf'] : array();
            Repository::saveRule($gateway, $data);
            FeeService::log('Fee rule updated for ' . $gateway . '.');

            $rules = Repository::rules();
            echo json_encode(array('ok' => true, 'rule' => $rules[$gateway]));
        } catch (\Throwable $e) {
            echo json_encode(array('ok' => false, 'error' => $e->getMessage()));
        }
        exit;
    }

    /**
     * Script that adds the fields to each gateway (AdminAreaFooterOutput hook).
     */
    public static function footer()
    {
        if (!self::isGatewayPage()) {
            return '';
        }

        Repository::createSchema();
        $currency = Repository::currency(0);
        $rules = Repository::rules();
        $data = array();
        foreach (Repository::activeGateways() as $gw => $name) {
            $data[$gw] = isset($rules[$gw]) ? $rules[$gw] : array(
                'enabled' => false, 'fee_type' => 'percent', 'percent' => 0, 'fixed' => 0,
                'max_fee' => 0, 'min_amount' => 0, 'gross_up' => false, 'taxed' => false, 'label' => '',
            );
        }

        $defaultLabel = (string) Repository::setting('fee_label');
        if (trim($defaultLabel) === '') {
            $defaultLabel = Lang::t('fee_item_default');
        }

        $keys = array(
            'gw_section', 'gw_enabled', 'gw_enabled_desc', 'gw_type', 'type_percent', 'type_fixed', 'type_both',
            'gw_percent', 'gw_fixed', 'gw_max_fee', 'gw_min_amount', 'gw_optional', 'gw_no_cap', 'gw_always',
            'gw_gross_up', 'gw_gross_up_desc', 'gw_taxed', 'gw_taxed_desc', 'gw_label', 'gw_label_hint',
            'gw_simulator', 'gw_example_percent', 'gw_example_fixed', 'gw_saved', 'gw_save_error',
        );
        $i18n = array();
        foreach ($keys as $k) {
            $i18n[$k] = Lang::t($k);
        }

        $cfg = json_encode(array(
            'rules' => $data,
            'action' => self::ACTION,
            'currency' => $currency->code,
            'defaultLabel' => $defaultLabel,
            'addonUrl' => 'addonmodules.php?module=' . Repository::MODULE . '&tab=simulator',
            't' => $i18n,
        ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        return <<<HTML
<style>
.pf-gw-head td{padding-top:18px!important;border-top:2px solid #e5e5e5}
.pf-gw-head strong{font-size:14px}
.pf-gw-row input[type=text]{max-width:160px;display:inline-block}
.pf-gw-row select{max-width:240px;display:inline-block}
.pf-gw-row .pf-hint{color:#888;font-size:12px;margin-left:6px}
.pf-gw-status{margin-left:8px;font-size:12px}
</style>
<script>
(function () {
  var cfg = {$cfg};
  var t = cfg.t;
  function num(v){ v = Number(v) || 0; return v === 0 ? '' : String(v); }
  function esc(s){ return String(s == null ? '' : s).replace(/[&<>"']/g, function(c){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }

  function row(label, field, hint){
    return '<tr class="pf-gw-row"><td class="fieldlabel">' + esc(label) + '</td><td class="fieldarea">' + field +
      (hint ? '<span class="pf-hint">' + hint + '</span>' : '') + '</td></tr>';
  }

  function build(r){
    var cur = esc(cfg.currency), opt = ' (' + esc(t.gw_optional) + ')';
    var h = '<tr class="pf-gw-head"><td class="fieldlabel"></td><td class="fieldarea"><strong>' + esc(t.gw_section) + '</strong></td></tr>';
    h += row(t.gw_enabled, '<label><input type="checkbox" data-pf="enabled"' + (r.enabled ? ' checked' : '') + '> ' + esc(t.gw_enabled_desc) + '</label>');
    h += row(t.gw_type, '<select class="form-control input-sm" data-pf="fee_type">' +
      '<option value="percent"' + (r.fee_type === 'percent' ? ' selected' : '') + '>' + esc(t.type_percent) + '</option>' +
      '<option value="fixed"' + (r.fee_type === 'fixed' ? ' selected' : '') + '>' + esc(t.type_fixed) + '</option>' +
      '<option value="both"' + (r.fee_type === 'both' ? ' selected' : '') + '>' + esc(t.type_both) + '</option></select>');
    h += row(t.gw_percent, '<input type="text" class="form-control input-sm" data-pf="percent" value="' + esc(num(r.percent)) + '" placeholder="' + esc(t.gw_example_percent) + '">', '%');
    h += row(t.gw_fixed, '<input type="text" class="form-control input-sm" data-pf="fixed" value="' + esc(num(r.fixed)) + '" placeholder="' + esc(t.gw_example_fixed) + '">', cur);
    h += row(t.gw_max_fee, '<input type="text" class="form-control input-sm" data-pf="max_fee" value="' + esc(num(r.max_fee)) + '" placeholder="' + esc(t.gw_no_cap) + '">', cur + opt);
    h += row(t.gw_min_amount, '<input type="text" class="form-control input-sm" data-pf="min_amount" value="' + esc(num(r.min_amount)) + '" placeholder="' + esc(t.gw_always) + '">', cur + opt);
    h += row(t.gw_gross_up, '<label><input type="checkbox" data-pf="gross_up"' + (r.gross_up ? ' checked' : '') + '> ' + esc(t.gw_gross_up_desc) + '</label>');
    h += row(t.gw_taxed, '<label><input type="checkbox" data-pf="taxed"' + (r.taxed ? ' checked' : '') + '> ' + esc(t.gw_taxed_desc) + '</label>');
    h += row(t.gw_label, '<input type="text" class="form-control input-sm" style="max-width:360px" data-pf="label" value="' + esc(r.label) + '" placeholder="' + esc(cfg.defaultLabel) + '">',
      esc(t.gw_label_hint) + ' <a href="' + esc(cfg.addonUrl) + '">' + esc(t.gw_simulator) + '</a>');
    return h;
  }

  function sync(form){
    var type = form.querySelector('[data-pf=fee_type]').value;
    var on = form.querySelector('[data-pf=enabled]').checked;
    form.querySelectorAll('.pf-gw-row').forEach(function (tr, i) { if (i > 0) tr.style.opacity = on ? '1' : '0.5'; });
    form.querySelector('[data-pf=percent]').closest('tr').style.display = type === 'fixed' ? 'none' : '';
    form.querySelector('[data-pf=gross_up]').closest('tr').style.display = type === 'fixed' ? 'none' : '';
    form.querySelector('[data-pf=fixed]').closest('tr').style.display = type === 'percent' ? 'none' : '';
  }

  function save(form, gw){
    var body = new URLSearchParams();
    body.append('pf_action', cfg.action);
    body.append('gateway', gw);
    var tok = form.querySelector('input[name=token]');
    if (tok) body.append('token', tok.value);
    form.querySelectorAll('[data-pf]').forEach(function (el) {
      var k = el.getAttribute('data-pf');
      if (el.type === 'checkbox') { if (el.checked) body.append('pf[' + k + ']', '1'); }
      else body.append('pf[' + k + ']', el.value);
    });
    var status = form.querySelector('.pf-gw-status');
    return fetch('configgateways.php', { method: 'POST', body: body, credentials: 'same-origin', keepalive: true,
      headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (status) { status.textContent = j.ok ? '✓ ' + t.gw_saved : '✗ ' + (j.error || t.gw_save_error); status.style.color = j.ok ? '#3c763d' : '#a94442'; }
      })
      .catch(function () { if (status) { status.textContent = '✗ ' + t.gw_save_error; status.style.color = '#a94442'; } });
  }

  document.querySelectorAll('form[id^="config"][id$="Form"]').forEach(function (form) {
    var modInput = form.querySelector('input[name=module]');
    var table = form.querySelector('table.form');
    if (!modInput || !table || form.getAttribute('data-pf-ready')) return;
    var gw = modInput.value;
    var r = cfg.rules[gw];
    if (!r) return;
    form.setAttribute('data-pf-ready', '1');

    var rows = table.querySelectorAll('tr');
    var last = rows[rows.length - 1];
    var hasButtons = last && last.querySelector('button, .btn');
    var tmp = document.createElement('tbody');
    tmp.innerHTML = build(r);
    var parent = last ? last.parentNode : table;
    Array.prototype.slice.call(tmp.children).forEach(function (tr) {
      if (hasButtons) parent.insertBefore(tr, last); else parent.appendChild(tr);
    });
    if (hasButtons) {
      var st = document.createElement('span');
      st.className = 'pf-gw-status';
      (last.querySelector('td.fieldarea') || last.lastElementChild).appendChild(st);
    }

    sync(form);
    form.querySelectorAll('[data-pf=fee_type],[data-pf=enabled]').forEach(function (el) {
      el.addEventListener('change', function () { sync(form); });
    });

    // Save together with "Save Changes" (WHMCS saves the gateway via AJAX or a reload).
    var lastSave = 0;
    function trigger() {
      var now = Date.now();
      if (now - lastSave < 1500) return;
      lastSave = now;
      save(form, gw);
    }
    form.addEventListener('submit', trigger, true);
    if (hasButtons) {
      last.querySelectorAll('button.btn-primary, input[type=submit]').forEach(function (b) {
        b.addEventListener('click', trigger, true);
      });
    }
  });
})();
</script>
HTML;
    }
}
