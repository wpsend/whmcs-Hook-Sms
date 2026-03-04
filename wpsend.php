<?php
/**
 * WPSend VIP Master Hub (Full GitHub Integrated)
 * GitHub Repo: https://github.com/wpsend/whmcs-Hook-Sms
 * Version: 2.1.0
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'GitHub-Driven SMS Master Controller (Auto-Sync Version)',
            'version' => '2.1.0',
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
                $table->string('local_version')->default('0.0.0');
                $table->boolean('admin_notify')->default(0);
            });
        }
        if (!Capsule::schema()->hasColumn('mod_wpsend_hooks', 'local_version')) {
            Capsule::schema()->table('mod_wpsend_hooks', function ($table) {
                $table->string('local_version')->default('0.0.0');
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

    // --- GitHub API Helper (To fetch files/meta) ---
    function wpsend_github_api($path = '') {
        $url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/" . $path;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WHMCS-WPSend-Client');
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true);
    }

    // --- Parse Version & Tags from Raw Code ---
    function wpsend_get_meta($content) {
        preg_match('/Version:\s*([0-9\.]+)/i', $content, $v_matches);
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $content, $t_matches);
        return [
            'version' => $v_matches[1] ?? '1.0.0',
            'tags' => !empty($t_matches[0]) ? implode(', ', array_unique($t_matches[0])) : '{name}, {email}, {id}'
        ];
    }

    function wpsend_output($vars) {
        wpsend_activate();
        $currentCoreV = '2.1.0'; // WHMCS-এ থাকা বর্তমান ভার্সন
        $hooksDir = __DIR__ . '/hooks/';

        // ১. মেইন ফাইল আপডেট চেক (GitHub থেকে wpsend.php এর ভার্সন রিড করা)
        $mainFileData = wpsend_github_api('wpsend.php');
        $remoteCoreV = $currentCoreV;
        if (isset($mainFileData['download_url'])) {
            $mainFileContent = @file_get_contents($mainFileData['download_url'] . "?t=" . time());
            preg_match('/Version:\s*([0-9\.]+)/i', $mainFileContent, $core_v_matches);
            $remoteCoreV = $core_v_matches[1] ?? $currentCoreV;
        }

        // Action: Core Upgrade
        if (isset($_GET['action']) && $_GET['action'] == 'upgrade_core') {
            if (isset($mainFileData['download_url'])) {
                file_put_contents(__FILE__, $mainFileContent);
                echo "<div class='alert alert-success'>✅ Module Upgraded to v$remoteCoreV! <script>setTimeout(function(){ location.reload(); }, 1500);</script></div>";
                return;
            }
        }

        // Action: Hook Sync
        if (isset($_GET['action']) && $_GET['action'] == 'sync_hook' && isset($_GET['file'])) {
            $file = basename($_GET['file']);
            $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/hook/".$file."?t=".time();
            $content = @file_get_contents($raw_url);
            if ($content) {
                if (!is_dir($hooksDir)) @mkdir($hooksDir, 0755);
                file_put_contents($hooksDir . $file, $content);
                $meta = wpsend_get_meta($content);
                Capsule::table('mod_wpsend_hooks')->updateOrInsert(['hook_file' => $file], ['local_version' => $meta['version']]);
                echo "<div class='alert alert-success'>✅ $file synced successfully!</div>";
            }
        }

        // Action: Save Templates
        if (isset($_POST['save_templates'])) {
            foreach ($_POST['msg'] as $file => $msg) {
                $notify = isset($_POST['notify'][$file]) ? 1 : 0;
                Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update(['message' => $msg, 'admin_notify' => $notify]);
            }
            echo "<div class='alert alert-success'>Settings saved!</div>";
        }

        $githubHooks = wpsend_github_api('hook'); // /hook ফোল্ডার থেকে ফাইল লিস্ট
        $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->limit(10)->get();
        ?>

        <style>
            .vip-hub { background: #fcfcfd; padding: 20px; }
            .v-card { background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 20px; }
            .hook-row { border-bottom: 1px solid #f1f5f9; padding: 15px 0; }
            .v-badge { font-size: 10px; padding: 2px 8px; border-radius: 10px; font-weight: bold; }
            .tag-box { background: #fffbeb; border: 1px dashed #fbbf24; padding: 8px; border-radius: 6px; font-size: 11px; margin-top: 10px; color: #92400e; }
        </style>

        <div class="vip-hub">
            <?php if (version_compare($remoteCoreV, $currentCoreV, '>')): ?>
                <div class="alert alert-info" style="display:flex; justify-content:space-between; align-items:center;">
                    <span>🚀 <strong>New Version v<?=$remoteCoreV?> Available!</strong> (GitHub-এ নতুন আপডেট পাওয়া গেছে)</span>
                    <a href="?module=wpsend&action=upgrade_core" class="btn btn-primary btn-sm">Update Now</a>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="v-card">
                        <h3 style="margin:0 0 15px 0;">💎 WPSend Master Hub</h3>
                        <form method="post">
                            <?php 
                            if(is_array($githubHooks)) {
                                foreach ($githubHooks as $gh): 
                                    $hName = $gh['name'];
                                    $localFile = $hooksDir . $hName;
                                    $db = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hName)->first();
                                    
                                    $locV = $db ? $db->local_version : '0.0.0';
                                    $tags = '{name}, {email}';
                                    if(file_exists($localFile)) {
                                        $meta = wpsend_get_meta(file_get_contents($localFile));
                                        $tags = $meta['tags'];
                                    }
                            ?>
                            <div class="hook-row">
                                <div style="display:flex; justify-content:space-between;">
                                    <strong><i class="fa fa-plug"></i> <?=$hName?></strong>
                                    <a href="?module=wpsend&action=sync_hook&file=<?=$hName?>" class="btn btn-default btn-xs">Sync (v<?=$locV?>)</a>
                                </div>
                                <?php if($db): ?>
                                    <textarea name="msg[<?=$hName?>]" class="form-control" rows="2" style="margin-top:10px;"><?=$db->message?></textarea>
                                    <div class="tag-box"><strong>Available Tags:</strong> <?=$tags?></div>
                                    <label style="font-weight:normal; margin-top:5px;"><input type="checkbox" name="notify[<?=$hName?>]" <?=$db->admin_notify ? 'checked' : ''?>> Notify Admin</label>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; } ?>
                            <button type="submit" name="save_templates" class="btn btn-success btn-block mt-3">Save VIP Settings</button>
                        </form>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="v-card" style="background:#1e293b; color:#fff;">
                        <small>CORE VERSION</small>
                        <h2 style="margin:5px 0; color:#fbbf24;">v<?=$currentCoreV?></h2>
                        <hr style="opacity:0.2;">
                        <small>Status: Connected to GitHub API</small>
                    </div>
                    <div class="v-card">
                        <h4>Recent Logs</h4>
                        <table class="table table-condensed" style="font-size:11px;">
                            <?php foreach($logs as $l): ?>
                                <tr><td><?=$l->to_num?></td><td><?=$l->status?></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// Auto Hook Loader
$hookPath = __DIR__ . '/hooks/';
if (is_dir($hookPath)) {
    foreach (glob($hookPath . "*.php") as $file) {
        if (basename($file) !== 'wpsend.php') include_once $file;
    }
}
