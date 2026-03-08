<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  Jagex Account Creator — Account Dashboard (dashboard.php)
//  Reads EF API key from gui_config.json (shared with index.php)
// ═══════════════════════════════════════════════════════════════════════════════

$guiConfig = __DIR__ . '/gui_config.json';

function loadGui(string $p): array {
    if (!file_exists($p)) return ['ef_api_key'=>'','categories'=>[]];
    return json_decode(file_get_contents($p), true) ?? [];
}
function saveGui(string $p, array $d): void {
    file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// ── Snapshot helpers for GP/hr and survival rate tracking ────────────────────
function loadSnapshots(string $guiConfig): array {
    $f = dirname($guiConfig) . '/dashboard_snapshots.json';
    if (!file_exists($f)) return [];
    return json_decode(file_get_contents($f), true) ?? [];
}
function saveSnapshots(string $guiConfig, array $snaps): void {
    $f = dirname($guiConfig) . '/dashboard_snapshots.json';
    // Keep last 60 snapshots per category (~1hr at 60s refresh)
    foreach ($snaps as $id => $entries) {
        if (count($entries) > 60) $snaps[$id] = array_slice($entries, -60);
    }
    file_put_contents($f, json_encode($snaps));
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

function fetchAllEFCategories(string $key): array {
    if (!$key) return [];
    foreach (['account-categories','account_categories','categories','accountCategories'] as $ep) {
        $page = 1; $per = 100; $all = [];
        while (true) {
            $data = efget($ep, ['page'=>$page,'per_page'=>$per], $key);
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
            foreach ($all as $id => $name) $out[] = ['id'=>$id,'name'=>$name];
            usort($out, fn($a,$b) => strcasecmp($a['name'],$b['name']));
            return $out;
        }
    }
    return [];
}

function fetchCategoryData(string $key, string $catId): array {
    $page=1; $per=200; $gp=0;
    $total=0; $banned=0; $active=0; $gotMeta=false;
    while (true) {
        $data = efget('accounts', ['page'=>$page,'per_page'=>$per,'account_category_id'=>$catId], $key);
        if (!$data) break;
        if (!$gotMeta && isset($data['meta']['statuses'])) {
            $s = $data['meta']['statuses'];
            $active  = (int)($s['active_f2p']??0)+(int)($s['active_p2p']??0)+(int)($s['active_unknown']??0);
            $banned  = (int)($s['banned']??0)+(int)($s['banned_permanent']??0)+(int)($s['banned_temporary']??0);
            $total   = (int)($data['meta']['total'] ?? array_sum(array_values($s)));
            $gotMeta = true;
        }
        $rows = $data['data'] ?? (isset($data[0]) ? $data : []);
        if (!$rows) break;
        foreach ($rows as $acc) {
            if (!$gotMeta) {
                $total++;
                $st = strtolower((string)($acc['status']??''));
                if ($st==='banned'||str_starts_with($st,'banned_')) $banned++;
                elseif (str_starts_with($st,'active')) $active++;
            }
            $gp += (int)($acc['coins'] ?? 0);
        }
        $last = $data['meta']['last_page'] ?? $data['meta']['lastPage'] ?? null;
        if ($last !== null) { if ($page >= $last) break; $page++; continue; }
        if (count($rows) < $per) break;
        $page++;
    }
    return ['total'=>$total,'banned'=>$banned,'active'=>$active,'gp'=>$gp];
}

function fmtGold(int $n): string {
    if ($n >= 1_000_000_000) return number_format($n/1_000_000_000,2).'B';
    if ($n >= 1_000_000)     return number_format($n/1_000_000,    2).'M';
    if ($n >= 1_000)         return number_format($n/1_000,        1).'K';
    return (string)$n;
}

// ── Load ───────────────────────────────────────────────────────────────────────
$gui        = loadGui($guiConfig);
$apiKey     = trim($gui['ef_api_key'] ?? '');
$refreshSec = max(10, (int)($gui['dash_refresh_sec'] ?? 60));
$monitored  = $gui['dash_categories'] ?? [];

// ── POST ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_category') {
        $id   = trim($_POST['category_id']   ?? '');
        $name = trim($_POST['category_name'] ?? '');
        $exists = array_filter($monitored, fn($c) => (string)$c['id'] === $id);
        if ($id && !$exists) {
            $monitored[] = ['id'=>$id,'name'=>$name ?: "Category {$id}"];
            $gui['dash_categories'] = $monitored;
            saveGui($guiConfig, $gui);
        }
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
    if ($action === 'remove_category') {
        $id = trim($_POST['category_id'] ?? '');
        $gui['dash_categories'] = array_values(array_filter($monitored, fn($c) => (string)$c['id'] !== $id));
        saveGui($guiConfig, $gui);
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
    if ($action === 'set_refresh') {
        $gui['dash_refresh_sec'] = max(10, (int)($_POST['refresh_sec'] ?? 60));
        saveGui($guiConfig, $gui);
        header('Location: '.$_SERVER['PHP_SELF']); exit;
    }
}

// ── Fetch data ─────────────────────────────────────────────────────────────────
$efCategories = fetchAllEFCategories($apiKey);

$cards = [];
$grandTotal = $grandBanned = $grandActive = $grandGP = 0;
foreach ($monitored as $cat) {
    $id = (string)$cat['id'];
    $d  = $apiKey ? fetchCategoryData($apiKey, $id) : ['total'=>0,'banned'=>0,'active'=>0,'gp'=>0];
    $cards[] = array_merge(['id'=>$id,'name'=>$cat['name']], $d);
    $grandTotal  += $d['total'];
    $grandBanned += $d['banned'];
    $grandActive += $d['active'];
    $grandGP     += $d['gp'];
}

// Snapshot + trend tracking
$snaps = loadSnapshots($guiConfig);
$now   = time();

foreach ($cards as &$card) {
    $id  = $card['id'];
    $pct = $card['total'] > 0 ? round(($card['active']/$card['total'])*100,1) : 0;

    // Add snapshot if enough time has passed since last one
    $last = !empty($snaps[$id]) ? end($snaps[$id]) : null;
    if (!$last || ($now - $last['ts']) >= ($refreshSec - 2)) {
        $snaps[$id][] = ['ts' => $now, 'gp' => $card['gp'], 'pct' => $pct];
    }

    // ── GP/hr via linear regression over a 30-min rolling window ──────────────
    // Instead of just comparing the last two points (noisy), we fit a line
    // through all points in the last 30 minutes. The slope of that line
    // (GP per second) is then multiplied by 3600 for GP/hr.
    // This smooths out the estimate as more data accumulates.
    $card['gp_hr']       = 0;
    $card['gp_hr_stable']= false; // true once we have ≥5 min of data
    $card['gp_hr_age']   = 0;    // minutes of data we're averaging over

    if (!empty($snaps[$id]) && count($snaps[$id]) >= 2) {
        // Pull all points from the last 30 minutes
        $window = array_values(array_filter($snaps[$id], fn($s) => ($now - $s['ts']) <= 1800));
        if (count($window) < 2) $window = array_slice($snaps[$id], -2);

        $n = count($window);
        // Linear regression: y = gp, x = timestamp (seconds)
        $sumX = $sumY = $sumXY = $sumX2 = 0;
        foreach ($window as $pt) {
            $sumX  += $pt['ts'];
            $sumY  += $pt['gp'];
            $sumXY += $pt['ts'] * $pt['gp'];
            $sumX2 += $pt['ts'] * $pt['ts'];
        }
        $denom = ($n * $sumX2 - $sumX * $sumX);
        if ($denom != 0) {
            $slope = ($n * $sumXY - $sumX * $sumY) / $denom; // GP per second
            $card['gp_hr'] = (int)round($slope * 3600);
        }

        $spanMin = round(($window[$n-1]['ts'] - $window[0]['ts']) / 60, 1);
        $card['gp_hr_age']    = $spanMin;
        $card['gp_hr_stable'] = $spanMin >= 5;
    }

    // ── Survival drop alert: dropped ≥5% vs 10 min ago (not just last tick) ──
    $card['survival_drop'] = false;
    if (!empty($snaps[$id]) && count($snaps[$id]) >= 2) {
        // Find a snapshot from ~10 minutes ago
        $ref = null;
        foreach ($snaps[$id] as $s) {
            if (($now - $s['ts']) <= 600) { $ref = $s; break; }
        }
        if (!$ref) $ref = $snaps[$id][0]; // oldest available
        $curr = end($snaps[$id])['pct'];
        if (($ref['pct'] - $curr) >= 5) $card['survival_drop'] = true;
    }
    $card['survival_pct'] = $pct;
}
unset($card);
saveSnapshots($guiConfig, $snaps);

// Build alerts
$dashAlerts = [];
foreach ($cards as $card) {
    if ($card['survival_drop']) {
        $dashAlerts[] = '⚠ <strong>'.htmlspecialchars($card['name']).'</strong> survival rate dropped — possible ban wave.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Account Dashboard</title>
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

nav{background:var(--surface);border-bottom:1px solid var(--border);padding:0 28px;display:flex;align-items:stretch;gap:0}
.nav-logo{display:flex;align-items:center;gap:10px;padding:12px 20px 12px 0;border-right:1px solid var(--border);margin-right:8px}
.nav-logo .mark{width:28px;height:28px;border:2px solid var(--accent);border-radius:5px;display:grid;place-items:center;font-family:var(--mono);font-size:13px;color:var(--accent);text-shadow:0 0 6px var(--accent)}
.nav-logo span{font-size:16px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.nav-logo span em{color:var(--accent);font-style:normal}
.nav-link{display:flex;align-items:center;padding:0 18px;font-family:var(--mono);font-size:11px;letter-spacing:1.5px;text-transform:uppercase;color:var(--text2);text-decoration:none;border-bottom:2px solid transparent;transition:all .15s}
.nav-link:hover{color:var(--text)}
.nav-link.active{color:var(--accent);border-bottom-color:var(--accent)}
.nav-right{margin-left:auto;display:flex;align-items:center;gap:14px}
.clock{font-family:var(--mono);font-size:11px;color:var(--text2)}
.refresh-label{font-family:var(--mono);font-size:11px;color:var(--muted)}

.wrap{max-width:1380px;margin:0 auto;padding:24px}

/* Controls bar */
.ctrl-bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:22px;
  background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 16px}
.ctrl-bar form{display:flex;align-items:center;gap:8px}
select,input[type="number"]{background:var(--surface);border:1px solid var(--border);color:var(--text);
  font-family:var(--mono);font-size:12px;padding:7px 10px;border-radius:4px;outline:none;transition:border-color .15s}
select:focus,input:focus{border-color:rgba(0,255,136,.4)}
.btn{padding:7px 14px;border-radius:4px;border:none;font-family:var(--head);font-size:12px;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s}
.btn-accent{background:rgba(0,255,136,.1);color:var(--accent);border:1px solid rgba(0,255,136,.25)}
.btn-accent:hover{background:rgba(0,255,136,.18)}
.btn-subtle{background:rgba(255,255,255,.03);color:var(--text2);border:1px solid var(--border)}
.btn-subtle:hover{background:rgba(255,255,255,.07);color:var(--text)}
.btn-danger{background:rgba(255,59,92,.1);color:var(--danger);border:1px solid rgba(255,59,92,.25)}
.btn-danger:hover{background:rgba(255,59,92,.18)}
.slabel{font-family:var(--mono);font-size:10px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;white-space:nowrap}

/* Grand totals row */
.grand-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px}
.grand-card{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:16px 18px;position:relative;overflow:hidden}
.grand-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.grand-card.gc-active::before{background:linear-gradient(90deg,var(--accent),transparent)}
.grand-card.gc-banned::before{background:linear-gradient(90deg,var(--danger),transparent)}
.grand-card.gc-total::before{background:linear-gradient(90deg,var(--accent2),transparent)}
.grand-card.gc-gp::before{background:linear-gradient(90deg,var(--warn),transparent)}
.grand-card .gl{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:6px}
.grand-card .gv{font-family:var(--mono);font-size:28px;line-height:1}
.gv.green{color:var(--accent)}.gv.red{color:var(--danger)}.gv.blue{color:var(--accent2)}.gv.yellow{color:var(--warn)}

/* Cards grid */
.cards-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px}
.cat-card{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden;transition:border-color .2s,box-shadow .2s}
.cat-card:hover{border-color:rgba(0,255,136,.2);box-shadow:0 0 18px rgba(0,255,136,.05)}
.card-head{padding:11px 15px;display:flex;align-items:center;gap:8px;border-bottom:1px solid var(--border);
  background:linear-gradient(90deg,rgba(0,255,136,.03) 0%,transparent 70%)}
.card-head h2{font-family:var(--head);font-size:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase}
.id-pill{margin-left:auto;font-family:var(--mono);font-size:9px;color:var(--text2);background:var(--surface);border:1px solid var(--border);padding:2px 7px;border-radius:8px}

.stat-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1px;background:var(--border)}
.sc{background:var(--card);padding:11px 13px}
.sc.sp2{grid-column:span 2}
.sl{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:4px}
.sv{font-family:var(--mono);font-size:20px;line-height:1}
.c-a{color:var(--accent)}.c-b{color:var(--danger)}.c-t{color:var(--accent2)}.c-g{color:var(--warn)}.c-p{color:var(--purple)}

.bar-wrap{padding:8px 14px 11px;border-top:1px solid var(--border)}
.bar-lbl{display:flex;justify-content:space-between;font-family:var(--mono);font-size:9px;color:var(--text2);margin-bottom:5px}
.bar-track{height:4px;border-radius:2px;background:rgba(255,59,92,.2);overflow:hidden}
.bar-fill{height:100%;border-radius:2px;transition:width .4s}

.card-foot{padding:8px 14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.foot-note{font-family:var(--mono);font-size:9px;color:var(--muted)}

/* Empty */
.empty{display:flex;flex-direction:column;align-items:center;justify-content:center;min-height:260px;gap:12px;color:var(--text2);font-family:var(--mono);font-size:12px;text-align:center}
.empty .big{font-size:48px;opacity:.1}

/* No key */
.banner{background:rgba(255,184,46,.06);border:1px solid rgba(255,184,46,.2);border-radius:6px;padding:11px 16px;margin-bottom:18px;font-family:var(--mono);font-size:11px;color:var(--warn);display:flex;align-items:center;gap:8px}
.banner a{color:var(--warn);text-decoration:underline}

#toast{position:fixed;bottom:22px;right:22px;background:var(--card);border:1px solid var(--accent);color:var(--accent);font-family:var(--mono);font-size:11px;padding:9px 16px;border-radius:5px;box-shadow:0 0 18px rgba(0,255,136,.12);opacity:0;transform:translateY(8px);transition:all .2s;pointer-events:none;z-index:9999}
#toast.show{opacity:1;transform:translateY(0)}

@media(max-width:700px){.grand-row{grid-template-columns:1fr 1fr}.cards-grid{grid-template-columns:1fr}}
</style>
</head>
<body>

<nav>
  <div class="nav-logo">
    <div class="mark">J</div>
    <span>Jagex <em>Creator</em></span>
  </div>
  <a class="nav-link" href="index.php">Creator GUI</a>
  <a class="nav-link active" href="dashboard.php">Account Dashboard</a>
  <a class="nav-link" href="agents.php">Agent Monitor</a>
  <a class="nav-link" href="accounts.php">Accounts</a>
  <div class="nav-right">
    <div class="clock" id="clockEl"></div>
    <span class="refresh-label" id="refEl"></span>
  </div>
</nav>

<div class="wrap">

  <?php if (!$apiKey): ?>
  <div class="banner">
    ⚠ No EternalFarm API key set. <a href="index.php">Go to Creator GUI → Settings</a> to add your key.
  </div>
  <?php endif; ?>

  <!-- Controls bar -->
  <div class="ctrl-bar">
    <span class="slabel">Monitor:</span>
    <form method="post">
      <input type="hidden" name="action" value="add_category">
      <select name="category_id" id="catSel" required>
        <option value="">
          <?= $apiKey ? 'Select EF category ('.count($efCategories).' found)…' : 'Set API key first…' ?>
        </option>
        <?php foreach ($efCategories as $c): ?>
          <option value="<?= htmlspecialchars($c['id']) ?>" data-name="<?= htmlspecialchars($c['name']) ?>">
            <?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['id']) ?>)
          </option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="category_name" id="catNameHid">
      <button type="submit" class="btn btn-accent" <?= !$apiKey ? 'disabled' : '' ?>>+ Add</button>
    </form>

    <div style="margin-left:auto;display:flex;align-items:center;gap:10px">
      <span class="slabel">Refresh:</span>
      <form method="post" style="display:flex;gap:8px;align-items:center">
        <input type="hidden" name="action" value="set_refresh">
        <input type="number" name="refresh_sec" value="<?= $refreshSec ?>" min="10" max="3600" style="width:70px">
        <button type="submit" class="btn btn-subtle">Set</button>
      </form>
    </div>
  </div>

  <!-- Alerts -->
  <?php if (!empty($dashAlerts)): ?>
  <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:16px">
    <?php foreach ($dashAlerts as $al): ?>
      <div style="padding:9px 14px;border-radius:6px;font-family:var(--mono);font-size:11px;background:rgba(255,59,92,.07);color:var(--danger);border:1px solid rgba(255,59,92,.2)"><?= $al ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Grand totals -->
  <?php if (!empty($cards)): ?>
  <div class="grand-row">
    <div class="grand-card gc-active">
      <div class="gl">Total Active</div>
      <div class="gv green"><?= number_format($grandActive) ?></div>
    </div>
    <div class="grand-card gc-banned">
      <div class="gl">Total Banned</div>
      <div class="gv red"><?= number_format($grandBanned) ?></div>
    </div>
    <div class="grand-card gc-total">
      <div class="gl">Total Accounts</div>
      <div class="gv blue"><?= number_format($grandTotal) ?></div>
    </div>
    <div class="grand-card gc-gp">
      <div class="gl">Total GP (all cats)</div>
      <div class="gv yellow"><?= fmtGold($grandGP) ?></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Category cards -->
  <?php if (empty($cards)): ?>
    <div class="empty">
      <div class="big">◈</div>
      <div>No categories monitored</div>
      <div style="color:var(--muted);font-size:10px">Use the dropdown above to add an EF category</div>
    </div>
  <?php else: ?>
  <div class="cards-grid">
    <?php foreach ($cards as $c):
      $pct      = ($c['total'] > 0) ? round(($c['active']/$c['total'])*100, 1) : 0.0;
      $barColor = $pct >= 70 ? 'var(--accent)' : ($pct >= 40 ? 'var(--warn)' : 'var(--danger)');
      $pctClass = $pct >= 70 ? 'c-a' : ($pct >= 40 ? 'c-g' : 'c-b');
      $gpPerAcc = $c['active'] > 0 ? (int)round($c['gp'] / $c['active']) : 0;
    ?>
    <div class="cat-card">
      <div class="card-head">
        <h2><?= htmlspecialchars($c['name']) ?></h2>
        <span class="id-pill"># <?= htmlspecialchars($c['id']) ?></span>
      </div>

      <div class="stat-grid">
        <div class="sc">
          <div class="sl">Active</div>
          <div class="sv c-a"><?= number_format($c['active']) ?></div>
        </div>
        <div class="sc">
          <div class="sl">Banned</div>
          <div class="sv c-b"><?= number_format($c['banned']) ?></div>
        </div>
        <div class="sc">
          <div class="sl">Total</div>
          <div class="sv c-t"><?= number_format($c['total']) ?></div>
        </div>
        <div class="sc">
          <div class="sl">GP Total</div>
          <div class="sv c-g"><?= fmtGold($c['gp']) ?></div>
        </div>
        <div class="sc">
          <div class="sl">GP / Active Acct</div>
          <div class="sv c-p"><?= $gpPerAcc > 0 ? fmtGold($gpPerAcc) : '—' ?></div>
        </div>
        <div class="sc" style="grid-column:span 3">
          <?php
            $gphr    = (int)($c['gp_hr'] ?? 0);
            $stable  = !empty($c['gp_hr_stable']);
            $age     = (float)($c['gp_hr_age'] ?? 0);
            $hrColor = $gphr < 0 ? 'var(--danger)' : ($gphr > 0 ? 'var(--warn)' : 'var(--muted)');
            $conf    = $stable ? 'HIGH' : ($age >= 2 ? 'MED' : 'LOW');
            $confCol = $stable ? 'var(--accent)' : ($age >= 2 ? 'var(--warn)' : 'var(--muted)');
            $ageStr  = $age >= 1 ? round($age).'m window' : ($age > 0 ? round($age*60).'s window' : 'collecting…');
          ?>
          <div style="display:flex;align-items:center;justify-content:space-between">
            <div class="sl">GP / Hour <span style="color:<?= $confCol ?>;font-size:8px;margin-left:4px"><?= $conf ?> CONF</span></div>
            <div style="font-family:var(--mono);font-size:9px;color:var(--muted)"><?= $ageStr ?></div>
          </div>
          <div style="display:flex;align-items:baseline;gap:10px;margin-top:4px">
            <div class="sv" style="font-size:20px;color:<?= $hrColor ?>">
              <?= $gphr !== 0 ? ($gphr > 0 ? '+' : '') . fmtGold($gphr) : '—' ?>
            </div>
            <?php if ($gphr !== 0 && $c['active'] > 0): ?>
            <div style="font-family:var(--mono);font-size:10px;color:var(--muted)">
              <?= ($gphr > 0 ? '+' : '') . fmtGold((int)round($gphr / $c['active'])) ?>/acct
            </div>
            <?php endif; ?>
          </div>
          <?php if (!$stable && $age > 0): ?>
          <div style="margin-top:6px;height:2px;background:rgba(26,35,53,.8);border-radius:1px;overflow:hidden">
            <div style="height:100%;width:<?= min(100, round($age/5*100)) ?>%;background:var(--accent2);border-radius:1px;transition:width 1s"></div>
          </div>
          <div style="font-family:var(--mono);font-size:8px;color:var(--muted);margin-top:3px">building confidence · <?= round(max(0,5-$age),1) ?>m until stable</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="bar-wrap">
        <div class="bar-lbl">
          <span>Survival rate</span>
          <span class="<?= $pctClass ?>"><?= $pct ?>%</span>
        </div>
        <div class="bar-track">
          <div class="bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>"></div>
        </div>
      </div>

      <?php if (!empty($c['survival_drop'])): ?>
      <div style="padding:6px 14px;background:rgba(255,59,92,.06);border-top:1px solid rgba(255,59,92,.15);font-family:var(--mono);font-size:9px;color:var(--danger)">
        ⚠ SURVIVAL RATE DROPPED — possible ban wave
      </div>
      <?php endif; ?>
      <div class="card-foot">
        <span class="foot-note">gp live from ef api · auto-refresh <?= $refreshSec ?>s</span>
        <form method="post">
          <input type="hidden" name="action" value="remove_category">
          <input type="hidden" name="category_id" value="<?= htmlspecialchars($c['id']) ?>">
          <button type="submit" class="btn btn-danger"
            style="padding:4px 10px;font-size:10px"
            onclick="return confirm('Remove <?= htmlspecialchars(addslashes($c['name'])) ?>?')">
            Remove
          </button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>

<div id="toast"></div>

<script>
// Clock
(function(){ function t(){ document.getElementById('clockEl').textContent=new Date().toLocaleTimeString('en-US',{hour12:false}); } t(); setInterval(t,1000); })();

// Countdown auto-refresh
(function(){
  let r = <?= $refreshSec ?>;
  const el = document.getElementById('refEl');
  let iv = setInterval(function(){
    el.textContent = 'refresh ' + r + 's';
    r--;
    if (r < 0) { clearInterval(iv); location.reload(); }
  }, 1000);
  el.textContent = 'refresh ' + r + 's';
})();

// Category dropdown → hidden name
const s = document.getElementById('catSel'), h = document.getElementById('catNameHid');
if(s&&h) s.addEventListener('change',()=>{ const o=s.options[s.selectedIndex]; h.value=o?(o.dataset.name||''):''; });
</script>
</body>
</html>
