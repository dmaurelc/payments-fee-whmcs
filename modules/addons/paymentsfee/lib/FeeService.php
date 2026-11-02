<?php
/**
 * Payments Fee WHMCS - Applies / removes the fee on invoices.
 */

namespace PaymentsFee;

use WHMCS\Database\Capsule;

class FeeService
{
    const ITEM_MARKER = 'paymentsfee';
    const LEGACY_ITEM_MARKER = 'recargopagos';

    // Where the recalculation is triggered from.
    const CTX_CREATION = 'creation';        // InvoiceCreation: invoice not delivered yet
    const CTX_CREATED = 'created';          // InvoiceCreated: safety net
    const CTX_GATEWAY = 'gateway_change';   // InvoiceChangeGateway
    const CTX_ITEMS = 'items_changed';      // UpdateInvoiceTotal (Draft only)
    const CTX_MANUAL = 'manual';            // admin "Recalculate" button

    // Results.
    const R_APPLIED = 'applied';
    const R_REMOVED = 'removed';
    const R_NONE = 'none';
    const R_LOCKED = 'locked';
    const R_SKIPPED = 'skipped';
    const R_NOT_FOUND = 'not_found';

    /** Prevents recursion: updateInvoiceTotal() fires the UpdateInvoiceTotal hook. */
    private static $running = false;

    public static function isRunning()
    {
        return self::$running;
    }

    /**
     * Recalculates the fee of an invoice.
     *
     * @return array ['result' => string, 'fee' => float, 'message' => string]
     */
    public static function recalculate($invoiceId, $context)
    {
        $invoiceId = (int) $invoiceId;
        if ($invoiceId <= 0 || self::$running) {
            return self::res(self::R_SKIPPED, 0, Lang::t('res_skipped'));
        }

        self::$running = true;
        try {
            return self::doRecalculate($invoiceId, $context);
        } catch (\Throwable $e) {
            self::log('Error on invoice #' . $invoiceId . ': ' . $e->getMessage());
            return self::res(self::R_SKIPPED, 0, Lang::t('error', array('error' => $e->getMessage())));
        } finally {
            self::$running = false;
        }
    }

    /**
     * Can this invoice be modified in this context?
     */
    public static function isEditable($invoice, $context)
    {
        $status = (string) $invoice->status;

        if ($status === 'Draft') {
            return true;
        }
        if ($status !== 'Unpaid') {
            return false; // Paid, Cancelled, Refunded, Collections, Payment Pending...
        }
        if ($context === self::CTX_CREATION) {
            // Per the WHMCS docs, during InvoiceCreation the invoice has not been
            // delivered yet and its line items can be changed.
            return true;
        }
        if ($context === self::CTX_ITEMS) {
            return false; // only follow manual edits on drafts
        }
        // Never touch invoices that already have payments.
        if (self::paidAmount($invoice->id) > 0) {
            return false;
        }
        return Env::canMutatePublished();
    }

    private static function doRecalculate($invoiceId, $context)
    {
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            return self::res(self::R_NOT_FOUND, 0, Lang::t('res_not_found'));
        }

        $record = Repository::invoiceRecord($invoiceId);

        // Safety net: if InvoiceCreation already processed it, do nothing.
        if ($context === self::CTX_CREATED && $record) {
            return self::res(self::R_SKIPPED, (float) $record->fee, Lang::t('res_processed'));
        }

        if (!self::isEditable($invoice, $context)) {
            return self::res(self::R_LOCKED, $record ? (float) $record->fee : 0, Lang::t('res_locked', array('status' => $invoice->status)));
        }

        $hadFee = self::removeFeeItems($invoiceId);
        self::updateTotals($invoiceId);
        $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();

        $gateway = (string) $invoice->paymentmethod;
        $skipReason = self::skipReason($invoice);
        $rule = $skipReason === null ? Repository::activeRule($gateway) : null;

        if ($rule === null) {
            Repository::saveInvoiceRecord($invoiceId, null, $gateway, 0, 0, $context);
            if ($hadFee) {
                self::log('Fee removed from invoice #' . $invoiceId . ' (' . ($skipReason ?: 'gateway without fee: ' . $gateway) . ')');
                return self::res(self::R_REMOVED, 0, Lang::t('res_removed'));
            }
            return self::res(self::R_NONE, 0, $skipReason ?: Lang::t('res_none'));
        }

        $currency = Repository::currencyForClient($invoice->userid);
        $base = self::baseAmount($invoice);
        $fee = Calculator::compute($rule, $base, $currency->rate, $currency->decimals);

        if ($fee <= 0) {
            Repository::saveInvoiceRecord($invoiceId, null, $gateway, 0, $base, $context);
            if ($hadFee) {
                return self::res(self::R_REMOVED, 0, Lang::t('res_removed_min'));
            }
            return self::res(self::R_NONE, 0, Lang::t('res_zero'));
        }

        $description = self::itemDescription($rule, $gateway, $currency, $invoice->userid);

        $itemId = Capsule::table('tblinvoiceitems')->insertGetId(array(
            'invoiceid' => $invoiceId,
            'userid' => $invoice->userid,
            'type' => '',
            'relid' => 0,
            'description' => $description,
            'amount' => $fee,
            'taxed' => $rule['taxed'] ? 1 : 0,
            'duedate' => $invoice->duedate,
            'paymentmethod' => $gateway,
            'notes' => self::ITEM_MARKER,
        ));

        self::updateTotals($invoiceId);
        Repository::saveInvoiceRecord($invoiceId, $itemId, $gateway, $fee, $base, $context);
        self::log('Fee of ' . Repository::money($fee, $currency) . ' applied to invoice #' . $invoiceId . ' (' . $gateway . ', ' . $context . ')');

        return self::res(self::R_APPLIED, $fee, Lang::t('res_applied'));
    }

    /**
     * Reason to skip the fee, or null when it applies.
     */
    private static function skipReason($invoice)
    {
        if (in_array((int) $invoice->userid, Repository::exemptClientIds(), true)) {
            return Lang::t('skip_exempt');
        }

        $types = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoice->id)
            ->pluck('type');
        $types = is_object($types) && method_exists($types, 'all') ? $types->all() : (array) $types;

        if (count($types) === 0) {
            return Lang::t('skip_no_items');
        }
        if (Repository::flag('skip_addfunds') && in_array('AddFunds', $types, true)) {
            return Lang::t('skip_addfunds');
        }
        if (Repository::flag('skip_masspay') && in_array('Invoice', $types, true)) {
            return Lang::t('skip_masspay');
        }
        return null;
    }

    /**
     * Base amount according to the "base_mode" setting.
     */
    public static function baseAmount($invoice)
    {
        $mode = (string) Repository::setting('base_mode');
        $total = (float) $invoice->total;

        if ($mode === 'subtotal') {
            return (float) $invoice->subtotal;
        }
        if ($mode === 'balance') {
            $balance = $total - (float) $invoice->credit - self::paidAmount($invoice->id);
            return max(0.0, $balance);
        }
        return $total;
    }

    /**
     * Invoice line text, in the client's language.
     */
    private static function itemDescription(array $rule, $gateway, $currency, $userId)
    {
        $previous = Lang::locale();
        Lang::useClientLanguage($userId);

        $template = $rule['label'] !== '' ? $rule['label'] : (string) Repository::setting('fee_label');
        if (trim($template) === '') {
            $template = Lang::t('fee_item_default');
        }
        $detail = Calculator::describe($rule, function ($amount) use ($currency) {
            return Repository::money($amount, $currency);
        }, $currency->rate, Lang::decimalSeparator());

        $text = str_replace(
            array('{gateway}', '{detail}', '{detalle}'),
            array(Repository::gatewayName($gateway), $detail, $detail),
            $template
        );

        $hasDetailVar = strpos($template, '{detail}') !== false || strpos($template, '{detalle}') !== false;
        if (Repository::flag('show_detail') && !$hasDetailVar && $detail !== '') {
            $text .= ' (' . $detail . ')';
        }

        Lang::setLocale($previous);
        return $text;
    }

    /**
     * Removes the fee lines from the invoice.
     *
     * @return bool true if there were any
     */
    public static function removeFeeItems($invoiceId)
    {
        $ids = Capsule::table('tblinvoiceitems')
            ->where('invoiceid', $invoiceId)
            ->whereIn('notes', array(self::ITEM_MARKER, self::LEGACY_ITEM_MARKER))
            ->pluck('id');
        $ids = is_object($ids) && method_exists($ids, 'all') ? $ids->all() : (array) $ids;
        $ids = array_map('intval', $ids);

        $record = Repository::invoiceRecord($invoiceId);
        if ($record && $record->itemid && !in_array((int) $record->itemid, $ids, true)) {
            $exists = Capsule::table('tblinvoiceitems')
                ->where('id', $record->itemid)
                ->where('invoiceid', $invoiceId)
                ->exists();
            if ($exists) {
                $ids[] = (int) $record->itemid;
            }
        }

        if (count($ids) === 0) {
            return false;
        }
        Capsule::table('tblinvoiceitems')->whereIn('id', $ids)->delete();
        return true;
    }

    public static function paidAmount($invoiceId)
    {
        return (float) Capsule::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->sum(Capsule::raw('amountin - amountout'));
    }

    private static function updateTotals($invoiceId)
    {
        if (!function_exists('updateInvoiceTotal') && defined('ROOTDIR')) {
            $file = ROOTDIR . '/includes/invoicefunctions.php';
            if (is_file($file)) {
                require_once $file;
            }
        }
        if (function_exists('updateInvoiceTotal')) {
            updateInvoiceTotal($invoiceId);
        } else {
            self::log('updateInvoiceTotal() is not available; totals of invoice #' . $invoiceId . ' were not recalculated.');
        }
    }

    private static function res($result, $fee, $message)
    {
        return array('result' => $result, 'fee' => (float) $fee, 'message' => $message);
    }

    /** Activity log entries are always in English, like WHMCS core entries. */
    public static function log($message)
    {
        if (function_exists('logActivity')) {
            logActivity('Payments Fee: ' . $message);
        }
    }
}
