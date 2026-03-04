<?php
/**
 * Hook Name: Invoice Paid SMS
 * Version: 1.0.1
 * Tags: {name}, {invoice_id}, {amount}, {txn_id}
 */

if (!defined("WHMCS")) die("Access denied");
use WHMCS\Database\Capsule;

add_hook('InvoicePaid', 1, function($vars) {
    $invoiceId = $vars['invoiceid'];
    $hookFile = basename(__FILE__);

    $config = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hookFile)->first();
    $template = ($config && !empty($config->message)) ? $config->message : 
                "Success! Hi {name}, we have received your payment for Invoice #{invoice_id}. Amount: {amount}. Thank you!";

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();

    $tags = [
        '{name}' => $client->firstname,
        '{invoice_id}' => $invoiceId,
        '{amount}' => $invoice->total,
        '{txn_id}' => $invoice->notes // Just an example
    ];
    $message = strtr($template, $tags);

    if (function_exists('wpsend_send_sms_core')) {
        wpsend_send_sms_core($client->phonenumber, $message);
    }
});
