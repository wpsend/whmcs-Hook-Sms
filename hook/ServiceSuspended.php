<?php
/**
 * Hook Name: Service Suspended SMS
 * Version: 1.0.0
 * Tags: {name}, {product_name}, {domain}
 */

if (!defined("WHMCS")) die("Access denied");
use WHMCS\Database\Capsule;

add_hook('AfterModuleSuspend', 1, function($vars) {
    $params = $vars['params'];
    $hookFile = basename(__FILE__);

    $config = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hookFile)->first();
    $template = ($config && !empty($config->message)) ? $config->message : 
                "Alert: Hi {name}, your service {product_name} ({domain}) has been suspended due to overdue payment.";

    $tags = [
        '{name}' => $params['clientsdetails']['firstname'],
        '{product_name}' => $params['model']['product']['name'],
        '{domain}' => $params['domain']
    ];
    $message = strtr($template, $tags);

    if (function_exists('wpsend_send_sms_core')) {
        wpsend_send_sms_core($params['clientsdetails']['phonenumber'], $message);
    }
});
