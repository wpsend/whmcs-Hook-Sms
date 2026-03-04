<?php
/**
 * WPSend VIP Master Hub
 * Developed by: WPSend.org
 * GitHub: https://github.com/wpsend/whmcs-Hook-Sms
 * Core Update: https://wpsend.org
 * Version: 2.0.1
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'Master Controller for GitHub Hook Sync & wpsend.org Self-Update',
            'version' => '2.0.1',
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text', 'Size' => '50'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text', 'Size' => '50'],
                'admin_mobile' => ['FriendlyName' => 'Admin Mobile', 'Type' => 'text', 'Description' => 'অ্যাডমিন এসএমএস পাওয়ার নাম্বার'],
            ]
        ];
    }

    function wpsend_activate() {
        // Create Hooks Table if not exists
        if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
            Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
                $table->string('hook_file')->unique();
                $table->text('message');
                $table->string('local_version')->default('0.0.0');
                $table->boolean('admin_notify')->default(0);
            });
        } else {
            // Check for local_version column (Fixing previous error)
            if (!Capsule::schema()->hasColumn('mod_wpsend_hooks', 'local_version')) {
                Capsule::schema()->table('mod_wpsend_hooks', function ($table) {
                    $table->string('local_version')->default('0.0.0');
                });
            }
        }

        // Create Logs Table
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
    function wpsend_get_remote_core_version() {
        $url = "https://wpsend.org/api/version_check.php?cache=" . time();
        $v = @file_get_contents($url);
        return $v ? trim($v) : '2.0.0';
    }

    // --- GitHub Hook Engine ---
    function wpsend_fetch_github_hook_list() {
        // নির্দিষ্ট করে /hook ফোল্ডার থেকে ডাটা আনা হচ্ছে
        $api_url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/hook";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WPSend-VIP-Agent');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        curl_close($ch);
        $data = json_decode($response, true);
        return is_array($data) ? $data : [];
    }

    // --- Shortcode & Version Parser ---
    function wpsend_parse_code_meta($content) {
        // Version বের করা: // Version: 1.0.5
        preg_match('/Version:\s*([0-9\.]+)/i', $content, $v_matches);
        $version = $v_matches[1] ?? '1.0.0';

        // কোড থেকে ডাইনামিক ট্যাগ বের করা: {ANY_TAG}
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $content, $t_matches);
        $tags = !empty($t_matches[0]) ? implode(', ', array_unique($t_matches[0])) : '{name}, {email}, {id}';

        return ['version' => $version, 'tags' => $tags];
    }

    // --- Global SMS Function ---
    function wpsend_send_sms_core($to, $message) {
        $apiKey = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'api_key')->value('value');
        $account = Capsule::table('tbladdonmodules')->where('module', 'wpsend')->where('setting', 'account')->value('value');
        if(!$apiKey || !$to) return false;

        $url = "https://cp.wpsend.org/fi/send.php?secret=".urlencode($apiKey)."&account=".urlencode($account)."&to=".urlencode($to)."&message=".urlencode($message);
        $res = @file_get_contents($url);
        
        Capsule::table('mod_wpsend_logs')->insert([
            'to_num' => $to,
            'msg' => $message,
            'status' => $res ? 'Sent' : 'Failed'
        ]);
        return $res;
    }

    // --- Admin Dashboard UI ---
    function wpsend_output($vars) {
        // ডাটাবেস চেক (Auto-fix)
        wpsend_activate();

        $currentCoreV = '2.0.0';
        $remoteCoreV = wpsend_get_remote_core_version();
        $hooksDir = __DIR__ . '/hooks/';

        // মেইন ফাইল আপডেট হ্যান্ডলার
        if (isset($_GET['action']) && $_GET['action'] == 'upgrade_main') {
            $raw_url = "https://wpsend.org/api/wpsend_latest.php?t=" . time();
            $new_code = @file_get_contents($raw_url);
            if ($new_code && strpos($new_code, '<?php') !== false) {
                file_put_contents(__FILE__, $new_code);
                echo "<div class='alert alert-success'>✅ Main wpsend.php upgraded to v$remoteCoreV! <script>setTimeout(function(){ location.reload(); }, 2000);</script></div>";
                return;
            }
        }

        // হুক সিঙ্ক হ্যান্ডলার (GitHub থেকে)
        if (isset($_GET['action']) && $_GET['action'] == 'sync_hook' && isset($_GET['file'])) {
            $file = basename($_GET['file']);
            $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/hook/".$file."?t=".time();
            $content = @file_get_contents($raw_url);
            if ($content) {
                if (!is_dir($hooksDir)) @mkdir($hooksDir, 0755);
                file_put_contents($hooksDir . $file, $content);
                
                $meta = wpsend_parse_code_meta($content);
                Capsule::table('mod_wpsend_hooks')->updateOrInsert(
                    ['hook_file' => $file],
                    ['local_version' => $meta['version']]
                );
                echo "<div class='alert alert-success'>✅ $file (v".$meta['version'].") has been synced!</div>";
            }
        }

        // টেমপ্লেট সেভ হ্যান্ডলার
        if (isset($_POST['save_all_vip'])) {
            foreach ($_POST['msg'] as $file => $msg) {
                $notify = isset($_POST['notify'][$file]) ? 1 : 0;
                Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update([
                    'message' => $msg,
                    'admin_notify' => $notify
                ]);
            }
            echo "<div class='alert alert-success'>💾 VIP Templates and Settings Saved!</div>";
        }

        $githubHooks = wpsend_fetch_github_hook_list();
        $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->limit(12)->get();
        ?>

        <style>
            .wpsend-vip-wrapper { background: #f8fafc; padding: 25px; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
            .vip-card { background: #ffffff; border-radius: 15px; border: 1px solid #e2e8f0; padding: 25px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1); margin-bottom: 25px; }
            .hook-row { border-bottom: 1px solid #f1f5f9; padding: 20px 0; }
            .hook-row:last-child { border: none; }
            .v-badge { font-size: 10px; padding: 3px 10px; border-radius: 50px; font-weight: bold; text-transform: uppercase; }
            .badge-local { background: #f1f5f9; color: #475569; }
            .badge-git { background: #dcfce7; color: #166534; }
            .tag-cloud { background: #fffbeb; border: 1px dashed #fbbf24; padding: 10px; border-radius: 8px; font-size: 12px; color: #92400e; margin-top: 10px; line-height: 1.6; }
            .btn-sync { background: #1e293b; color: #ffffff; border-radius: 8px; font-weight: 600; font-size: 12px; padding: 8px 16px; transition: 0.3s; border: none; }
            .btn-sync:hover { background: #0f172a; color: #fff; text-decoration: none; }
            .core-notice { background: #eff6ff; border-left: 6px solid #2563eb; padding: 20px; border-radius: 8px; margin-bottom: 30px; display: flex; justify-content: space-between; align-items: center; }
        </style>

        <div class="wpsend-vip-wrapper">
            <?php if (version_compare($remoteCoreV, $currentCoreV, '>')): ?>
                <div class="core-notice">
                    <div>
                        <h4 style="margin:0; color:#1e40af;"><i class="fa fa-rocket"></i> New Module Update Available! (v<?=$remoteCoreV?>)</h4>
                        <p style="margin:5px 0 0 0; color:#1e40af;">আপনার মেইন কন্ট্রোলারটি আপডেট করা প্রয়োজন। এতে নতুন ফিচার এবং নিরাপত্তা আপডেট রয়েছে।</p>
                    </div>
                    <a href="?module=wpsend&action=upgrade_main" class="btn btn-primary btn-lg">Update wpsend.php</a>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="vip-card">
                        <h3 style="margin-top:0; font-weight:700; color:#0f172a;">💎 WPSend VIP Hook Hub</h3>
                        <p class="text-muted">গিটহাব থেকে হুকগুলো সিঙ্ক করুন এবং আপনার পছন্দমতো এসএমএস কাস্টমাইজ করুন।</p>
                        <hr>
                        <form method="post">
                            <?php 
                            foreach ($githubHooks as $gh): 
                                $hName = $gh['name'];
                                $localExists = file_exists($hooksDir . $hName);
                                $db = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hName)->first();
                                
                                $locV = $db ? $db->local_version : '0.0.0';
                                $availableTags = '{name}, {id}, {email}'; // Default

                                if($localExists){
                                    $p = wpsend_parse_code_meta(file_get_contents($hooksDir . $hName));
                                    $availableTags = $p['tags'];
                                }
                            ?>
                            <div class="hook-row">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <div>
                                        <span style="font-size:16px; font-weight:600; color:#334155;"><i class="fa fa-file-code-o"></i> <?=$hName?></span>
                                        <div style="margin-top:5px;">
                                            <span class="v-badge badge-local">WHMCS: v<?=$locV?></span>
                                            <span class="v-badge badge-git">GitHub: Latest</span>
                                        </div>
                                    </div>
                                    <a href="?module=wpsend&action=sync_hook&file=<?=$hName?>" class="btn-sync">
                                        <i class="fa fa-refresh"></i> <?=$localExists ? 'Update Hook' : 'Download Hook'?>
                                    </a>
                                </div>

                                <?php if ($localExists && $db): ?>
                                    <div style="margin-top:15px;">
                                        <label class="small" style="font-weight:600; color:#64748b;">Custom SMS Template:</label>
                                        <textarea name="msg[<?=$hName?>]" class="form-control" rows="3" style="border-radius:10px;"><?=$db->message?></textarea>
                                        <div class="tag-cloud">
                                            <strong><i class="fa fa-tags"></i> Available Tags (Synced):</strong><br><?=$availableTags?>
                                        </div>
                                        <label style="margin-top:12px; font-weight:500; cursor:pointer; color:#475569;">
                                            <input type="checkbox" name="notify[<?=$hName?>]" <?=$db->admin_notify ? 'checked' : ''?>> Notify Admin (<?=$vars['admin_mobile']?>)
                                        </label>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                            <button type="submit" name="save_all_vip" class="btn btn-success btn-block btn-lg" style="margin-top:30px; border-radius:12px; font-weight:700;">💾 Save All Configurations</button>
                        </form>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="vip-card" style="background:#0f172a; color:#fff;">
                        <small style="text-transform:uppercase; letter-spacing:1px; opacity:0.7;">Main Controller</small>
                        <h1 style="margin:10px 0; font-weight:800; color:#fbbf24;">v<?=$currentCoreV?></h1>
                        <p style="font-size:13px; opacity:0.8;">WPSend.org VIP Connected</p>
                        <hr style="border-top: 1px solid #334155;">
                        <div style="font-size:12px;">
                            <i class="fa fa-check-circle text-success"></i> GitHub API: Healthy<br>
                            <i class="fa fa-check-circle text-success"></i> Core Update: Online
                        </div>
                    </div>

                    <div class="vip-card">
                        <h4 style="margin-top:0; font-weight:700;"><i class="fa fa-list-alt"></i> Delivery Logs</h4>
                        <table class="table table-hover" style="font-size:12px;">
                            <thead><tr><th>Recipient</th><th class="text-right">Status</th></tr></thead>
                            <tbody>
                                <?php foreach($logs as $log): ?>
                                <tr>
                                    <td style="font-weight:500;"><?=$log->to_num?></td>
                                    <td class="text-right"><span class="label label-<?=($log->status=='Sent'?'success':'danger')?>"><?=$log->status?></span></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <small class="text-muted">Showing last 12 activities</small>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

/**
 * AUTO HOOK LOADER
 * Automatically loads all active hooks from /hooks folder
 */
$hookPath = __DIR__ . '/hooks/';
if (is_dir($hookPath)) {
    $files = glob($hookPath . "*.php");
    foreach ($files as $file) {
        if (basename($file) !== 'wpsend.php') {
            include_once $file;
        }
    }
}
