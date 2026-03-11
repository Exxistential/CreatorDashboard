<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  Trade Unrestriction Mover (mover.php)
//  Scans a source category for accounts meeting trade unrestriction requirements
//  and moves them to a target category via the EF API.
//
//  Requirements: 10+ QP, 100+ Total Level, 20+ hours played_time
// ═══════════════════════════════════════════════════════════════════════════════
ini_set('memory_limit', '128M');
set_time_limit(0);

$guiConfig = __DIR__ . '/gui_config.json';

// ── QP / TTL thresholds ───────────────────────────────────────────────────────
const MIN_QP         = 10;
const MIN_TTL        = 100;
const MIN_PLAYED_HRS = 20.0;

function loadGui(string $p): array {
    if (!file_exists($p)) return [];
    return json_decode(file_get_contents($p), true) ?? [];
}
function saveGui(string $p, array $d): void {
    file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function efget(string $ep, array $params, string $key): ?array {
    $url = 'https://api.eternalfarm.net/v1/' . ltrim($ep, '/') . '?' .
           http_build_query(array_merge($params, ['apikey' => $key]));
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Accept: application/json\r\nAuthorization: Bearer {$key}\r\n",
        'timeout'       => 30,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    return $raw ? (json_decode($raw, true) ?: null) : null;
}

// Move account to a new category via EF API
// Tries PUT then PATCH on the standard accounts endpoint.
// Also tries the account-categories assignment endpoint as fallback.
function efMoveAccount(string $accountId, string $targetCatId, string $key): array {
    global $http_response_header;

    $payloadInt    = json_encode(['account_category_id' => (int)$targetCatId]);
    $payloadStr    = json_encode(['account_category_id' => $targetCatId]);

    // Candidates: [method, url, payload]
    $attempts = [
        ['PUT',   'https://api.eternalfarm.net/v1/accounts/' . $accountId, $payloadInt],
        ['PATCH', 'https://api.eternalfarm.net/v1/accounts/' . $accountId, $payloadInt],
        ['PUT',   'https://api.eternalfarm.net/v1/accounts/' . $accountId, $payloadStr],
        ['POST',  'https://api.eternalfarm.net/v1/accounts/' . $accountId . '/category', $payloadInt],
        ['PUT',   'https://api.eternalfarm.net/v1/account-categories/' . $targetCatId . '/accounts/' . $accountId, '{}'],
    ];

    $lastCode = 0; $lastBody = ''; $lastUrl = ''; $lastPayload = ''; $lastMethod = '';

    foreach ($attempts as [$method, $baseUrl, $payload]) {
        $url = $baseUrl . '?apikey=' . urlencode($key);
        $ctx = stream_context_create(['http' => [
            'method'        => $method,
            'header'        => "Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer {$key}\r\nContent-Length: " . strlen($payload) . "\r\n",
            'content'       => $payload,
            'timeout'       => 12,
            'ignore_errors' => true,
        ]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) $code = (int)$m[1];
        }
        $lastCode = $code; $lastBody = (string)$raw;
        $lastUrl = $url; $lastPayload = $payload; $lastMethod = $method;

        // Success
        if ($code >= 200 && $code < 300) {
            return ['code'=>$code,'body'=>$raw,'url'=>$url,'payload'=>$payload,'method'=>$method,'attempts'=>1];
        }
        // If not 404, don't keep trying — it's a real error (403, 422, etc.)
        if ($code !== 0 && $code !== 404) break;
    }

    return [
        'code'    => $lastCode,
        'body'    => $lastBody,
        'url'     => $lastUrl,
        'payload' => $lastPayload,
        'method'  => $lastMethod,
    ];
}

function fetchAllEFCategories(string $key): array {
    if (!$key) return [];
    foreach (['account-categories', 'account_categories', 'categories', 'accountCategories'] as $ep) {
        $page = 1; $per = 100; $all = [];
        while (true) {
            $data = efget($ep, ['page' => $page, 'per_page' => $per], $key);
            if (!$data) break;
            $rows = $data['data'] ?? (isset($data[0]) ? $data : []);
            if (!$rows) break;
            foreach ($rows as $r) {
                if (!isset($r['id'])) continue;
                $all[(string)$r['id']] = $r['name'] ?? "Category {$r['id']}";
            }
            $last = $data['meta']['last_page'] ?? $data['meta']['lastPage'] ?? null;
            if ($last !== null) { if ($page >= $last) break; $page++; continue; }
            if (count($rows) < $per) break;
            $page++;
        }
        if ($all) {
            $out = [];
            foreach ($all as $id => $name) $out[] = ['id' => $id, 'name' => $name];
            usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
            return $out;
        }
    }
    return [];
}

// ── AJAX: test_move — fires a single PATCH/PUT against one account to find working endpoint ──
if (isset($_GET['test_move'])) {
    header('Content-Type: application/json');
    $gcfg    = loadGui($guiConfig);
    $key     = trim($gcfg['ef_api_key'] ?? '');
    $accId   = trim($_GET['acc']  ?? '');
    $catId   = trim($_GET['cat']  ?? '');
    if (!$key || !$accId || !$catId) {
        echo json_encode(['error'=>'missing key, acc, or cat']); exit;
    }
    global $http_response_header;
    $attempts = [];
    foreach ([
        ['PUT',   "https://api.eternalfarm.net/v1/accounts/{$accId}",                           json_encode(['account_category_id'=>(int)$catId])],
        ['PATCH', "https://api.eternalfarm.net/v1/accounts/{$accId}",                           json_encode(['account_category_id'=>(int)$catId])],
        ['PUT',   "https://api.eternalfarm.net/v1/accounts/{$accId}",                           json_encode(['account_category_id'=>$catId])],
        ['POST',  "https://api.eternalfarm.net/v1/accounts/{$accId}/category",                  json_encode(['account_category_id'=>(int)$catId])],
        ['PUT',   "https://api.eternalfarm.net/v1/account-categories/{$catId}/accounts/{$accId}", '{}'],
    ] as [$m, $u, $p]) {
        $url = $u . '?apikey=' . urlencode($key);
        $ctx = stream_context_create(['http'=>['method'=>$m,'ignore_errors'=>true,'timeout'=>10,
            'header'=>"Content-Type: application/json\r\nAccept: application/json\r\nAuthorization: Bearer {$key}\r\nContent-Length: ".strlen($p)."\r\n",
            'content'=>$p]]);
        $raw  = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h)
            if (preg_match('#HTTP/\S+\s+(\d+)#',$h,$mm)) $code=(int)$mm[1];
        $attempts[] = ['method'=>$m,'url'=>$u,'payload'=>$p,'code'=>$code,'body'=>substr((string)$raw,0,300)];
        if ($code >= 200 && $code < 300) break; // stop on success
        if ($code !== 0 && $code !== 404)  break; // stop on real error
    }
    echo json_encode(['attempts'=>$attempts], JSON_PRETTY_PRINT);
    exit;
}

// ── AJAX: probe — dumps raw first account from a category so we can verify field names ──
if (isset($_GET['probe'])) {
    header('Content-Type: application/json');
    $gcfg  = loadGui($GLOBALS['guiConfig'] ?? (__DIR__.'/gui_config.json'));
    $key   = trim($gcfg['ef_api_key'] ?? '');
    $catId = trim($_GET['cat'] ?? '');
    if (!$key || !$catId) { echo json_encode(['error'=>'no key or cat']); exit; }
    // Try fetching without status filter too
    $data  = efget('accounts', ['page'=>1,'per_page'=>3,'account_category_id'=>$catId], $key);
    $data2 = efget('accounts', ['page'=>1,'per_page'=>3,'account_category_id'=>$catId,'status'=>'active'], $key);
    echo json_encode([
        'no_filter_count'     => count($data['data'] ?? []),
        'active_filter_count' => count($data2['data'] ?? []),
        'meta'                => $data['meta'] ?? null,
        'first_account_keys'  => isset($data['data'][0]) ? array_keys($data['data'][0]) : [],
        'first_account'       => $data['data'][0] ?? null,
        'active_first'        => $data2['data'][0] ?? null,
    ], JSON_PRETTY_PRINT);
    exit;
}

// ── AJAX: scan + move (streamed NDJSON so UI can show live progress) ──────────
if (isset($_GET['run_mover'])) {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('X-Accel-Buffering: no');
    header('Cache-Control: no-cache');

    $gcfg    = loadGui($guiConfig);
    $key     = trim($gcfg['ef_api_key'] ?? '');
    $srcId   = trim($_GET['src'] ?? '');
    $dstId   = trim($_GET['dst'] ?? '');
    $dryRun  = ($_GET['dry'] ?? '0') === '1';

    $emit = function(array $d) {
        echo json_encode($d) . "\n";
        if (ob_get_level()) ob_flush();
        flush();
    };

    if (!$key || !$srcId) {
        $emit(['type' => 'error', 'msg' => 'Missing API key or source category.']);
        exit;
    }
    if (!$dryRun && !$dstId) {
        $emit(['type' => 'error', 'msg' => 'Target category required for a live run.']);
        exit;
    }
    if (!$dryRun && $srcId === $dstId) {
        $emit(['type' => 'error', 'msg' => 'Source and target categories must be different.']);
        exit;
    }

    $emit(['type' => 'status', 'msg' => 'Scanning source category…']);

    $page = 1; $per = 200;
    $scanned = 0; $qualified = 0; $moved = 0; $failed = 0;

    while (true) {
        $data = efget('accounts', [
            'page'                => $page,
            'per_page'            => $per,
            'account_category_id' => $srcId,
            'status'              => 'active',   // only move active accounts
        ], $key);

        if (!$data) {
            $emit(['type' => 'error', 'msg' => "API error on page $page — stopping."]);
            break;
        }

        $rows = $data['data'] ?? [];
        if (!$rows) break;

        foreach ($rows as $acc) {
            $scanned++;
            $id    = (string)($acc['id'] ?? '');
            $email = (string)($acc['email'] ?? $id);

            $qp      = (int)($acc['quest_points'] ?? 0);
            $ttl     = (int)($acc['total_level']  ?? 0);
            $playedMs= (int)($acc['played_time']  ?? 0);
            $playedH = $playedMs / 3_600_000;

            $meetsQP  = $qp  >= MIN_QP;
            $meetsTTL = $ttl >= MIN_TTL;
            $meetsHrs = $playedH >= MIN_PLAYED_HRS;
            $qualifies = $meetsQP && $meetsTTL && $meetsHrs;

            // Emit a scan event so the UI can show the account in the results list
            $emit([
                'type'      => 'scan',
                'id'        => $id,
                'email'     => $email,
                'qp'        => $qp,
                'ttl'       => $ttl,
                'played_h'  => round($playedH, 2),
                'meets_qp'  => $meetsQP,
                'meets_ttl' => $meetsTTL,
                'meets_hrs' => $meetsHrs,
                'qualifies' => $qualifies,
            ]);

            if (!$qualifies || !$id) continue;

            $qualified++;

            if ($dryRun) {
                $emit(['type' => 'move_ok', 'id' => $id, 'email' => $email, 'dry' => true]);
                continue;
            }

            $res = efMoveAccount($id, $dstId, $key);
            $respBody = @json_decode($res['body'], true);
            $errMsg   = is_array($respBody)
                ? ($respBody['message'] ?? $respBody['error'] ?? json_encode($respBody))
                : substr((string)$res['body'], 0, 200);
            if ($res['code'] >= 200 && $res['code'] < 300) {
                $moved++;
                $emit(['type'=>'move_ok','id'=>$id,'email'=>$email,'code'=>$res['code'],'method'=>($res['method']??'?')]);
            } else {
                $failed++;
                $emit(['type'=>'move_fail','id'=>$id,'email'=>$email,
                       'code'=>$res['code'],'err'=>$errMsg,
                       'raw'=>substr($res['body'],0,300),
                       'url'=>$res['url'],'payload'=>$res['payload']]);
            }

            // Small pause to avoid hammering the API
            usleep(120_000); // 120ms between moves
        }

        $last = $data['meta']['last_page'] ?? $data['meta']['lastPage'] ?? null;
        if ($last !== null) { if ($page >= $last) break; $page++; continue; }
        if (count($rows) < $per) break;
        $page++;
    }

    $emit([
        'type'      => 'done',
        'scanned'   => $scanned,
        'qualified' => $qualified,
        'moved'     => $moved,
        'failed'    => $failed,
        'dry'       => $dryRun,
    ]);
    exit;
}

// ── Page load ─────────────────────────────────────────────────────────────────
$gui        = loadGui($guiConfig);
$apiKey     = trim($gui['ef_api_key'] ?? '');
$categories = fetchAllEFCategories($apiKey);

// Persist last-used src/dst
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_prefs') {
    $gui['mover_src'] = trim($_POST['src_category'] ?? '');
    $gui['mover_dst'] = trim($_POST['dst_category'] ?? '');
    saveGui($guiConfig, $gui);
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

$savedSrc = $gui['mover_src'] ?? '';
$savedDst = $gui['mover_dst'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Trade Unrestriction Mover</title>
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

/* Layout */
.wrap{max-width:1300px;margin:0 auto;padding:24px;display:flex;flex-direction:column;gap:20px}

/* Panels */
.panel{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.panel-top-accent{height:2px}
.panel-top-accent.green{background:linear-gradient(90deg,var(--accent),transparent)}
.panel-top-accent.blue{background:linear-gradient(90deg,var(--accent2),transparent)}
.panel-top-accent.yellow{background:linear-gradient(90deg,var(--warn),transparent)}
.panel-top-accent.purple{background:linear-gradient(90deg,var(--purple),transparent)}
.panel-top-accent.red{background:linear-gradient(90deg,var(--danger),transparent)}
.panel-head{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;
  background:linear-gradient(90deg,rgba(0,255,136,.03),transparent 60%)}
.panel-head h2{font-family:var(--head);font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.ph-sub{margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text2)}
.panel-body{padding:16px}
.sdiv{height:1px;background:var(--border);margin:14px 0}

/* Config row */
.config-grid{display:grid;grid-template-columns:1fr 60px 1fr auto auto auto;gap:12px;align-items:end}
.fg{display:flex;flex-direction:column;gap:4px}
.fg label{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase}
.fg .hint{font-family:var(--mono);font-size:9px;color:var(--muted);margin-top:3px}
select,input[type=number]{background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-family:var(--mono);font-size:12px;padding:7px 10px;border-radius:4px;width:100%;outline:none;transition:border-color .15s}
select:focus,input:focus{border-color:rgba(0,255,136,.4);box-shadow:0 0 0 2px rgba(0,255,136,.06)}

/* Arrow divider */
.arrow-sep{display:flex;align-items:center;justify-content:center;padding-bottom:2px;
  font-family:var(--mono);font-size:20px;color:var(--muted)}

/* Buttons */
.btn{padding:8px 16px;border-radius:4px;border:none;font-family:var(--head);font-size:12px;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s;white-space:nowrap}
.btn-accent{background:rgba(0,255,136,.1);color:var(--accent);border:1px solid rgba(0,255,136,.25)}
.btn-accent:hover{background:rgba(0,255,136,.18);box-shadow:0 0 10px rgba(0,255,136,.12)}
.btn-warn{background:rgba(255,184,46,.1);color:var(--warn);border:1px solid rgba(255,184,46,.25)}
.btn-warn:hover{background:rgba(255,184,46,.18)}
.btn-subtle{background:rgba(255,255,255,.03);color:var(--text2);border:1px solid var(--border)}
.btn-subtle:hover{background:rgba(255,255,255,.07);color:var(--text)}
.btn:disabled{opacity:.35;cursor:not-allowed}
.btn-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

/* Requirements */
.req-row{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:0}
.req-card{background:var(--card2);border:1px solid var(--border);border-radius:6px;padding:12px 14px;
  display:flex;align-items:center;gap:12px}
.req-icon{font-size:20px;width:32px;text-align:center;flex-shrink:0}
.req-val{font-family:var(--mono);font-size:20px;font-weight:700;color:var(--accent);line-height:1}
.req-lbl{font-family:var(--mono);font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--text2);margin-top:2px}

/* Progress bar */
.progress-bar-wrap{background:var(--card2);border:1px solid var(--border);border-radius:6px;padding:14px 16px}
.pb-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}
.pb-label{font-family:var(--mono);font-size:10px;letter-spacing:2px;text-transform:uppercase;color:var(--text2)}
.pb-count{font-family:var(--mono);font-size:11px;color:var(--text2)}
.pb-track{height:6px;background:rgba(26,35,53,.8);border-radius:3px;overflow:hidden}
.pb-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,var(--accent2),var(--accent));
  transition:width .3s;width:0}
.pb-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px}
.pbs{background:var(--card);border:1px solid var(--border);border-radius:5px;padding:9px 12px}
.pbs .sl{font-family:var(--mono);font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--text2);margin-bottom:3px}
.pbs .sv{font-family:var(--mono);font-size:18px}
.sv.green{color:var(--accent)}.sv.yellow{color:var(--warn)}.sv.red{color:var(--danger)}.sv.blue{color:var(--accent2)}

/* Status line */
.status-line{font-family:var(--mono);font-size:11px;color:var(--text2);min-height:18px;margin-top:8px}
.status-line.ok{color:var(--accent)}.status-line.err{color:var(--danger)}

/* Results table */
.tbl-wrap{overflow-x:auto;border-radius:6px;border:1px solid var(--border);margin-top:0}
table{width:100%;border-collapse:collapse;font-size:13px}
thead tr{background:var(--card2)}
th{padding:8px 12px;font-family:var(--mono);font-size:9px;letter-spacing:2px;text-transform:uppercase;
   color:var(--text2);text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:9px 12px;border-bottom:1px solid rgba(26,35,53,.5);vertical-align:middle;font-family:var(--mono);font-size:11px}
tr:last-child td{border-bottom:none}
tr.q-yes td{background:rgba(0,255,136,.025)}
tr.q-no td{opacity:.45}
tr.moved td{background:rgba(0,200,255,.025)}
tr.failed td{background:rgba(255,59,92,.035)}

/* Check/cross pills */
.pill{display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:8px;
  font-family:var(--mono);font-size:10px;font-weight:600}
.pill-ok{background:rgba(0,255,136,.1);color:var(--accent);border:1px solid rgba(0,255,136,.2)}
.pill-no{background:rgba(255,59,92,.08);color:var(--danger);border:1px solid rgba(255,59,92,.15)}
.pill-warn{background:rgba(255,184,46,.1);color:var(--warn);border:1px solid rgba(255,184,46,.2)}

/* Move status badge */
.move-badge{font-family:var(--mono);font-size:10px;padding:2px 8px;border-radius:8px}
.mb-pending{color:var(--muted);border:1px solid var(--border)}
.mb-moved{background:rgba(0,200,255,.1);color:var(--accent2);border:1px solid rgba(0,200,255,.2)}
.mb-dry{background:rgba(167,139,250,.1);color:var(--purple);border:1px solid rgba(167,139,250,.2)}
.mb-fail{background:rgba(255,59,92,.1);color:var(--danger);border:1px solid rgba(255,59,92,.2)}
.mb-skip{color:var(--muted);border:1px solid var(--border)}

/* Filter tabs */
.filter-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.ftab{padding:4px 12px;border-radius:4px;font-family:var(--mono);font-size:10px;letter-spacing:1px;
  text-transform:uppercase;cursor:pointer;border:1px solid var(--border);background:transparent;
  color:var(--text2);transition:all .15s}
.ftab.active{background:rgba(0,255,136,.08);color:var(--accent);border-color:rgba(0,255,136,.25)}
.ftab:hover:not(.active){color:var(--text)}

/* Banner */
.banner{background:rgba(255,184,46,.06);border:1px solid rgba(255,184,46,.2);border-radius:6px;
  padding:10px 14px;font-family:var(--mono);font-size:11px;color:var(--warn)}

/* Log panel */
.log-box{background:var(--bg);border:1px solid var(--border);border-radius:5px;padding:10px 12px;
  font-family:var(--mono);font-size:11px;line-height:1.7;height:220px;overflow-y:auto;color:#7a9ab0}
.log-box .log-ok{color:var(--accent)}
.log-box .log-err{color:var(--danger)}
.log-box .log-warn{color:var(--warn)}
.log-box .log-info{color:var(--accent2)}

@media(max-width:900px){
  .config-grid{grid-template-columns:1fr 1fr;grid-template-rows:auto auto auto}
  .arrow-sep{display:none}
  .req-row{grid-template-columns:1fr}
  .pb-stats{grid-template-columns:1fr 1fr}
}
</style>
</head>
<body>

<nav>
  <div class="nav-logo">
    <div class="mark">J</div>
    <span>Jagex <em>Creator</em></span>
  </div>
  <a class="nav-link" href="index.php">Creator GUI</a>
  <a class="nav-link" href="dashboard.php">Account Dashboard</a>
  <a class="nav-link" href="agents.php">Agent Monitor</a>
  <a class="nav-link" href="accounts.php">Accounts</a>
  <a class="nav-link" href="lifespan.php">Playtime</a>
  <a class="nav-link active" href="mover.php">Trade Mover</a>
  <div class="nav-right">
    <div class="clock" id="clockEl"></div>
  </div>
</nav>

<div class="wrap">

<?php if (!$apiKey): ?>
<div class="banner">&#9888; No EternalFarm API key configured. <a href="index.php" style="color:var(--warn)">Go to Creator GUI &rarr; Settings</a></div>
<?php endif; ?>

<!-- Config Panel -->
<div class="panel">
  <div class="panel-top-accent blue"></div>
  <div class="panel-head">
    <h2>&#8644; Trade Unrestriction Mover</h2>
    <span class="ph-sub">scan source &rarr; move qualifiers to target</span>
  </div>
  <div class="panel-body">

    <!-- Requirements display -->
    <div class="req-row" style="margin-bottom:16px">
      <div class="req-card">
        <div class="req-icon">&#128190;</div>
        <div>
          <div class="req-val"><?= MIN_QP ?>+</div>
          <div class="req-lbl">Quest Points</div>
        </div>
      </div>
      <div class="req-card">
        <div class="req-icon">&#9878;</div>
        <div>
          <div class="req-val"><?= MIN_TTL ?>+</div>
          <div class="req-lbl">Total Level</div>
        </div>
      </div>
      <div class="req-card">
        <div class="req-icon">&#9200;</div>
        <div>
          <div class="req-val"><?= MIN_PLAYED_HRS ?>+</div>
          <div class="req-lbl">Hours Played</div>
        </div>
      </div>
    </div>

    <div class="sdiv"></div>

    <!-- Category selector -->
    <div class="config-grid">
      <div class="fg">
        <label>Source Category</label>
        <select id="srcCat">
          <option value="">— select source —</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= htmlspecialchars($c['id']) ?>"
            <?= $c['id'] === $savedSrc ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Category to scan for qualifying accounts</div>
      </div>

      <div class="arrow-sep">&rarr;</div>

      <div class="fg">
        <label>Target Category</label>
        <select id="dstCat">
          <option value="">— select target —</option>
          <?php foreach ($categories as $c): ?>
          <option value="<?= htmlspecialchars($c['id']) ?>"
            <?= $c['id'] === $savedDst ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="hint">Where qualifying accounts will be moved</div>
      </div>

      <div class="fg">
        <label>&nbsp;</label>
        <button class="btn btn-subtle" id="probeBtn" onclick="probeCategory()" <?= !$apiKey ? 'disabled' : '' ?>>&#128270; Probe</button>
        <div class="hint">Inspect raw fields</div>
      </div>

      <div class="fg">
        <label>&nbsp;</label>
        <button class="btn btn-warn" id="dryBtn" onclick="startMover(true)" <?= !$apiKey ? 'disabled' : '' ?>>Dry Run</button>
        <div class="hint">Scan only, no moves</div>
      </div>

      <div class="fg">
        <label>&nbsp;</label>
        <button class="btn btn-accent" id="runBtn" onclick="startMover(false)" <?= !$apiKey ? 'disabled' : '' ?>>Run Mover</button>
        <div class="hint">Scan &amp; move</div>
      </div>
    </div>

    <!-- Save prefs + test single move -->
    <div style="display:flex;align-items:center;gap:12px;margin-top:10px;flex-wrap:wrap">
      <form method="post" style="display:inline">
        <input type="hidden" name="action" value="save_prefs">
        <input type="hidden" name="src_category" id="saveSrc">
        <input type="hidden" name="dst_category" id="saveDst">
        <button type="submit" class="btn btn-subtle" style="font-size:10px;padding:5px 10px"
          onclick="document.getElementById('saveSrc').value=document.getElementById('srcCat').value;
                   document.getElementById('saveDst').value=document.getElementById('dstCat').value;">
          Save Selection
        </button>
      </form>
      <div style="display:flex;align-items:center;gap:6px">
        <input type="text" id="testAccId" placeholder="Account ID" style="width:130px;padding:5px 8px;font-size:11px">
        <button class="btn btn-subtle" style="font-size:10px;padding:5px 10px" onclick="testSingleMove()">Test Single Move</button>
      </div>
      <span style="font-family:var(--mono);font-size:9px;color:var(--muted)">Tests API endpoint without running full scan</span>
    </div>

  </div>
</div>

<!-- Progress Panel -->
<div class="panel" id="progressPanel" style="display:none">
  <div class="panel-top-accent green"></div>
  <div class="panel-head">
    <h2>&#9654; Progress</h2>
    <span class="ph-sub" id="progressLabel">—</span>
  </div>
  <div class="panel-body">
    <div class="progress-bar-wrap">
      <div class="pb-header">
        <span class="pb-label">Accounts Scanned</span>
        <span class="pb-count" id="pbCount">0 / …</span>
      </div>
      <div class="pb-track"><div class="pb-fill" id="pbFill"></div></div>
      <div class="pb-stats">
        <div class="pbs"><div class="sl">Scanned</div><div class="sv blue" id="statScanned">0</div></div>
        <div class="pbs"><div class="sl">Qualified</div><div class="sv yellow" id="statQual">0</div></div>
        <div class="pbs"><div class="sl">Moved</div><div class="sv green" id="statMoved">0</div></div>
        <div class="pbs"><div class="sl">Failed</div><div class="sv red" id="statFailed">0</div></div>
      </div>
    </div>
    <div class="status-line" id="statusLine"></div>
  </div>
</div>

<!-- Log Panel -->
<div class="panel" id="logPanel" style="display:none">
  <div class="panel-top-accent yellow"></div>
  <div class="panel-head">
    <h2>&#128220; Activity Log</h2>
    <span class="ph-sub">real-time output</span>
    <button class="btn btn-subtle" style="margin-left:auto;font-size:10px;padding:4px 10px"
      onclick="document.getElementById('logBox').innerHTML=''">Clear</button>
  </div>
  <div class="panel-body">
    <div class="log-box" id="logBox"></div>
  </div>
</div>

<!-- Results Panel -->
<div class="panel" id="resultsPanel" style="display:none">
  <div class="panel-top-accent purple"></div>
  <div class="panel-head">
    <h2>&#128203; Scan Results</h2>
    <div style="display:flex;gap:8px;margin-left:auto;align-items:center">
      <div class="filter-row">
        <button class="ftab active" data-filter="all" onclick="setFilter('all',this)">All</button>
        <button class="ftab" data-filter="qualified" onclick="setFilter('qualified',this)">Qualified</button>
        <button class="ftab" data-filter="moved" onclick="setFilter('moved',this)">Moved</button>
        <button class="ftab" data-filter="failed" onclick="setFilter('failed',this)">Failed</button>
        <button class="ftab" data-filter="unqualified" onclick="setFilter('unqualified',this)">Not Qualified</button>
      </div>
      <button class="btn btn-subtle" style="font-size:10px;padding:4px 10px" onclick="exportCSV()">Export CSV</button>
    </div>
  </div>
  <div class="panel-body" style="padding:0">
    <div class="tbl-wrap">
      <table id="resultsTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Email</th>
            <th>QP</th>
            <th>Total Level</th>
            <th>Played (hrs)</th>
            <th>Qualifies</th>
            <th>Move Status</th>
          </tr>
        </thead>
        <tbody id="resultsTbody"></tbody>
      </table>
    </div>
  </div>
</div>

</div><!-- /wrap -->

<script>
(function(){ function t(){ document.getElementById('clockEl').textContent=new Date().toLocaleTimeString('en-US',{hour12:false}); } t(); setInterval(t,1000); })();

const rows = [];
let currentFilter = 'all';

function sv(id, val) { const el = document.getElementById(id); if(el) el.textContent = val; }

// ── Log panel ──
function logMsg(msg, cls) {
  const box = document.getElementById('logBox');
  if (!box) return;
  const d = document.createElement('div');
  d.className = cls || '';
  d.textContent = '[' + new Date().toLocaleTimeString('en-US',{hour12:false}) + '] ' + msg;
  box.appendChild(d);
  box.scrollTop = box.scrollHeight;
}

// ── Probe: dump raw account fields to help debug missing quest_points/total_level ──
function probeCategory() {
  const src = document.getElementById('srcCat').value;
  if (!src) { alert('Select a source category first.'); return; }
  document.getElementById('logPanel').style.display = '';
  logMsg('Probing category ' + src + ' for raw account fields…', 'log-info');
  fetch('?probe=1&cat=' + encodeURIComponent(src))
    .then(r => r.json())
    .then(d => {
      logMsg('Accounts (no filter): ' + d.no_filter_count + '  |  Accounts (active filter): ' + d.active_filter_count, '');
      if (d.first_account_keys && d.first_account_keys.length) {
        logMsg('Fields on account object: ' + d.first_account_keys.join(', '), 'log-info');
        const fa = d.first_account || d.active_first;
        if (fa) {
          logMsg('quest_points = ' + JSON.stringify(fa.quest_points) +
                 '  |  total_level = ' + JSON.stringify(fa.total_level) +
                 '  |  played_time = ' + JSON.stringify(fa.played_time), 'log-ok');
          logMsg('id = ' + fa.id + '  |  email = ' + fa.email + '  |  status = ' + fa.status, '');
        }
      } else {
        logMsg('No accounts found — category may be empty or API key issue.', 'log-err');
        logMsg('Raw response: ' + JSON.stringify(d).slice(0, 400), 'log-warn');
      }
    })
    .catch(e => logMsg('Probe request failed: ' + e, 'log-err'));
}

function startMover(isDry) {
  const src = document.getElementById('srcCat').value;
  const dst = document.getElementById('dstCat').value;
  if (!src) { alert('Please select a source category.'); return; }
  if (!isDry && !dst) { alert('Please select a target category for a live run.'); return; }
  if (!isDry && src === dst) { alert('Source and target must be different.'); return; }

  // Reset UI
  rows.length = 0;
  document.getElementById('resultsTbody').innerHTML = '';
  document.getElementById('logBox').innerHTML = '';
  sv('statScanned',0); sv('statQual',0); sv('statMoved',0); sv('statFailed',0);
  sv('pbCount','0 scanned');
  document.getElementById('pbFill').style.width = '0';
  document.getElementById('statusLine').textContent = '';
  document.getElementById('statusLine').className = 'status-line';
  document.getElementById('progressPanel').style.display = '';
  document.getElementById('resultsPanel').style.display = '';
  document.getElementById('logPanel').style.display = '';
  document.getElementById('progressLabel').textContent = isDry ? 'DRY RUN — no accounts will be moved' : 'LIVE RUN';

  document.getElementById('runBtn').disabled = true;
  document.getElementById('dryBtn').disabled = true;
  document.getElementById('probeBtn').disabled = true;

  let scanned=0, qualified=0, moved=0, failed=0;
  logMsg((isDry ? 'DRY RUN' : 'LIVE RUN') + ' started — src=' + src + (dst ? ' dst='+dst : ''), 'log-info');

  const url = `?run_mover=1&src=${encodeURIComponent(src)}&dst=${encodeURIComponent(dst||'')}&dry=${isDry?1:0}`;

  fetch(url).then(r => {
    const reader = r.body.getReader();
    const decoder = new TextDecoder();
    let buf = '';

    function pump() {
      return reader.read().then(({done, value}) => {
        if (value) buf += decoder.decode(value, {stream:true});
        let nl;
        while ((nl = buf.indexOf('\n')) !== -1) {
          const line = buf.slice(0,nl).trim();
          buf = buf.slice(nl+1);
          if (!line) continue;
          let d; try { d = JSON.parse(line); } catch(e) { continue; }
          handleEvent(d);
        }
        if (done) { finish(); return; }
        return pump();
      });
    }

    function handleEvent(d) {
      if (d.type === 'status') {
        setStatus(d.msg, '');
        logMsg(d.msg, 'log-info');

      } else if (d.type === 'error') {
        setStatus(d.msg, 'err');
        logMsg('ERROR: ' + d.msg, 'log-err');

      } else if (d.type === 'scan') {
        scanned++;
        if (d.qualifies) qualified++;
        const row = {
          id:d.id, email:d.email, qp:d.qp, ttl:d.ttl, played_h:d.played_h,
          meets_qp:d.meets_qp, meets_ttl:d.meets_ttl, meets_hrs:d.meets_hrs,
          qualifies:d.qualifies, moveStatus:'pending'
        };
        rows.push(row);
        addTableRow(row, rows.length);
        sv('statScanned', scanned);
        sv('statQual', qualified);
        sv('pbCount', scanned + ' scanned');
        if (d.qualifies) {
          logMsg('QUALIFIED: ' + d.email + ' — QP:' + d.qp + ' TTL:' + d.ttl + ' Hrs:' + d.played_h, 'log-ok');
        }
        setStatus('Scanning… ' + scanned + ' accounts checked', '');

      } else if (d.type === 'move_ok') {
        moved++;
        const row = rows.find(r=>r.id===d.id);
        if (row) { row.moveStatus = d.dry?'dry':'moved'; refreshRowStatus(d.id, row.moveStatus); }
        sv('statMoved', moved);
        logMsg((d.dry ? 'DRY MOVE: ' : 'MOVED: ') + d.email + ' (HTTP '+d.code+' via '+(d.method||'?')+')', d.dry?'log-warn':'log-ok');

      } else if (d.type === 'move_fail') {
        failed++;
        const row = rows.find(r=>r.id===d.id);
        if (row) { row.moveStatus='failed'; refreshRowStatus(d.id,'failed'); }
        sv('statFailed', failed);
        logMsg('FAILED: ' + d.email + ' — HTTP ' + d.code + ' | ' + (d.err||'unknown error'), 'log-err');
        if (d.raw)     logMsg('  Response body: ' + d.raw, 'log-warn');
        if (d.url)     logMsg('  URL: ' + d.url, 'log-warn');
        if (d.payload) logMsg('  Payload: ' + d.payload, 'log-warn');

      } else if (d.type === 'done') {
        const label = d.dry
          ? `Dry run complete — ${d.qualified} would move / ${d.scanned} scanned`
          : `Done — moved ${d.moved} / ${d.qualified} qualified (${d.failed} failed) / ${d.scanned} scanned`;
        setStatus(label, 'ok');
        logMsg(label, 'log-ok');
        document.getElementById('pbFill').style.width = '100%';
        sv('pbCount', d.scanned + ' scanned');
      }
    }

    function finish() {
      document.getElementById('runBtn').disabled = false;
      document.getElementById('dryBtn').disabled = false;
      document.getElementById('probeBtn').disabled = false;
      applyFilter(currentFilter);
    }

    pump().catch(e => {
      setStatus('Stream error: ' + e, 'err');
      logMsg('Stream error: ' + e, 'log-err');
      finish();
    });

  }).catch(e => {
    setStatus('Request failed: ' + e, 'err');
    logMsg('Request failed: ' + e, 'log-err');
    document.getElementById('runBtn').disabled = false;
    document.getElementById('dryBtn').disabled = false;
    document.getElementById('probeBtn').disabled = false;
  });
}

function setStatus(msg, cls) {
  const el = document.getElementById('statusLine');
  el.textContent = msg;
  el.className = 'status-line' + (cls ? ' '+cls : '');
}

const MIN_QP  = <?= MIN_QP ?>;
const MIN_TTL = <?= MIN_TTL ?>;
const MIN_HRS = <?= MIN_PLAYED_HRS ?>;

function pill(meets, val, min, unit) {
  const cls = meets ? 'pill-ok' : 'pill-no';
  const sym = meets ? '✓' : '✗';
  return `<span class="pill ${cls}">${sym} ${val}${unit?' '+unit:''}</span>`;
}
function moveBadge(status) {
  const m = {
    pending: '<span class="move-badge mb-pending">—</span>',
    moved:   '<span class="move-badge mb-moved">MOVED</span>',
    dry:     '<span class="move-badge mb-dry">DRY</span>',
    failed:  '<span class="move-badge mb-fail">FAILED</span>',
  };
  return m[status] || '';
}
function rowClass(row) {
  if (row.moveStatus==='moved') return 'moved';
  if (row.moveStatus==='failed') return 'failed';
  return row.qualifies ? 'q-yes' : 'q-no';
}

function addTableRow(row, idx) {
  const tbody = document.getElementById('resultsTbody');
  const tr = document.createElement('tr');
  tr.id = 'row-' + row.id;
  tr.className = rowClass(row);
  tr.dataset.qualifies = row.qualifies ? '1' : '0';
  tr.dataset.status = row.moveStatus;
  tr.innerHTML = `
    <td style="color:var(--muted)">${idx}</td>
    <td style="color:var(--text)">${escHtml(row.email)}</td>
    <td>${pill(row.meets_qp, row.qp, MIN_QP, 'QP')}</td>
    <td>${pill(row.meets_ttl, row.ttl, MIN_TTL, 'TTL')}</td>
    <td>${pill(row.meets_hrs, row.played_h, MIN_HRS, 'h')}</td>
    <td>${row.qualifies?'<span class="pill pill-ok">✓ YES</span>':'<span class="pill pill-no">✗ NO</span>'}</td>
    <td id="ms-${escHtml(row.id)}">${moveBadge(row.moveStatus)}</td>
  `;
  tr.style.display = rowVisible(tr) ? '' : 'none';
  tbody.appendChild(tr);
}

function refreshRowStatus(id, status) {
  const tr = document.getElementById('row-' + id);
  if (!tr) return;
  tr.dataset.status = status;
  if (status==='moved') tr.className='moved';
  else if (status==='failed') tr.className='failed';
  const ms = document.getElementById('ms-'+id);
  if (ms) ms.innerHTML = moveBadge(status);
  tr.style.display = rowVisible(tr) ? '' : 'none';
}

function rowVisible(tr) {
  if (currentFilter==='all')         return true;
  if (currentFilter==='qualified')   return tr.dataset.qualifies==='1';
  if (currentFilter==='unqualified') return tr.dataset.qualifies==='0';
  if (currentFilter==='moved')       return tr.dataset.status==='moved'||tr.dataset.status==='dry';
  if (currentFilter==='failed')      return tr.dataset.status==='failed';
  return true;
}
function setFilter(f, btn) {
  currentFilter = f;
  document.querySelectorAll('.ftab').forEach(b => b.classList.toggle('active', b===btn));
  applyFilter(f);
}
function applyFilter(f) {
  document.querySelectorAll('#resultsTbody tr').forEach(tr => {
    tr.style.display = rowVisible(tr) ? '' : 'none';
  });
}
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function testSingleMove() {
  const accId = document.getElementById('testAccId').value.trim();
  const dst   = document.getElementById('dstCat').value;
  if (!accId) { alert('Enter an account ID first (copy one from the log)'); return; }
  if (!dst)   { alert('Select a target category first'); return; }
  document.getElementById('logPanel').style.display = '';
  logMsg('Testing single move: account ' + accId + ' → category ' + dst, 'log-info');
  fetch('?test_move=1&acc=' + encodeURIComponent(accId) + '&cat=' + encodeURIComponent(dst))
    .then(r => r.json())
    .then(d => {
      if (!d.attempts) { logMsg('Error: ' + JSON.stringify(d), 'log-err'); return; }
      d.attempts.forEach(a => {
        const ok = a.code >= 200 && a.code < 300;
        const cls = ok ? 'log-ok' : (a.code === 404 ? '' : 'log-err');
        logMsg((ok ? '✓' : '✗') + ' ' + a.method + ' ' + a.url + ' → HTTP ' + a.code, cls);
        if (a.body && a.body !== '{}' && a.body.length > 2)
          logMsg('  body: ' + a.body, a.code >= 400 ? 'log-warn' : '');
      });
      const success = d.attempts.find(a => a.code >= 200 && a.code < 300);
      if (success) logMsg('SUCCESS — working endpoint: ' + success.method + ' ' + success.url, 'log-ok');
      else logMsg('All endpoints returned 404 or error — check account ID and API key permissions', 'log-err');
    })
    .catch(e => logMsg('Request failed: ' + e, 'log-err'));
}

function exportCSV() {
  const hdr = 'email,quest_points,total_level,played_hours,qualifies,move_status\n';
  const body = rows.map(r => [r.email,r.qp,r.ttl,r.played_h,r.qualifies?'yes':'no',r.moveStatus].join(',')).join('\n');
  const a = document.createElement('a');
  a.href = 'data:text/csv;charset=utf-8,' + encodeURIComponent(hdr+body);
  a.download = 'trade_unrestricted_' + new Date().toISOString().slice(0,10) + '.csv';
  a.click();
}
</script>
</body>
</html>
