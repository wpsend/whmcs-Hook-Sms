<?php
/**
 * Hook Name: Client Login SMS Alert
 * Description: Sends an SMS to client and admin upon successful login.
 * Version: 1.1.1
 * Tags: {name}, {email}, {device}, {ip}, {location}, {time}
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

add_hook('ClientAreaPage', 1, function($vars) {

    // ১. ইউজার লগইন করা না থাকলে বা অলরেডি এই সেশনে মেসেজ গিয়ে থাকলে রিটার্ন
    if (!isset($_SESSION['uid'])) {
        return;
    }

    $userId = $_SESSION['uid'];
    $ip = $_SERVER['REMOTE_ADDR'];

    // একই সেশনে বারবার SMS যাওয়া রোধ করা (Session Based Lock)
    if (isset($_SESSION["wpsend_login_sent"]) && $_SESSION["wpsend_login_sent"] == $ip) {
        return;
    }
    $_SESSION["wpsend_login_sent"] = $ip;

    // ২. ডাটাবেস থেকে কনফিগ এবং টেমপ্লেট আনা
    $hookFile = basename(__FILE__);
    $config = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hookFile)->first();
    
    // ডিফল্ট মেসেজ যদি ডাটাবেসে কিছু না থাকে
    $template = ($config && !empty($config->message)) ? $config->message : 
                "Security Alert: Hi {name}, a new login detected from {device}. IP: {ip}. Location: {location}. Time: {time}";

    // ৩. ক্লায়েন্ট ইনফরমেশন সংগ্রহ
    try {
        $client = Capsule::table('tblclients')->where('id', $userId)->first();
        if (!$client || empty($client->phonenumber)) return;

        $name   = $client->firstname;
        $email  = $client->email;
        $number = preg_replace('/[^0-9]/', '', $client->phonenumber);
    } catch (\Exception $e) { return; }

    // ৪. ডিভাইস ডিটেকশন (Simplified Logic)
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if (preg_match('/Mobile|Android|iPhone/i', $ua)) $device = 'Mobile';
    elseif (preg_match('/Windows/i', $ua))           $device = 'Windows PC';
    elseif (preg_match('/Macintosh|Mac OS/i', $ua))  $device = 'Mac';
    else                                             $device = 'Web Browser';

    // ৫. লোকেশন ডিটেইলস (IP-API ব্যবহার করে)
    $location = "Unknown";
    $apiUrl = "http://ip-api.com/json/{$ip}?fields=status,country,city";
    $locResponse = @file_get_contents($apiUrl);
    if ($locResponse) {
        $locData = json_decode($locResponse);
        if ($locData && $locData->status === "success") {
            $location = "{$locData->city}, {$locData->country}";
        }
    }

    // ৬. মেসেজ ভেরিয়েবল রিপ্লেস করা
    $tags = [
        '{name}'     => $name,
        '{email}'    => $email,
        '{device}'   => $device,
        '{ip}'       => $ip,
        '{location}' => $location,
        '{time}'     => date('d-M-Y H:i A')
    ];
    $finalMessage = strtr($template, $tags);

    // ৭. SMS পাঠানো (মেইন wpsend.php এর ফাংশন ব্যবহার করে)
    // নোট: wpsend.php তে ফাংশনটির নাম wpsend_send_sms_core অথবা wpsend_send_sms হতে পারে
    $sendFunctionName = 'wpsend_send_sms_core'; // আপনার মেইন ফাইলের ফাংশন নাম অনুযায়ী চেক করুন

    if (function_exists($sendFunctionName)) {
        
        // ক্লায়েন্টকে SMS পাঠানো
        $sendFunctionName($number, $finalMessage);

        // অ্যাডমিন নোটিফিকেশন চেক
        if ($config && $config->admin_notify) {
            $admin_mobile = Capsule::table('tbladdonmodules')
                ->where('module', 'wpsend')
                ->where('setting', 'admin_mobile')
                ->value('value');
            
            if ($admin_mobile) {
                $admin_msg = "Admin Alert: Client {$name} (ID: {$userId}) just logged in from {$ip} ({$location}).";
                $sendFunctionName($admin_mobile, $admin_msg);
            }
        }
    }
});
