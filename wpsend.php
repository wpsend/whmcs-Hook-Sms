<?php
/**
 * WPSend VIP SMS Hub - All-in-One Controller
 * Core Update: wpsend.org (Updates main wpsend.php)
 * Hooks Sync: GitHub (Filters out main file)
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'GitHub Hooks & wpsend.org Self-Update System',
            'version' => '1.7.1', 
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text'],
            ]
        ];
    }

    function wpsend_activate() {
        if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
            Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
                $table->string('hook_file')->unique();
                $table->text('message');
                $table->boolean('admin_notify')->default(0);
            });
        }
    }

    // --- Core Update Check (wpsend.org) ---
    function wpsend_core_update_check($current) {
        $url = "https://wpsend.org/api/version_check.php?cache=" . time();
        $remote_v = @file_get_contents($url);
        if ($remote_v && version_compare(trim($remote_v), $current, '>')) {
            return trim($remote_v);
        }
        return false;
    }

    // --- GitHub Hook List ---
    function wpsend_get_github_hooks() {
        $api_url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WPSend-VIP-Hub');
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        return (is_array($data)) ? $data : [];
    }

    function wpsend_output($vars) {
        $currentVersion = '1.7.0';
        $newUpdate = wpsend_core_update_check($currentVersion);
        $hooksDir = __DIR__ . '/hooks/';
        $mainFile = __FILE__;

        // ১. কোর মডিউল আপডেট লজিক (wpsend.org থেকে মেইন ফাইল আপডেট হবে)
        if (isset($_GET['action']) && $_GET['action'] == 'update_core') {
            $raw_core_url = "https://wpsend.org/api/wpsend_latest.php?t=" . time();
            $new_code = @file_get_contents($raw_core_url);
            if ($new_code) {
                file_put_contents($mainFile, $new_code);
                echo "<div class='alert alert-success'>✅ Core Module updated to latest version! Please refresh.</div>";
                return;
            }
        }

        // ২. হুক ডাউনলোড হ্যান্ডলার (Filters out wpsend.php)
        if (isset($_GET['action']) && $_GET['action'] == 'sync' && isset($_GET['file'])) {
            $file = basename($_GET['file']);
            // সিকিউরিটি চেক: হুক ফোল্ডারে মেইন ফাইল ডাউনলোড হতে দিবে না
            if ($file == 'wpsend.php') {
                echo "<div class='alert alert-danger'>❌ You cannot download main controller into hooks folder!</div>";
            } else {
                $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/" . $file . "?t=" . time();
                $content = @file_get_contents($raw_url);
                if ($content) {
                    if (!is_dir($hooksDir)) @mkdir($hooksDir, 0755);
                    file_put_contents($hooksDir . $file, $content);
                    Capsule::table('mod_wpsend_hooks')->updateOrInsert(['hook_file' => $file], ['message' => 'Default message for ' . $file]);
                    echo "<div class='alert alert-success'>✅ $file successfully synced!</div>";
                }
            }
        }

        // ৩. UI রেন্ডারিং
        if ($newUpdate) {
            echo "<div class='alert alert-warning' style='background:#fff3cd; border-left:5px solid #ffc107;'>
                    <strong>🚀 New Core Version v$newUpdate Available!</strong><br>
                    <a href='?module=wpsend&action=update_core' class='btn btn-warning btn-sm' style='margin-top:10px;'>Update Core Now (Auto)</a>
                  </div>";
        }
        ?>

        <style>
            .vip-container { background: #fff; border: 1px solid #e0e6ed; border-radius: 10px; padding: 20px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
            .hook-row { border-bottom: 1px solid #f1f4f8; padding: 12px 0; display: flex; justify-content: space-between; align-items: center; }
            .btn-vip { border-radius: 6px; font-weight: 600; padding: 5px 15px; font-size: 12px; }
        </style>

        <div class="vip-container">
            <h3>💎 VIP Admin Hub (v<?=$currentVersion?>)</h3>
            <hr>
            <form method="post">
                <div class="row">
                    <div class="col-md-12">
                        <h4>📂 Remote Hooks (GitHub)</h4>
                        <?php 
                        $remoteHooks = wpsend_get_github_hooks();
                        foreach ($remoteHooks as $h): 
                            if($h['type'] != 'file' || $h['name'] == 'wpsend.php') continue; // ফিল্টার
                            $name = $h['name'];
                            $isLocal = file_exists($hooksDir . $name);
                            $dbData = Capsule::table('mod_wpsend_hooks')->where('hook_file', $name)->first();
                        ?>
                        <div class="hook-row">
                            <span><i class="fa fa-code"></i> <?=$name?></span>
                            <a href="?module=wpsend&action=sync&file=<?=$name?>" class="btn btn-default btn-vip">
                                <?=$isLocal ? 'Update' : 'Download'?>
                            </a>
                        </div>
                        <?php if($isLocal && $dbData): ?>
                            <textarea name="msg[<?=$name?>]" class="form-control mt-1" rows="2" placeholder="মেসেজ লিখুন..."><?=$dbData->message?></textarea>
                            <label><input type="checkbox" name="admin_notify[<?=$name?>]" <?=$dbData->admin_notify ? 'checked' : ''?>> Notify Admin</label>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        <button type="submit" name="save_all" class="btn btn-primary btn-block mt-3">Save Templates</button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
}

// ৪. হুক অটো-লোডার
foreach (glob(__DIR__ . "/hooks/*.php") as $filename) {
    if (basename($filename) !== 'wpsend.php') {
        include_once $filename;
    }
}
