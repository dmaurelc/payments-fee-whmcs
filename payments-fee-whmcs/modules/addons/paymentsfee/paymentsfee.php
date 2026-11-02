<?php
/**
 * Payments Fee WHMCS
 *
 * Adds a fee (fixed amount, percentage or both) to invoices based on the
 * payment gateway. Compatible with WHMCS 8.x and 9.x (immutable invoices).
 *
 * @author  dmaurel.cl
 * @license MIT
 * @link    https://github.com/dmaurelc/payments-fee-whmcs
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/bootstrap.php';

use PaymentsFee\Admin;
use PaymentsFee\Lang;
use PaymentsFee\Repository;

function paymentsfee_config()
{
    return array(
        'name' => 'Payments Fee',
        'description' => Lang::t('module_description'),
        'author' => '<a href="https://dmaurel.cl" target="_blank">dmaurel.cl</a>',
        'language' => 'english',
        'version' => PAYMENTSFEE_VERSION,
        'fields' => array(
            'fee_label' => array(
                'FriendlyName' => Lang::t('cfg_fee_label'),
                'Type' => 'text',
                'Size' => '60',
                'Default' => '',
                'Description' => Lang::t('cfg_fee_label_desc'),
            ),
            'show_detail' => array(
                'FriendlyName' => Lang::t('cfg_show_detail'),
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => Lang::t('cfg_show_detail_desc'),
            ),
            'base_mode' => array(
                'FriendlyName' => Lang::t('cfg_base_mode'),
                'Type' => 'dropdown',
                'Options' => array(
                    'total' => Lang::t('cfg_base_total'),
                    'subtotal' => Lang::t('cfg_base_subtotal'),
                    'balance' => Lang::t('cfg_base_balance'),
                ),
                'Default' => 'total',
            ),
            'skip_addfunds' => array(
                'FriendlyName' => Lang::t('cfg_skip_addfunds'),
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => Lang::t('cfg_skip_addfunds_desc'),
            ),
            'skip_masspay' => array(
                'FriendlyName' => Lang::t('cfg_skip_masspay'),
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => Lang::t('cfg_skip_masspay_desc'),
            ),
            'exempt_clients' => array(
                'FriendlyName' => Lang::t('cfg_exempt_clients'),
                'Type' => 'text',
                'Size' => '40',
                'Description' => Lang::t('cfg_exempt_clients_desc'),
            ),
            'published_mode' => array(
                'FriendlyName' => Lang::t('cfg_published_mode'),
                'Type' => 'dropdown',
                'Options' => array(
                    'auto' => Lang::t('cfg_published_auto'),
                    'recalculate' => Lang::t('cfg_published_recalculate'),
                    'keep' => Lang::t('cfg_published_keep'),
                ),
                'Default' => 'auto',
                'Description' => Lang::t('cfg_published_mode_desc'),
            ),
            'show_in_checkout' => array(
                'FriendlyName' => Lang::t('cfg_show_in_checkout'),
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => Lang::t('cfg_show_in_checkout_desc'),
            ),
        ),
    );
}

function paymentsfee_activate()
{
    try {
        Repository::createSchema();
        Repository::migrateLegacy();
        return array('status' => 'success', 'description' => Lang::t('activated'));
    } catch (\Throwable $e) {
        return array('status' => 'error', 'description' => Lang::t('activate_error', array('error' => $e->getMessage())));
    }
}

function paymentsfee_deactivate()
{
    // Tables are kept so rules and the log are not lost.
    return array('status' => 'success', 'description' => Lang::t('deactivated'));
}

function paymentsfee_upgrade($vars)
{
    Repository::createSchema();
}

function paymentsfee_output($vars)
{
    try {
        Repository::createSchema();
        Admin::render($vars);
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">' . htmlspecialchars(Lang::t('error', array('error' => $e->getMessage())), ENT_QUOTES, 'UTF-8') . '</div>';
    }
}
