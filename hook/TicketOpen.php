<?php
/**
 * Hook Name: Ticket Opened SMS
 * Version: 1.0.5
 * Tags: {name}, {ticket_id}, {subject}, {priority}
 */

if (!defined("WHMCS")) die("Access denied");
use WHMCS\Database\Capsule;

add_hook('TicketOpen', 1, function($vars) {
    $ticketId = $vars['ticketid'];
    $subject = $vars['subject'];
    $hookFile = basename(__FILE__);

    $config = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hookFile)->first();
    $template = ($config && !empty($config->message)) ? $config->message : 
                "Support: Hi {name}, your ticket #{ticket_id} has been opened. Topic: {subject}. We will reply soon.";

    $client = Capsule::table('tblclients')->where('id', $vars['userid'])->first();
    if (!$client) return;

    $tags = [
        '{name}' => $client->firstname,
        '{ticket_id}' => $vars['tid'],
        '{subject}' => $subject,
        '{priority}' => $vars['priority']
    ];
    $message = strtr($template, $tags);

    if (function_exists('wpsend_send_sms_core')) {
        wpsend_send_sms_core($client->phonenumber, $message);
    }
});
