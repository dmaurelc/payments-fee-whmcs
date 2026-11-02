<?php
/**
 * Payments Fee WHMCS - test suite (no WHMCS needed).
 *
 * Run: php tests/tests.php
 */

require __DIR__ . '/fakewhmcs.php';
require __DIR__ . '/../modules/addons/paymentsfee/lib/bootstrap.php';
require __DIR__ . '/../modules/addons/paymentsfee/hooks.php';

use PaymentsFee\Calculator as C;
use PaymentsFee\Repository as R;
use PaymentsFee\Lang as L;
use WHMCS\Database\Capsule;

$fails = 0;
$n = 0;
function ok($cond, $name) { global $fails, $n; $n++; if (!$cond) { $fails++; echo "FAIL  $name\n"; } else { echo "ok    $name\n"; } }
function inv($id) { return Capsule::table('tblinvoices')->where('id', $id)->first(); }
function feeItems($id) { return Capsule::table('tblinvoiceitems')->where('invoiceid', $id)->whereIn('notes', array('paymentsfee', 'recargopagos'))->get()->all(); }
function resetEnv($version) {
    $GLOBALS['FAKE_VERSION'] = $version;
    $r = new ReflectionProperty('PaymentsFee\Env', 'version');
    if (PHP_VERSION_ID < 80100) { $r->setAccessible(true); }
    $r->setValue(null, null);
}

// ---------------------------------------------------------------- calculator
ok(C::compute(array('fee_type' => 'percent', 'percent' => 3.5), 10000, 1, 0) == 350, '3.5% of 10,000 = 350');
ok(C::compute(array('fee_type' => 'fixed', 'fixed' => 300), 10000, 1, 0) == 300, 'fixed 300');
ok(C::compute(array('fee_type' => 'both', 'percent' => 2.95, 'fixed' => 300), 20000, 1, 0) == 890, '2.95% + 300 of 20,000 = 890');
ok(C::compute(array('fee_type' => 'percent', 'percent' => 3.5, 'gross_up' => 1), 10000, 1, 0) == 363, 'gross-up 3.5% of 10,000 = 363 (ceil of 362.69)');
ok(C::compute(array('fee_type' => 'percent', 'percent' => 5, 'gross_up' => 1), 90000, 1, 0) == 4737, 'gross-up 5% of 90,000 = 4,737');
$g = C::compute(array('fee_type' => 'both', 'percent' => 3.19, 'fixed' => 0.30, 'gross_up' => 1), 100, 1, 2);
ok((100 + $g) * (1 - 0.0319) - 0.30 >= 100 && (100 + $g) * (1 - 0.0319) - 0.30 - 100 < 0.01, "gross-up covers the exact commission (fee $g)");
ok(C::compute(array('fee_type' => 'percent', 'percent' => 10, 'max_fee' => 500), 10000, 1, 0) == 500, 'maximum fee cap');
ok(C::compute(array('fee_type' => 'percent', 'percent' => 10, 'min_amount' => 5000), 4000, 1, 0) == 0, 'below minimum invoice amount: no fee');
ok(C::compute(array('fee_type' => 'fixed', 'fixed' => 1000), 50, 0.00105, 2) == 1.05, 'fixed 1000 CLP converted to USD = 1.05');
ok(C::compute(array('fee_type' => 'percent', 'percent' => 3), 0, 1, 0) == 0, 'zero base');
ok(C::formatPercent(3.5) === '3.5' && C::formatPercent(3.5, ',') === '3,5' && C::formatPercent(2) === '2', 'percent formatting');
ok(C::formatMoney(1500, 4, '$', '') === '$1,500' && C::formatMoney(1500.5, 3, '$', '') === '$1.500,50' && C::formatMoney(4737, 3, '$', ' CLP', 0) === '$4.737 CLP', 'money formatting');
ok(R::num('3,49') == 3.49 && R::num('1.500') == 1500 && R::num('1.500,50') == 1500.5 && R::num('0.3') == 0.3 && R::num('12.000.000') == 12000000, 'number parsing');

// ---------------------------------------------------------------- languages
ok(L::normalize('spanish') === 'spanish' && L::normalize('es_ES') === 'spanish' && L::normalize('english') === 'english' && L::normalize('portuguese-br') === 'english', 'language normalization');
L::setLocale('english');
ok(L::t('fee_item_default', array('gateway' => 'Stripe')) === 'Payment fee (Stripe)', 'English string');
L::setLocale('spanish');
ok(L::t('fee_item_default', array('gateway' => 'Stripe')) === 'Recargo por pago con Stripe', 'Spanish string');
ok(L::t('does_not_exist') === 'does_not_exist', 'missing key falls back to the key');
$en = (function () { $_ADDONLANG = array(); include __DIR__ . '/../modules/addons/paymentsfee/lang/english.php'; return $_ADDONLANG; })();
$es = (function () { $_ADDONLANG = array(); include __DIR__ . '/../modules/addons/paymentsfee/lang/spanish.php'; return $_ADDONLANG; })();
$missing = array_merge(array_diff(array_keys($en), array_keys($es)), array_diff(array_keys($es), array_keys($en)));
ok(count($missing) === 0, 'English and Spanish files have the same keys' . ($missing ? ' (missing: ' . implode(', ', $missing) . ')' : ''));

// ---------------------------------------------------------------- invoice flow, WHMCS 8
fake_reset();
L::setLocale('english');
R::saveRule('webpay', array('enabled' => 1, 'fee_type' => 'percent', 'percent' => '3,5'));
R::saveRule('flow', array('enabled' => 1, 'fee_type' => 'both', 'percent' => '2,95', 'fixed' => '300'));
R::saveRule('banktransfer', array('enabled' => 0, 'fee_type' => 'fixed', 'fixed' => '1000'));

fake_invoice(1, 10, 'webpay', array(array('Hosting', 10000)));
run_hook('InvoiceCreation', array('invoiceid' => 1));
ok(inv(1)->total == 10350 && count(feeItems(1)) == 1, 'creation: webpay 3.5% => total 10,350');
ok(feeItems(1)[0]->description === 'Recargo por pago con Webpay (3,5%)', 'line text in the client language (Spanish): ' . feeItems(1)[0]->description);
ok(L::locale() === 'english', 'locale restored after writing the line');

run_hook('InvoiceCreated', array('invoiceid' => 1));
ok(inv(1)->total == 10350 && count(feeItems(1)) == 1, 'InvoiceCreated does not duplicate the fee');

Capsule::table('tblinvoices')->where('id', 1)->update(array('paymentmethod' => 'flow'));
run_hook('InvoiceChangeGateway', array('invoiceid' => 1, 'paymentmethod' => 'flow'));
ok(inv(1)->total == 10595 && count(feeItems(1)) == 1, 'WHMCS 8 gateway change to flow: 2.95% + 300 = 595');

Capsule::table('tblinvoices')->where('id', 1)->update(array('paymentmethod' => 'banktransfer'));
run_hook('InvoiceChangeGateway', array('invoiceid' => 1, 'paymentmethod' => 'banktransfer'));
ok(inv(1)->total == 10000 && count(feeItems(1)) == 0, 'switching to a gateway without fee removes the line');

fake_invoice(2, 10, 'webpay', array(array('Hosting', 10000)), 'Paid');
run_hook('InvoiceChangeGateway', array('invoiceid' => 2));
ok(count(feeItems(2)) == 0, 'paid invoices are never modified');

fake_invoice(3, 10, 'webpay', array(array('Hosting', 10000)));
Capsule::table('tblaccounts')->insert(array('invoiceid' => 3, 'amountin' => 5000, 'amountout' => 0));
run_hook('InvoiceChangeGateway', array('invoiceid' => 3));
ok(count(feeItems(3)) == 0, 'invoices with partial payments are not modified on gateway change');

fake_invoice(4, 10, 'webpay', array(array('Funds', 10000, 'AddFunds')));
run_hook('InvoiceCreation', array('invoiceid' => 4));
ok(count(feeItems(4)) == 0, 'add funds invoices are skipped');
fake_invoice(5, 10, 'webpay', array(array('Invoice #1', 10000, 'Invoice')));
run_hook('InvoiceCreation', array('invoiceid' => 5));
ok(count(feeItems(5)) == 0, 'mass payment invoices are skipped');
fake_setting('skip_addfunds', '');
fake_invoice(6, 10, 'webpay', array(array('Funds', 10000, 'AddFunds')));
run_hook('InvoiceCreation', array('invoiceid' => 6));
ok(count(feeItems(6)) == 1, 'add funds invoices get a fee when the option is off');

fake_setting('exempt_clients', '30, 99');
fake_invoice(7, 30, 'webpay', array(array('Hosting', 10000)));
run_hook('InvoiceCreation', array('invoiceid' => 7));
ok(count(feeItems(7)) == 0, 'exempt client');

fake_invoice(8, 20, 'flow', array(array('Hosting', 100)));
run_hook('InvoiceCreation', array('invoiceid' => 8));
ok(abs(feeItems(8)[0]->amount - 3.27) < 0.001, 'USD: 2.95% + 300 CLP converted = ' . feeItems(8)[0]->amount);
ok(feeItems(8)[0]->description === 'Payment fee (Flow) (2.95% + US$0.32)', 'line text in the client language (English): ' . feeItems(8)[0]->description);

R::saveRule('webpay', array('enabled' => 1, 'fee_type' => 'percent', 'percent' => '3,5', 'taxed' => 1));
fake_invoice(9, 10, 'webpay', array(array('Hosting', 10000, 'Hosting', 1)), 'Unpaid', 19);
run_hook('InvoiceCreation', array('invoiceid' => 9));
ok(feeItems(9)[0]->amount == 417 && feeItems(9)[0]->taxed == 1, 'taxed fee on total incl. 19% tax = 417');
fake_setting('base_mode', 'subtotal');
Capsule::table('tblinvoices')->where('id', 9)->update(array('status' => 'Draft'));
run_hook('InvoiceChangeGateway', array('invoiceid' => 9));
ok(feeItems(9)[0]->amount == 350, 'subtotal base = 350');
fake_setting('base_mode', 'total');

fake_setting('fee_label', 'Card fee - {gateway}: {detail}');
fake_invoice(12, 10, 'webpay', array(array('Hosting', 10000)));
run_hook('InvoiceCreation', array('invoiceid' => 12));
ok(feeItems(12)[0]->description === 'Card fee - Webpay: 3,5%', 'custom text with {gateway} and {detail}: ' . feeItems(12)[0]->description);
fake_setting('fee_label', '');

fake_invoice(10, 10, 'webpay', array(array('Service', 20000)), 'Draft');
run_hook('UpdateInvoiceTotal', array('invoiceid' => 10));
ok(count(feeItems(10)) == 1 && feeItems(10)[0]->amount == 700, 'admin draft: fee 700');
Capsule::table('tblinvoiceitems')->insert(array('invoiceid' => 10, 'userid' => 10, 'type' => '', 'relid' => 0, 'description' => 'Extra', 'amount' => 10000, 'taxed' => 0, 'duedate' => '', 'paymentmethod' => 'webpay', 'notes' => ''));
updateInvoiceTotal(10);
ok(count(feeItems(10)) == 1 && feeItems(10)[0]->amount == 1050 && inv(10)->total == 31050, 'admin draft: adding a line recalculates the fee to 1,050');

fake_invoice(11, 10, 'webpay', array(array('Service', 20000)));
run_hook('UpdateInvoiceTotal', array('invoiceid' => 11));
ok(count(feeItems(11)) == 0, 'UpdateInvoiceTotal ignores Unpaid invoices');

// ---------------------------------------------------------------- WHMCS 9 (immutable)
resetEnv('9.0.2-release.1');
ok(\PaymentsFee\Env::invoicesImmutable() && !\PaymentsFee\Env::canMutatePublished(), 'WHMCS 9 detected as immutable');

fake_invoice(20, 10, 'webpay', array(array('Hosting', 10000)));
run_hook('InvoiceCreation', array('invoiceid' => 20));
ok(count(feeItems(20)) == 1 && inv(20)->total == 10350, 'WHMCS 9: fee added on creation');
Capsule::table('tblinvoices')->where('id', 20)->update(array('paymentmethod' => 'flow'));
run_hook('InvoiceChangeGateway', array('invoiceid' => 20));
ok(count(feeItems(20)) == 1 && inv(20)->total == 10350, 'WHMCS 9: gateway change does not modify a published invoice');
fake_invoice(21, 10, 'webpay', array(array('Hosting', 10000)), 'Draft');
Capsule::table('tblinvoices')->where('id', 21)->update(array('paymentmethod' => 'flow'));
run_hook('InvoiceChangeGateway', array('invoiceid' => 21));
ok(count(feeItems(21)) == 1 && feeItems(21)[0]->amount == 595, 'WHMCS 9: drafts are recalculated');
$GLOBALS['allow_adminarea_invoice_mutation'] = true;
ok(\PaymentsFee\Env::canMutatePublished(), 'WHMCS 9 with $allow_adminarea_invoice_mutation allows recalculation');
unset($GLOBALS['allow_adminarea_invoice_mutation']);
fake_setting('published_mode', 'recalculate');
run_hook('InvoiceChangeGateway', array('invoiceid' => 20));
ok(inv(20)->total == 10595, '"always recalculate" mode forces the change');
resetEnv('8.13.1-release.1');

// ---------------------------------------------------------------- legacy migration
fake_reset();
$p = Capsule::$pdo;
$p->exec("CREATE TABLE mod_recargopagos_rules (id INTEGER PRIMARY KEY, gateway TEXT, enabled INTEGER, fee_type TEXT, percent REAL, fixed REAL, max_fee REAL, min_amount REAL, gross_up INTEGER, taxed INTEGER, label TEXT, created_at TEXT, updated_at TEXT)");
$p->exec("INSERT INTO mod_recargopagos_rules VALUES (1,'flow',1,'percent',5,0,0,0,1,0,'','x','x')");
$p->exec("CREATE TABLE mod_recargopagos_invoices (invoiceid INTEGER PRIMARY KEY, itemid INTEGER, gateway TEXT, fee REAL, base REAL, context TEXT, updated_at TEXT)");
$p->exec("INSERT INTO mod_recargopagos_invoices VALUES (500, 9000, 'flow', 4737, 90000, 'creation', 'x')");
$p->exec("INSERT INTO tbladdonmodules VALUES ('recargopagos','modo_publicadas','mantener'),('recargopagos','descripcion','Recargo por pago con {gateway}'),('recargopagos','clientes_exentos','7')");
ok(R::migrateLegacy() === 1, 'legacy rules migrated');
$rule = R::activeRule('flow');
ok($rule && $rule['percent'] == 5 && $rule['gross_up'], 'migrated rule keeps its values');
ok(R::setting('published_mode') === 'keep' && R::setting('fee_label') === '' && R::exemptClientIds() === array(7), 'legacy settings mapped');
ok(R::invoiceRecord(500) !== null, 'legacy invoice log migrated');
ok(R::migrateLegacy() === 0, 'migration runs only once');

echo "\n$n tests, $fails failures\n";
exit($fails ? 1 : 0);
