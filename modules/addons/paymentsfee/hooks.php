<?php
/**
 * Payments Fee WHMCS - WHMCS hooks.
 *
 * WHMCS only loads this file while the addon is active.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use PaymentsFee\Admin;
use PaymentsFee\FeeService;
use PaymentsFee\Frontend;
use PaymentsFee\GatewayPage;

/**
 * 1) Invoice creation (before it is delivered to the client).
 *    Works on WHMCS 8 and 9: line items can still be changed here.
 *    Covers cart orders, automatic renewals and API-created invoices.
 */
add_hook('InvoiceCreation', 1, function ($vars) {
    if (!empty($vars['invoiceid'])) {
        FeeService::recalculate($vars['invoiceid'], FeeService::CTX_CREATION);
    }
});

/**
 * 2) Safety net in case an invoice did not go through InvoiceCreation.
 *    Only acts while the invoice is still editable.
 */
add_hook('InvoiceCreated', 1, function ($vars) {
    if (!empty($vars['invoiceid'])) {
        FeeService::recalculate($vars['invoiceid'], FeeService::CTX_CREATED);
    }
});

/**
 * 3) Gateway change (client on the invoice or admin in Options).
 *    Drafts: always. Published unpaid invoices: depends on "published_mode".
 */
add_hook('InvoiceChangeGateway', 1, function ($vars) {
    if (!empty($vars['invoiceid'])) {
        FeeService::recalculate($vars['invoiceid'], FeeService::CTX_GATEWAY);
    }
});

/**
 * 4) Line item edits on Draft invoices created manually by an admin.
 */
add_hook('UpdateInvoiceTotal', 1, function ($vars) {
    if (!empty($vars['invoiceid']) && !FeeService::isRunning()) {
        FeeService::recalculate($vars['invoiceid'], FeeService::CTX_ITEMS);
    }
});

/**
 * 5) Fee info and "Recalculate" button on the admin invoice view.
 */
add_hook('AdminInvoicesControlsOutput', 1, function ($vars) {
    try {
        return Admin::invoiceBox($vars);
    } catch (\Throwable $e) {
        return '';
    }
});

/**
 * 6) Fee fields inside Configuration > Payment Gateways.
 */
add_hook('AdminAreaPage', 1, function ($vars) {
    GatewayPage::handleSave();
});

add_hook('AdminAreaFooterOutput', 1, function ($vars) {
    try {
        return GatewayPage::footer();
    } catch (\Throwable $e) {
        return '';
    }
});

/**
 * 7) Fee and total to pay at checkout and on the invoice gateway selector.
 */
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    try {
        return Frontend::footer($vars);
    } catch (\Throwable $e) {
        return '';
    }
});
