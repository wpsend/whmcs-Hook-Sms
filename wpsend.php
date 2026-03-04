<?php
if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

function wpsend_config() {
    return [
        'name' => 'WPSend VIP Hub',
        'description' => 'GitHub-Synced SMS Automation System with VIP UI',
        'version' => '1.5.6',
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

// GitHub API থেকে ফাইল লিস্ট আনার নিরাপদ ফাংশন
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
    return (is_array($data)) ? $data : []; // নিশ্চিত করা হচ্ছে এটি যেন সবসময় অ্যারে হয়
}

function wpsend_send_sms($to, $message) {
    $apiKey = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'api_key')->value('value');
    $account = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'account')->value('value');
    
    $url = "https://cp.wpsend.org/fi/send.php?secret=" . urlencode($apiKey) . "&account=" . urlencode($account) . "&to=" . urlencode($to) . "&message=" . urlencode($message);
    $response = @file_get_contents($url);
    
    Capsule::table('mod_wpsend_logs')->insert([
        'to_num' => $to,
        'msg' => $message,
        'status' => $response ? 'Success: '.$response : 'Failed'
    ]);
    return $response;
}

function wpsend_output($vars) {
    $hooksDir = __DIR__ . '/hooks/';
    if (!is_dir($hooksDir)) @mkdir($hooksDir, 0755);

    // ১. হুক ডাউনলোড লজিক
    if (isset($_GET['action']) && $_GET['action'] == 'download' && isset($_GET['file'])) {
        $file = basename($_GET['file']);
        $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/" . $file;
        $content = @file_get_contents($raw_url);
        if ($content) {
            file_put_contents($hooksDir . $file, $content);
            // মেসেজ ডুপ্লিকেট না হওয়ার জন্য চেক
            if (!Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->exists()) {
                Capsule::table('mod_wpsend_hooks')->insert(['hook_file' => $file, 'message' => 'Default message for ' . $file]);
            }
            echo "<div class='alert alert-success'>$file successfully synced!</div>";
        }
    }

    // ২. মেসেজ আপডেট লজিক
    if (isset($_POST['save_all'])) {
        foreach ($_POST['msg'] as $file => $text) {
            $admin_val = isset($_POST['admin_notify'][$file]) ? 1 : 0;
            Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update([
                'message' => $text,
                'admin_notify' => $admin_val
            ]);
        }
        echo "<div class='alert alert-success'>All templates updated!</div>";
    }

    $remoteFiles = wpsend_get_remote_hooks();
    $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->limit(5)->get();
    ?>

    <style>
        .vip-card { background: #fff; border-radius: 10px; padding: 20px; border: 1px solid #ddd; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .hook-row { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #eee; padding: 10px 0; }
        .btn-update { padding: 4px 10px; font-size: 12px; border-radius: 4px; background: #28a745; color: #fff; text-decoration: none; }
    </style>

    <div class="row">
        <div class="col-md-8">
            <div class="vip-card">
                <h3>💎 VIP Hook Hub</h3>
                <form method="post">
                <?php 
                foreach ($remoteFiles as $rFile) {
                    if (!isset($rFile['name']) || $rFile['type'] !== 'file') continue;
                    $name = $rFile['name'];
                    $isInstalled = file_exists($hooksDir . $name);
                    $dbData = Capsule::table('mod_wpsend_hooks')->where('hook_file', $name)->first();
                    ?>
                    <div class="hook-row">
                        <span><strong><i class="fa fa-file-code-o"></i> <?=$name?></strong></span>
                        <a href="?module=wpsend&action=download&file=<?=$name?>" class="btn-update">
                            <?=$isInstalled ? 'Update from GitHub' : 'Download Hook'?>
                        </a>
                    </div>
                    <?php if ($isInstalled && $dbData): ?>
                        <textarea name="msg[<?=$name?>]" class="form-control mt-2" rows="2"><?=$dbData->message?></textarea>
                        <label><input type="checkbox" name="admin_notify[<?=$name?>]" <?=$dbData->admin_notify ? 'checked' : ''?>> Notify Admin</label>
                    <?php endif; ?>
                <?php } ?>
                <hr>
                <button type="submit" name="save_all" class="btn btn-primary btn-block">Save All Templates</button>
                </form>
            </div>
        </div>

        <div class="col-md-4">
            <div class="vip-card">
                <h4>📊 Recent Logs</h4>
                <table class="table table-sm" style="font-size: 11px;">
                    <?php foreach($logs as $log): ?>
                        <tr><td><?=$log->to_num?></td><td><?=$log->status?></td></tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <div class="vip-card bg-dark text-white text-center">
                <h5>Server Version</h5>
                <h3 style="color: #ffc107;">1.5.5</h3>
                <small>Connected to WPSend.org</small>
            </div>
        </div>
    </div>
    <?php
}

// অটো লোডার
foreach (glob(__DIR__ . "/hooks/*.php") as $filename) {
    include_once $filename;
}
