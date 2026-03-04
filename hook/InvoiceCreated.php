<?php
/**
 * Hook Name: Invoice Created SMS
 * Version: 1.0.2
 * Tags: {name}, {invoice_id}, {amount}, {due_date}
 */

if (!defined("WHMCS")) die("Access denied");
use WHMCS\Database\Capsule;

add_hook('InvoiceCreated', 1, function($vars) {
    $invoiceId = $vars['invoiceid'];
    $hookFile = basename(__FILE__);
    
    $config = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hookFile)->first();
    $template = ($config && !empty($config->message)) ? $config->message : 
                "Hi {name}, a new invoice #{invoice_id} has been generated. Amount: {amount}. Due Date: {due_date}. Please pay to avoid suspension.";

    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    
    if (!$client || empty($client->phonenumber)) return;

    $tags = [
        '{name}' => $client->firstname,
        '{invoice_id}' => $invoiceId,
        '{amount}' => $invoice->total . ' ' . $invoice->currency,
        '{due_date}' => date('d-M-Y', strtotime($invoice->duedate))
    ];
    $message = strtr($template, $tags);

    if (function_exists('wpsend_send_sms_core')) {
        wpsend_send_sms_core($client->phonenumber, $message);
    }
});
