<?php
/**
 * WPSend VIP Master Hub - Final Version
 * GitHub Repo: https://github.com/wpsend/whmcs-Hook-Sms
 * Version: 2.1.7
 */

if (!defined("WHMCS")) die("Access denied");

use WHMCS\Database\Capsule;

if (!function_exists('wpsend_config')) {

    function wpsend_config() {
        return [
            'name' => 'WPSend VIP Hub',
            'description' => 'Master Controller with GitHub Hook Sync & Core Auto-Update',
            'version' => '2.1.7',
            'author' => 'WPSend.org',
            'fields' => [
                'api_key' => ['FriendlyName' => 'API Key', 'Type' => 'text'],
                'account' => ['FriendlyName' => 'Account ID', 'Type' => 'text'],
                'admin_mobile' => ['FriendlyName' => 'Admin Mobile', 'Type' => 'text', 'Description' => 'Admin number for notifications'],
            ]
        ];
    }

    function wpsend_activate() {
        // Create Hooks Table
        if (!Capsule::schema()->hasTable('mod_wpsend_hooks')) {
            Capsule::schema()->create('mod_wpsend_hooks', function ($table) {
                $table->string('hook_file')->unique();
                $table->text('message');
                $table->string('local_version')->default('0.0.0');
                $table->boolean('admin_notify')->default(0);
            });
        }
        // Ensure local_version column exists
        if (!Capsule::schema()->hasColumn('mod_wpsend_hooks', 'local_version')) {
            Capsule::schema()->table('mod_wpsend_hooks', function ($table) {
                $table->string('local_version')->default('0.0.0');
            });
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

    // --- Safe GitHub API Fetcher ---
    function wpsend_get_github_data($path = '') {
        $url = "https://api.github.com/repos/wpsend/whmcs-Hook-Sms/contents/" . $path;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WPSend-VIP-Controller');
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        return (is_array($data) && !isset($data['message'])) ? $data : null;
    }

    // --- Meta Parser for Version & Tags ---
    function wpsend_parse_meta_data($code) {
        preg_match('/Version:\s*([0-9\.]+)/i', $code, $v_match);
        preg_match_all('/\{[a-zA-Z0-9_]+\}/', $code, $t_match);
        return [
            'version' => $v_match[1] ?? '1.0.0',
            'tags' => !empty($t_match[0]) ? implode(', ', array_unique($t_match[0])) : '{name}, {id}, {email}'
        ];
    }

    function wpsend_output($vars) {
        wpsend_activate();
        $currentCoreV = '2.1.7'; // Current File Version
        $hooksDir = __DIR__ . '/hooks/';

        // ১. মেইন ফাইল আপডেট চেক (GitHub API থেকে)
        $remoteCoreV = $currentCoreV;
        $mainFileMeta = wpsend_get_github_data('wpsend.php');
        $mainContent = '';

        if ($mainFileMeta && isset($mainFileMeta['download_url'])) {
            $mainContent = @file_get_contents($mainFileMeta['download_url'] . "?t=" . time());
            if ($mainContent) {
                preg_match('/Version:\s*([0-9\.]+)/i', $mainContent, $core_matches);
                $remoteCoreV = $core_matches[1] ?? $currentCoreV;
            }
        }

        // Action: Self Upgrade Core
        if (isset($_GET['action']) && $_GET['action'] == 'upgrade_core' && !empty($mainContent)) {
            file_put_contents(__FILE__, $mainContent);
            echo "<div class='alert alert-success'>✅ Module Upgraded! Refreshing...<script>setTimeout(function(){ location.reload(); }, 1500);</script></div>";
            return;
        }

        // Action: Sync Hook from GitHub Folder
        if (isset($_GET['action']) && $_GET['action'] == 'sync_hook' && isset($_GET['file'])) {
            $file = basename($_GET['file']);
            $raw_url = "https://raw.githubusercontent.com/wpsend/whmcs-Hook-Sms/main/hook/".$file."?t=".time();
            $content = @file_get_contents($raw_url);
            if ($content) {
                if (!is_dir($hooksDir)) @mkdir($hooksDir, 0755);
                file_put_contents($hooksDir . $file, $content);
                $meta = wpsend_parse_meta_data($content);
                Capsule::table('mod_wpsend_hooks')->updateOrInsert(['hook_file' => $file], ['local_version' => $meta['version']]);
                echo "<div class='alert alert-success'>✅ $file synced successfully (v".$meta['version'].")!</div>";
            }
        }

        // Action: Save UI Templates
        if (isset($_POST['save_templates'])) {
            if (isset($_POST['msg']) && is_array($_POST['msg'])) {
                foreach ($_POST['msg'] as $file => $msg) {
                    $notify = isset($_POST['notify'][$file]) ? 1 : 0;
                    Capsule::table('mod_wpsend_hooks')->where('hook_file', $file)->update(['message' => $msg, 'admin_notify' => $notify]);
                }
                echo "<div class='alert alert-success'>💾 Settings saved successfully!</div>";
            }
        }

        $githubHooks = wpsend_get_github_data('hook');
        $logs = Capsule::table('mod_wpsend_logs')->orderBy('id', 'desc')->limit(10)->get();
        ?>

        <style>
            .wpsend-container { background: #f9fafb; padding: 25px; font-family: 'Segoe UI', sans-serif; }
            .v-card { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; padding: 20px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 25px; }
            .hook-item { border-bottom: 1px solid #f3f4f6; padding: 15px 0; }
            .hook-item:last-child { border: none; }
            .v-badge { font-size: 10px; padding: 3px 8px; border-radius: 6px; font-weight: bold; background: #f3f4f6; color: #4b5563; }
            .tag-hint { background: #fffbeb; border: 1px dashed #fcd34d; padding: 10px; border-radius: 8px; font-size: 11px; margin-top: 10px; color: #92400e; line-height: 1.5; }
            .btn-sync { background: #1f2937; color: #fff; border-radius: 8px; padding: 6px 16px; font-size: 12px; border: none; transition: 0.2s; }
            .btn-sync:hover { background: #000; color: #fff; text-decoration: none; }
        </style>

        <div class="wpsend-container">
            <?php if (version_compare($remoteCoreV, $currentCoreV, '>')): ?>
                <div class="alert alert-info" style="display:flex; justify-content:space-between; align-items:center; border-left: 6px solid #2563eb;">
                    <span>🚀 <strong>New Version Available: v<?=$remoteCoreV?></strong> (GitHub-এ নতুন আপডেট পাওয়া গেছে)</span>
                    <a href="?module=wpsend&action=upgrade_core" class="btn btn-primary btn-sm">Update wpsend.php</a>
                </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="v-card">
                        <h3 style="margin:0 0 20px 0; font-weight:700;">💎 Master Template Hub</h3>
                        <form method="post">
                            <?php 
                            if($githubHooks && is_array($githubHooks)):
                                foreach ($githubHooks as $gh): 
                                    if(!isset($gh['name'])) continue;
                                    $hName = $gh['name'];
                                    $localFile = $hooksDir . $hName;
                                    $db = Capsule::table('mod_wpsend_hooks')->where('hook_file', $hName)->first();
                                    
                                    $locV = $db ? $db->local_version : '0.0.0';
                                    $tags = '{name}, {id}, {email}';
                                    if(file_exists($localFile)) {
                                        $meta = wpsend_parse_meta_data(file_get_contents($localFile));
                                        $tags = $meta['tags'];
                                    }
                            ?>
                            <div class="hook-item">
                                <div style="display:flex; justify-content:space-between; align-items:center;">
                                    <strong><i class="fa fa-code-fork"></i> <?=$hName?> <span class="v-badge">v<?=$locV?></span></strong>
                                    <a href="?module=wpsend&action=sync_hook&file=<?=$hName?>" class="btn-sync">Sync / Update</a>
                                </div>
                                <?php if($db): ?>
                                    <textarea name="msg[<?=$hName?>]" class="form-control" rows="2" style="margin-top:12px; border-radius:10px;"><?=$db->message?></textarea>
                                    <div class="tag-hint"><strong>Available Tags:</strong> <?=$tags?></div>
                                    <label style="font-weight:normal; margin-top:10px; cursor:pointer;"><input type="checkbox" name="notify[<?=$hName?>]" <?=$db->admin_notify ? 'checked' : ''?>> Notify Admin (<?=$vars['admin_mobile']?>)</label>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; 
                            else: echo "<p class='alert alert-warning'>GitHub API limits reached or Repo not found. Please refresh after a minute.</p>";
                            endif; ?>
                            <button type="submit" name="save_templates" class="btn btn-success btn-lg btn-block" style="margin-top:20px; border-radius:12px; font-weight:700;">💾 Save All VIP Settings</button>
                        </form>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="v-card" style="background:#111827; color:#fff;">
                        <small style="opacity:0.6; letter-spacing:1px;">MASTER VERSION</small>
                        <h1 style="margin:10px 0; font-weight:800; color:#fbbf24;">v<?=$currentCoreV?></h1>
                        <div style="font-size:12px; opacity:0.8;">Status: Secure VIP Connected</div>
                    </div>
                    <div class="v-card">
                        <h4 style="margin-top:0; font-weight:700;"><i class="fa fa-history"></i> Recent Logs</h4>
                        <table class="table table-hover" style="font-size:12px;">
                            <?php foreach($logs as $l): ?>
                                <tr><td><?=$l->to_num?></td><td class="text-right"><span class="label label-<?=($l->status=='Sent'?'success':'default')?>"><?=$l->status?></span></td></tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// AUTO LOADER: hooks/ ফোল্ডার থেকে ফাইলগুলো লোড করবে
$hooks = glob(__DIR__ . '/hooks/*.php');
if ($hooks) {
    foreach ($hooks as $h) {
        if (basename($h) !== 'wpsend.php') include_once $h;
    }
}
