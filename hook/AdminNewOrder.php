<?php
/**
 * Hook Name: Admin New Order Alert
 * Version: 1.0.0
 * Tags: {client_name}, {order_id}, {amount}
 */

if (!defined("WHMCS")) die("Access denied");
use WHMCS\Database\Capsule;

add_hook('AcceptOrder', 1, function($vars) {
    $orderId = $vars['orderid'];
    $hookFile = basename(__FILE__);

    // অ্যাডমিন নাম্বার আনা
    $admin_mobile = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'admin_mobile')->value('value');
    if (!$admin_mobile) return;

    $order = Capsule::table('tblorders')->where('id', $orderId)->first();
    $client = Capsule::table('tblclients')->where('id', $order->userid)->first();

    $message = "VIP Alert: New Order #{order_id} placed by {client_name}. Amount: {amount}. Check Admin Panel.";
    
    $tags = [
        '{client_name}' => $client->firstname . ' ' . $client->lastname,
        '{order_id}' => $orderId,
        '{amount}' => $order->amount
    ];
    $finalMsg = strtr($message, $tags);

    if (function_exists('wpsend_send_sms_core')) {
        wpsend_send_sms_core($admin_mobile, $finalMsg);
    }
});
