<?php
/**
 * WPSend VIP Master Hub - Professional SMS Automation
 * Website: https://wpsend.org
 * GitHub Hooks: https://github.com/wpsend/whmcs-Hook-Sms
 * Version: 1.8.0
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'Professional Hub for GitHub Hook Sync & wpsend.org Core Updates',
            'version' => '1.8.1',
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text', 'Size' => '50'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text', 'Size' => '50'],
                'admin_mobile' => ['FriendlyName' => 'Admin Mobile', 'Type' => 'text', 'Description' => 'Admin SMS poyar number'],
            ]
        ];
    }

    function wpsend_activate() {
        // Table mod_wpsend_hooks
        if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
            Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
                $table->string('hook_file')->unique();
                $table->text('message');
                $table->string('local_version')->default('0.0.0');
                $table->boolean('admin_notify')->default(0);
            });
        } else {
            // Auto check and add local_version column
            if (!Capsule::schema()->hasColumn('mod_wpsend_hooks', 'local_version')) {
                Capsule::schema()->table('mod_wpsend_hooks', function ($table) {
                    $table->string('local_version')->default('0.0.0');
                });
            }
        }

        // Table mod_wpsend_logs
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

    // --- Core Update Engine (wpsend.org) ---
    function wpsend_get_core_version() {
        $url = "https://wpsend.org/api/version_check.php?cache=" . time();
        $v = @file_get_contents($url);
        return $v ? trim($v) : '1.8.0';
    }

    // --- GitHub Hook Engine ---
    function wpsend_fetch_github_hooks() {
        $api_url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WPSend-VIP-Agent');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    // --- Helper to Extract Tags and Version from Code ---
    function wpsend_parse_hook_file($content) {
        // Extract Version: // Version: 1.2.0
        preg_match('/Version:\s*([0-9\.]+)/i', $content, $v_matches);
        $version = $v_matches[1] ?? '1.0.0';

        // Extract Tags: {name}, {id} etc from the message logic
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $content, $t_matches);
        $tags = !empty($t_matches[0]) ? implode(', ', array_unique($t_matches[0])) : '{name}, {id}, {email}';

        return ['version' => $version, 'tags' => $tags];
    }

    // --- SMS Sender ---
    function wpsend_send_sms($to, $message) {
        $apiKey = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'api_key')->value('value');
        $account = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'account')->value('value');
        if(!$apiKey || !$to) return false;

        $url = "https://cp.wpsend.org/fi/send.php?secret=".urlencode($apiKey)."&account=".urlencode($account)."&to=".urlencode($to)."&message=".urlencode($message);
        $res = @file_get_contents($url);
        
        Capsule::table('mod_wpsend_logs')->insert([
            'to_num' => $to,
            'msg' => $message,
            'status' => $res ? 'Success' : 'Failed'
        ]);
        return $res;
    }

    // --- Admin Dashboard UI ---
    function wpsend_output($vars) {
        wpsend_activate(); // Ensure database is up to date
        $currentV = '1.8.0';
        $remoteV = wpsend_get_core_version();
        $hooksDir = __DIR__ . '/hooks/';

        // Action: Core Update
        if (isset($_GET['action']) && $_GET['action'] == 'update_core') {
            $raw_url = "https://wpsend.org/api/wpsend_latest.php?cache=" . time();
            $new_code = @file_get_contents($raw_url);
            if ($new_code && strpos($new_code, '<?php') !== false) {
                file_put_contents(__FILE__, $new_code);
                echo "<div class='alert alert-success'>Main wpsend.php updated to v$remoteV! Refreshing...<script>setTimeout(function(){ location.href='addonmodules.php?module=wpsend'; }, 2000);</script></div>";
                return;
            }
        }

        // Action: Sync Hook
        if (isset($_GET['action']) && $_GET['action'] == 'sync_hook' && isset($_GET['file'])) {
            $file = basename($_GET['file']);
            if ($file == 'wpsend.php') {
                echo "<div class='alert alert-danger'>Security: wpsend.php cannot be a hook.</div>";
            } else {
                $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/".$file."?t=".time();
                $content = @file_get_contents($raw_url);
                if ($content) {
                    if (!is_dir($hooksDir)) @mkdir($hooksDir, 0755);
                    file_put_contents($hooksDir . $file, $content);
                    
                    $info = wpsend_parse_hook_file($content);
                    Capsule::table('mod_wpsend_hooks')->updateOrInsert(
                        ['hook_file' => $file],
                        ['local_version' => $info['version']]
                    );
                    echo "<div class='alert alert-success'>$file Synced successfully!</div>";
                }
            }
        }

        // Action: Save Templates
        if (isset($_POST['save_all'])) {
            foreach ($_POST['msg'] as $file => $msg) {
                $notify = isset($_POST['notify'][$file]) ? 1 : 0;
                Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update([
                    'message' => $msg,
                    'admin_notify' => $notify
                ]);
            }
            echo "<div class='alert alert-success'>VIP Settings Saved!</div>";
        }

        $remoteHooks = wpsend_fetch_github_hooks();
        $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->limit(10)->get();
        ?>

        <style>
            .wpsend-vip-hub { background: #f4f7f6; padding: 20px; font-family: 'Inter', sans-serif; }
            .v-card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 25px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); margin-bottom: 25px; }
            .hook-item { border-bottom: 1px solid #edf2f7; padding: 20px 0; }
            .hook-item:last-child { border: 0; }
            .v-title { font-weight: 700; color: #1a202c; font-size: 16px; }
            .v-badge { font-size: 11px; padding: 2px 8px; border-radius: 20px; font-weight: bold; }
            .badge-local { background: #e2e8f0; color: #4a5568; }
            .badge-git { background: #c6f6d5; color: #22543d; }
            .tag-info { background: #fffaf0; border: 1px dashed #fbd38d; padding: 8px; border-radius: 6px; font-size: 12px; color: #c05621; margin-top: 10px; }
            .btn-sync { background: #2d3748; color: #fff; border-radius: 6px; padding: 6px 15px; font-size: 12px; transition: 0.3s; }
            .btn-sync:hover { background: #000; color: #fff; text-decoration: none; }
            .update-banner { background: #ebf8ff; border-left: 5px solid #3182ce; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        </style>

        <div class="wpsend-vip-hub">
            <?php if (version_compare($remoteV, $currentV, '>')): ?>
                <div class="update-banner">
                    <h4 style="margin:0; color:#2b6cb0;">🚀 New Core Update Available! (v<?=$remoteV?>)</h4>
                    <p style="margin:5px 0;">Apnar module-ti purono hoye geche. Shob features pite update korun.</p>
                    <a href="?module=wpsend&action=update_core" class="btn btn-primary btn-sm">Update Main wpsend.php Now</a>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="v-card">
                        <h3 style="margin-top:0;">💎 WPSend VIP Template Manager</h3>
                        <p class="text-muted small">GitHub repository theke hook sync korun ebong SMS content edit korun.</p>
                        <hr>
                        <form method="post">
                        <?php 
                        foreach ($remoteHooks as $h): 
                            if ($h['type'] != 'file' || $h['name'] == 'wpsend.php') continue;
                            $name = $h['name'];
                            $isInstalled = file_exists($hooksDir . $name);
                            $dbData = Capsule::table('mod_wpsend_hooks')->where('hook_file', $name)->first();
                            
                            $localVersion = $dbData ? $dbData->local_version : '0.0.0';
                            $tags = '{name}, {id}, {email}, {phone}'; // Default
                            
                            // If local file exists, parse for tags
                            if($isInstalled){
                                $parsed = wpsend_parse_hook_file(file_get_contents($hooksDir . $name));
                                $tags = $parsed['tags'];
                            }
                        ?>
                            <div class="hook-item">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <span class="v-title"><i class="fa fa-code-fork"></i> <?=$name?></span>
                                        <div style="margin-top:4px;">
                                            <span class="v-badge badge-local">Local: v<?=$localVersion?></span>
                                            <span class="v-badge badge-git">GitHub: Latest</span>
                                        </div>
                                    </div>
                                    <a href="?module=wpsend&action=sync_hook&file=<?=$name?>&url=<?=urlencode($h['download_url'])?>" class="btn-sync">
                                        <i class="fa fa-refresh"></i> <?=$isInstalled ? 'Update Hook' : 'Install Hook'?>
                                    </a>
                                </div>

                                <?php if ($isInstalled && $dbData): ?>
                                    <div style="margin-top:15px;">
                                        <textarea name="msg[<?=$name?>]" class="form-control" rows="3" placeholder="Write SMS message..."><?=$dbData->message?></textarea>
                                        <div class="tag-info">
                                            <strong><i class="fa fa-tags"></i> Available Tags:</strong> <?=$tags?>
                                        </div>
                                        <label style="margin-top:10px; font-weight:normal; cursor:pointer;">
                                            <input type="checkbox" name="notify[<?=$name?>]" <?=$dbData->admin_notify ? 'checked' : ''?>> Also notify Admin (<?=$vars['admin_mobile']?>)
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                        <button type="submit" name="save_all" class="btn btn-success btn-lg btn-block" style="margin-top:30px; border-radius:8px;">💾 Save VIP Configuration</button>
                        </form>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="v-card" style="background: #1a202c; color:#fff;">
                        <small style="opacity:0.7;">System Version</small>
                        <h2 style="margin:10px 0; color:#ecc94b;">v<?=$currentV?></h2>
                        <p class="small">Master Controller is Active</p>
                        <hr style="border-color:#2d3748;">
                        <div class="small">GitHub API: Connected</div>
                        <div class="small">wpsend.org: Online</div>
                    </div>

                    <div class="v-card">
                        <h4 style="margin-top:0;">📡 Recent SMS Logs</h4>
                        <table class="table table-condensed" style="font-size:11px;">
                            <thead><tr><th>To</th><th>Status</th></tr></thead>
                            <tbody>
                                <?php foreach($logs as $log): ?>
                                <tr>
                                    <td><?=$log->to_num?></td>
                                    <td><span class="label label-<?=($log->status=='Success'?'success':'danger')?>"><?=$log->status?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <small class="text-muted">Showing last 10 activities</small>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * AUTO LOADER
 * Automatically include all hook files from /hooks/ directory
 * Excluding main file to prevent loops
 */
$hookFiles = glob(__DIR__ . '/hooks/*.php');
if (is_array($hookFiles)) {
    foreach ($hookFiles as $file) {
        if (basename($file) !== 'wpsend.php') {
            include_once $file;
        }
    }
}
