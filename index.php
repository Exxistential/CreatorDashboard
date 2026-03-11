<?php
ini_set('memory_limit', '32M');
// ── AJAX endpoints — must be first, before any HTML output ───────────────────
$guiCfgPath  = __DIR__ . '/gui_config.json';
$pidFilePath = __DIR__ . '/creator.pid';

function sendDiscordWebhook(string $url, array $embed): void {
    if (!$url) return;
    $payload = json_encode(['embeds' => [$embed]]);
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n",
        'content'       => $payload,
        'timeout'       => 5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
}

if (isset($_GET['status_check']) || isset($_GET['log_tail']) || isset($_GET['discord_notify']) || isset($_GET['discord_test'])) {
    $gcfg = file_exists($guiCfgPath) ? (json_decode(file_get_contents($guiCfgPath), true) ?? []) : [];
    $cdir = rtrim($gcfg['creator_dir'] ?? '', '/\\');
    $lf   = $cdir ? $cdir . DIRECTORY_SEPARATOR . 'creator_output.log' : '';

    if (isset($_GET['status_check'])) {
        header('Content-Type: application/json');
        $pid = file_exists($pidFilePath) ? (int)file_get_contents($pidFilePath) : 0;
        $on  = false;
        if ($pid > 0) {
            if (strtoupper(substr(PHP_OS,0,3)) === 'WIN') {
                $out = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
                $on  = $out && str_contains($out, (string)$pid);
            } else {
                $on = file_exists("/proc/{$pid}");
            }
        }
        if (!$on && file_exists($pidFilePath)) @unlink($pidFilePath);
        // Also clean up lockfile if it exists but process is gone
        $lf2 = dirname($pidFilePath) . '/creator.lock';
        if ($on === false && file_exists($lf2)) {
            // Only remove lock if pid is gone (not just missing)
            if (!file_exists($pidFilePath)) @unlink($lf2);
        }
        echo json_encode(['running' => $on]);
        exit;
    }

    if (isset($_GET['log_tail'])) {
        header('Content-Type: application/json');
        $lines = []; $created = 0; $failed = 0; $totalMb = 0.0;
        if ($lf && file_exists($lf) && ($fh = @fopen($lf, 'r')) !== false) {
            while (($ln = fgets($fh)) !== false) {
                $ln = rtrim($ln);
                if ($ln === '') continue;
                $lines[] = $ln;
                if (count($lines) > 120) array_shift($lines);
                if (stripos($ln, 'SUCCESS') !== false && preg_match('/Total data used: ([\d.]+)MB/i', $ln, $m)) {
                    $totalMb += (float)$m[1]; $created++;
                }
                if (preg_match('/Failed creations:\s*([1-9]\d*)/i', $ln, $m)) {
                    $failed += (int)$m[1];
                }
            }
            fclose($fh);
        }
        $avgMb = $created > 0 ? round($totalMb / $created, 2) : 0;
        $html = '';
        foreach ($lines as $line) {
            $cls = '';
            if (stripos($line,'success') !== false || stripos($line,'created') !== false) $cls = 'log-ok';
            elseif (stripos($line,'error') !== false || stripos($line,'fail') !== false)   $cls = 'log-err';
            elseif (stripos($line,'warning') !== false)                                     $cls = 'log-warn';
            elseif (stripos($line,'INFO') !== false)                                        $cls = 'log-info';
            $html .= '<div class="' . htmlspecialchars($cls) . '">' . htmlspecialchars($line) . "</div>\n";
        }
        echo json_encode(['html'=>$html,'lines'=>count($lines),'created'=>$created,'failed'=>$failed,'total_mb'=>round($totalMb,2),'avg_mb'=>$avgMb]);
        exit;
    }

    if (isset($_GET['discord_notify'])) {
        header('Content-Type: application/json');
        $webhook = trim($gcfg['discord_webhook'] ?? '');
        $created = (int)($_GET['created']  ?? 0);
        $failed  = (int)($_GET['failed']   ?? 0);
        $totalMb = (float)($_GET['total_mb'] ?? 0);
        $avgMb   = (float)($_GET['avg_mb']   ?? 0);
        $total   = $created + $failed;
        $rate    = $total > 0 ? round($created / $total * 100, 1) : 0;
        if ($webhook) {
            $color = $failed === 0 ? 3066993 : ($created === 0 ? 15158332 : 16776960);
            sendDiscordWebhook($webhook, [
                'title'       => 'Account Creator Finished',
                'color'       => $color,
                'description' => 'Creation run completed.',
                'fields'      => [
                    ['name'=>'Created',            'value'=>(string)$created,       'inline'=>true],
                    ['name'=>'Failed',             'value'=>(string)$failed,        'inline'=>true],
                    ['name'=>'Success Rate',       'value'=>$rate.'%',              'inline'=>true],
                    ['name'=>'Total Data Used',    'value'=>round($totalMb,2).'MB', 'inline'=>true],
                    ['name'=>'Avg Data / Account', 'value'=>$avgMb.'MB',            'inline'=>true],
                ],
                'footer'    => ['text' => 'Jagex Creator - '.date('Y-m-d H:i:s')],
                'timestamp' => gmdate("Y-m-d\TH:i:s\Z"),
            ]);
            echo json_encode(['sent' => true]);
        } else {
            echo json_encode(['sent' => false, 'reason' => 'No webhook configured']);
        }
        exit;
    }

    // Test webhook — posts directly from the server so there are no CORS issues
    if (isset($_GET['discord_test'])) {
        header('Content-Type: application/json');
        $webhook = trim($gcfg['discord_webhook'] ?? '');
        if (!$webhook) {
            echo json_encode(['sent'=>false,'reason'=>'No webhook URL saved in settings yet.']);
            exit;
        }
        $payload = json_encode(['embeds'=>[[
            'title'       => 'Webhook Test',
            'color'       => 3066993,
            'description' => 'Connection successful! Alerts from Jagex Creator are working.',
            'footer'      => ['text' => 'Jagex Creator - '.date('Y-m-d H:i:s')],
            'timestamp'   => gmdate("Y-m-d\TH:i:s\Z"),
        ]]]);
        $ctx = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nContent-Length: ".strlen($payload)."\r\n",
            'content'       => $payload,
            'timeout'       => 8,
            'ignore_errors' => true,
        ]]);
        $result = @file_get_contents($webhook, false, $ctx);
        $code = 0;
        if (!empty($http_response_header)) {
            foreach ($http_response_header as $h) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
            }
        }
        if ($code === 204) {
            echo json_encode(['sent'=>true,'code'=>204]);
        } else {
            echo json_encode([
                'sent'   => false,
                'code'   => $code,
                'reason' => $code ? 'Discord returned HTTP '.$code : 'No HTTP response — check server can reach discord.com outbound',
                'detail' => substr((string)$result, 0, 300),
            ]);
        }
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  Jagex Account Creator — GUI (index.php)
// ═══════════════════════════════════════════════════════════════════════════════
$guiConfig = __DIR__ . '/gui_config.json';
$pidFile   = __DIR__ . '/creator.pid';

function loadGui(string $p): array {
    if (!file_exists($p)) return [];
    return json_decode(file_get_contents($p), true) ?? [];
}
function saveGui(string $p, array $d): void {
    file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ── TOML helpers ──────────────────────────────────────────────────────────────
function readToml(string $path): array {
    $cfg = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (preg_match('/^\[(\w+)\]$/', $line)) continue;
        if (!str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if ($v === 'true')  { $cfg[$k] = true;  continue; }
        if ($v === 'false') { $cfg[$k] = false; continue; }
        if (preg_match('/^"(.*)"$/', $v, $m))  { $cfg[$k] = $m[1]; continue; }
        if (preg_match('/^\[(.*)\]$/', $v, $m)) {
            $items = array_map(fn($i) => trim(trim($i), '"\''), array_filter(explode(',', $m[1]), 'strlen'));
            $cfg[$k] = $items; continue;
        }
        if (is_numeric($v)) { $cfg[$k] = str_contains($v,'.') ? (float)$v : (int)$v; continue; }
        $cfg[$k] = $v;
    }
    return $cfg;
}

function buildToml(array $cfg, string $efKey): string {
    $bool = fn($v) => $v ? 'true' : 'false';
    $str  = fn($v) => '"' . addslashes((string)$v) . '"';
    $arr  = fn($a) => '[' . implode(', ', array_map(fn($i)=>'"'.addslashes($i).'"', $a)) . ']';
    $proxies = $cfg['proxies_list'] ?? [];
    $proxyLines = '';
    foreach ($proxies as $p) {
        $proxyLines .= "\n  { ip = \"{$p['ip']}\", port = {$p['port']}" .
            (!empty($p['username']) ? ", username = \"{$p['username']}\", password = \"{$p['password']}\"" : '') . " },";
    }
    $proxyBlock = $proxyLines ? "[\n{$proxyLines}\n]" : '[]';
    return "# Jagex Account Creator config.toml\n" .
        "accounts_to_create     = {$cfg['accounts_to_create']}\n" .
        "threads                = {$cfg['threads']}\n" .
        "log_level              = \"{$cfg['log_level']}\"\n" .
        "headless               = {$bool($cfg['headless'])}\n" .
        "enable_dev_tools       = {$bool($cfg['enable_dev_tools'])}\n" .
        "user_agent             = {$str($cfg['user_agent'])}\n" .
        "element_wait_timeout   = {$cfg['element_wait_timeout']}\n" .
        "cache_update_threshold = {$cfg['cache_update_threshold']}\n" .
        "gproxy_log_level       = \"{$cfg['gproxy_log_level']}\"\n" .
        "mail_provider          = \"{$cfg['mail_provider']}\"\n" .
        "use_proxy_for_temp_mail = {$bool($cfg['use_proxy_for_temp_mail'])}\n" .
        "imap_ip                = {$str($cfg['imap_ip'])}\n" .
        "imap_port              = {$cfg['imap_port']}\n" .
        "imap_email             = {$str($cfg['imap_email'])}\n" .
        "imap_password          = {$str($cfg['imap_password'])}\n" .
        "imap_domains           = {$str($cfg['imap_domains'])}\n" .
        "gw_domains             = {$arr($cfg['gw_domains'])}\n" .
        "username_length        = {$cfg['username_length']}\n" .
        "account_password       = {$str($cfg['account_password'])}\n" .
        "random_password_length = {$cfg['random_password_length']}\n" .
        "set_2fa                = {$bool($cfg['set_2fa'])}\n" .
        "ef_api_key             = {$str($efKey)}\n\n" .
        "proxies_list = {$proxyBlock}\n";
}

function convertProxies(string $raw): array {
    $proxies = []; $errors = [];
    foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
        $parts = array_map('trim', explode(':', $line));
        if (count($parts) === 2) {
            $proxies[] = ['ip'=>$parts[0],'port'=>(int)$parts[1],'username'=>'','password'=>''];
        } elseif (count($parts) === 4) {
            $proxies[] = ['ip'=>$parts[0],'port'=>(int)$parts[1],'username'=>$parts[2],'password'=>$parts[3]];
        } else {
            $errors[] = "Bad format: $line";
        }
    }
    return ['proxies'=>$proxies,'errors'=>$errors];
}

function isWindows(): bool { return strtoupper(substr(PHP_OS,0,3)) === 'WIN'; }

function findUv(array $gui): string {
    if (!empty($gui['uv_path']) && file_exists($gui['uv_path'])) return $gui['uv_path'];
    $candidates = isWindows()
        ? [getenv('USERPROFILE').'\.local\bin\uv.exe']
        : ['/home/'.get_current_user().'/.local/bin/uv', '/usr/local/bin/uv', trim((string)shell_exec('which uv 2>/dev/null'))];
    foreach ($candidates as $c) { if ($c && file_exists($c)) return $c; }
    return 'uv';
}

function isRunning(string $pidFile): bool {
    // Use a lockfile alongside the pidfile — more reliable than PID tracking
    // since uv spawns child processes and the tracked PID may exit early.
    $lockFile = dirname($pidFile) . '/creator.lock';
    if (file_exists($lockFile)) return true;
    // Fallback: if no lockfile, check pid the old way
    if (!file_exists($pidFile)) return false;
    $pid = (int)file_get_contents($pidFile);
    if ($pid <= 0) return false;
    if (isWindows()) {
        $out = shell_exec('tasklist /FI "IMAGENAME eq uv.exe" /NH 2>NUL');
        if ($out && str_contains($out, 'uv.exe')) return true;
        $out2 = shell_exec('tasklist /FI "PID eq '.$pid.'" /NH 2>NUL');
        return $out2 && str_contains($out2, (string)$pid);
    }
    return file_exists("/proc/{$pid}");
}

// ── Load config ───────────────────────────────────────────────────────────────
$gui        = loadGui($guiConfig);
$creatorDir = rtrim($gui['creator_dir'] ?? '', '/\\');
$efApiKey   = $gui['ef_api_key']      ?? '';
$discordWh  = $gui['discord_webhook'] ?? '';
$configPath = $creatorDir ? $creatorDir . '/config.toml' : '';
$toml       = ($configPath && file_exists($configPath)) ? readToml($configPath) : [];
$running    = isRunning($pidFile);
$logFile    = $creatorDir ? $creatorDir . DIRECTORY_SEPARATOR . 'creator_output.log' : '';
$logLines   = [];
if ($logFile && file_exists($logFile) && ($fh = @fopen($logFile,'r')) !== false) {
    while (($ln = fgets($fh)) !== false) {
        $ln = rtrim($ln); if ($ln==='') continue;
        $logLines[] = $ln; if (count($logLines)>120) array_shift($logLines);
    }
    fclose($fh);
}
$proxyList = $gui['pending_proxies'] ?? ($toml['proxies_list'] ?? []);

// ── POST handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_gui') {
        $gui['creator_dir']     = rtrim(trim($_POST['creator_dir'] ?? ''), '/\\');
        $gui['ef_api_key']      = trim($_POST['ef_api_key'] ?? '');
        $gui['uv_path']         = trim($_POST['uv_path'] ?? '');
        $gui['python_path']     = trim($_POST['python_path'] ?? '');
        $gui['discord_webhook'] = trim($_POST['discord_webhook'] ?? '');
        saveGui($guiConfig, $gui);
        header('Location: '.$_SERVER['PHP_SELF'].'?saved=gui'); exit;
    }

    if ($action === 'save_config' && $configPath) {
        $c = [
            'accounts_to_create'     => max(1,(int)$_POST['accounts_to_create']),
            'threads'                => max(1,(int)$_POST['threads']),
            'log_level'              => in_array($_POST['log_level'],['DEBUG','INFO','WARNING','ERROR']) ? $_POST['log_level'] : 'INFO',
            'headless'               => isset($_POST['headless']),
            'enable_dev_tools'       => isset($_POST['enable_dev_tools']),
            'user_agent'             => trim($_POST['user_agent'] ?? ''),
            'element_wait_timeout'   => max(5,(int)$_POST['element_wait_timeout']),
            'cache_update_threshold' => max(0.0,min(1.0,(float)$_POST['cache_update_threshold'])),
            'gproxy_log_level'       => in_array($_POST['gproxy_log_level'],['DEBUG','INFO','WARNING','ERROR']) ? $_POST['gproxy_log_level'] : 'INFO',
            'mail_provider'          => in_array($_POST['mail_provider'],['xitroo','guerrilla_mail','imap']) ? $_POST['mail_provider'] : 'xitroo',
            'use_proxy_for_temp_mail'=> isset($_POST['use_proxy_for_temp_mail']),
            'imap_ip'                => trim($_POST['imap_ip'] ?? ''),
            'imap_port'              => max(1,(int)($_POST['imap_port'] ?? 993)),
            'imap_email'             => trim($_POST['imap_email'] ?? ''),
            'imap_password'          => trim($_POST['imap_password'] ?? ''),
            'imap_domains'           => trim($_POST['imap_domains'] ?? ''),
            'gw_domains'             => array_values(array_filter(array_map('trim', explode("\n", $_POST['gw_domains'] ?? '')))),
            'username_length'        => max(6,min(20,(int)$_POST['username_length'])),
            'account_password'       => trim($_POST['account_password'] ?? ''),
            'random_password_length' => max(8,min(32,(int)$_POST['random_password_length'])),
            'set_2fa'                => isset($_POST['set_2fa']),
            'proxies_list'           => json_decode($_POST['proxies_json'] ?? '[]', true) ?: [],
        ];
        file_put_contents($configPath, buildToml($c, $efApiKey));
        header('Location: '.$_SERVER['PHP_SELF'].'?saved=config'); exit;
    }

    if ($action === 'apply_proxies') {
        $conv = convertProxies($_POST['proxy_raw'] ?? '');
        $gui['pending_proxies']      = $conv['proxies'];
        $gui['pending_proxy_errors'] = $conv['errors'];
        saveGui($guiConfig, $gui);
        header('Location: '.$_SERVER['PHP_SELF'].'?saved=proxies'); exit;
    }

    if ($action === 'start' && $creatorDir && !$running) {
        $uv = findUv($gui);
        $py = $gui['python_path'] ?? '';
        if (!$py) $py = isWindows() ? 'pythonw' : 'python3';

        $launcherPath = $creatorDir . DIRECTORY_SEPARATOR . 'gui_launcher.py';
        $logPath      = $creatorDir . DIRECTORY_SEPARATOR . 'creator_output.log';
        $uvEsc        = addslashes($uv);
        $dirEsc       = addslashes($creatorDir);
        $logEsc       = addslashes($logPath);

        file_put_contents($launcherPath,
            "import subprocess, sys, os\n" .
            "os.chdir(r\"{$dirEsc}\")\n" .
            "log = open(r\"{$logEsc}\", \"a\")\n" .
            "CREATE_NO_WINDOW = 0x08000000\n" .
            "DETACHED_PROCESS = 0x00000008\n" .
            "flags = (CREATE_NO_WINDOW | DETACHED_PROCESS) if sys.platform == 'win32' else 0\n" .
            "p = subprocess.Popen(\n" .
            "    [r\"{$uvEsc}\", \"run\", \"main.py\"],\n" .
            "    cwd=r\"{$dirEsc}\",\n" .
            "    stdout=log, stderr=log,\n" .
            "    creationflags=flags,\n" .
            "    close_fds=True,\n" .
            ")\n" .
            "print(p.pid)\n" .
            "sys.stdout.flush()\n"
        );

        $spec = [1 => ['pipe','r'], 2 => ['pipe','r']];
        $proc = proc_open([$py, $launcherPath], $spec, $pipes, $creatorDir);
        if ($proc) {
            $pid = (int)trim(stream_get_contents($pipes[1]));
            fclose($pipes[1]); fclose($pipes[2]);
            proc_close($proc);
            if ($pid > 0) file_put_contents($pidFile, $pid);
        }
        // Write a lockfile so isRunning() can detect the process reliably
        $lockFile = dirname($pidFile) . '/creator.lock';
        file_put_contents($lockFile, date('Y-m-d H:i:s'));
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }

    if ($action === 'stop' && $running) {
        $pid = (int)file_get_contents($pidFile);
        if (isWindows()) shell_exec('taskkill /FI "IMAGENAME eq uv.exe" /F 2>NUL');
        else             shell_exec('kill -TERM '.$pid.' 2>/dev/null');
        @unlink($pidFile);
        @unlink(dirname($pidFile) . '/creator.lock');
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }

    if ($action === 'clear_log' && $logFile) {
        @file_put_contents($logFile, '');
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Creator GUI</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#08090b;--surface:#0d1017;--card:#111720;--card2:#151d28;--border:#1a2335;
  --accent:#00ff88;--accent2:#00c8ff;--danger:#ff3b5c;--warn:#ffb82e;--purple:#a78bfa;
  --muted:#3a4a5c;--text:#c0d4e8;--text2:#5a7a94;
  --mono:'Share Tech Mono',monospace;--head:'Rajdhani',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--head);font-size:14px;min-height:100vh}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:9999;
  background:repeating-linear-gradient(0deg,transparent,transparent 2px,rgba(0,255,136,.01) 2px,rgba(0,255,136,.01) 4px)}
nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;display:flex;align-items:stretch}
.nav-logo{display:flex;align-items:center;gap:10px;padding:12px 20px 12px 0;border-right:1px solid var(--border);margin-right:8px}
.nav-logo .mark{width:28px;height:28px;border:2px solid var(--accent);border-radius:5px;display:grid;place-items:center;font-family:var(--mono);font-size:13px;color:var(--accent);text-shadow:0 0 6px var(--accent)}
.nav-logo span{font-size:16px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.nav-logo span em{color:var(--accent);font-style:normal}
.nav-link{display:flex;align-items:center;padding:0 18px;font-family:var(--mono);font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);text-decoration:none;border-bottom:2px solid transparent;transition:all .15s}
.nav-link:hover{color:var(--text)}
.nav-link.active{color:var(--accent);border-bottom-color:var(--accent)}
.nav-right{margin-left:auto;display:flex;align-items:center;gap:14px}
.clock{font-family:var(--mono);font-size:11px;color:var(--text2)}
.wrap{max-width:1200px;margin:0 auto;padding:24px}
.cols{display:grid;grid-template-columns:1fr 1fr;gap:20px}
@media(max-width:900px){.cols{grid-template-columns:1fr}}
.panel{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;margin-bottom:20px}
.panel-top-accent{height:2px}
.panel-top-accent.green{background:linear-gradient(90deg,var(--accent),transparent)}
.panel-top-accent.red{background:linear-gradient(90deg,var(--danger),transparent)}
.panel-top-accent.blue{background:linear-gradient(90deg,var(--accent2),transparent)}
.panel-top-accent.yellow{background:linear-gradient(90deg,var(--warn),transparent)}
.panel-head{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;
  background:linear-gradient(90deg,rgba(0,255,136,.03),transparent 60%)}
.panel-head h2{font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.ph-sub{margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text2)}
.panel-body{padding:18px}
label{display:block;font-family:var(--mono);font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--text2);margin-bottom:6px}
.fg{margin-bottom:14px}
.hint{font-family:var(--mono);font-size:9px;color:var(--muted);margin-top:4px}
input[type=text],input[type=password],input[type=number],select,textarea{
  width:100%;background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-family:var(--mono);font-size:12px;padding:8px 11px;border-radius:4px;outline:none;transition:border-color .15s}
input:focus,select:focus,textarea:focus{border-color:rgba(0,255,136,.35)}
textarea{resize:vertical;min-height:80px}
.checkbox-row{display:flex;align-items:center;gap:8px;margin-bottom:10px}
.checkbox-row input[type=checkbox]{width:auto;accent-color:var(--accent)}
.checkbox-row label{margin:0;font-size:10px;letter-spacing:1px}
.section-lbl{font-family:var(--mono);font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--text2);margin-bottom:8px}
.divider{border:none;border-top:1px solid var(--border);margin:14px 0}
.discord-section{border-top:1px solid var(--border);padding-top:14px;margin-top:6px}
.btn{padding:7px 16px;border-radius:4px;border:none;font-family:var(--head);font-size:12px;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s}
.btn-accent{background:var(--accent);color:#000}
.btn-accent:hover{background:#00e67a}
.btn-danger{background:rgba(255,59,92,.15);color:var(--danger);border:1px solid rgba(255,59,92,.3)}
.btn-danger:hover{background:rgba(255,59,92,.25)}
.btn-warn{background:rgba(255,184,46,.12);color:var(--warn);border:1px solid rgba(255,184,46,.25)}
.btn-warn:hover{background:rgba(255,184,46,.2)}
.btn-subtle{background:rgba(255,255,255,.04);color:var(--text2);border:1px solid var(--border)}
.btn-subtle:hover{background:rgba(255,255,255,.08);color:var(--text)}
.btn:disabled{opacity:.35;cursor:not-allowed}
.btn-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.banner{background:rgba(255,184,46,.06);border:1px solid rgba(255,184,46,.2);border-radius:6px;
  padding:10px 16px;margin-bottom:18px;font-family:var(--mono);font-size:11px;color:var(--warn)}
.banner-ok{background:rgba(0,255,136,.05);border-color:rgba(0,255,136,.2);color:var(--accent)}
.status-row{display:flex;align-items:center;gap:12px;margin-bottom:16px;padding:12px 14px;
  background:var(--card2);border-radius:6px;border:1px solid var(--border)}
.status-dot{width:10px;height:10px;border-radius:50%;background:var(--danger);flex-shrink:0}
.status-dot.running{background:var(--accent);box-shadow:0 0 8px var(--accent);animation:pulse 1.5s infinite}
.status-label{font-family:var(--mono);font-size:11px;font-weight:700;letter-spacing:2px;color:var(--danger)}
.proc-label{font-family:var(--mono);font-size:10px;color:var(--text2);margin-left:auto}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.log-box{background:#060810;border:1px solid var(--border);border-radius:4px;padding:12px;
  font-family:var(--mono);font-size:11px;line-height:1.6;height:340px;overflow-y:auto;
  white-space:pre-wrap;word-break:break-all}
.log-ok{color:var(--accent)}.log-err{color:var(--danger)}.log-warn{color:var(--warn)}.log-info{color:var(--text2)}
.bw-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px}
.bw-card{background:var(--card2);border:1px solid var(--border);border-radius:6px;padding:10px 12px}
.bw-label{font-family:var(--mono);font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--text2);margin-bottom:4px}
.bw-val{font-family:var(--mono);font-size:18px;color:var(--accent2)}
.proxy-err{color:var(--danger);font-family:var(--mono);font-size:10px;margin-top:4px}
.proxy-list{max-height:120px;overflow-y:auto;background:var(--card2);border:1px solid var(--border);
  border-radius:4px;padding:8px;font-family:var(--mono);font-size:10px;color:var(--text2);white-space:pre}
</style>
</head>
<body>
<nav>
  <div class="nav-logo">
    <div class="mark">J</div>
    <span>Jagex <em>Creator</em></span>
  </div>
  <a class="nav-link active" href="index.php">Creator GUI</a>
  <a class="nav-link" href="dashboard.php">Account Dashboard</a>
  <a class="nav-link" href="agents.php">Agent Monitor</a>
  <a class="nav-link" href="accounts.php">Accounts</a>
  <a class="nav-link" href="lifespan.php">Playtime</a>
  <a class="nav-link" href="mover.php">Trade Mover</a>
  <div class="nav-right">
    <div class="clock" id="clockEl"></div>
  </div>
</nav>

<div class="wrap">

<?php if (isset($_GET['saved'])): $msgs=['gui'=>'Settings saved.','config'=>'config.toml saved.','proxies'=>'Proxies converted — save config to apply.']; ?>
  <div class="banner banner-ok"><?= htmlspecialchars($msgs[$_GET['saved']] ?? 'Saved.') ?></div>
<?php endif; ?>

<?php if (!$creatorDir || !file_exists($creatorDir.'/config.toml')): ?>
  <div class="banner">&#9888; Set your creator directory below to load config.toml</div>
<?php endif; ?>

<div class="cols">
<div><!-- LEFT -->

  <!-- Settings -->
  <div class="panel">
    <div class="panel-top-accent blue"></div>
    <div class="panel-head"><h2>&#9881; Settings</h2><span class="ph-sub">gui_config.json</span></div>
    <div class="panel-body">
      <form method="post">
        <input type="hidden" name="action" value="save_gui">
        <div class="fg">
          <label>Creator Directory (absolute path)</label>
          <input type="text" name="creator_dir" value="<?= htmlspecialchars($creatorDir) ?>"
            placeholder="/home/user/jagex_account_creator">
          <div class="hint">Folder containing main.py and config.toml</div>
        </div>
        <div class="fg">
          <label>EternalFarm API Key</label>
          <input type="password" name="ef_api_key" value="<?= htmlspecialchars($efApiKey) ?>"
            placeholder="Paste your EF API key…" autocomplete="off">
          <div class="hint">Used for Account Dashboard and written to config.toml</div>
        </div>
        <div class="fg">
          <label>uv Executable Path <span style="font-weight:400;color:var(--muted)">(optional — leave blank to auto-detect)</span></label>
          <input type="text" name="uv_path" value="<?= htmlspecialchars($gui['uv_path'] ?? '') ?>"
            placeholder="e.g. C:\Users\you\.local\bin\uv.exe">
          <div class="hint">Find it: where uv (Windows) / which uv (Linux)</div>
        </div>
        <div class="fg">
          <label>Python Executable Path <span style="font-weight:400;color:var(--muted)">(optional — leave blank to auto-detect)</span></label>
          <input type="text" name="python_path" value="<?= htmlspecialchars($gui['python_path'] ?? '') ?>"
            placeholder="e.g. C:\…\pythonw.exe">
          <div class="hint">Windows: use pythonw.exe to avoid a terminal window popping up</div>
        </div>

        <div class="discord-section">
          <div class="section-lbl">Discord Alerts</div>
          <div class="fg">
            <label>Webhook URL <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
            <input type="text" name="discord_webhook"
              value="<?= htmlspecialchars($discordWh) ?>"
              placeholder="https://discord.com/api/webhooks/…"
              autocomplete="off">
            <div class="hint">Alerts: creator finished &middot; agent offline &middot; agent underutilized &middot; ban wave detected</div>
          </div>
        </div>

        <div class="btn-row">
          <button type="submit" class="btn btn-accent">Save Settings</button>
          <?php if ($discordWh): ?>
          <button type="button" class="btn btn-subtle" onclick="testWebhook(this)">Test Webhook</button>
          <?php endif; ?>
        </div>
      </form>
    </div>
  </div>

  <!-- Process Control -->
  <div class="panel">
    <div class="panel-top-accent <?= $running ? 'green' : 'red' ?>"></div>
    <div class="panel-head">
      <h2>&#9654; Process Control</h2>
      <span class="ph-sub"><?= $creatorDir ? htmlspecialchars(basename($creatorDir)) : 'no dir set' ?></span>
    </div>
    <div class="panel-body">
      <div class="status-row">
        <div class="status-dot <?= $running ? 'running' : '' ?>"></div>
        <span class="status-label"><?= $running ? 'RUNNING' : 'STOPPED' ?></span>
        <span class="proc-label" id="procLbl">Status: <?= $running ? 'RUNNING' : 'STOPPED' ?></span>
      </div>
      <div class="btn-row">
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="start">
          <button type="submit" class="btn btn-accent" id="btnStart" <?= (!$creatorDir||$running)?'disabled':'' ?>>Start</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="stop">
          <button type="submit" class="btn btn-danger" id="btnStop" <?= !$running?'disabled':'' ?>>Stop</button>
        </form>
        <form method="post" style="display:inline">
          <input type="hidden" name="action" value="clear_log">
          <button type="submit" class="btn btn-subtle"
            onclick="return confirm('Clear the log file?')">Clear Log</button>
        </form>
      </div>
    </div>
  </div>

  <!-- Run Stats -->
  <div class="panel">
    <div class="panel-top-accent yellow"></div>
    <div class="panel-head"><h2>&#128202; Run Stats</h2><span class="ph-sub">from creator_output.log</span></div>
    <div class="panel-body">
      <div class="bw-row">
        <div class="bw-card"><div class="bw-label">Created</div><div class="bw-val" id="bw-created">—</div></div>
        <div class="bw-card"><div class="bw-label">Failed</div><div class="bw-val" id="bw-failed">—</div></div>
        <div class="bw-card"><div class="bw-label">Total Data</div><div class="bw-val" id="bw-total">—</div></div>
        <div class="bw-card"><div class="bw-label">Avg / Acct</div><div class="bw-val" id="bw-avg">—</div></div>
      </div>
    </div>
  </div>

  <!-- Proxy Converter -->
  <div class="panel">
    <div class="panel-top-accent blue"></div>
    <div class="panel-head"><h2>&#128260; Proxy Converter</h2></div>
    <div class="panel-body">
      <form method="post">
        <input type="hidden" name="action" value="apply_proxies">
        <div class="fg">
          <label>Raw Proxies (ip:port or ip:port:user:pass, one per line)</label>
          <textarea name="proxy_raw" rows="5" placeholder="1.2.3.4:8080&#10;5.6.7.8:3128:user:pass"></textarea>
        </div>
        <button type="submit" class="btn btn-warn" <?= !$configPath?'disabled':'' ?>>Convert &amp; Stage</button>
      </form>
      <?php if (!empty($gui['pending_proxy_errors'])): ?>
        <div class="proxy-err"><?= implode('<br>', array_map('htmlspecialchars', $gui['pending_proxy_errors'])) ?></div>
      <?php endif; ?>
      <?php if ($proxyList): ?>
        <div class="section-lbl" style="margin-top:12px"><?= count($proxyList) ?> proxies staged — save config.toml to apply</div>
        <div class="proxy-list"><?php foreach ($proxyList as $p) echo htmlspecialchars("{$p['ip']}:{$p['port']}" . (!empty($p['username'])?" [{$p['username']}]":'')) . "\n"; ?></div>
      <?php endif; ?>
    </div>
  </div>

</div><!-- /left -->

<div><!-- RIGHT -->

  <!-- Live Log -->
  <div class="panel">
    <div class="panel-top-accent green"></div>
    <div class="panel-head">
      <h2>&#128221; Live Log</h2>
      <span class="ph-sub" id="logLineCount"><?= count($logLines) ?> lines</span>
    </div>
    <div class="panel-body">
      <div class="log-box" id="logBox">
        <?php if ($logLines): foreach ($logLines as $ln):
          $cls = '';
          if (stripos($ln,'success')!==false||stripos($ln,'created')!==false) $cls='log-ok';
          elseif(stripos($ln,'error')!==false||stripos($ln,'fail')!==false)   $cls='log-err';
          elseif(stripos($ln,'warning')!==false)                               $cls='log-warn';
          elseif(stripos($ln,'INFO')!==false)                                  $cls='log-info';
        ?><div class="<?= $cls ?>"><?= htmlspecialchars($ln) ?></div>
        <?php endforeach; else: ?><div style="color:var(--muted)">No output yet.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <!-- config.toml Editor -->
  <?php if ($configPath && file_exists($configPath)): ?>
  <div class="panel">
    <div class="panel-top-accent blue"></div>
    <div class="panel-head">
      <h2>&#128196; config.toml Editor</h2>
      <span class="ph-sub"><?= htmlspecialchars($configPath) ?></span>
    </div>
    <div class="panel-body">
      <form method="post">
        <input type="hidden" name="action" value="save_config">
        <input type="hidden" name="proxies_json" id="proxiesJsonHidden" value="<?= htmlspecialchars(json_encode($proxyList)) ?>">

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div class="fg">
            <label>Accounts to Create</label>
            <input type="number" name="accounts_to_create" value="<?= (int)($toml['accounts_to_create']??10) ?>" min="1">
          </div>
          <div class="fg">
            <label>Threads</label>
            <input type="number" name="threads" value="<?= (int)($toml['threads']??1) ?>" min="1">
          </div>
          <div class="fg">
            <label>Log Level</label>
            <select name="log_level">
              <?php foreach(['DEBUG','INFO','WARNING','ERROR'] as $lv): ?>
              <option <?= ($toml['log_level']??'INFO')===$lv?'selected':'' ?>><?= $lv ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>gProxy Log Level</label>
            <select name="gproxy_log_level">
              <?php foreach(['DEBUG','INFO','WARNING','ERROR'] as $lv): ?>
              <option <?= ($toml['gproxy_log_level']??'INFO')===$lv?'selected':'' ?>><?= $lv ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Mail Provider</label>
            <select name="mail_provider" id="mailProviderSel">
              <?php foreach(['xitroo','guerrilla_mail','imap'] as $mp): ?>
              <option value="<?= $mp ?>" <?= ($toml['mail_provider']??'xitroo')===$mp?'selected':'' ?>><?= $mp ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="fg">
            <label>Element Wait Timeout (s)</label>
            <input type="number" name="element_wait_timeout" value="<?= (int)($toml['element_wait_timeout']??30) ?>" min="5">
          </div>
          <div class="fg">
            <label>Cache Update Threshold</label>
            <input type="number" name="cache_update_threshold" step="0.01" min="0" max="1"
              value="<?= number_format((float)($toml['cache_update_threshold']??0.5),2) ?>">
          </div>
          <div class="fg">
            <label>Username Length</label>
            <input type="number" name="username_length" value="<?= (int)($toml['username_length']??10) ?>" min="6" max="20">
          </div>
          <div class="fg">
            <label>Account Password</label>
            <input type="text" name="account_password" value="<?= htmlspecialchars($toml['account_password']??'') ?>">
            <div class="hint">Leave blank to generate random passwords</div>
          </div>
          <div class="fg">
            <label>Random Password Length</label>
            <input type="number" name="random_password_length" value="<?= (int)($toml['random_password_length']??12) ?>" min="8" max="32">
          </div>
        </div>

        <div class="fg">
          <label>User Agent</label>
          <input type="text" name="user_agent" value="<?= htmlspecialchars($toml['user_agent']??'') ?>">
        </div>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-bottom:10px">
          <div class="checkbox-row"><input type="checkbox" name="headless" id="ch_headless" <?= !empty($toml['headless'])?'checked':'' ?>><label for="ch_headless">Headless</label></div>
          <div class="checkbox-row"><input type="checkbox" name="enable_dev_tools" id="ch_devtools" <?= !empty($toml['enable_dev_tools'])?'checked':'' ?>><label for="ch_devtools">DevTools</label></div>
          <div class="checkbox-row"><input type="checkbox" name="set_2fa" id="ch_2fa" <?= !empty($toml['set_2fa'])?'checked':'' ?>><label for="ch_2fa">Set 2FA</label></div>
          <div class="checkbox-row"><input type="checkbox" name="use_proxy_for_temp_mail" id="ch_proxymail" <?= !empty($toml['use_proxy_for_temp_mail'])?'checked':'' ?>><label for="ch_proxymail">Proxy for Temp Mail</label></div>
        </div>

        <div id="imap_section" style="display:none">
          <hr class="divider">
          <div class="section-lbl">IMAP Settings</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="fg"><label>IMAP IP</label><input type="text" name="imap_ip" value="<?= htmlspecialchars($toml['imap_ip']??'') ?>"></div>
            <div class="fg"><label>IMAP Port</label><input type="number" name="imap_port" value="<?= (int)($toml['imap_port']??993) ?>"></div>
            <div class="fg"><label>IMAP Email</label><input type="text" name="imap_email" value="<?= htmlspecialchars($toml['imap_email']??'') ?>"></div>
            <div class="fg"><label>IMAP Password</label><input type="password" name="imap_password" value="<?= htmlspecialchars($toml['imap_password']??'') ?>"></div>
          </div>
          <div class="fg"><label>IMAP Domains</label><input type="text" name="imap_domains" value="<?= htmlspecialchars($toml['imap_domains']??'') ?>"></div>
        </div>

        <div id="gw_section" style="display:none">
          <hr class="divider">
          <div class="section-lbl">Guerrilla Mail Domains</div>
          <div class="fg">
            <textarea name="gw_domains" rows="3"><?= htmlspecialchars(implode("\n", $toml['gw_domains']??[])) ?></textarea>
            <div class="hint">One domain per line</div>
          </div>
        </div>

        <div class="btn-row">
          <button type="submit" class="btn btn-accent">Save config.toml</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /right -->
</div><!-- /cols -->
</div><!-- /wrap -->

<script>
(function(){ function t(){ document.getElementById('clockEl').textContent=new Date().toLocaleTimeString('en-US',{hour12:false}); } t(); setInterval(t,1000); })();

function toggleMailSections() {
  const v  = document.getElementById('mailProviderSel')?.value;
  const im = document.getElementById('imap_section');
  const gw = document.getElementById('gw_section');
  if (im) im.style.display = v === 'imap' ? 'block' : 'none';
  if (gw) gw.style.display = v === 'guerrilla_mail' ? 'block' : 'none';
}
document.getElementById('mailProviderSel')?.addEventListener('change', toggleMailSections);
toggleMailSections();

const lb = document.getElementById('logBox');
if (lb) lb.scrollTop = lb.scrollHeight;

function sv(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }
let _prevRunning = null, _lastStats = {}, _discordNotified = false;

setInterval(() => {
  fetch('?log_tail=1').then(r=>r.json()).then(d=>{
    if (lb) { lb.innerHTML = d.html || '<div style="color:var(--muted)">No output yet.</div>'; lb.scrollTop = lb.scrollHeight; }
    const lc = document.getElementById('logLineCount');
    if (lc) lc.textContent = (d.lines||0)+' lines';
    sv('bw-created', d.created ?? 0);
    sv('bw-failed',  d.failed  ?? 0);
    sv('bw-total',   (d.total_mb ?? 0)+'MB');
    sv('bw-avg',     d.created > 0 ? (d.avg_mb+'MB') : '—');
    _lastStats = d;
  }).catch(()=>{});

  fetch('?status_check=1').then(r=>r.json()).then(d=>{
    const on = d.running;
    document.querySelectorAll('.status-dot').forEach(el => el.classList.toggle('running', on));
    document.querySelectorAll('.status-label').forEach(el => { el.innerHTML = on ? 'RUNNING' : 'STOPPED'; });
    const pl = document.getElementById('procLbl');
    if (pl) pl.textContent = 'Status: ' + (on ? 'RUNNING' : 'STOPPED');
    const bs = document.getElementById('btnStart');
    const bk = document.getElementById('btnStop');
    if (bs) bs.disabled = on;
    if (bk) bk.disabled = !on;
    // Fire Discord once when run completes
    if (_prevRunning === true && on === false && !_discordNotified && (_lastStats.created > 0 || _lastStats.failed > 0)) {
      _discordNotified = true;
      const p = new URLSearchParams({discord_notify:1, created:_lastStats.created||0, failed:_lastStats.failed||0, total_mb:_lastStats.total_mb||0, avg_mb:_lastStats.avg_mb||0});
      fetch('?'+p).catch(()=>{});
    }
    if (on && _prevRunning === false) _discordNotified = false;
    _prevRunning = on;
  }).catch(()=>{});
}, 3000);

// Test webhook — routes through PHP to avoid CORS
function testWebhook(btn) {
  const orig = btn.textContent;
  btn.disabled = true;
  btn.textContent = 'Sending…';
  fetch('?discord_test=1')
    .then(r => r.json())
    .then(d => {
      if (d.sent) {
        alert('Test message sent to Discord successfully!');
      } else {
        alert('Failed:\n' + (d.reason || 'Unknown error') + (d.detail ? '\n\nDetail: ' + d.detail : ''));
      }
    })
    .catch(err => alert('Request failed — is PHP running?\n' + err))
    .finally(() => { btn.disabled = false; btn.textContent = orig; });
}
</script>
</body>
</html>
