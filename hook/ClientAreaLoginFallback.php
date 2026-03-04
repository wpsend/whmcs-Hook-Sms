<?php
/**
 * Hook Name: Client Login SMS Alert
 * Description: Sends an SMS to client and admin upon successful login detection.
 * Version: 1.1.2
 * Default: Security Alert: Hi {name}, a new login detected from {device}. IP: {ip}. Location: {location}. Time: {time}
 * Tags: {name}, {email}, {device}, {ip}, {location}, {time}
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

add_hook('ClientAreaPage', 1, function($vars) {

    // ১. ইউজার লগইন করা না থাকলে বা অলরেডি এই সেশনে মেসেজ গিয়ে থাকলে রিটার্ন
    if (!isset($_SESSION['uid']) || empty($_SESSION['uid'])) {
        return;
    }

    $userId = $_SESSION['uid'];
    $ip = $_SERVER['REMOTE_ADDR'];

    // একই সেশনে বারবার SMS যাওয়া রোধ করা (Session Based Lock)
    // আমরা IP এবং UID মিলিয়ে লক করছি যাতে ভুল করে অন্য ইউজারের সেশনে না যায়
    $lockKey = "wpsend_login_sent_" . $userId;
    if (isset($_SESSION[$lockKey]) && $_SESSION[$lockKey] == $ip) {
        return;
    }

    // ২. ডাটাবেস থেকে কনফিগ এবং টেমপ্লেট আনা
    $hookFile = basename(__FILE__);
    $config = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hookFile)->first();
    
    // টেমপ্লেট না থাকলে মেটা ডাটা থেকে ডিফল্টটি নেওয়া
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

    // ৪. ডিভাইস ডিটেকশন
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    if (preg_match('/(Mobile|Android|iPhone|iPad)/i', $ua)) $device = 'Mobile Device';
    elseif (preg_match('/(Windows|Win64)/i', $ua))           $device = 'Windows PC';
    elseif (preg_match('/(Macintosh|Mac OS)/i', $ua))       $device = 'Mac/Apple';
    else                                                     $device = 'Web Browser';

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

    // ৭. সেশন লক সেট করা (মেসেজ প্রসেস হওয়ার ঠিক আগে)
    $_SESSION[$lockKey] = $ip;

    // ৮. SMS পাঠানোর ফাংশন (WPSend API Call)
    // আমরা মেইন wpsend.php এর ডাটাবেস থেকে API Key নিচ্ছি
    $apiKey = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'api_key')->value('value');
    
    if ($apiKey) {
        // SMS পাঠানোর ফাংশন কল
        wpsend_login_sms_sender($number, $finalMessage, $apiKey);

        // অ্যাডমিন নোটিফিকেশন চেক (যদি হুক সেটিংসে অন থাকে)
        if ($config && $config->admin_notify) {
            $admin_mobile = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'admin_num')->value('value');
            
            if ($admin_mobile) {
                $admin_msg = "WPSend Alert: Client {$name} (ID: {$userId}) logged in from {$ip} ({$location}).";
                wpsend_login_sms_sender($admin_mobile, $admin_msg, $apiKey);
            }
        }
    }
});

/**
 * এই হুকের জন্য ইন্টারনাল এসএমএস সেন্ডার ফাংশন
 * এটি মেইন মডিউলের API এর সাথে কানেক্ট করবে
 */
function wpsend_login_sms_sender($number, $message, $apiKey) {
    $apiUrl = "https://my.wpsend.org/api/sms/send"; // আপনার আসল API URL এখানে দিন
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'api_key' => $apiKey,
        'to'      => $number,
        'msg'     => $message
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    curl_close($ch);

    // লগ সেভ করা (যদি লগ টেবিল থাকে)
    try {
        Capsule::table('mod_wpsend_logs')->insert([
            'receiver' => $number,
            'msg'      => $message,
            'status'   => 'Sent',
            'date'     => date('Y-m-d H:i:s')
        ]);
    } catch (\Exception $e) { /* লগ টেবিল না থাকলে ইগনোর করবে */ }

    return $response;
}
