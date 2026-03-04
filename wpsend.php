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
            'version' => '1.7.6',
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text'],
            ]
        ];
    }

    function wpsend_activate() {
        // টেবিল এবং কলাম অটোমেটিক তৈরি ও চেক করার লজিক
        if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
            Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
                $table->string('hook_file')->unique();
                $table->text('message');
                $table->string('local_version')->default('0.0.0');
                $table->boolean('admin_notify')->default(0);
            });
        } else {
            // যদি টেবিল থাকে কিন্তু কলাম না থাকে তবে তা এড করা
            if (!Capsule::schema()->hasColumn('mod_wpsend_hooks', 'local_version')) {
                Capsule::schema()->table('mod_wpsend_hooks', function ($table) {
                    $table->string('local_version')->default('0.0.0');
                });
            }
        }

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

    // --- GitHub Hook Fetcher ---
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
        // পেজ লোড হওয়ার সময় কলাম মিসিং থাকলে ফিক্স করা (এরর হ্যান্ডলিং)
        wpsend_activate();

        $currentVersion = '1.7.6';
        $newCoreUpdate = wpsend_core_update_check($currentVersion);
        $hooksDir = __DIR__ . '/hooks/';

        // ১. মেইন ফাইল অটো-আপডেট
        if (isset($_GET['action']) && $_GET['action'] == 'update_core') {
            $update_url = "https://wpsend.org/api/wpsend_latest.php?t=" . time();
            $new_code = @file_get_contents($update_url);
            if ($new_code) {
                file_put_contents(__FILE__, $new_code);
                echo "<div class='alert alert-success'>✅ Main wpsend.php updated successfully! Please refresh.</div>";
                return;
            }
        }

        // ২. হুক ডাউনলোড/আপডেট হ্যান্ডলার
        if (isset($_GET['action']) && $_GET['action'] == 'sync_hook') {
            $filename = basename($_GET['file']);
            $raw_url = $_GET['url'];
            $content = @file_get_contents($raw_url);
            if ($content) {
                if (!is_dir($hooksDir)) @mkdir($hooksDir, 0755);
                file_put_contents($hooksDir . $filename, $content);
                
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

        if ($newCoreUpdate) {
            echo "<div class='alert alert-info'>
                    <strong>New Core Update v$newCoreUpdate Available!</strong> 
                    <a href='?module=wpsend&action=update_core' class='btn btn-xs btn-primary'>Update wpsend.php Now</a>
                  </div>";
        }
        ?>

        <style>
            .vip-hub { background: #fff; padding: 20px; border-radius: 8px; border: 1px solid #ddd; }
            .hook-card { background: #fdfdfd; padding: 15px; border-radius: 6px; border: 1px solid #eee; margin-bottom: 15px; }
            .badge-v { background: #5a67d8; color: white; padding: 2px 6px; border-radius: 4px; font-size: 10px; }
        </style>

        <div class="vip-hub">
            <h3>💎 WPSend Master Dashboard</h3>
            <hr>
            <form method="post">
                <?php 
                $remoteHooks = wpsend_get_github_hooks();
                foreach ($remoteHooks as $h): 
                    $name = $h['name'];
                    $isLocal = file_exists($hooksDir . $name);
                    $db = Capsule::table('mod_wpsend_hooks')->where('hook_file', $name)->first();
                    $localV = $db ? $db->local_version : 'Not Sync';
                ?>
                <div class="hook-card">
                    <div style="display:flex; justify-content:space-between;">
                        <strong><?=$name?> <span class="badge-v">v<?=$localV?></span></strong>
                        <a href="?module=wpsend&action=sync_hook&file=<?=$name?>&url=<?=urlencode($h['download_url'])?>" class="btn btn-sm btn-default">Sync From GitHub</a>
                    </div>
                    <?php if($isLocal && $db): ?>
                        <textarea name="msg[<?=$name?>]" class="form-control mt-2" rows="2" style="margin-top:10px;"><?=$db->message?></textarea>
                        <label><input type="checkbox" name="admin_notify[<?=$name?>]" <?=$db->admin_notify ? 'checked' : ''?>> Admin Notify</label>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <button type="submit" name="save_templates" class="btn btn-success btn-block">Save All Settings</button>
            </form>
        </div>
        <?php
    }
}

// ৪. অটো-লোডার
$hookFiles = glob(__DIR__ . '/hooks/*.php');
foreach ($hookFiles as $file) {
    if (basename($file) !== 'wpsend.php') {
        include_once $file;
    }
}
