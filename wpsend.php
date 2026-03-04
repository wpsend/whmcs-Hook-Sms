<?php
/**
 * WPSend VIP Master Controller - Ultra Professional
 * Website: https://wpsend.org
 * API Source: https://my.wpsend.org
 * Version: 2.5.0
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'Professional SMS Hub for WHMCS. Manage Hooks, Updates, and Templates via wpsend.org',
            'version' => '2.5.0',
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text', 'Size' => '50'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text', 'Size' => '50'],
                'admin_mobile' => ['FriendlyName' => 'Admin Mobile', 'Type' => 'text', 'Description' => 'অ্যাডমিন নোটিফিকেশন পাওয়ার নাম্বার'],
            ]
        ];
    }

    function wpsend_activate() {
        // Table for hooks template & versioning
        if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
            Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
                $table->string('hook_file')->unique();
                $table->text('message')->nullable();
                $table->string('local_version')->default('0.0.0');
                $table->boolean('admin_notify')->default(0);
            });
        }
        
        // Ensure local_version column exists for older installations
        if (!Capsule::schema()->hasColumn('mod_wpsend_hooks', 'local_version')) {
            Capsule::schema()->table('mod_wpsend_hooks', function ($table) {
                $table->string('local_version')->default('0.0.0');
            });
        }

        // Table for SMS Logs
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

    // --- Core Sync Engine ---
    function wpsend_remote_get($path) {
        $url = "https://my.wpsend.org/api/sms/" . $path;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $res = curl_exec($ch);
        curl_close($ch);
        return $res;
    }

    // --- Parser for Version & Tags ---
    function wpsend_parse_code($code) {
        preg_match('/Version:\s*([0-9\.]+)/i', $code, $v);
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $code, $t);
        return [
            'version' => $v[1] ?? '1.0.0',
            'tags' => !empty($t[0]) ? implode(', ', array_unique($t[0])) : '{name}, {id}, {email}'
        ];
    }

    // --- Unified SMS Sender ---
    function wpsend_send_sms_core($to, $message) {
        $apiKey = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'api_key')->value('value');
        $acc = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'account')->value('value');
        
        if(!$apiKey || !$to) return "Missing Info";

        $url = "https://cp.wpsend.org/fi/send.php?secret=".urlencode($apiKey)."&account=".urlencode($acc)."&to=".urlencode($to)."&message=".urlencode($message);
        $res = @file_get_contents($url);
        
        Capsule::table('mod_wpsend_logs')->insert([
            'to_num' => $to,
            'msg' => $message,
            'status' => ($res ? 'Sent' : 'Failed')
        ]);
        return $res;
    }

    function wpsend_output($vars) {
        wpsend_activate();
        $currentCoreV = '2.5.0';
        $hooksPath = __DIR__ . '/hooks/';

        // 1. Core Update Check
        $remoteCoreCode = wpsend_remote_get('wpsend.php');
        $remoteMeta = wpsend_parse_code($remoteCoreCode);
        $remoteCoreV = $remoteMeta['version'];

        // Action: Update Core
        if (isset($_GET['action']) && $_GET['action'] == 'update_core' && !empty($remoteCoreCode)) {
            file_put_contents(__FILE__, $remoteCoreCode);
            echo "<div class='alert alert-success'>✅ Main Controller Updated to v$remoteCoreV! <script>setTimeout(function(){ location.reload(); }, 1500);</script></div>";
            return;
        }

        // Action: Sync/Download Hook
        if (isset($_GET['action']) && $_GET['action'] == 'sync_hook' && isset($_GET['file'])) {
            $file = basename($_GET['file']);
            $code = wpsend_remote_get('hook/' . $file);
            if ($code && strpos($code, '<?php') !== false) {
                if (!is_dir($hooksPath)) @mkdir($hooksPath, 0755);
                file_put_contents($hooksPath . $file, $code);
                $meta = wpsend_parse_code($code);
                
                // Get default message from code comment if exists
                preg_match('/Default:\s*(.*)/i', $code, $defMsg);
                $msg = $defMsg[1] ?? '';

                Capsule::table('mod_wpsend_hooks')->updateOrInsert(
                    ['hook_file' => $file],
                    ['local_version' => $meta['version'], 'message' => $msg]
                );
                echo "<div class='alert alert-success'>✅ $file has been synced!</div>";
            }
        }

        // Action: Test SMS
        if (isset($_POST['send_test'])) {
            $testNum = $_POST['test_num'];
            $testMsg = $_POST['test_msg'];
            wpsend_send_sms_core($testNum, $testMsg);
            echo "<div class='alert alert-info'>Test SMS processed. Check logs below.</div>";
        }

        // Action: Save Templates
        if (isset($_POST['save_all'])) {
            foreach ($_POST['msg'] as $file => $text) {
                $notify = isset($_POST['notify'][$file]) ? 1 : 0;
                Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update([
                    'message' => $text,
                    'admin_notify' => $notify
                ]);
            }
            echo "<div class='alert alert-success'>Settings saved successfully!</div>";
        }

        // Fetch Remote Hooks List
        $hookJson = wpsend_remote_get('hook/list.php'); // Assuming you have a list.php or similar
        $remoteHooks = json_decode($hookJson, true) ?: []; 
        
        // If no list.php, we could simulate or use a predefined list from your API
        $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->limit(8)->get();
        ?>

        <style>
            .wpsend-vip-ui { background: #f4f7f9; padding: 25px; font-family: 'Segoe UI', Tahoma, sans-serif; }
            .v-card { background: #fff; border-radius: 12px; border: 1px solid #e1e8ed; padding: 25px; box-shadow: 0 4px 12px rgba(0,0,0,0.05); margin-bottom: 25px; }
            .v-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #f0f3f5; padding-bottom: 15px; margin-bottom: 20px; }
            .v-badge { font-size: 10px; padding: 3px 10px; border-radius: 20px; font-weight: bold; text-transform: uppercase; }
            .badge-update { background: #e0f2ff; color: #007bff; border: 1px solid #007bff; }
            .badge-ok { background: #e6fffa; color: #38b2ac; border: 1px solid #38b2ac; }
            .hook-item { background: #fff; border: 1px solid #edf2f7; border-radius: 10px; padding: 20px; margin-bottom: 15px; transition: 0.3s; }
            .hook-item:hover { border-color: #cbd5e0; box-shadow: 0 5px 15px rgba(0,0,0,0.03); }
            .tag-pill { background: #edf2f7; color: #4a5568; padding: 2px 8px; border-radius: 5px; font-size: 11px; margin-right: 5px; display: inline-block; margin-top: 5px; }
            .status-bar { background: #1a202c; color: #fff; padding: 15px 25px; border-radius: 10px; margin-bottom: 25px; display: flex; gap: 40px; }
            .log-table { font-size: 12px; width: 100%; }
            .log-table th { color: #718096; text-transform: uppercase; font-size: 10px; }
        </style>

        <div class="wpsend-vip-ui">
            
            <div class="status-bar">
                <div><small style="opacity:0.6;display:block;">MODULE VERSION</small> <strong>v<?=$currentCoreV?></strong></div>
                <div><small style="opacity:0.6;display:block;">API STATUS</small> <strong style="color:#48bb78;">Connected</strong></div>
                <div><small style="opacity:0.6;display:block;">CORE STATUS</small> 
                    <?php if(version_compare($remoteCoreV, $currentCoreV, '>')): ?>
                        <a href="?module=wpsend&action=update_core" class="v-badge badge-update">Update to v<?=$remoteCoreV?></a>
                    <?php else: ?>
                        <span class="v-badge badge-ok">Up to date</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="row">
                <div class="col-md-8">
                    <div class="v-card">
                        <div class="v-header">
                            <h3 style="margin:0;">💎 Hook & Template Manager</h3>
                            <span class="text-muted small">Managed via my.wpsend.org</span>
                        </div>

                        <form method="post">
                            <?php 
                            // সিমুলেটেড লিস্ট যদি API থেকে না আসে
                            $availableFiles = ['client.php', 'invoice.php', 'ticket.php', 'order.php']; 
                            foreach ($availableFiles as $hName): 
                                $localFile = $hooksPath . $hName;
                                $isInstalled = file_exists($localFile);
                                $db = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hName)->first();
                                
                                $locV = $db ? $db->local_version : '0.0.0';
                                $tags = '{name}, {id}, {email}';
                                if($isInstalled){
                                    $p = wpsend_parse_code(file_get_contents($localFile));
                                    $tags = $p['tags'];
                                }
                            ?>
                            <div class="hook-item">
                                <div style="display:flex; justify-content:space-between; align-items:flex-start;">
                                    <div>
                                        <h4 style="margin:0 0 5px 0; color:#2d3748;"><?=$hName?></h4>
                                        <span class="v-badge <?=($isInstalled?'badge-ok':'')?>">Local: v<?=$locV?></span>
                                    </div>
                                    <a href="?module=wpsend&action=sync_hook&file=<?=$hName?>" class="btn btn-sm btn-default">
                                        <i class="fa fa-refresh"></i> <?=$isInstalled ? 'Update Hook' : 'Install Hook'?>
                                    </a>
                                </div>

                                <?php if($isInstalled && $db): ?>
                                    <div style="margin-top:15px;">
                                        <textarea name="msg[<?=$hName?>]" class="form-control" rows="3" placeholder="SMS Content..."><?=$db->message?></textarea>
                                        <div style="margin-top:10px;">
                                            <small class="text-muted">Available Tags:</small><br>
                                            <?php foreach(explode(', ', $tags) as $t): ?>
                                                <span class="tag-pill"><?=$t?></span>
                                            <?php endforeach; ?>
                                        </div>
                                        <label style="margin-top:10px; font-weight:normal; cursor:pointer;">
                                            <input type="checkbox" name="notify[<?=$hName?>]" <?=$db->admin_notify ? 'checked' : ''?>> Notify Admin (<?=$vars['admin_mobile']?>)
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <button type="submit" name="save_all" class="btn btn-primary btn-lg btn-block" style="border-radius:10px;">💾 Save All Templates</button>
                        </form>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="v-card">
                        <h4 style="margin-top:0;">⚡ Quick Test SMS</h4>
                        <hr>
                        <form method="post">
                            <div class="form-group">
                                <input type="text" name="test_num" class="form-control" placeholder="Phone Number (e.g. 88017...)" required>
                            </div>
                            <div class="form-group">
                                <textarea name="test_msg" class="form-control" rows="2" placeholder="Test Message..." required>Test SMS from WPSend VIP Hub.</textarea>
                            </div>
                            <button type="submit" name="send_test" class="btn btn-info btn-block">Send Test SMS</button>
                        </form>
                    </div>

                    <div class="v-card">
                        <h4 style="margin-top:0;">📡 Recent Activity</h4>
                        <hr>
                        <table class="log-table">
                            <thead><tr><th>To</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach($logs as $log): ?>
                                <tr>
                                    <td style="padding:5px 0;"><?=$log->to_num?></td>
                                    <td><span class="label label-<?=($log->status=='Sent'?'success':'danger')?>"><?=$log->status?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// --- Dynamic Hook Auto Loader ---
$hookPath = __DIR__ . '/hooks/';
if (is_dir($hookPath)) {
    $files = glob($hookPath . "*.php");
    foreach ($files as $file) {
        if (basename($file) !== 'wpsend.php') {
            include_once $file;
        }
    }
}
