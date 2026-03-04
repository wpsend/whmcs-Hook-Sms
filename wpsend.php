<?php
/**
 * WPSend VIP SMS Hub - WHMCS Addon
 * Developed by: WPSend.org
 * Version: 1.6.0
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

// একই ফাংশন দুইবার ডিক্লেয়ার হওয়া রোধ করতে চেক
if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'GitHub-Synced SMS Automation with Core Update System',
            'version' => '1.6.1',
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text', 'Size' => '50'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text', 'Size' => '50'],
                'admin_mobile' => ['FriendlyName' => 'Admin Mobile', 'Type' => 'text', 'Description' => 'অ্যাডমিন অ্যালার্ট পাওয়ার নাম্বার'],
            ]
        ];
    }

    function wpsend_activate() {
        // টেমপ্লেট স্টোরেজ টেবিল
        if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
            Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
                $table->string('hook_file')->unique();
                $table->text('message');
                $table->boolean('admin_notify')->default(0);
            });
        }
        // প্রফেশনাল লগ টেবিল
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

    // --- Core Update Checker (wpsend.org থেকে) ---
    function wpsend_check_core_update($current) {
        // আপনার সার্ভারে একটি ফাইল রাখবেন যা শুধু ভার্সন নাম্বার রিটার্ন করবে
        $url = "https://wpsend.org/api/version_check.php?v=" . time();
        $remote = @file_get_contents($url);
        if ($remote && version_compare(trim($remote), $current, '>')) {
            return trim($remote);
        }
        return false;
    }

    // --- GitHub Hook Fetcher ---
    function wpsend_get_remote_hooks() {
        $api_url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WPSend-WHMCS-Hub');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        return (is_array($data)) ? $data : [];
    }

    // --- VIP SMS Engine ---
    function wpsend_send_sms($to, $message) {
        $apiKey = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'api_key')->value('value');
        $account = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'account')->value('value');
        
        $url = "https://cp.wpsend.org/fi/send.php?secret=" . urlencode($apiKey) . "&account=" . urlencode($account) . "&to=" . urlencode($to) . "&message=" . urlencode($message);
        $response = @file_get_contents($url);
        
        Capsule::table('mod_wpsend_logs')->insert([
            'to_num' => $to,
            'msg' => $message,
            'status' => $response ? 'Sent' : 'Failed'
        ]);
        return $response;
    }

    // --- Admin Dashboard UI ---
    function wpsend_output($vars) {
        $currentVersion = '1.6.0';
        $newCore = wpsend_check_core_update($currentVersion);
        $hooksDir = __DIR__ . '/hooks/';

        // ১. কোর আপডেট অ্যালার্ট
        if ($newCore) {
            echo "<div class='alert alert-danger' style='border-left: 5px solid #d9534f; background: #fdf7f7;'>
                    <strong>🚀 New Update Available! (v$newCore)</strong><br>
                    আপনার WPSend মডিউলটির নতুন ভার্সন এসেছে। দয়া করে <a href='https://wpsend.org' target='_blank'>wpsend.org</a> থেকে লেটেস্ট ফাইলটি ডাউনলোড করুন।
                  </div>";
        }

        // ২. হুক ডাউনলোড/আপডেট লজিক
        if (isset($_GET['action']) && $_GET['action'] == 'sync' && isset($_GET['file'])) {
            $file = basename($_GET['file']);
            $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/" . $file . "?cache=" . time();
            $content = @file_get_contents($raw_url);
            if ($content) {
                if (!is_dir($hooksDir)) mkdir($hooksDir, 0755);
                file_put_contents($hooksDir . $file, $content);
                // ডিফল্ট মেসেজ ইনসার্ট (যদি না থাকে)
                if (!Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->exists()) {
                    Capsule::table('mod_wpsend_hooks')->insert(['hook_file' => $file, 'message' => 'Default SMS for ' . $file]);
                }
                echo "<div class='alert alert-success'>✅ $file successfully synced from GitHub!</div>";
            }
        }

        // ৩. মেসেজ সেভ লজিক
        if (isset($_POST['save_templates'])) {
            foreach ($_POST['msg'] as $file => $text) {
                $admin = isset($_POST['admin_notify'][$file]) ? 1 : 0;
                Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update([
                    'message' => $text, 
                    'admin_notify' => $admin
                ]);
            }
            echo "<div class='alert alert-info'>🎉 Templates updated successfully!</div>";
        }

        $remoteFiles = wpsend_get_remote_hooks();
        $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->limit(10)->get();
        ?>

        <style>
            .wpsend-wrap { background: #f9f9fb; padding: 20px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
            .card { background: #fff; border-radius: 8px; border: 1px solid #e1e4e8; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
            .hook-box { border-bottom: 1px solid #f0f0f0; padding: 15px 0; }
            .btn-sync { background: #5850ec; color: #fff; border: none; padding: 5px 12px; border-radius: 5px; font-size: 12px; }
            .btn-sync:hover { background: #4338ca; color: #fff; text-decoration: none; }
            .log-badge { font-size: 10px; padding: 3px 7px; border-radius: 10px; background: #e0e7ff; color: #4338ca; }
        </style>

        <div class="wpsend-wrap">
            <div class="row">
                <div class="col-md-8">
                    <div class="card">
                        <h3 style="margin-top:0;">💎 VIP SMS Template Manager</h3>
                        <p class="text-muted">GitHub থেকে হুক ডাউনলোড করুন এবং আপনার মেসেজ কাস্টমাইজ করুন।</p>
                        <hr>
                        <form method="post">
                            <?php foreach ($remoteFiles as $rFile): 
                                if ($rFile['type'] !== 'file') continue;
                                $name = $rFile['name'];
                                $isLocal = file_exists($hooksDir . $name);
                                $db = Capsule::table('mod_wpsend_hooks')->where('hook_file', $name)->first();
                            ?>
                            <div class="hook-box">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong><i class="fa fa-file-code-o"></i> <?=$name?></strong>
                                    <a href="?module=wpsend&action=sync&file=<?=$name?>" class="btn-sync">
                                        <i class="fa fa-refresh"></i> <?=$isLocal ? 'Update Hook' : 'Install Hook'?>
                                    </a>
                                </div>
                                <?php if ($isLocal && $db): ?>
                                    <textarea name="msg[<?=$name?>]" class="form-control" rows="2" style="margin-top:10px; border-radius:6px;"><?=$db->message?></textarea>
                                    <label style="margin-top:8px; font-weight:normal; cursor:pointer;">
                                        <input type="checkbox" name="admin_notify[<?=$name?>]" <?=$db->admin_notify ? 'checked' : ''?>> Notify Admin
                                    </label>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <button type="submit" name="save_templates" class="btn btn-primary btn-lg btn-block" style="margin-top:20px;">Save All Changes</button>
                        </form>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="card" style="background: #2d3748; color: #fff; text-align: center;">
                        <small style="opacity:0.7;">System Version</small>
                        <h1 style="margin: 10px 0; color: #fbbf24;">v<?=$currentVersion?></h1>
                        <p style="font-size:12px;">Connected to <strong>wpsend.org</strong></p>
                    </div>
                    
                    <div class="card">
                        <h4>📊 Recent Delivery Logs</h4>
                        <table class="table table-hover" style="font-size:12px;">
                            <?php foreach($logs as $log): ?>
                                <tr>
                                    <td><?=$log->to_num?></td>
                                    <td class="text-right"><span class="log-badge"><?=$log->status?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// ৪. হুক অটো-লোডার (মেইন ফাইল যেন লোড না হয় সেই সতর্কতা সহ)
foreach (glob(__DIR__ . "/hooks/*.php") as $filename) {
    if (basename($filename) !== 'wpsend.php') {
        include_once $filename;
    }
}
