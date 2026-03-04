<?php
/**
 * WPSend VIP Master Controller
 * GitHub: https://github.com/wpsend/whmcs-Hook-Sms
 * Core Update: wpsend.org
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'Automated Hook Sync & Core Update System',
            'version' => '1.7.5', // আপনার কারেন্ট ভার্সন
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text'],
            ]
        ];
    }

    function wpsend_activate() {
        // টেমপ্লেট ও ভার্সন টেবিল
        if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
            Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
                $table->string('hook_file')->unique();
                $table->text('message');
                $table->string('local_version')->default('0.0.0');
                $table->boolean('admin_notify')->default(0);
            });
        }
        // লগ টেবিল
        if (!Capsule::schema()->hasTable('mod_wpsend_logs')) {
            Capsule::schema()->create('mod_wpsend_logs', function ($table) {
                $table->increments('id');
                $table->string('to_num');
                $table->text('msg');
                $table->string('status');
                $table->timestamp('created_at')->useCurrent();
            });
        }
    }

    // --- Core Update Check (wpsend.org) ---
    function wpsend_core_update_check($current) {
        $url = "https://wpsend.org/api/version_check.php?t=" . time();
        $remote_v = @file_get_contents($url);
        if ($remote_v && version_compare(trim($remote_v), $current, '>')) return trim($remote_v);
        return false;
    }

    // --- GitHub Hook Fetcher (With Version Logic) ---
    function wpsend_get_github_hooks() {
        $api_url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WPSend-VIP');
        $response = curl_exec($ch);
        curl_close($ch);
        $files = json_decode($response, true);
        
        $hook_data = [];
        if (is_array($files)) {
            foreach ($files as $file) {
                if ($file['type'] == 'file' && $file['name'] != 'wpsend.php') {
                    // প্রতিটি ফাইলের কন্টেন্ট থেকে ভার্সন খুঁজে বের করা (Optionally)
                    // অথবা আমরা ধরে নিচ্ছি GitHub-এ সবসময় লেটেস্ট আছে
                    $hook_data[] = [
                        'name' => $file['name'],
                        'download_url' => $file['download_url']
                    ];
                }
            }
        }
        return $hook_data;
    }

    function wpsend_output($vars) {
        $currentVersion = '1.7.5';
        $newCoreUpdate = wpsend_core_update_check($currentVersion);
        $hooksDir = __DIR__ . '/hooks/';

        // ১. মেইন ফাইল অটো-আপডেট (wpsend.org থেকে)
        if (isset($_GET['action']) && $_GET['action'] == 'update_core') {
            $update_url = "https://wpsend.org/api/wpsend_latest.php?t=" . time();
            $new_code = @file_get_contents($update_url);
            if ($new_code) {
                file_put_contents(__FILE__, $new_code);
                echo "<div class='alert alert-success'>✅ Main wpsend.php updated successfully!</div>";
                return;
            }
        }

        // ২. হুক ডাউনলোড/আপডেট হ্যান্ডলার
        if (isset($_GET['action']) && $_GET['action'] == 'sync_hook') {
            $filename = basename($_GET['file']);
            $raw_url = $_GET['url'];
            $content = @file_get_contents($raw_url);
            if ($content) {
                if (!is_dir($hooksDir)) mkdir($hooksDir, 0755);
                file_put_contents($hooksDir . $filename, $content);
                
                // গিটহাব থেকে ভার্সন রিড করার চেষ্টা (যদি কমেন্টে থাকে: Version: 1.2.0)
                preg_match('/Version:\s*([0-9\.]+)/i', $content, $matches);
                $remote_v = $matches[1] ?? '1.0.0';

                Capsule::table('mod_wpsend_hooks')->updateOrInsert(
                    ['hook_file' => $filename],
                    ['local_version' => $remote_v]
                );
                echo "<div class='alert alert-success'>✅ $filename synced (v$remote_v)!</div>";
            }
        }

        // ৩. মেসেজ সেভ
        if (isset($_POST['save_templates'])) {
            foreach ($_POST['msg'] as $file => $text) {
                $admin = isset($_POST['admin_notify'][$file]) ? 1 : 0;
                Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update(['message' => $text, 'admin_notify' => $admin]);
            }
            echo "<div class='alert alert-success'>Settings saved.</div>";
        }

        // ৪. UI রেন্ডারিং
        if ($newCoreUpdate) {
            echo "<div class='alert alert-info' style='border-left:5px solid #007bff;'>
                    <strong>New Version v$newCoreUpdate Available!</strong> 
                    <a href='?module=wpsend&action=update_core' class='btn btn-xs btn-primary'>Auto Update Main File</a>
                  </div>";
        }
        ?>

        <style>
            .vip-box { background: #fff; border-radius: 10px; border: 1px solid #dee2e6; padding: 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
            .hook-card { background: #f8f9fa; border-radius: 8px; padding: 15px; margin-bottom: 15px; border-left: 4px solid #6c757d; }
            .v-badge { font-size: 10px; padding: 2px 5px; border-radius: 4px; background: #e9ecef; color: #495057; }
            .tag-hint { font-size: 11px; color: #28a745; margin-top: 5px; font-weight: 600; }
        </style>

        <div class="vip-box">
            <h2 style="margin-top:0;">💎 WPSend VIP Hub (v<?=$currentVersion?>)</h2>
            <hr>
            <form method="post">
                <div class="row">
                    <div class="col-md-12">
                        <h4>📂 GitHub Hooks Repository</h4>
                        <?php 
                        $remoteHooks = wpsend_get_github_hooks();
                        foreach ($remoteHooks as $h): 
                            $name = $h['name'];
                            $isLocal = file_exists($hooksDir . $name);
                            $db = Capsule::table('mod_wpsend_hooks')->where('hook_file', $name)->first();
                            $localV = $db ? $db->local_version : 'Not Installed';
                            
                            // হুক অনুযায়ী শর্টকোড হিন্ট সেট করা
                            $tags = "{name}, {email}, {id}";
                            if(strpos($name, 'Invoice') !== false) $tags .= ", {total}, {due_date}";
                            if(strpos($name, 'Login') !== false) $tags .= ", {ip}, {location}, {device}";
                        ?>
                        <div class="hook-card">
                            <div style="display:flex; justify-content:space-between; align-items:center;">
                                <span>
                                    <strong><i class="fa fa-plug"></i> <?=$name?></strong> 
                                    <span class="v-badge">Local: <?=$localV?></span>
                                </span>
                                <a href="?module=wpsend&action=sync_hook&file=<?=$name?>&url=<?=urlencode($h['download_url'])?>" class="btn btn-sm btn-dark">
                                    <i class="fa fa-sync"></i> <?=$isLocal ? 'Update Hook' : 'Install Hook'?>
                                </a>
                            </div>
                            <?php if ($isLocal && $db): ?>
                                <textarea name="msg[<?=$name?>]" class="form-control mt-2" rows="2" style="margin-top:10px;"><?=$db->message?></textarea>
                                <div class="tag-hint"><i class="fa fa-tags"></i> Available Tags: <?=$tags?></div>
                                <label style="margin-top:5px; font-weight:normal; cursor:pointer;"><input type="checkbox" name="admin_notify[<?=$name?>]" <?=$db->admin_notify ? 'checked' : ''?>> Notify Admin</label>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" name="save_templates" class="btn btn-success btn-lg btn-block" style="margin-top:15px;">💾 Save All VIP Settings</button>
                    </div>
                </div>
            </form>
        </div>
        <?php
    }
}

// ৫. অটো-লোডার (hooks/ ফোল্ডার থেকে সব ফাইল লোড করবে)
$hookFiles = glob(__DIR__ . '/hooks/*.php');
foreach ($hookFiles as $file) {
    if (basename($file) !== 'wpsend.php') {
        include_once $file;
    }
}
