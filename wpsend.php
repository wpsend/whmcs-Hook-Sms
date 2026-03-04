<?php
if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

// ১. ফাংশন ডিক্লেয়ার করার আগে চেক (এরর এড়াতে)
if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'GitHub-Synced SMS Automation System',
            'version' => '1.6.1', // আপনার বর্তমান ভার্সন
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text'],
                'admin_mobile' => ['FriendlyName' => 'Admin Mobile', 'Type' => 'text'],
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

    // --- Core Update Checker ---
    function wpsend_check_core_update($currentVersion) {
        // আপনার সার্ভার wpsend.org এ একটি version.txt বা JSON ফাইল রাখবেন যেখানে লেটেস্ট ভার্সন নাম্বার থাকবে
        $update_url = "https://wpsend.org/api/version_check.php"; 
        $remote_version = @file_get_contents($update_url); 
        
        if ($remote_version && version_compare($remote_version, $currentVersion, '>')) {
            return $remote_version;
        }
        return false;
    }

    function wpsend_get_remote_hooks() {
        $api_url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WPSend-WHMCS-Updater');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        return (is_array($data)) ? $data : [];
    }

    function wpsend_send_sms($to, $message) {
        $apiKey = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'api_key')->value('value');
        $account = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'account')->value('value');
        $url = "https://cp.wpsend.org/fi/send.php?secret=" . urlencode($apiKey) . "&account=" . urlencode($account) . "&to=" . urlencode($to) . "&message=" . urlencode($message);
        $response = @file_get_contents($url);
        Capsule::table('mod_wpsend_logs')->insert(['to_num' => $to, 'msg' => $message, 'status' => $response ? 'Success' : 'Failed']);
        return $response;
    }

    function wpsend_output($vars) {
        $currentVersion = '1.6.0';
        $newUpdate = wpsend_check_core_update($currentVersion);
        $hooksDir = __DIR__ . '/hooks/';

        // ১. কোর আপডেট নোটিশ
        if ($newUpdate) {
            echo "<div class='alert alert-warning' style='border-left: 5px solid #f39c12;'>
                    <strong>🚀 New Core Update Available!</strong><br>
                    Version $newUpdate is now available. <a href='https://wpsend.org' target='_blank' class='btn btn-xs btn-warning'>Update Now from wpsend.org</a>
                  </div>";
        }

        // ২. হুক ডাউনলোড হ্যান্ডলার
        if (isset($_GET['action']) && $_GET['action'] == 'download' && isset($_GET['file'])) {
            $file = basename($_GET['file']);
            $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/" . $file;
            $content = @file_get_contents($raw_url);
            if ($content) {
                if (!is_dir($hooksDir)) mkdir($hooksDir, 0755);
                file_put_contents($hooksDir . $file, $content);
                Capsule::table('mod_wpsend_hooks')->updateOrInsert(['hook_file' => $file], ['message' => 'New message for ' . $file]);
                echo "<div class='alert alert-success'>$file successfully synced!</div>";
            }
        }

        // ৩. টেমপ্লেট সেভ
        if (isset($_POST['save_all'])) {
            foreach ($_POST['msg'] as $file => $text) {
                $admin_val = isset($_POST['admin_notify'][$file]) ? 1 : 0;
                Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update(['message' => $text, 'admin_notify' => $admin_val]);
            }
            echo "<div class='alert alert-success'>Templates saved!</div>";
        }

        $remoteFiles = wpsend_get_remote_hooks();
        $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->limit(5)->get();
        ?>

        <style>
            .vip-card { background: #fff; border-radius: 12px; padding: 25px; border: 1px solid #e0e0e0; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
            .hook-title { font-weight: 600; color: #2c3e50; font-size: 15px; }
            .btn-vip { border-radius: 6px; font-weight: 600; padding: 6px 15px; transition: 0.3s; }
            .btn-sync { background: #6c5ce7; color: #fff; }
            .btn-sync:hover { background: #a29bfe; color: #fff; }
        </style>

        <div class="row">
            <div class="col-md-8">
                <div class="vip-card">
                    <h3 style="margin-top:0;">💎 WPSend VIP Hub</h3>
                    <form method="post">
                        <?php foreach ($remoteFiles as $rFile): 
                            if ($rFile['type'] !== 'file') continue;
                            $name = $rFile['name'];
                            $isInstalled = file_exists($hooksDir . $name);
                            $dbData = Capsule::table('mod_wpsend_hooks')->where('hook_file', $name)->first();
                        ?>
                        <div style="border-bottom: 1px solid #eee; padding: 15px 0;">
                            <div class="d-flex justify-content-between align-items-center" style="display:flex; justify-content:space-between;">
                                <span class="hook-title"><i class="fa fa-plug"></i> <?=$name?></span>
                                <a href="?module=wpsend&action=download&file=<?=$name?>" class="btn btn-vip btn-sync btn-sm">
                                    <?=$isInstalled ? 'Update' : 'Download'?>
                                </a>
                            </div>
                            <?php if ($isInstalled && $dbData): ?>
                                <textarea name="msg[<?=$name?>]" class="form-control mt-2" rows="2" style="margin-top:10px;"><?=$dbData->message?></textarea>
                                <label style="margin-top:5px; display:block;"><input type="checkbox" name="admin_notify[<?=$name?>]" <?=$dbData->admin_notify ? 'checked' : ''?>> Notify Admin</label>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <button type="submit" name="save_all" class="btn btn-primary btn-block" style="margin-top:20px;">Save All Templates</button>
                    </form>
                </div>
            </div>

            <div class="col-md-4">
                <div class="vip-card">
                    <h4 style="margin-top:0;">📊 Recent Logs</h4>
                    <table class="table table-condensed" style="font-size:12px;">
                        <?php foreach($logs as $log): ?>
                            <tr><td><?=$log->to_num?></td><td class="text-right"><span class="label label-info"><?=$log->status?></span></td></tr>
                        <?php endforeach; ?>
                    </table>
                </div>
                <div class="vip-card bg-primary text-white text-center" style="background:#2c3e50; color:#fff;">
                    <small>System Status</small>
                    <h2 style="margin:5px 0;">v<?=$currentVersion?></h2>
                    <p style="font-size:12px; opacity:0.8;">Fully VIP Connected</p>
                </div>
            </div>
        </div>
        <?php
    }
} // End function_exists check

// ৪. হুক লোড করার সময় মেইন ফাইল বাদ দেওয়া (খুবই গুরুত্বপূর্ণ)
foreach (glob(__DIR__ . "/hooks/*.php") as $filename) {
    if (basename($filename) !== 'wpsend.php') {
        include_once $filename;
    }
}
