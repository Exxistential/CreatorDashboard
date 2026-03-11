<?php
ini_set('memory_limit', '32M'); // streaming reads keep actual usage under 4MB
// ── AJAX endpoints — must be first, before any HTML output ───────────────────
$guiCfgPath  = __DIR__ . '/gui_config.json';
$pidFilePath = __DIR__ . '/creator.pid';

function sendDiscordWebhook(string $url, array $embed): void {
    if (!$url) return;
    $payload = json_encode(['embeds' => [$embed]]);
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json
Content-Length: " . strlen($payload) . "
",
        'content' => $payload,
        'timeout' => 5,
        'ignore_errors' => true,
    ]]);
    @file_get_contents($url, false, $ctx);
}

if (isset($_GET['status_check']) || isset($_GET['log_tail'])) {
    $gcfg    = file_exists($guiCfgPath) ? (json_decode(file_get_contents($guiCfgPath), true) ?? []) : [];
    $cdir    = rtrim($gcfg['creator_dir'] ?? '', '/\\');
    $lf      = $cdir ? $cdir . DIRECTORY_SEPARATOR . 'creator_output.log' : '';

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
        echo json_encode(['running' => $on]);
        exit;
    }

    if (isset($_GET['log_tail'])) {
        header('Content-Type: application/json');
        // Stream line-by-line — no full file load into RAM
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

        // Build HTML rows for log
        $html = '';
        foreach ($lines as $line) {
            $cls = '';
            if (stripos($line,'success') !== false || stripos($line,'created') !== false) $cls = 'log-ok';
            elseif (stripos($line,'error') !== false || stripos($line,'fail') !== false) $cls = 'log-err';
            elseif (stripos($line,'warning') !== false) $cls = 'log-warn';
            elseif (stripos($line,'INFO') !== false) $cls = 'log-info';
            $html .= '<div class="' . htmlspecialchars($cls) . '">' . htmlspecialchars($line) . "</div>\n";
        }

        echo json_encode([
            'html'     => $html,
            'lines'    => count($lines),
            'created'  => $created,
            'failed'   => $failed,
            'total_mb' => round($totalMb, 2),
            'avg_mb'   => $avgMb,
        ]);
        exit;
    }

    // Send Discord notification when creator finishes
    if (isset($_GET['discord_notify'])) {
        header('Content-Type: application/json');
        $webhook  = trim($gcfg['discord_webhook'] ?? '');
        $created  = (int)($_GET['created']  ?? 0);
        $failed   = (int)($_GET['failed']   ?? 0);
        $totalMb  = (float)($_GET['total_mb'] ?? 0);
        $avgMb    = (float)($_GET['avg_mb']   ?? 0);
        $total    = $created + $failed;
        $rate     = $total > 0 ? round($created / $total * 100, 1) : 0;
        if ($webhook) {
            $color = $failed === 0 ? 3066993 : ($created === 0 ? 15158332 : 16776960);
            sendDiscordWebhook($webhook, [
                'title'       => '✅ Account Creator Finished',
                'color'       => $color,
                'description' => "Creation run completed.",
                'fields'      => [
                    ['name'=>'✔ Created',       'value'=>(string)$created,              'inline'=>true],
                    ['name'=>'✖ Failed',         'value'=>(string)$failed,               'inline'=>true],
                    ['name'=>'Success Rate',      'value'=>$rate.'%',                     'inline'=>true],
                    ['name'=>'Total Data Used',   'value'=>round($totalMb,2).'MB',        'inline'=>true],
                    ['name'=>'Avg Data / Account','value'=>$avgMb.'MB',                   'inline'=>true],
                ],
                'footer' => ['text' => 'Jagex Creator · '.date('Y-m-d H:i:s')],
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);
            echo json_encode(['sent' => true]);
        } else {
            echo json_encode(['sent' => false, 'reason' => 'No webhook configured']);
        }
        exit;
    }
}

// ═══════════════════════════════════════════════════════════════════════════════
//  Jagex Account Creator — GUI (index.php)
//  Place this file (and dashboard.php) anywhere on a PHP 7.4+ host.
//  Shared config saved to gui_config.json in the same directory.
// ═══════════════════════════════════════════════════════════════════════════════

$guiConfig   = __DIR__ . '/gui_config.json';
$pidFile     = __DIR__ . '/creator.pid';
$logFile     = ''; // set dynamically after creatorDir is known — see below

// ── GUI config helpers ─────────────────────────────────────────────────────────
function loadGui(string $p): array {
    if (!file_exists($p)) return [
        'creator_dir' => '',
        'ef_api_key'  => '',
    ];
    return json_decode(file_get_contents($p), true) ?? [];
}
function saveGui(string $p, array $d): void {
    file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ── TOML helpers (read/write config.toml) ─────────────────────────────────────
function readToml(string $path): array {
    if (!file_exists($path)) return [];
    // Simple TOML parser sufficient for this config structure
    $lines   = file($path, FILE_IGNORE_NEW_LINES);
    $data    = [];
    $section = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        if (preg_match('/^\[([^\]]+)\]$/', $line, $m)) {
            $parts   = explode('.', $m[1]);
            $section = $parts;
            continue;
        }
        if (str_contains($line, '=')) {
            [$k, $v] = explode('=', $line, 2);
            $k = trim($k); $v = trim($v);
            // Strip inline comments
            $v = preg_replace('/#.*$/', '', $v);
            $v = trim($v);
            // Parse value
            if ($v === 'true')  $val = true;
            elseif ($v === 'false') $val = false;
            elseif (is_numeric($v)) $val = $v + 0;
            elseif (preg_match('/^"(.*)"$/', $v, $m2)) $val = $m2[1];
            elseif (preg_match('/^\[/', $v)) $val = $v; // raw array, skip
            else $val = $v;
            $ref = &$data;
            foreach ($section as $s) {
                if (!isset($ref[$s])) $ref[$s] = [];
                $ref = &$ref[$s];
            }
            $ref[$k] = $val;
        }
    }
    return $data;
}

function buildToml(array $cfg, string $efKey): string {
    $proxies = $cfg['proxies_list'] ?? [];
    $proxyEnabled = !empty($proxies) ? 'true' : 'false';

    $proxyLines = '';
    foreach ($proxies as $p) {
        $ip   = $p['ip']   ?? '';
        $port = $p['port'] ?? '';
        $u    = $p['username'] ?? '';
        $pw   = $p['password'] ?? '';
        if ($u)
            $proxyLines .= "    { ip = \"{$ip}\", port = \"{$port}\", username = \"{$u}\", password = \"{$pw}\" },\n";
        else
            $proxyLines .= "    { ip = \"{$ip}\", port = \"{$port}\" },\n";
    }

    $gwDomains = implode(",\n    ", array_map(fn($d) => '"'.$d.'"', $cfg['gw_domains'] ?? [
        "sharklasers.com","guerrillamail.info","grr.la","guerrillamail.biz",
        "guerrillamail.com","guerrillamail.de","guerrillamail.net","guerrillamail.org",
        "pokemail.net","spam4.me"
    ]));

    $imapDomains = implode(', ', array_map(fn($d) => '"'.$d.'"', array_filter(explode("\n", $cfg['imap_domains'] ?? ''))));

    $password   = $cfg['account_password'] ?? '';
    $headless   = ($cfg['headless'] ?? false) ? 'true' : 'false';
    $devTools   = ($cfg['enable_dev_tools'] ?? false) ? 'true' : 'false';
    $set2fa     = ($cfg['set_2fa'] ?? true) ? 'true' : 'false';
    $useProxy4Mail = ($cfg['use_proxy_for_temp_mail'] ?? false) ? 'true' : 'false';

    $efSection = $efKey ? "\n[eternal_farm]\napi_key = \"{$efKey}\"\n" : '';

    return <<<TOML
[account_creator]
accounts_to_create = {$cfg['accounts_to_create']}
threads = {$cfg['threads']}
log_level = "{$cfg['log_level']}"

[browser]
headless = {$headless}
enable_dev_tools = {$devTools}
user_agent = "{$cfg['user_agent']}"
element_wait_timeout = {$cfg['element_wait_timeout']}
cache_update_threshold = {$cfg['cache_update_threshold']}

[gproxy]
log_level = "{$cfg['gproxy_log_level']}"

[email]
mail_provider = "{$cfg['mail_provider']}"
use_proxy_for_temp_mail = {$useProxy4Mail}

[email.guerrilla_mail]
domains = [
    {$gwDomains},
]

[email.imap]
ip = "{$cfg['imap_ip']}"
port = {$cfg['imap_port']}
email = "{$cfg['imap_email']}"
password = "{$cfg['imap_password']}"
domains = [{$imapDomains}]

[account]
username_length = {$cfg['username_length']}
password = "{$password}"
random_password_length = {$cfg['random_password_length']}
set_2fa = {$set2fa}

[proxies]
enabled = {$proxyEnabled}
list = [
{$proxyLines}]
{$efSection}
TOML;
}

// ── OS + uv path helpers ──────────────────────────────────────────────────────
function isWindows(): bool {
    return strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
}

function findUv(array $gui): string {
    // User-specified path takes priority
    if (!empty($gui['uv_path']) && file_exists($gui['uv_path'])) return $gui['uv_path'];
    if (isWindows()) {
        $up = getenv('USERPROFILE') ?: '';
        $ap = getenv('APPDATA')     ?: '';
        $lp = getenv('LOCALAPPDATA')?: '';
        $candidates = [
            $up  . '\.cargo\\bin\\uv.exe',
            $up  . '\.local\\bin\\uv.exe',
            $ap  . '\\uv\\uv.exe',
            $lp  . '\\uv\\uv.exe',
            $lp  . '\\Programs\\uv\\uv.exe',
            'C:\\uv\\uv.exe',
        ];
    } else {
        $home = getenv('HOME') ?: '';
        $candidates = [
            $home . '/.cargo/bin/uv',
            $home . '/.local/bin/uv',
            '/usr/local/bin/uv',
            '/usr/bin/uv',
        ];
    }
    foreach ($candidates as $c) {
        if ($c && file_exists($c)) return $c;
    }
    // Last resort: hope it's in PATH
    return isWindows() ? 'uv.exe' : 'uv';
}

// ── Process helpers ────────────────────────────────────────────────────────────
function isRunning(string $pidFile): bool {
    if (!file_exists($pidFile)) return false;
    $pid = (int)file_get_contents($pidFile);
    if ($pid <= 0) return false;
    if (isWindows()) {
        $out = shell_exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>NUL');
        return $out && str_contains($out, (string)$pid);
    }
    return file_exists("/proc/{$pid}");
}

// ── Parse bandwidth from log ───────────────────────────────────────────────────
function parseBandwidth(string $logFile): array {
    $stats = ['total_mb' => 0.0, 'runs' => [], 'created' => 0, 'failed' => 0];
    if (!file_exists($logFile)) return $stats;
    $fh = @fopen($logFile, 'r');
    if (!$fh) return $stats;
    while (($line = fgets($fh)) !== false) {
        if (stripos($line, 'SUCCESS') !== false && preg_match('/Total data used: ([\d.]+)MB/i', $line, $m)) {
            $stats['total_mb'] += (float)$m[1];
            $stats['runs'][] = ['mb' => (float)$m[1]];
            $stats['created']++;
        }
        if (preg_match('/Failed creations:\s*([1-9]\d*)/i', $line, $m)) {
            $stats['failed'] += (int)$m[1];
        }
    }
    fclose($fh);
    return $stats;
}

// ── Convert proxies (PHP port of convert_proxies.py) ──────────────────────────
function convertProxies(string $raw): array {
    $results = []; $errors = [];
    foreach (explode("\n", trim($raw)) as $line) {
        $line = trim($line);
        if (!$line) continue;
        $parts = explode(':', $line);
        if (count($parts) === 2) {
            $results[] = ['ip'=>$parts[0],'port'=>$parts[1],'username'=>'','password'=>''];
        } elseif (count($parts) === 4) {
            $results[] = ['ip'=>$parts[0],'port'=>$parts[1],'username'=>$parts[2],'password'=>$parts[3]];
        } else {
            $errors[] = "Invalid format: {$line}";
        }
    }
    return ['proxies'=>$results, 'errors'=>$errors];
}

// ══ Load state ═════════════════════════════════════════════════════════════════
$gui = loadGui($guiConfig);
$creatorDir = rtrim($gui['creator_dir'] ?? '', '/\\');
$efApiKey   = $gui['ef_api_key'] ?? '';
// Log lives inside the creator folder so Apache never holds a lock on it
$logFile    = $creatorDir ? $creatorDir . DIRECTORY_SEPARATOR . 'creator_output.log' : __DIR__ . '/creator_output.log';
$configPath = $creatorDir ? $creatorDir . '/config.toml' : '';
$toml       = ($configPath && file_exists($configPath)) ? readToml($configPath) : [];
$running    = isRunning($pidFile);
$bw         = parseBandwidth($logFile);

// ══ POST handler ═══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save GUI settings (creator dir + EF key)
    if ($action === 'save_gui') {
        $gui['creator_dir']      = rtrim(trim($_POST['creator_dir'] ?? ''), '/\\');
        $gui['ef_api_key']       = trim($_POST['ef_api_key'] ?? '');
        $gui['uv_path']          = trim($_POST['uv_path'] ?? '');
        $gui['python_path']      = trim($_POST['python_path'] ?? '');
        $gui['discord_webhook']  = trim($_POST['discord_webhook'] ?? '');
        saveGui($guiConfig, $gui);
        header('Location: '.$_SERVER['PHP_SELF'].'?saved=gui'); exit;
    }

    // Save config.toml
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

    // Convert + apply proxies
    if ($action === 'apply_proxies' && $configPath) {
        $raw  = $_POST['proxy_raw'] ?? '';
        $conv = convertProxies($raw);
        // Re-read toml and inject proxies
        $existing = readToml($configPath);
        // Merge current form values from hidden fields
        $pj = json_encode($conv['proxies']);
        // Store proxy list in gui config temporarily so save_config can pick it up
        $gui['pending_proxies']      = $conv['proxies'];
        $gui['pending_proxy_errors'] = $conv['errors'];
        saveGui($guiConfig, $gui);
        header('Location: '.$_SERVER['PHP_SELF'].'?saved=proxies'); exit;
    }

    // Start creator
    if ($action === 'start' && $creatorDir && !$running) {
        $uv = findUv($gui);

        if (isWindows()) {
            // Write a small Python launcher script.
            // Python opens the log file itself — completely independent of Apache/PHP.
            // This avoids ALL Windows file-locking issues with bat/cmd redirection.
            $launcher = $creatorDir . '\\gui_launcher.py';
            $py  = "import subprocess, sys, os\n";
            $py .= "os.chdir(r'" . $creatorDir . "')\n";
            $py .= "log = open(r'" . $logFile . "', 'w')\n";
            $py .= "p = subprocess.Popen(\n";
            $py .= "    [r'" . $uv . "', 'run', 'main.py'],\n";
            $py .= "    stdout=log, stderr=log,\n";
            $py .= "    cwd=r'" . $creatorDir . "',\n";
            $py .= "    creationflags=0x00000008  # DETACHED_PROCESS\n";
            $py .= ")\n";
            $py .= "print(p.pid)\n";
            $py .= "log.close()\n";
            file_put_contents($launcher, $py);

            // Run the launcher with pythonw (no console window) and capture just the PID
            $descriptors = [
                0 => ['file', 'NUL', 'r'],
                1 => ['pipe', 'w'],
                2 => ['file', 'NUL', 'w'],
            ];
            // Find pythonw.exe or python.exe
            $pythonw = !empty($gui['python_path']) && file_exists($gui['python_path']) ? $gui['python_path'] : '';
            if (!$pythonw) foreach ([
                getenv('LOCALAPPDATA') . '\\Programs\\Python\\Python313\\pythonw.exe',
                getenv('LOCALAPPDATA') . '\\Programs\\Python\\Python312\\pythonw.exe',
                getenv('LOCALAPPDATA') . '\\Programs\\Python\\Python311\\pythonw.exe',
                getenv('LOCALAPPDATA') . '\\Programs\\Python\\Python310\\pythonw.exe',
                'C:\\Python313\\pythonw.exe',
                'C:\\Python312\\pythonw.exe',
                'C:\\Python311\\pythonw.exe',
                'C:\\Python310\\pythonw.exe',
                'pythonw.exe',
                'python.exe',
            ] as $p) {
                if ($p && (file_exists($p) || $p === 'pythonw.exe' || $p === 'python.exe')) {
                    $pythonw = $p; break;
                }
            }

            $proc = proc_open('"' . $pythonw . '" "' . $launcher . '"', $descriptors, $pipes);
            $pid  = 0;
            if (is_resource($proc)) {
                $out = stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                proc_close($proc);
                $pid = (int)trim($out);
            }

        } else {
            $cmd = 'cd ' . escapeshellarg($creatorDir)
                 . ' && ' . escapeshellarg($uv)
                 . ' run main.py >> ' . escapeshellarg($logFile)
                 . ' 2>&1 & echo $!';
            $pid = (int)trim(shell_exec($cmd));
        }

        if ($pid > 0) file_put_contents($pidFile, $pid);
        header('Location: ' . $_SERVER['PHP_SELF'] . '?started=1'); exit;
    }
    // Stop creator
    if ($action === 'stop' && $running) {
        $pid = (int)file_get_contents($pidFile);
        if (isWindows()) {
            shell_exec('taskkill /PID ' . $pid . ' /F /T 2>NUL');
        } else {
            shell_exec("kill {$pid} 2>/dev/null");
            shell_exec("kill -9 {$pid} 2>/dev/null");
        }
        @unlink($pidFile);
        header('Location: '.$_SERVER['PHP_SELF'].'?stopped=1'); exit;
    }

    // Clear log
    if ($action === 'clear_log') {
        file_put_contents($logFile, '');
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
}

// ── Derive display values from toml ───────────────────────────────────────────
$t  = $toml;
$ac = $t['account_creator'] ?? [];
$br = $t['browser']         ?? [];
$gp = $t['gproxy']          ?? [];
$em = $t['email']           ?? [];
$gw = $t['email']['guerrilla_mail'] ?? [];
$im = $t['email']['imap']   ?? [];
$acc= $t['account']         ?? [];
$px = $t['proxies']         ?? [];

$gwDomainsTxt  = implode("\n", (array)($gw['domains'] ?? []));
$imapDomainsTxt= is_array($im['domains'] ?? null) ? implode("\n", $im['domains']) : '';
$proxyList     = $gui['pending_proxies'] ?? [];
$proxyErrors   = $gui['pending_proxy_errors'] ?? [];
$proxyJson     = json_encode($proxyList);

// Log tail (last 120 lines)
$logLines = [];
if (file_exists($logFile) && ($fh = @fopen($logFile, 'r')) !== false) {
    while (($line = fgets($fh)) !== false) {
        $line = rtrim($line);
        if ($line === '') continue;
        $logLines[] = $line;
        if (count($logLines) > 120) array_shift($logLines);
    }
    fclose($fh);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Jagex Creator GUI</title>
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

/* Nav */
nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;display:flex;align-items:stretch;gap:0}
.nav-logo{display:flex;align-items:center;gap:10px;padding:12px 20px 12px 0;border-right:1px solid var(--border);margin-right:8px}
.nav-logo .mark{width:28px;height:28px;border:2px solid var(--accent);border-radius:5px;display:grid;place-items:center;font-family:var(--mono);font-size:13px;color:var(--accent);text-shadow:0 0 6px var(--accent)}
.nav-logo span{font-size:16px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.nav-logo span em{color:var(--accent);font-style:normal}
.nav-link{display:flex;align-items:center;padding:0 18px;font-family:var(--mono);font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);text-decoration:none;border-bottom:2px solid transparent;transition:all .15s}
.nav-link:hover{color:var(--text)}
.nav-link.active{color:var(--accent);border-bottom-color:var(--accent)}
.nav-right{margin-left:auto;display:flex;align-items:center;gap:10px}
.status-dot{width:8px;height:8px;border-radius:50%;background:var(--muted)}
.status-dot.running{background:var(--accent);box-shadow:0 0 8px var(--accent);animation:pulse 1.5s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.status-label{font-family:var(--mono);font-size:11px;color:var(--text2)}

/* Layout */
.wrap{max-width:1380px;margin:0 auto;padding:24px 24px;display:grid;grid-template-columns:1fr 1fr;gap:20px}
.full{grid-column:1/-1}
.col{display:flex;flex-direction:column;gap:20px}

/* Panels */
.panel{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.panel-head{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;
  background:linear-gradient(90deg,rgba(0,255,136,.03) 0%,transparent 60%)}
.panel-head h2{font-family:var(--head);font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--text)}
.panel-head .ph-sub{margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text2)}
.panel-body{padding:16px}
.panel-top-accent{height:2px}
.panel-top-accent.green{background:linear-gradient(90deg,var(--accent),transparent)}
.panel-top-accent.blue{background:linear-gradient(90deg,var(--accent2),transparent)}
.panel-top-accent.yellow{background:linear-gradient(90deg,var(--warn),transparent)}
.panel-top-accent.purple{background:linear-gradient(90deg,var(--purple),transparent)}
.panel-top-accent.red{background:linear-gradient(90deg,var(--danger),transparent)}

/* Forms */
.fg{display:flex;flex-direction:column;gap:4px;margin-bottom:12px}
.fg:last-child{margin-bottom:0}
.fg label{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase}
.fg .hint{font-family:var(--mono);font-size:9px;color:var(--muted);margin-top:3px;line-height:1.4}
input[type="text"],input[type="number"],input[type="password"],select,textarea{
  background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-family:var(--mono);font-size:12px;padding:7px 10px;border-radius:4px;width:100%;outline:none;transition:border-color .15s}
input:focus,select:focus,textarea:focus{border-color:rgba(0,255,136,.4);box-shadow:0 0 0 2px rgba(0,255,136,.06)}
textarea{resize:vertical;min-height:80px}
.inline-check{display:flex;align-items:center;gap:8px;cursor:pointer;font-family:var(--mono);font-size:12px;color:var(--text2)}
.inline-check input[type="checkbox"]{width:14px;height:14px;accent-color:var(--accent)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.form-row3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px}

/* Buttons */
.btn{padding:8px 16px;border-radius:4px;border:none;font-family:var(--head);font-size:12px;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s}
.btn-accent{background:rgba(0,255,136,.1);color:var(--accent);border:1px solid rgba(0,255,136,.25)}
.btn-accent:hover{background:rgba(0,255,136,.18);box-shadow:0 0 10px rgba(0,255,136,.12)}
.btn-danger{background:rgba(255,59,92,.1);color:var(--danger);border:1px solid rgba(255,59,92,.25)}
.btn-danger:hover{background:rgba(255,59,92,.18)}
.btn-subtle{background:rgba(255,255,255,.03);color:var(--text2);border:1px solid var(--border)}
.btn-subtle:hover{background:rgba(255,255,255,.07);color:var(--text)}
.btn-warn{background:rgba(255,184,46,.1);color:var(--warn);border:1px solid rgba(255,184,46,.25)}
.btn-warn:hover{background:rgba(255,184,46,.18)}
.btn-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-top:14px}
.btn-big{padding:12px 28px;font-size:14px}
.btn:disabled{opacity:.35;cursor:not-allowed}

/* Stat cards */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:0}
.stat-card{background:var(--card2);border:1px solid var(--border);border-radius:6px;padding:12px 14px}
.stat-card .sl{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:4px}
.stat-card .sv{font-family:var(--mono);font-size:20px;line-height:1}
.sv.green{color:var(--accent)}.sv.red{color:var(--danger)}.sv.blue{color:var(--accent2)}.sv.yellow{color:var(--warn)}

/* Process controls */
.proc-status{display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--card2);border-radius:6px;margin-bottom:14px}
.proc-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.proc-dot.on{background:var(--accent);box-shadow:0 0 8px var(--accent);animation:pulse 1.5s infinite}
.proc-dot.off{background:var(--muted)}
.proc-label{font-family:var(--mono);font-size:12px}
.proc-label em{font-style:normal;color:var(--accent)}
.proc-pid{margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--muted)}

/* Log */
.log-box{background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:10px 12px;
  font-family:var(--mono);font-size:11px;line-height:1.6;height:300px;overflow-y:auto;color:#7a9ab0}
.log-box .log-ok{color:var(--accent)}.log-box .log-err{color:var(--danger)}
.log-box .log-warn{color:var(--warn)}.log-box .log-info{color:var(--accent2)}

/* Proxy output */
.proxy-out{background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:10px 12px;
  font-family:var(--mono);font-size:11px;color:var(--accent);max-height:160px;overflow-y:auto}
.proxy-err{color:var(--danger);font-size:10px;margin-top:4px;font-family:var(--mono)}

/* Toast */
#toast{position:fixed;bottom:22px;right:22px;background:var(--card);border:1px solid var(--accent);
  color:var(--accent);font-family:var(--mono);font-size:11px;padding:9px 16px;border-radius:5px;
  box-shadow:0 0 18px rgba(0,255,136,.12);opacity:0;transform:translateY(8px);
  transition:all .2s;pointer-events:none;z-index:9999}
#toast.err{border-color:var(--danger);color:var(--danger)}
#toast.show{opacity:1;transform:translateY(0)}

/* Banner */
.banner{background:rgba(255,184,46,.06);border:1px solid rgba(255,184,46,.2);border-radius:6px;
  padding:10px 14px;margin-bottom:18px;font-family:var(--mono);font-size:11px;color:var(--warn);
  display:flex;align-items:center;gap:8px}

/* Section divider */
.sdiv{height:1px;background:var(--border);margin:14px 0}
.section-lbl{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--muted);text-transform:uppercase;margin-bottom:10px}

@media(max-width:900px){.wrap{grid-template-columns:1fr}.stat-row{grid-template-columns:1fr 1fr}}
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
  <div class="nav-right">
    <div class="status-dot <?= $running ? 'running' : '' ?>"></div>
    <span class="status-label"><?= $running ? 'RUNNING' : 'STOPPED' ?></span>
  </div>
</nav>

<div class="wrap">

  <!-- ── Left column ── -->
  <div class="col">

    <!-- Settings -->
    <div class="panel">
      <div class="panel-top-accent blue"></div>
      <div class="panel-head">
        <h2>⚙ GUI Settings</h2>
        <span class="ph-sub">creator path + ef key</span>
      </div>
      <div class="panel-body">
        <?php if (!$creatorDir || !file_exists($creatorDir.'/config.toml')): ?>
        <div class="banner">⚠ Set your creator directory path to load config.toml</div>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="save_gui">
          <div class="fg">
            <label>Creator Directory (absolute path to jagex_account_creator folder)</label>
            <input type="text" name="creator_dir" value="<?= htmlspecialchars($creatorDir) ?>" placeholder="/home/user/jagex_account_creator">
            <div class="hint">The folder containing main.py and config.toml</div>
          </div>
          <div class="fg">
            <label>EternalFarm API Key</label>
            <input type="password" name="ef_api_key"
              value="<?= htmlspecialchars($efApiKey) ?>"
              placeholder="Paste your EF API key…"
              autocomplete="off">
            <div class="hint">Used for the Account Dashboard page and optionally written to config.toml</div>
          </div>
          <div class="fg">
            <label>uv Executable Path (leave blank to auto-detect)</label>
            <input type="text" name="uv_path" value="<?= htmlspecialchars($gui['uv_path'] ?? '') ?>"
              placeholder="e.g. C:\Users\you\.local\bin\uv.exe  or  /home/user/.local/bin/uv">
            <div class="hint">Full path to uv. Auto-detected if blank. Find it by running: where uv  (Windows) or which uv  (Linux)</div>
          </div>
          <div class="fg">
            <label>Python Executable Path (leave blank to auto-detect)</label>
            <input type="text" name="python_path" value="<?= htmlspecialchars($gui['python_path'] ?? '') ?>"
              placeholder="e.g. C:\Users\you\AppData\Local\Programs\Python\Python312\pythonw.exe">
            <div class="hint">Used to launch the creator on Windows. Find it by running: where pythonw  (or where python)</div>
          </div>
          <div class="fg" style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px">
            <label>Discord Webhook URL <span style="color:var(--muted);font-weight:400;font-size:11px">(optional)</span></label>
            <input type="text" name="discord_webhook"
              value="<?= htmlspecialchars($gui['discord_webhook'] ?? '') ?>"
              placeholder="https://discord.com/api/webhooks/…"
              autocomplete="off">
            <div class="hint">Receives alerts for: creator completion, agent offline/underutilization, ban wave detection. Leave blank to disable.</div>
          </div>
          <div class="btn-row">
            <button type="submit" class="btn btn-accent">Save Settings</button>
            <?php if (!empty($gui['discord_webhook'])): ?>
            <button type="button" class="btn btn-subtle" onclick="testWebhook()">Test Webhook</button>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <!-- Process Control -->
    <div class="panel">
      <div class="panel-top-accent <?= $running ? 'green' : 'red' ?>"></div>
      <div class="panel-head">
        <h2>▶ Process Control</h2>
        <span class="ph-sub"><?= $creatorDir ? htmlspecialchars(basename($creatorDir)) : 'no dir set' ?></span>
      </div>
      <div class="panel-body">
        <div class="proc-status">
          <div class="proc-dot <?= $running ? 'on' : 'off' ?>"></div>
          <span class="proc-label">
            Status: <?= $running ? '<em>RUNNING</em>' : 'STOPPED' ?>
          </span>
          <?php if ($running && file_exists($pidFile)): ?>
            <span class="proc-pid">PID <?= (int)file_get_contents($pidFile) ?></span>
          <?php endif; ?>
        </div>

        <div class="form-row">
          <form method="post">
            <input type="hidden" name="action" value="start">
            <button type="submit" class="btn btn-accent btn-big" style="width:100%"
              <?= ($running || !$creatorDir) ? 'disabled' : '' ?>>
              ▶ Start Creator
            </button>
          </form>
          <form method="post">
            <input type="hidden" name="action" value="stop">
            <button type="submit" class="btn btn-danger btn-big" style="width:100%"
              <?= !$running ? 'disabled' : '' ?>>
              ■ Stop Creator
            </button>
          </form>
        </div>

        <!-- Bandwidth stats -->
        <div class="sdiv"></div>
        <div class="section-lbl">Bandwidth Usage (this session)</div>
        <div class="stat-row">
          <div class="stat-card">
            <div class="sl">Created</div>
            <div class="sv green" id="bw-created"><?= $bw['created'] ?></div>
          </div>
          <div class="stat-card">
            <div class="sl">Failed</div>
            <div class="sv red" id="bw-failed"><?= $bw['failed'] ?></div>
          </div>
          <div class="stat-card">
            <div class="sl">Total Data</div>
            <div class="sv yellow" id="bw-total"><?= number_format($bw['total_mb'], 2) ?>MB</div>
          </div>
          <div class="stat-card">
            <div class="sl">Avg / Acct</div>
            <div class="sv blue" id="bw-avg">
              <?= $bw['created'] > 0 ? number_format($bw['total_mb']/$bw['created'],2).'MB' : '—' ?>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Proxy Converter -->
    <div class="panel">
      <div class="panel-top-accent yellow"></div>
      <div class="panel-head">
        <h2>⇄ Proxy Converter</h2>
        <span class="ph-sub">host:port:user:pass → toml format</span>
      </div>
      <div class="panel-body">
        <form method="post" id="proxyForm">
          <input type="hidden" name="action" value="apply_proxies">
          <div class="fg">
            <label>Paste Proxies (one per line: ip:port or ip:port:user:pass)</label>
            <textarea name="proxy_raw" rows="6" placeholder="192.168.1.1:1080:username:password&#10;10.0.0.1:3128&#10;..."><?= htmlspecialchars($_POST['proxy_raw'] ?? '') ?></textarea>
          </div>
          <div class="btn-row">
            <button type="submit" class="btn btn-warn" <?= !$configPath ? 'disabled' : '' ?>>Convert &amp; Apply to Config</button>
          </div>
        </form>

        <?php if (!empty($proxyList)): ?>
        <div class="sdiv"></div>
        <div class="section-lbl"><?= count($proxyList) ?> proxies converted &amp; pending — save config to apply</div>
        <div class="proxy-out">
          <?php foreach ($proxyList as $p): ?>
            <?php $auth = $p['username'] ? " ({$p['username']}:***)" : ''; ?>
            <div><?= htmlspecialchars($p['ip'].':'.$p['port'].$auth) ?></div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($proxyErrors)): ?>
          <?php foreach ($proxyErrors as $e): ?>
            <div class="proxy-err">⚠ <?= htmlspecialchars($e) ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /left col -->

  <!-- ── Right column ── -->
  <div class="col">

    <!-- Config Editor -->
    <?php if ($configPath && file_exists($configPath)): ?>
    <div class="panel">
      <div class="panel-top-accent green"></div>
      <div class="panel-head">
        <h2>📄 config.toml Editor</h2>
        <span class="ph-sub"><?= htmlspecialchars($configPath) ?></span>
      </div>
      <div class="panel-body">
        <form method="post">
          <input type="hidden" name="action" value="save_config">
          <input type="hidden" name="proxies_json" id="proxiesJsonField" value="<?= htmlspecialchars($proxyJson) ?>">

          <!-- Account Creator -->
          <div class="section-lbl">[ account_creator ]</div>
          <div class="form-row3">
            <div class="fg">
              <label>Accounts to Create</label>
              <input type="number" name="accounts_to_create" value="<?= (int)($ac['accounts_to_create'] ?? 1) ?>" min="1">
            </div>
            <div class="fg">
              <label>Threads</label>
              <input type="number" name="threads" value="<?= (int)($ac['threads'] ?? 1) ?>" min="1">
            </div>
            <div class="fg">
              <label>Log Level</label>
              <select name="log_level">
                <?php foreach (['DEBUG','INFO','WARNING','ERROR'] as $lvl): ?>
                  <option <?= ($ac['log_level']??'INFO')===$lvl?'selected':'' ?>><?= $lvl ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="sdiv"></div>
          <!-- Browser -->
          <div class="section-lbl">[ browser ]</div>
          <div class="form-row" style="margin-bottom:10px">
            <label class="inline-check">
              <input type="checkbox" name="headless" <?= ($br['headless']??false)?'checked':'' ?>>
              Headless Mode
            </label>
            <label class="inline-check">
              <input type="checkbox" name="enable_dev_tools" <?= ($br['enable_dev_tools']??false)?'checked':'' ?>>
              Enable DevTools
            </label>
          </div>
          <div class="fg">
            <label>User Agent</label>
            <input type="text" name="user_agent" value="<?= htmlspecialchars($br['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/145.0.0.0 Safari/537.36') ?>">
            <div class="hint">Must match the UA shown in your Chrome at chrome://version</div>
          </div>
          <div class="form-row">
            <div class="fg">
              <label>Element Wait Timeout (sec)</label>
              <input type="number" name="element_wait_timeout" value="<?= (int)($br['element_wait_timeout'] ?? 30) ?>" min="5">
            </div>
            <div class="fg">
              <label>Cache Update Threshold</label>
              <input type="number" name="cache_update_threshold" value="<?= (float)($br['cache_update_threshold'] ?? 0.3) ?>" min="0" max="1" step="0.05">
            </div>
          </div>

          <div class="sdiv"></div>
          <!-- gproxy -->
          <div class="section-lbl">[ gproxy ]</div>
          <div class="fg" style="max-width:200px">
            <label>Log Level</label>
            <select name="gproxy_log_level">
              <?php foreach (['DEBUG','INFO','WARNING','ERROR'] as $lvl): ?>
                <option <?= ($gp['log_level']??'INFO')===$lvl?'selected':'' ?>><?= $lvl ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="sdiv"></div>
          <!-- Email -->
          <div class="section-lbl">[ email ]</div>
          <div class="form-row" style="margin-bottom:10px">
            <div class="fg">
              <label>Mail Provider</label>
              <select name="mail_provider" id="mailProviderSel">
                <?php foreach (['xitroo','guerrilla_mail','imap'] as $mp): ?>
                  <option value="<?= $mp ?>" <?= ($em['mail_provider']??'xitroo')===$mp?'selected':'' ?>><?= $mp ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="fg" style="justify-content:flex-end">
              <label class="inline-check" style="margin-top:22px">
                <input type="checkbox" name="use_proxy_for_temp_mail" <?= ($em['use_proxy_for_temp_mail']??false)?'checked':'' ?>>
                Use Proxy for Temp Mail
              </label>
            </div>
          </div>

          <!-- IMAP section -->
          <div id="imapSection" style="display:none">
            <div class="section-lbl" style="color:var(--accent2)">[ email.imap ]</div>
            <div class="form-row">
              <div class="fg">
                <label>IMAP IP / Host</label>
                <input type="text" name="imap_ip" value="<?= htmlspecialchars($im['ip'] ?? '') ?>" placeholder="mail.domain.com">
              </div>
              <div class="fg">
                <label>IMAP Port</label>
                <input type="number" name="imap_port" value="<?= (int)($im['port'] ?? 993) ?>">
              </div>
            </div>
            <div class="form-row">
              <div class="fg">
                <label>Catch-All Email</label>
                <input type="text" name="imap_email" value="<?= htmlspecialchars($im['email'] ?? '') ?>" placeholder="catchall@domain.com">
              </div>
              <div class="fg">
                <label>IMAP Password</label>
                <input type="password" name="imap_password" value="<?= htmlspecialchars($im['password'] ?? '') ?>">
              </div>
            </div>
            <div class="fg">
              <label>IMAP Domains (one per line)</label>
              <textarea name="imap_domains" rows="3" placeholder="mydomain.com&#10;myotherdomain.net"><?= htmlspecialchars($imapDomainsTxt) ?></textarea>
            </div>
          </div>

          <!-- Guerrilla domains -->
          <div id="gwSection" style="display:none">
            <div class="section-lbl" style="color:var(--accent2)">[ email.guerrilla_mail ] domains</div>
            <div class="fg">
              <label>Domains (one per line)</label>
              <textarea name="gw_domains" rows="5"><?= htmlspecialchars($gwDomainsTxt) ?></textarea>
            </div>
          </div>

          <div class="sdiv"></div>
          <!-- Account -->
          <div class="section-lbl">[ account ]</div>
          <div class="form-row3">
            <div class="fg">
              <label>Username Length</label>
              <input type="number" name="username_length" value="<?= (int)($acc['username_length'] ?? 12) ?>" min="6" max="20">
            </div>
            <div class="fg">
              <label>Rand Password Length</label>
              <input type="number" name="random_password_length" value="<?= (int)($acc['random_password_length'] ?? 16) ?>" min="8" max="32">
            </div>
            <div class="fg" style="justify-content:flex-end">
              <label class="inline-check" style="margin-top:22px">
                <input type="checkbox" name="set_2fa" <?= ($acc['set_2fa']??true)?'checked':'' ?>>
                Set 2FA on Accounts
              </label>
            </div>
          </div>
          <div class="fg">
            <label>Fixed Password (blank = random per account)</label>
            <input type="text" name="account_password" value="<?= htmlspecialchars($acc['password'] ?? '') ?>" placeholder="Leave blank for random">
          </div>

          <div class="sdiv"></div>
          <!-- Proxies summary -->
          <div class="section-lbl">[ proxies ] — <?= count($proxyList) ?> loaded via converter</div>
          <?php if (empty($proxyList) && !empty($px['list'])): ?>
            <div style="font-family:var(--mono);font-size:10px;color:var(--text2);margin-bottom:8px">
              Current config has proxies set. Use Proxy Converter to replace them.
            </div>
          <?php elseif (empty($proxyList)): ?>
            <div style="font-family:var(--mono);font-size:10px;color:var(--muted);margin-bottom:8px">
              No proxies loaded. Use the Proxy Converter panel to add proxies.
            </div>
          <?php endif; ?>

          <div class="btn-row">
            <button type="submit" class="btn btn-accent btn-big">💾 Save config.toml</button>
          </div>
        </form>
      </div>
    </div>
    <?php else: ?>
    <div class="panel">
      <div class="panel-top-accent red"></div>
      <div class="panel-head"><h2>📄 config.toml Editor</h2></div>
      <div class="panel-body">
        <div class="banner">⚠ No creator directory set or config.toml not found. Set the path in GUI Settings.</div>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /right col -->

  <!-- ── Full width: Log ── -->
  <div class="panel full">
    <div class="panel-top-accent purple"></div>
    <div class="panel-head">
      <h2>📋 Output Log</h2>
      <span class="ph-sub" id="logLineCount"><?= count($logLines) ?> lines</span>
      <form method="post" style="margin-left:auto">
        <input type="hidden" name="action" value="clear_log">
        <button type="submit" class="btn btn-subtle" style="padding:4px 10px;font-size:10px">Clear</button>
      </form>
    </div>
    <div class="panel-body">
      <div class="log-box" id="logBox">
        <?php foreach ($logLines as $line): ?>
          <?php
            $cls = '';
            if (stripos($line,'success') !== false || stripos($line,'created') !== false) $cls = 'log-ok';
            elseif (stripos($line,'error') !== false || stripos($line,'fail') !== false) $cls = 'log-err';
            elseif (stripos($line,'warning') !== false) $cls = 'log-warn';
            elseif (stripos($line,'INFO') !== false) $cls = 'log-info';
          ?>
          <div class="<?= $cls ?>"><?= htmlspecialchars($line) ?></div>
        <?php endforeach; ?>
        <?php if (empty($logLines)): ?>
          <div style="color:var(--muted)">No output yet. Start the creator to see logs.</div>
        <?php endif; ?>
      </div>
    </div>
  </div>

</div><!-- /wrap -->

<div id="toast"></div>

<script>
// Toast on redirect
(function(){
  const p = new URLSearchParams(location.search);
  const s = p.get('saved'), started = p.get('started'), stopped = p.get('stopped');
  const msgs = {
    gui:     '✓ GUI settings saved',
    config:  '✓ config.toml saved',
    proxies: '✓ Proxies converted — save config.toml to apply',
  };
  const msg = msgs[s] || (started ? '▶ Creator started' : stopped ? '■ Creator stopped' : null);
  if (msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(()=>t.classList.remove('show'), 3000);
    // Clean URL
    history.replaceState({}, '', location.pathname);
  }
})();

// Mail provider section toggle
function toggleMailSections() {
  const v = document.getElementById('mailProviderSel')?.value;
  const imap = document.getElementById('imapSection');
  const gw   = document.getElementById('gwSection');
  if(imap) imap.style.display = v === 'imap' ? 'block' : 'none';
  if(gw)   gw.style.display   = v === 'guerrilla_mail' ? 'block' : 'none';
}
document.getElementById('mailProviderSel')?.addEventListener('change', toggleMailSections);
toggleMailSections();

// Auto-scroll log to bottom
const lb = document.getElementById('logBox');
if (lb) lb.scrollTop = lb.scrollHeight;

// Poll log + stats every 3s
function sv(id, val) { const el = document.getElementById(id); if (el) el.textContent = val; }

let _prevRunning = null;
let _lastStats   = {};
let _discordNotified = false; // only fire once per run completion

setInterval(() => {
  fetch('?log_tail=1')
    .then(r => r.json())
    .then(d => {
      if (lb) {
        lb.innerHTML = d.html || '<div style="color:var(--muted)">No output yet.</div>';
        lb.scrollTop = lb.scrollHeight;
      }
      const lc = document.getElementById('logLineCount');
      if (lc) lc.textContent = (d.lines || 0) + ' lines';
      sv('bw-created',  d.created  ?? 0);
      sv('bw-failed',   d.failed   ?? 0);
      sv('bw-total',    (d.total_mb ?? 0) + 'MB');
      sv('bw-avg',      d.created > 0 ? (d.avg_mb + 'MB') : '—');
      _lastStats = d;
    })
    .catch(() => {});

  // Status badge + Discord completion alert
  fetch('?status_check=1').then(r=>r.json()).then(d=>{
    const on = d.running;
    document.querySelectorAll('.status-dot').forEach(el => el.classList.toggle('running', on));
    document.querySelectorAll('.status-label').forEach(el => { el.innerHTML = on ? 'RUNNING' : 'STOPPED'; });
    document.querySelectorAll('.proc-label').forEach(el => { el.innerHTML = 'Status: ' + (on ? '<em>RUNNING</em>' : 'STOPPED'); });

    // Fire Discord webhook once when process transitions running→stopped
    // and there are actual stats to report
    if (_prevRunning === true && on === false && !_discordNotified && _lastStats.created > 0) {
      _discordNotified = true;
      const p = new URLSearchParams({
        discord_notify: 1,
        created:  _lastStats.created  || 0,
        failed:   _lastStats.failed   || 0,
        total_mb: _lastStats.total_mb || 0,
        avg_mb:   _lastStats.avg_mb   || 0,
      });
      fetch('?' + p.toString()).catch(() => {});
    }
    // Reset flag when a new run starts
    if (on && _prevRunning === false) _discordNotified = false;
    _prevRunning = on;
  }).catch(()=>{});
}, 3000);

function testWebhook() {
  fetch('?discord_notify=1&created=5&failed=1&total_mb=6.2&avg_mb=1.03')
    .then(r => r.json())
    .then(d => alert(d.sent ? '✓ Test message sent to Discord!' : 'Not sent: ' + (d.reason || 'unknown')))
    .catch(() => alert('Request failed'));
}
</script>


</body>
</html>
