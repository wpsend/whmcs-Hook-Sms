<?php

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

add_hook('ClientAreaPage', 1, function($vars) {

    // ইউজার লগইন করা না থাকলে রিটার্ন
    if (!isset($_SESSION['uid'])) {
        return;
    }

    $userId = $_SESSION['uid'];
    $ip = $_SERVER['REMOTE_ADDR'];

    // একই সেশনে বারবার SMS যাওয়া রোধ করা
    if (isset($_SESSION["wpsend_login_sent"]) && $_SESSION["wpsend_login_sent"] == $ip) {
        return;
    }
    $_SESSION["wpsend_login_sent"] = $ip;

    // ১. ডাটাবেস থেকে এই ফাইলের জন্য সেট করা মেসেজ এবং কনফিগ আনা
    $hookFile = basename(__FILE__);
    $config = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hookFile)->first();
    
    // যদি ডাটাবেসে মেসেজ না থাকে, তবে একটি ডিফল্ট মেসেজ সেট করা
    $template = ($config && !empty($config->message)) ? $config->message : 
                "Security Alert: Hi {name}, a new login detected from {device}. IP: {ip}. Location: {location}.";

    // ২. ক্লায়েন্ট ডিটেইলস
    $client = \WHMCS\User\Client::find($userId);
    if (!$client || empty($client->phonenumber)) return;

    $name  = $client->firstname;
    $email = $client->email;
    $number = preg_replace('/[^0-9]/', '', $client->phonenumber);

    // ৩. ডিভাইস ডিটেকশন
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $device = 'Device';
    if (preg_match('/Mobile|Android|iPhone/i', $ua)) $device = 'Mobile';
    elseif (preg_match('/Windows/i', $ua))           $device = 'Windows PC';
    elseif (preg_match('/Macintosh|Mac OS/i', $ua))  $device = 'Mac';

    // ৪. লোকেশন ডিটেইলস (IP-API)
    $location = "Unknown";
    try {
        $locData = json_decode(@file_get_contents("http://ip-api.com/json/{$ip}?fields=status,country,city"));
        if ($locData && $locData->status === "success") {
            $location = "{$locData->city}, {$locData->country}";
        }
    } catch (\Exception $e) { $location = "Unknown"; }

    // ৫. মেসেজ ভেরিয়েবল রিপ্লেস করা
    $finalMessage = str_replace(
        ['{name}', '{email}', '{device}', '{ip}', '{location}', '{time}'], 
        [$name, $email, $device, $ip, $location, date('Y-m-d H:i:s')], 
        $template
    );

    // ৬. SMS পাঠানো এবং লগ ইনসার্ট করা (মেইন ফাইলে থাকা ফাংশন ব্যবহার করে)
    if (function_exists('wpsend_send_sms')) {
        wpsend_send_sms($number, $finalMessage);

        // অ্যাডমিন নোটিফিকেশন চেক
        if ($config && $config->admin_notify) {
            $admin_mobile = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'admin_mobile')->value('value');
            if ($admin_mobile) {
                $admin_msg = "Admin Alert: Client {$name} (ID: {$userId}) just logged in from {$ip}.";
                wpsend_send_sms($admin_mobile, $admin_msg);
            }
        }
    }
});
