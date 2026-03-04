<?php
if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

function wpsend_config() {
    return [
        'name' => 'WPSend VIP Hub',
        'description' => 'GitHub-Synced SMS Automation System',
        'version' => '1.5.0', // আপনার কারেন্ট ভার্সন
        'author' => 'WPSend.org',
        'fields' => [
            'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text'],
            'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text'],
        ]
    ];
}

function wpsend_activate() {
    // টেবিল ১: হুক ফাইল এবং মেসেজ স্টোরেজ
    if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
        Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
            $table->string('hook_file')->unique();
            $table->text('message');
            $table->boolean('admin_notify')->default(0);
            $table->string('version')->default('1.0.0');
        });
    }
    // টেবিল ২: SMS লগস
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

// --- GitHub Sync Logic ---
function wpsend_get_remote_hooks() {
    // আপনার গিটহাব রিপোজিটরি থেকে ফাইলের লিস্ট আনবে (Raw Content API)
    $api_url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WPSend-WHMCS-Updater');
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// --- SMS Engine ---
function wpsend_send_sms($to, $message) {
    $apiKey = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'api_key')->value('value');
    $account = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'account')->value('value');
    
    $url = "https://cp.wpsend.org/fi/send.php?secret=" . urlencode($apiKey) . "&account=" . urlencode($account) . "&to=" . urlencode($to) . "&message=" . urlencode($message);
    $response = @file_get_contents($url);
    
    Capsule::table('mod_wpsend_logs')->insert([
        'to_num' => $to,
        'msg' => $message,
        'status' => $response ? 'Success' : 'Failed'
    ]);
    return $response;
}

// --- Admin Dashboard UI ---
function wpsend_output($vars) {
    $hooksDir = __DIR__ . '/hooks/';
    if (!is_dir($hooksDir)) mkdir($hooksDir, 0755);

    // ১. হুক ডাউনলোড/আপডেট হ্যান্ডলার
    if (isset($_GET['download_hook'])) {
        $file = $_GET['download_hook'];
        $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/" . $file;
        $content = @file_get_contents($raw_url);
        if ($content) {
            file_put_contents($hooksDir . $file, $content);
            Capsule::table('mod_wpsend_hooks')->updateOrInsert(['hook_file' => $file], ['message' => 'Default SMS message for '.$file]);
            echo "<div class='alert alert-success'>$file successfully synced from GitHub!</div>";
        }
    }

    // ২. মেসেজ সেভ হ্যান্ডলার
    if (isset($_POST['save_settings'])) {
        foreach ($_POST['msg'] as $file => $text) {
            $admin = isset($_POST['admin_notify'][$file]) ? 1 : 0;
            Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update(['message' => $text, 'admin_notify' => $admin]);
        }
        echo "<div class='alert alert-success'>Changes saved successfully!</div>";
    }

    $remoteFiles = wpsend_get_remote_hooks();
    $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->take(10)->get();
    ?>

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        .vip-container { font-family: 'Inter', sans-serif; background: #f8fafc; padding: 20px; }
        .vip-card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); }
        .badge-update { background: #3b82f6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; }
        .hook-item { border-bottom: 1px solid #f1f5f9; padding: 15px 0; }
        .hook-item:last-child { border: 0; }
        .btn-sync { background: #10b981; color: white; border: 0; padding: 5px 15px; border-radius: 6px; }
    </style>

    <div class="vip-container">
        <div class="row">
            <div class="col-md-8">
                <div class="vip-card">
                    <h3>💎 VIP Hook Management</h3>
                    <p class="text-muted">গিটহাব থেকে সরাসরি হুক ফাইল আপডেট এবং মেসেজ ম্যানেজ করুন।</p>
                    <form method="post">
                        <?php 
                        if(is_array($remoteFiles)) {
                            foreach ($remoteFiles as $rFile) {
                                if($rFile['type'] != 'file') continue;
                                $name = $rFile['name'];
                                $isInstalled = file_exists($hooksDir . $name);
                                $data = Capsule::table('mod_wpsend_hooks')->where('hook_file', $name)->first();
                                ?>
                                <div class="hook-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong><i class="fa fa-code"></i> <?=$name?></strong>
                                        <?php if(!$isInstalled): ?>
                                            <a href="?module=wpsend&download_hook=<?=$name?>" class="btn-sync">Download Hook</a>
                                        <?php else: ?>
                                            <span class="badge badge-success">Installed</span>
                                            <a href="?module=wpsend&download_hook=<?=$name?>" class="btn btn-sm btn-link text-primary">Check for Updates</a>
                                        <?php endif; ?>
                                    </div>
                                    <?php if($isInstalled): ?>
                                        <textarea name="msg[<?=$name?>]" class="form-control mt-2" rows="2"><?=$data->message?></textarea>
                                        <label class="mt-1"><input type="checkbox" name="admin_notify[<?=$name?>]" <?=($data->admin_notify?'checked':'')?>> Notify Admin</label>
                                    <?php endif; ?>
                                </div>
                        <?php } } ?>
                        <button type="submit" name="save_settings" class="btn btn-primary btn-block mt-3">Save All Changes</button>
                    </form>
                </div>
            </div>

            <div class="col-md-4">
                <div class="vip-card">
                    <h4>📡 System Logs</h4>
                    <table class="table table-sm" style="font-size: 12px;">
                        <thead><tr><th>To</th><th>Status</th></tr></thead>
                        <tbody>
                            <?php foreach($logs as $log): ?>
                            <tr><td><?=$log->to_num?></td><td><span class="badge badge-info"><?=$log->status?></span></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="vip-card bg-info text-white">
                    <h5>Update Check</h5>
                    <p>WPSend.org API: Connected <br> Version: 1.5.0</p>
                    <button class="btn btn-light btn-sm btn-block">Check for Core Update</button>
                </div>
            </div>
        </div>
    </div>
    <?php
}

// অটো-লোড হুকস
foreach (glob(__DIR__ . "/hooks/*.php") as $filename) {
    include_once $filename;
}
