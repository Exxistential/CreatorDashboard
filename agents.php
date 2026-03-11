<?php
ini_set('memory_limit', '32M');

$guiConfig = __DIR__ . '/gui_config.json';

function loadGui(string $p): array {
    if (!file_exists($p)) return ['ef_api_key' => '', 'agents_refresh_sec' => 15];
    return json_decode(file_get_contents($p), true) ?? [];
}
function saveGui(string $p, array $d): void {
    file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function efget(string $ep, array $params, string $key): ?array {
    if (!$key) return null;
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

function timeAgo(?string $iso): string {
    if (!$iso) return '—';
    $ts   = strtotime($iso);
    $diff = time() - $ts;
    if ($diff < 60)   return $diff . 's ago';
    if ($diff < 3600) return floor($diff/60) . 'm ago';
    if ($diff < 86400)return floor($diff/3600) . 'h ago';
    return floor($diff/86400) . 'd ago';
}

function enabledClients(array $agent): array {
    $clients = [
        'dreambot'      => 'DreamBot',
        'dreambot4'     => 'DreamBot 4',
        'osbot'         => 'OSBot',
        'tribot'        => 'TRiBot',
        'tribotx'       => 'TRiBotX',
        'tribot_echo'   => 'TRiBot Echo',
        'eternalclient' => 'EternalClient',
        'eternalclientrl'=> 'EternalClientRL',
        'storm'         => 'Storm',
        'runelite'      => 'RuneLite',
        'microbot'      => 'Microbot',
        'inubot'        => 'InuBot',
        'unethicalite'  => 'Unethicalite',
        'vitalite'      => 'Vitalite',
        'custom'        => 'Custom',
    ];
    $enabled = [];
    foreach ($clients as $key => $label) {
        $cfg = $agent[$key . '_config'] ?? null;
        if ($cfg && !empty($cfg['enabled'])) $enabled[] = $label;
    }
    return $enabled;
}

// ── POST ───────────────────────────────────────────────────────────────────────
$gui = loadGui($guiConfig);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_refresh') {
    $gui['agents_refresh_sec'] = max(5, (int)($_POST['refresh_sec'] ?? 15));
    saveGui($guiConfig, $gui);
    header('Location: ' . $_SERVER['PHP_SELF']); exit;
}

$apiKey     = trim($gui['ef_api_key'] ?? '');
$refreshSec = max(5, (int)($gui['agents_refresh_sec'] ?? 15));

// ── Fetch agents ───────────────────────────────────────────────────────────────
$agents = [];
$fetchError = '';
if ($apiKey) {
    $resp = efget('agents', ['per_page' => 200], $apiKey);
    if ($resp && isset($resp['data'])) {
        $agents = $resp['data'];
        // Sort: online first, then by name
        usort($agents, function($a, $b) {
            $ao = ($a['presence_status'] === 'online') ? 0 : 1;
            $bo = ($b['presence_status'] === 'online') ? 0 : 1;
            if ($ao !== $bo) return $ao - $bo;
            return strcasecmp($a['name'], $b['name']);
        });
    } else {
        $fetchError = 'Failed to fetch agents from EF API.';
    }
}

// ── Grand totals ───────────────────────────────────────────────────────────────
$totalOnline       = 0; $totalOffline = 0;
$totalActiveThreads= 0; $totalCapacity = 0;
$totalCheckerOn    = 0; $totalBrowserOn = 0;
$alerts = [];
foreach ($agents as $a) {
    if ($a['presence_status'] === 'online') $totalOnline++; else $totalOffline++;
    $totalActiveThreads += (int)($a['active_threads'] ?? 0);
    $totalCapacity      += (int)($a['total_capacity'] ?? 0);
    if ($a['checker_enabled']) $totalCheckerOn++;
    if ($a['browser_enabled']) $totalBrowserOn++;

    // Alert: online but 0 active threads and has capacity
    if ($a['presence_status'] === 'online' && (int)($a['active_threads'] ?? 0) === 0 && (int)($a['total_capacity'] ?? 0) > 0) {
        $alerts[] = ['type'=>'idle', 'name'=>$a['name']];
    }
    // Alert: stale instance_actions_checked_at (>3 min while online)
    if ($a['presence_status'] === 'online' && !empty($a['instance_actions_checked_at'])) {
        $staleSec = time() - strtotime($a['instance_actions_checked_at']);
        if ($staleSec > 180) {
            $alerts[] = ['type'=>'stale', 'name'=>$a['name'], 'sec'=>$staleSec];
        }
    }
}
$fleetPct = $totalCapacity > 0 ? round(($totalActiveThreads / $totalCapacity) * 100) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Agent Monitor</title>
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
.ref-label{font-family:var(--mono);font-size:11px;color:var(--muted)}

/* Layout */
.wrap{max-width:1440px;margin:0 auto;padding:24px}

/* Controls bar */
.ctrl-bar{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:20px;
  background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 16px}
.ctrl-bar .slabel{font-family:var(--mono);font-size:10px;letter-spacing:2px;color:var(--text2);text-transform:uppercase}
select,input[type="number"]{background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-family:var(--mono);font-size:12px;padding:7px 10px;border-radius:4px;outline:none;transition:border-color .15s}
select:focus,input:focus{border-color:rgba(0,255,136,.4)}
.btn{padding:7px 14px;border-radius:4px;border:none;font-family:var(--head);font-size:12px;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s}
.btn-subtle{background:rgba(255,255,255,.03);color:var(--text2);border:1px solid var(--border)}
.btn-subtle:hover{background:rgba(255,255,255,.07);color:var(--text)}

/* Grand totals */
.grand-row{display:grid;grid-template-columns:repeat(7,1fr);gap:12px;margin-bottom:22px}
.alert-banner{padding:9px 14px;border-radius:6px;font-family:var(--mono);font-size:11px;line-height:1.5}
.alert-banner strong{font-weight:700}
.alert-warn{background:rgba(255,184,46,.07);color:var(--warn);border:1px solid rgba(255,184,46,.2)}
.alert-danger{background:rgba(255,59,92,.07);color:var(--danger);border:1px solid rgba(255,59,92,.2)}
.gc{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:14px 16px;position:relative;overflow:hidden}
.gc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.gc.g-online::before{background:linear-gradient(90deg,var(--accent),transparent)}
.gc.g-offline::before{background:linear-gradient(90deg,var(--danger),transparent)}
.gc.g-threads::before{background:linear-gradient(90deg,var(--accent2),transparent)}
.gc.g-cap::before{background:linear-gradient(90deg,var(--purple),transparent)}
.gc.g-checker::before{background:linear-gradient(90deg,var(--warn),transparent)}
.gc.g-browser::before{background:linear-gradient(90deg,#f472b6,transparent)}
.gc .gl{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:5px}
.gc .gv{font-family:var(--mono);font-size:26px;line-height:1}
.gv.c-green{color:var(--accent)}.gv.c-red{color:var(--danger)}.gv.c-blue{color:var(--accent2)}
.gv.c-purple{color:var(--purple)}.gv.c-yellow{color:var(--warn)}.gv.c-pink{color:#f472b6}

/* Agent table */
.tbl-wrap{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.tbl-head-bar{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;
  background:linear-gradient(90deg,rgba(0,255,136,.03),transparent 60%)}
.tbl-head-bar h2{font-family:var(--head);font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.tbl-head-bar .sub{margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text2)}
.top-accent{height:2px;background:linear-gradient(90deg,var(--accent),transparent)}

table{width:100%;border-collapse:collapse}
thead tr{background:var(--card2)}
th{padding:9px 14px;font-family:var(--mono);font-size:9px;letter-spacing:2px;text-transform:uppercase;
   color:var(--text2);text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:11px 14px;border-bottom:1px solid rgba(26,35,53,.6);vertical-align:middle;font-size:13px}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(0,255,136,.02)}

/* Status badges */
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:10px;
  font-family:var(--mono);font-size:10px;letter-spacing:1px;font-weight:600;white-space:nowrap}
.badge-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.b-online{background:rgba(0,255,136,.1);color:var(--accent);border:1px solid rgba(0,255,136,.2)}
.b-online .badge-dot{background:var(--accent);box-shadow:0 0 5px var(--accent);animation:pulse 1.5s infinite}
.b-offline{background:rgba(255,59,92,.08);color:var(--danger);border:1px solid rgba(255,59,92,.15)}
.b-offline .badge-dot{background:var(--danger)}
.b-unknown{background:rgba(90,122,148,.08);color:var(--text2);border:1px solid var(--border)}
.b-unknown .badge-dot{background:var(--muted)}
.b-yes{background:rgba(0,200,255,.08);color:var(--accent2);border:1px solid rgba(0,200,255,.15);padding:3px 9px;border-radius:10px;font-family:var(--mono);font-size:10px}
.b-no{background:rgba(26,35,53,.5);color:var(--muted);border:1px solid var(--border);padding:3px 9px;border-radius:10px;font-family:var(--mono);font-size:10px}
.b-enabled{background:rgba(255,184,46,.08);color:var(--warn);border:1px solid rgba(255,184,46,.15);padding:3px 9px;border-radius:10px;font-family:var(--mono);font-size:10px}
.b-disabled{background:rgba(26,35,53,.5);color:var(--muted);border:1px solid var(--border);padding:3px 9px;border-radius:10px;font-family:var(--mono);font-size:10px}

@keyframes pulse{0%,100%{opacity:1}50%{opacity:.3}}

/* Thread bar */
.tbar-wrap{display:flex;align-items:center;gap:8px;min-width:140px}
.tbar-track{flex:1;height:5px;background:rgba(26,35,53,.8);border-radius:3px;overflow:hidden}
.tbar-fill{height:100%;border-radius:3px;transition:width .3s}
.tbar-lbl{font-family:var(--mono);font-size:11px;white-space:nowrap;min-width:52px;text-align:right}

/* CPU/MEM bars */
.res-bar{display:flex;align-items:center;gap:6px}
.res-track{width:60px;height:4px;background:rgba(26,35,53,.8);border-radius:2px;overflow:hidden}
.res-fill{height:100%;border-radius:2px}
.res-lbl{font-family:var(--mono);font-size:11px;min-width:36px}

/* Agent name */
.agent-name{font-family:var(--head);font-weight:700;font-size:14px;letter-spacing:1px}
.agent-id{font-family:var(--mono);font-size:9px;color:var(--muted);margin-top:2px}
.agent-ver{font-family:var(--mono);font-size:9px;color:var(--text2)}

/* Client pills */
.client-list{display:flex;flex-wrap:wrap;gap:4px}
.cpill{font-family:var(--mono);font-size:9px;padding:2px 7px;border-radius:8px;
  background:rgba(167,139,250,.1);color:var(--purple);border:1px solid rgba(167,139,250,.2)}

/* Last seen */
.lastseen{font-family:var(--mono);font-size:10px;color:var(--text2)}
.lastseen.fresh{color:var(--accent)}
.lastseen.stale{color:var(--muted)}

/* Banner */
.banner{background:rgba(255,184,46,.06);border:1px solid rgba(255,184,46,.2);border-radius:6px;
  padding:10px 16px;margin-bottom:18px;font-family:var(--mono);font-size:11px;color:var(--warn);display:flex;align-items:center;gap:8px}
.banner a{color:var(--warn);text-decoration:underline}

/* Empty */
.empty-row td{text-align:center;padding:48px;color:var(--muted);font-family:var(--mono);font-size:12px}

@media(max-width:1300px){.grand-row{grid-template-columns:repeat(4,1fr)}}
@media(max-width:900px){.grand-row{grid-template-columns:repeat(3,1fr)}}
@media(max-width:700px){.grand-row{grid-template-columns:1fr 1fr}}
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
  <a class="nav-link active" href="agents.php">Agent Monitor</a>
  <a class="nav-link" href="accounts.php">Accounts</a>
  <a class="nav-link" href="lifespan.php">Playtime</a>
  <a class="nav-link" href="mover.php">Trade Mover</a>
  <div class="nav-right">
    <div class="clock" id="clockEl"></div>
    <span class="ref-label" id="refEl"></span>
  </div>
</nav>

<div class="wrap">

  <?php if (!$apiKey): ?>
  <div class="banner">⚠ No EternalFarm API key set. <a href="index.php">Go to Creator GUI → Settings</a> to add your key.</div>
  <?php elseif ($fetchError): ?>
  <div class="banner">⚠ <?= htmlspecialchars($fetchError) ?></div>
  <?php endif; ?>

  <!-- Controls -->
  <div class="ctrl-bar">
    <span class="slabel"><?= count($agents) ?> agents</span>
    <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
      <span class="slabel">Refresh:</span>
      <form method="post" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="action" value="set_refresh">
        <input type="number" name="refresh_sec" value="<?= $refreshSec ?>" min="5" max="3600" style="width:70px">
        <button type="submit" class="btn btn-subtle">Set</button>
      </form>
    </div>
  </div>

  <!-- Grand totals -->
  <?php if (!empty($alerts)): ?>
  <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px">
    <?php foreach ($alerts as $al): ?>
      <?php if ($al['type'] === 'idle'): ?>
        <div class="alert-banner alert-warn">⚠ <strong><?= htmlspecialchars($al['name']) ?></strong> is ONLINE but has 0 active threads — possible crash or misconfiguration.</div>
      <?php elseif ($al['type'] === 'stale'): ?>
        <div class="alert-banner alert-danger">⚠ <strong><?= htmlspecialchars($al['name']) ?></strong> last action check was <?= floor($al['sec']/60) ?>m ago — agent may be frozen.</div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <div class="grand-row">
    <div class="gc g-online">
      <div class="gl">Online</div>
      <div class="gv c-green"><?= $totalOnline ?></div>
    </div>
    <div class="gc g-offline">
      <div class="gl">Offline</div>
      <div class="gv c-red"><?= $totalOffline ?></div>
    </div>
    <div class="gc g-threads">
      <div class="gl">Active Threads</div>
      <div class="gv c-blue"><?= number_format($totalActiveThreads) ?></div>
    </div>
    <div class="gc g-cap">
      <div class="gl">Total Capacity</div>
      <div class="gv c-purple"><?= number_format($totalCapacity) ?></div>
    </div>
    <div class="gc g-util" style="position:relative;overflow:hidden">
      <div style="content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent2),transparent)"></div>
      <div class="gl">Fleet Utilization</div>
      <div class="gv" style="color:<?= $fleetPct >= 90 ? 'var(--danger)' : ($fleetPct >= 60 ? 'var(--warn)' : 'var(--accent)') ?>"><?= $fleetPct ?>%</div>
      <div style="margin-top:6px;height:4px;background:rgba(26,35,53,.8);border-radius:2px;overflow:hidden">
        <div style="height:100%;width:<?= $fleetPct ?>%;background:<?= $fleetPct >= 90 ? 'var(--danger)' : ($fleetPct >= 60 ? 'var(--warn)' : 'var(--accent)') ?>;border-radius:2px"></div>
      </div>
    </div>
    <div class="gc g-checker">
      <div class="gl">Checker Enabled</div>
      <div class="gv c-yellow"><?= $totalCheckerOn ?></div>
    </div>
    <div class="gc g-browser">
      <div class="gl">Browser Enabled</div>
      <div class="gv c-pink"><?= $totalBrowserOn ?></div>
    </div>
  </div>

  <!-- Agent table -->
  <div class="tbl-wrap">
    <div class="top-accent"></div>
    <div class="tbl-head-bar">
      <h2>⬡ Agents</h2>
      <span class="sub">live from ef api · auto-refresh <?= $refreshSec ?>s</span>
    </div>
    <table>
      <thead>
        <tr>
          <th>Agent</th>
          <th>Status</th>
          <th>Threads</th>
          <th>Checker</th>
          <th>Browser</th>
          <th>CPU</th>
          <th>RAM</th>
          <th>Clients</th>
          <th>Version</th>
          <th>Last Seen</th>
          <th>Last Action</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($agents)): ?>
        <tr class="empty-row"><td colspan="10">No agents found. Check your API key.</td></tr>
        <?php endif; ?>
        <?php foreach ($agents as $a):
          $online        = $a['presence_status'] === 'online';
          $checkerStatus = trim($a['checker_presence_status'] ?? '');
          $checkerOnline = $checkerStatus === 'online';
          $checkerKnown  = in_array($checkerStatus, ['online','offline']);

          $active   = (int)($a['active_threads'] ?? 0);
          $capacity = (int)($a['total_capacity'] ?? 0);
          $pct      = $capacity > 0 ? min(100, round(($active/$capacity)*100)) : 0;
          $tbarColor = $pct >= 90 ? 'var(--danger)' : ($pct >= 60 ? 'var(--warn)' : 'var(--accent)');

          $cpu = round((float)($a['cpu_usage'] ?? 0));
          $mem = (int)($a['memory_usage'] ?? 0);
          $cpuColor = $cpu >= 95 ? 'var(--danger)' : ($cpu >= 70 ? 'var(--warn)' : 'var(--accent2)');
          $memColor = $mem >= 95 ? 'var(--danger)' : ($mem >= 80 ? 'var(--warn)' : 'var(--accent2)');

          $clients = enabledClients($a);
          $lastSeen = $a['presence_updated_at'] ?? null;
          $lastSeenDiff = $lastSeen ? (time() - strtotime($lastSeen)) : 9999;
          $lastSeenClass = $lastSeenDiff < 120 ? 'fresh' : ($lastSeenDiff > 3600 ? 'stale' : '');
        ?>
        <tr>
          <!-- Agent name -->
          <td>
            <div class="agent-name"><?= htmlspecialchars($a['name']) ?></div>
            <div class="agent-id"># <?= $a['id'] ?></div>
          </td>

          <!-- Online status -->
          <td>
            <?php if ($online): ?>
              <span class="badge b-online"><span class="badge-dot"></span>ONLINE</span>
            <?php else: ?>
              <span class="badge b-offline"><span class="badge-dot"></span>OFFLINE</span>
            <?php endif; ?>
          </td>

          <!-- Threads bar -->
          <td>
            <div class="tbar-wrap">
              <div class="tbar-track">
                <div class="tbar-fill" style="width:<?= $pct ?>%;background:<?= $tbarColor ?>"></div>
              </div>
              <span class="tbar-lbl" style="color:<?= $tbarColor ?>"><?= $active ?>/<?= $capacity ?></span>
            </div>
          </td>

          <!-- Checker -->
          <td>
            <?php if (!$a['checker_enabled']): ?>
              <span class="b-disabled">DISABLED</span>
            <?php elseif ($checkerOnline): ?>
              <span class="badge b-online" style="font-size:9px"><span class="badge-dot"></span>ONLINE</span>
            <?php elseif ($checkerStatus === 'offline'): ?>
              <span class="badge b-offline" style="font-size:9px"><span class="badge-dot"></span>OFFLINE</span>
            <?php else: ?>
              <span class="b-enabled">ENABLED</span>
            <?php endif; ?>
            <?php if ($a['checker_enabled'] && $a['checker_threads'] > 0): ?>
              <div style="font-family:var(--mono);font-size:9px;color:var(--text2);margin-top:3px"><?= $a['checker_threads'] ?> threads</div>
            <?php endif; ?>
          </td>

          <!-- Browser -->
          <td>
            <?php if ($a['browser_enabled']): ?>
              <span class="b-yes">ENABLED</span>
              <?php if ($a['browser_threads'] > 0): ?>
                <div style="font-family:var(--mono);font-size:9px;color:var(--text2);margin-top:3px"><?= $a['browser_threads'] ?> threads</div>
              <?php endif; ?>
            <?php else: ?>
              <span class="b-no">OFF</span>
            <?php endif; ?>
          </td>

          <!-- CPU -->
          <td>
            <div class="res-bar">
              <div class="res-track">
                <div class="res-fill" style="width:<?= $cpu ?>%;background:<?= $cpuColor ?>"></div>
              </div>
              <span class="res-lbl" style="color:<?= $cpuColor ?>"><?= $cpu ?>%</span>
            </div>
          </td>

          <!-- RAM -->
          <td>
            <div class="res-bar">
              <div class="res-track">
                <div class="res-fill" style="width:<?= $mem ?>%;background:<?= $memColor ?>"></div>
              </div>
              <span class="res-lbl" style="color:<?= $memColor ?>"><?= $mem ?>%</span>
            </div>
          </td>

          <!-- Enabled clients -->
          <td>
            <?php if (empty($clients)): ?>
              <span style="color:var(--muted);font-family:var(--mono);font-size:10px">—</span>
            <?php else: ?>
              <div class="client-list">
                <?php foreach ($clients as $c): ?>
                  <span class="cpill"><?= htmlspecialchars($c) ?></span>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </td>

          <!-- Version -->
          <td>
            <span class="agent-ver"><?= htmlspecialchars($a['presence_version'] ?? '—') ?></span>
          </td>

          <!-- Last seen -->
          <td>
            <span class="lastseen <?= $lastSeenClass ?>"><?= timeAgo($lastSeen) ?></span>
          </td>

          <!-- Last action check -->
          <?php
            $lastAction = $a['instance_actions_checked_at'] ?? null;
            $actionDiff = $lastAction ? (time() - strtotime($lastAction)) : 9999;
            $actionClass = ($online && $actionDiff > 180) ? 'stale' : ($actionDiff < 120 ? 'fresh' : '');
          ?>
          <td>
            <?php if (!$lastAction): ?>
              <span style="color:var(--muted);font-family:var(--mono);font-size:10px">—</span>
            <?php else: ?>
              <span class="lastseen <?= $actionClass ?>"><?= timeAgo($lastAction) ?></span>
              <?php if ($online && $actionDiff > 180): ?>
                <div style="font-family:var(--mono);font-size:9px;color:var(--danger);margin-top:2px">FROZEN?</div>
              <?php endif; ?>
            <?php endif; ?>
          </td>

        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<script>
// Clock
(function(){ function t(){ document.getElementById('clockEl').textContent=new Date().toLocaleTimeString('en-US',{hour12:false}); } t(); setInterval(t,1000); })();

// ── Discord helpers ──────────────────────────────────────────────────────────
const WEBHOOK = <?= json_encode($gui['discord_webhook'] ?? '') ?>;

function discordEmbed(embed) {
  if (!WEBHOOK) return;
  fetch(WEBHOOK, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify({embeds:[embed]})
  }).catch(()=>{});
}

// ── Agent state tracking for offline + utilization alerts ───────────────────
// Stored in sessionStorage so it survives the page reload cycle
function loadState() {
  try { return JSON.parse(sessionStorage.getItem('agentState') || '{}'); } catch(e) { return {}; }
}
function saveState(s) {
  try { sessionStorage.setItem('agentState', JSON.stringify(s)); } catch(e) {}
}

const AGENT_DATA = <?= json_encode(array_map(fn($a) => [
  'name'           => $a['name'],
  'status'         => $a['presence_status'],
  'active_threads' => (int)($a['active_threads'] ?? 0),
  'total_capacity' => (int)($a['total_capacity'] ?? 0),
], $agents)) ?>;

const NOW_TS   = <?= time() ?>;
const UTIL_THROTTLE_SEC = 6 * 3600; // 6 hours between underutil alerts per agent

(function checkAlerts(){
  const state = loadState();
  const alerts = [];

  AGENT_DATA.forEach(agent => {
    const key     = 'agent_' + agent.name.replace(/\W/g,'_');
    const prev    = state[key] || {};

    // ── Offline alert: was online last refresh, now offline ──────────────────
    if (prev.status === 'online' && agent.status !== 'online') {
      alerts.push({
        title: '🔴 Agent Went Offline',
        color: 15158332,
        fields: [
          {name:'Agent', value:agent.name, inline:true},
          {name:'Previous Status', value:'online', inline:true},
        ],
        footer: {text:'Jagex Creator · Agent Monitor'},
        timestamp: new Date().toISOString(),
      });
    }

    // ── Underutilization alert: online, capacity > 0, under 75% ─────────────
    const util = agent.total_capacity > 0
      ? agent.active_threads / agent.total_capacity
      : 1;
    if (agent.status === 'online' && agent.total_capacity > 0 && util < 0.75) {
      const lastAlert = prev.util_alerted_at || 0;
      if ((NOW_TS - lastAlert) >= UTIL_THROTTLE_SEC) {
        state[key] = {...(state[key]||{}), util_alerted_at: NOW_TS};
        alerts.push({
          title: '⚠️ Agent Underutilized',
          color: 16776960,
          fields: [
            {name:'Agent',          value:agent.name,                                         inline:true},
            {name:'Utilization',    value: Math.round(util*100)+'%',                          inline:true},
            {name:'Threads',        value: agent.active_threads+'/'+agent.total_capacity,     inline:true},
          ],
          footer: {text:'Jagex Creator · Agent Monitor (max 1 alert per 6h per agent)'},
          timestamp: new Date().toISOString(),
        });
      }
    }

    // Update stored status
    state[key] = {...(state[key]||{}), status: agent.status};
  });

  saveState(state);
  alerts.forEach(embed => discordEmbed(embed));
})();

// ── Countdown auto-refresh — stops if user navigated away ───────────────────
(function(){
  let r = <?= $refreshSec ?>;
  const el = document.getElementById('refEl');
  if (!el) return;
  el.textContent = 'refresh ' + r + 's';
  let iv = setInterval(function(){
    if (document.hidden) return; // tab not visible, don't reload
    r--;
    el.textContent = 'refresh ' + r + 's';
    if (r < 0) { clearInterval(iv); location.reload(); }
  }, 1000);
  // If user navigates away, kill the interval so it can't fire location.reload()
  // on a page that has already unloaded (which confuses the browser history)
  window.addEventListener('beforeunload', function(){ clearInterval(iv); });
})();
</script>
</body>
</html>
