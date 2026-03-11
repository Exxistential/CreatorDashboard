<?php
// ═══════════════════════════════════════════════════════════════════════════════
//  Jagex Account Creator — Account Lifespan Tracker (lifespan.php)
//  Computes ban lifespan stats per EF category using created_at / banned_at
//  and played_time fields from the EF accounts API.
// ═══════════════════════════════════════════════════════════════════════════════
ini_set('memory_limit', '64M');
set_time_limit(120);

$guiConfig = __DIR__ . '/gui_config.json';

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

// ── Fetch all accounts for a category, returning only lifespan-relevant fields ─
function fetchLifespanData(string $key, string $catId): array {
    $page = 1; $per = 200;
    $banned = []; $active = []; $total = 0;

    while (true) {
        $data = efget('accounts', [
            'page'                => $page,
            'per_page'            => $per,
            'account_category_id' => $catId,
        ], $key);
        if (!$data) break;

        $rows = $data['data'] ?? [];
        if (!$rows) break;
        $total += count($rows);

        foreach ($rows as $acc) {
            $status   = strtolower((string)($acc['status'] ?? ''));
            $bannedAt = $acc['banned_at'] ?? null;
            $playedMs = (int)($acc['played_time'] ?? 0);
            $banCount = (int)($acc['ban_count']   ?? 0);
            $playedHr = round($playedMs / 3_600_000, 4); // keep 4dp for sub-hour accuracy

            if ($bannedAt && str_starts_with($status, 'banned')) {
                $banned[] = [
                    'played_hr' => $playedHr,
                    'ban_count' => $banCount,
                    'banned_ts' => strtotime($bannedAt),
                ];
            } elseif (str_starts_with($status, 'active')) {
                $active[] = ['played_hr' => $playedHr];
            }
        }

        $last = $data['meta']['last_page'] ?? $data['meta']['lastPage'] ?? null;
        if ($last !== null) { if ($page >= $last) break; $page++; continue; }
        if (count($rows) < $per) break;
        $page++;
    }

    return ['banned' => $banned, 'active' => $active, 'total' => $total];
}

// ── Compute stats from raw lifespan arrays ─────────────────────────────────────
function computeStats(array $vals): array {
    if (!$vals) return ['count'=>0,'mean'=>0,'median'=>0,'min'=>0,'max'=>0,'p25'=>0,'p75'=>0,'stddev'=>0];
    sort($vals);
    $n     = count($vals);
    $mean  = array_sum($vals) / $n;
    $min   = $vals[0];
    $max   = $vals[$n-1];
    $med   = $n % 2 === 0 ? ($vals[$n/2-1] + $vals[$n/2]) / 2 : $vals[(int)($n/2)];
    $p25   = $vals[(int)($n * 0.25)];
    $p75   = $vals[(int)($n * 0.75)];
    $var   = array_sum(array_map(fn($v) => ($v-$mean)**2, $vals)) / $n;
    return ['count'=>$n,'mean'=>$mean,'median'=>$med,'min'=>$min,'max'=>$max,'p25'=>$p25,'p75'=>$p75,'stddev'=>sqrt($var)];
}

// ── Build histogram buckets (hours) ───────────────────────────────────────────
function buildHistogram(array $lifespanHrs, int $buckets = 12): array {
    if (!$lifespanHrs) return [];
    $min = 0;
    $max = max($lifespanHrs);
    if ($max <= 0) return [];
    $bucketSize = max(1, ceil($max / $buckets));
    $hist = [];
    for ($i = 0; $i < $buckets; $i++) {
        $lo = $i * $bucketSize;
        $hi = $lo + $bucketSize;
        $hist[] = ['lo'=>$lo, 'hi'=>$hi, 'count'=>0];
    }
    foreach ($lifespanHrs as $v) {
        $idx = min($buckets - 1, (int)floor($v / $bucketSize));
        $hist[$idx]['count']++;
    }
    // Remove empty trailing buckets
    while (count($hist) > 1 && end($hist)['count'] === 0) array_pop($hist);
    return $hist;
}

function fmtDuration(float $hours): string {
    if ($hours < 1)      return round($hours * 60) . 'm';
    if ($hours < 24)     return round($hours, 1) . 'h';
    if ($hours < 24 * 7) return round($hours / 24, 1) . 'd';
    return round($hours / 168, 1) . 'w';
}

// ── Handle category config ─────────────────────────────────────────────────────
$gui     = loadGui($guiConfig);
$apiKey  = trim($gui['ef_api_key'] ?? '');
$monitored = $gui['dash_categories'] ?? [];

// Add/remove categories (shared with dashboard)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_category' && !empty($_POST['category_id'])) {
        $newId   = (string)$_POST['category_id'];
        $newName = (string)($_POST['category_name'] ?? $newId);
        $exists  = array_filter($monitored, fn($c) => (string)$c['id'] === $newId);
        if (!$exists) { $monitored[] = ['id'=>$newId,'name'=>$newName]; $gui['dash_categories'] = $monitored; saveGui($guiConfig, $gui); }
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
    if ($action === 'remove_category' && !empty($_POST['category_id'])) {
        $rmId    = (string)$_POST['category_id'];
        $monitored = array_values(array_filter($monitored, fn($c) => (string)$c['id'] !== $rmId));
        $gui['dash_categories'] = $monitored; saveGui($guiConfig, $gui);
        header('Location: ' . $_SERVER['PHP_SELF']); exit;
    }
}

// ── Fetch EF categories for the add-dropdown ──────────────────────────────────
$efCategories = [];
if ($apiKey) {
    foreach (['account-categories','account_categories','categories','accountCategories'] as $ep) {
        $data = efget($ep, ['per_page'=>200], $apiKey);
        if ($data) {
            $rows = $data['data'] ?? (isset($data[0]) ? $data : []);
            if ($rows) {
                foreach ($rows as $r) {
                    if (isset($r['id'])) $efCategories[(string)$r['id']] = $r['name'] ?? "Category {$r['id']}";
                }
                break;
            }
        }
    }
}

// ── Compute lifespan data for each monitored category ─────────────────────────
$categoryStats = [];
foreach ($monitored as $cat) {
    $id   = (string)$cat['id'];
    $name = $cat['name'];

    if (!$apiKey) {
        $categoryStats[] = ['id'=>$id,'name'=>$name,'error'=>'No API key'];
        continue;
    }

    $raw  = fetchLifespanData($apiKey, $id);
    $bannedAccounts = $raw['banned'];
    $activeAccounts = $raw['active'];

    // All stats are based purely on played_time — the only reliable metric
    $playedHrsBanned  = array_column($bannedAccounts, 'played_hr');
    $playedHrsActive  = array_column($activeAccounts,  'played_hr');

    $bannedStats = computeStats($playedHrsBanned);
    $activeStats = computeStats($playedHrsActive);

    // Histogram of played_time at ban (hours)
    $histogram = buildHistogram($playedHrsBanned);

    // Ban wave detection: cluster banned_at timestamps into 1-hour windows
    $banWaves = [];
    if ($bannedAccounts) {
        $timestamps = array_column($bannedAccounts, 'banned_ts');
        sort($timestamps);
        $window = 3600;
        $waves  = [];
        $cur    = [$timestamps[0]];
        for ($i = 1; $i < count($timestamps); $i++) {
            if ($timestamps[$i] - end($cur) <= $window) {
                $cur[] = $timestamps[$i];
            } else {
                if (count($cur) >= 3) $waves[] = ['ts'=>$cur[0], 'count'=>count($cur)];
                $cur = [$timestamps[$i]];
            }
        }
        if (count($cur) >= 3) $waves[] = ['ts'=>$cur[0], 'count'=>count($cur)];
        usort($waves, fn($a,$b) => $b['count'] - $a['count']);
        $banWaves = array_slice($waves, 0, 5);
        usort($banWaves, fn($a,$b) => $a['ts'] - $b['ts']);
    }

    // Repeat ban rate
    $repeatBans = count(array_filter($bannedAccounts, fn($a) => $a['ban_count'] > 1));

    $categoryStats[] = [
        'id'           => $id,
        'name'         => $name,
        'total'        => $raw['total'],
        'banned_count' => count($bannedAccounts),
        'active_count' => count($activeAccounts),
        'banned'       => $bannedStats,
        'active'       => $activeStats,
        'histogram'    => $histogram,
        'ban_waves'    => $banWaves,
        'repeat_bans'  => $repeatBans,
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Account Playtime Stats</title>
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

.wrap{max-width:1400px;margin:0 auto;padding:24px;display:flex;flex-direction:column;gap:20px}

/* Controls */
.ctrl-bar{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:12px 16px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.slabel{font-family:var(--mono);font-size:10px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;white-space:nowrap}
select,input[type=number]{background:var(--surface);border:1px solid var(--border);color:var(--text);font-family:var(--mono);font-size:11px;padding:6px 10px;border-radius:4px;outline:none}
select:focus,input:focus{border-color:rgba(0,255,136,.4)}
.btn{padding:7px 14px;border-radius:4px;border:none;font-family:var(--head);font-size:12px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s}
.btn-accent{background:rgba(0,255,136,.1);color:var(--accent);border:1px solid rgba(0,255,136,.25)}
.btn-accent:hover{background:rgba(0,255,136,.18)}
.btn-danger{background:rgba(255,59,92,.08);color:var(--danger);border:1px solid rgba(255,59,92,.2)}
.btn-danger:hover{background:rgba(255,59,92,.15)}

.banner{background:rgba(255,184,46,.06);border:1px solid rgba(255,184,46,.2);border-radius:6px;padding:10px 16px;font-family:var(--mono);font-size:11px;color:var(--warn)}
.banner a{color:var(--warn);text-decoration:underline}

/* Category cards */
.cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(520px,1fr));gap:18px}
.cat-card{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.cat-card:hover{border-color:rgba(0,200,255,.15)}
.card-top{height:2px;background:linear-gradient(90deg,var(--accent2),transparent)}
.card-head{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;
  background:linear-gradient(90deg,rgba(0,200,255,.04),transparent 70%)}
.card-head h2{font-family:var(--head);font-size:14px;font-weight:700;letter-spacing:1.5px;text-transform:uppercase}
.id-pill{font-family:var(--mono);font-size:9px;color:var(--text2);background:var(--surface);border:1px solid var(--border);padding:2px 7px;border-radius:8px}
.banned-pill{margin-left:auto;font-family:var(--mono);font-size:9px;padding:2px 8px;border-radius:8px;background:rgba(255,59,92,.1);color:var(--danger);border:1px solid rgba(255,59,92,.2)}

/* Stat grid */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1px;background:var(--border)}
.sc{background:var(--card);padding:10px 12px}
.sl{font-family:var(--mono);font-size:9px;letter-spacing:1.5px;color:var(--text2);text-transform:uppercase;margin-bottom:3px}
.sv{font-family:var(--mono);font-size:18px;line-height:1.1}
.sv-sm{font-family:var(--mono);font-size:14px}
.sv-xs{font-family:var(--mono);font-size:11px;color:var(--text2)}
.c-green{color:var(--accent)}.c-red{color:var(--danger)}.c-blue{color:var(--accent2)}.c-yellow{color:var(--warn)}.c-purple{color:var(--purple)}

/* Histogram */
.hist-wrap{padding:14px 16px;border-top:1px solid var(--border)}
.hist-title{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:10px}
.hist-chart{display:flex;align-items:flex-end;gap:3px;height:60px}
.hist-bar-wrap{display:flex;flex-direction:column;align-items:center;flex:1;height:100%;justify-content:flex-end;gap:3px;min-width:0}
.hist-bar{width:100%;border-radius:2px 2px 0 0;transition:height .3s;min-height:1px;background:var(--accent2);opacity:.7;cursor:default}
.hist-bar:hover{opacity:1}
.hist-bar.peak{background:var(--danger);opacity:.85}
.hist-lbl{font-family:var(--mono);font-size:8px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;text-align:center}
.hist-count{font-family:var(--mono);font-size:8px;color:var(--text2);text-align:center}

/* Playtime row */
.pt-row{display:grid;grid-template-columns:1fr 1fr;gap:1px;background:var(--border);border-top:1px solid var(--border)}

/* Ban waves */
.waves-wrap{padding:10px 16px;border-top:1px solid var(--border)}
.waves-title{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:8px}
.wave-list{display:flex;flex-direction:column;gap:4px}
.wave-item{display:flex;align-items:center;gap:10px;font-family:var(--mono);font-size:10px}
.wave-date{color:var(--text2);min-width:130px}
.wave-bar-track{flex:1;height:6px;background:rgba(26,35,53,.8);border-radius:3px;overflow:hidden}
.wave-bar{height:100%;background:var(--danger);border-radius:3px;opacity:.7}
.wave-count{color:var(--danger);min-width:40px;text-align:right}

/* Remove button */
.card-foot{padding:8px 14px;border-top:1px solid var(--border);display:flex;justify-content:space-between;align-items:center}
.foot-note{font-family:var(--mono);font-size:9px;color:var(--muted)}

/* No data */
.no-data{padding:32px;text-align:center;font-family:var(--mono);font-size:11px;color:var(--muted)}

@media(max-width:900px){.cat-grid{grid-template-columns:1fr}.stat-grid{grid-template-columns:repeat(2,1fr)}}
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
  <a class="nav-link active" href="lifespan.php">Playtime</a>
  <a class="nav-link" href="mover.php">Trade Mover</a>
  <div class="nav-right">
    <div class="clock" id="clockEl"></div>
  </div>
</nav>

<div class="wrap">

  <?php if (!$apiKey): ?>
  <div class="banner">⚠ No EternalFarm API key set. <a href="index.php">Go to Creator GUI → Settings</a> to add your key.</div>
  <?php endif; ?>

  <!-- Controls: add category -->
  <div class="ctrl-bar">
    <span class="slabel">Category</span>
    <form method="post" style="display:flex;gap:8px;align-items:center">
      <input type="hidden" name="action" value="add_category">
      <select name="category_id" onchange="this.form.querySelector('[name=category_name]').value=this.options[this.selectedIndex].text">
        <option value="">— select to add —</option>
        <?php
          $monitoredIds = array_column($monitored, 'id');
          foreach ($efCategories as $cid => $cname):
            if (in_array((string)$cid, array_map('strval', $monitoredIds))) continue;
        ?>
          <option value="<?= htmlspecialchars($cid) ?>"><?= htmlspecialchars($cname) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="hidden" name="category_name" value="">
      <button type="submit" class="btn btn-accent">+ Track</button>
    </form>
    <span style="margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--muted)">
      Note: &nbsp;<span style="color:var(--text2)">lifespan = banned_at − created_at &nbsp;·&nbsp; playtime = played_time at ban</span>
    </span>
  </div>

  <?php if (empty($monitored)): ?>
  <div style="text-align:center;padding:80px;font-family:var(--mono);font-size:12px;color:var(--muted)">
    <div style="font-size:48px;opacity:.1;margin-bottom:16px">⏱</div>
    No categories tracked yet. Add one above.<br>
    <span style="color:var(--text2);font-size:10px">Categories are shared with Account Dashboard.</span>
  </div>
  <?php endif; ?>

  <div class="cat-grid">
  <?php foreach ($categoryStats as $cs):
    $b     = $cs['banned'];   // stats for banned accounts (played_time)
    $a     = $cs['active'];   // stats for active accounts (played_time)
    $hist  = $cs['histogram'];
    $waves = $cs['ban_waves'];
    $maxWave = $waves ? max(array_column($waves, 'count')) : 1;
    $peakBucket = 0; $peakCount = 0;
    foreach ($hist as $i => $bk) { if ($bk['count'] > $peakCount) { $peakCount=$bk['count']; $peakBucket=$i; } }
    $maxHistCount = $peakCount ?: 1;
    $hasBanned = $b['count'] > 0;
  ?>
  <div class="cat-card">
    <div class="card-top"></div>
    <div class="card-head">
      <h2><?= htmlspecialchars($cs['name']) ?></h2>
      <span class="id-pill"># <?= htmlspecialchars($cs['id']) ?></span>
      <?php if ($cs['banned_count'] > 0): ?>
      <span class="banned-pill"><?= number_format($cs['banned_count']) ?> banned</span>
      <?php endif; ?>
    </div>

    <?php if (!$hasBanned): ?>
      <div class="no-data">No banned accounts with playtime data in this category.</div>
    <?php else: ?>

    <!-- Playtime at ban stats — primary metric -->
    <div class="stat-grid">
      <div class="sc">
        <div class="sl">Avg Playtime</div>
        <div class="sv c-yellow"><?= fmtDuration($b['mean']) ?></div>
        <div class="sv-xs"><?= round($b['mean'],1) ?>h total</div>
      </div>
      <div class="sc">
        <div class="sl">Median</div>
        <div class="sv c-blue"><?= fmtDuration($b['median']) ?></div>
        <div class="sv-xs"><?= round($b['median'],1) ?>h</div>
      </div>
      <div class="sc">
        <div class="sl">Fastest Ban</div>
        <div class="sv c-red"><?= fmtDuration($b['min']) ?></div>
        <div class="sv-xs"><?= round($b['min'],1) ?>h played</div>
      </div>
      <div class="sc">
        <div class="sl">Most Played</div>
        <div class="sv c-green"><?= fmtDuration($b['max']) ?></div>
        <div class="sv-xs"><?= round($b['max'],1) ?>h played</div>
      </div>
      <div class="sc">
        <div class="sl">25th Pct</div>
        <div class="sv-sm c-blue"><?= fmtDuration($b['p25']) ?></div>
        <div class="sv-xs">25% banned by here</div>
      </div>
      <div class="sc">
        <div class="sl">75th Pct</div>
        <div class="sv-sm c-blue"><?= fmtDuration($b['p75']) ?></div>
        <div class="sv-xs">75% banned by here</div>
      </div>
      <div class="sc">
        <div class="sl">Std Dev</div>
        <div class="sv-sm" style="color:var(--muted)"><?= fmtDuration($b['stddev']) ?></div>
        <div class="sv-xs"><?= $b['stddev'] < $b['mean'] * 0.5 ? 'consistent' : 'variable' ?></div>
      </div>
      <div class="sc">
        <div class="sl">Repeat Bans</div>
        <div class="sv-sm c-red"><?= $cs['repeat_bans'] ?></div>
        <div class="sv-xs"><?= $b['count'] > 0 ? round($cs['repeat_bans']/$b['count']*100,1) : 0 ?>% of bans</div>
      </div>
    </div>

    <!-- Active vs banned playtime comparison -->
    <div class="pt-row">
      <div class="sc" style="background:var(--card2)">
        <div class="sl">Banned — avg playtime</div>
        <div class="sv-sm c-red"><?= fmtDuration($b['mean']) ?></div>
        <div class="sv-xs">median <?= fmtDuration($b['median']) ?> · <?= $b['count'] ?> accounts</div>
      </div>
      <div class="sc" style="background:var(--card2)">
        <div class="sl">Active — avg playtime</div>
        <div class="sv-sm c-green"><?= $a['count'] > 0 ? fmtDuration($a['mean']) : '—' ?></div>
        <div class="sv-xs">median <?= $a['count'] > 0 ? fmtDuration($a['median']) : '—' ?> · <?= $a['count'] ?> accounts</div>
      </div>
    </div>

    <!-- Histogram: played_time distribution at point of ban -->
    <?php if ($hist): ?>
    <div class="hist-wrap">
      <div class="hist-title">How much time had accounts played before getting banned?</div>
      <div class="hist-chart">
        <?php foreach ($hist as $i => $bucket):
          $pct    = $maxHistCount > 0 ? round($bucket['count'] / $maxHistCount * 100) : 0;
          $isPeak = ($i === $peakBucket && $bucket['count'] > 0);
          $lbl    = fmtDuration($bucket['lo']) . '–' . fmtDuration($bucket['hi']);
        ?>
        <div class="hist-bar-wrap" title="<?= $lbl ?>: <?= $bucket['count'] ?> accounts">
          <div class="hist-count"><?= $bucket['count'] > 0 ? $bucket['count'] : '' ?></div>
          <div class="hist-bar <?= $isPeak ? 'peak' : '' ?>" style="height:<?= max(2,$pct) ?>%"></div>
          <div class="hist-lbl"><?= fmtDuration($bucket['lo']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php
        $peakData = $hist[$peakBucket] ?? null;
        if ($peakData && $peakData['count'] > 0):
          $peakPct = round($peakData['count'] / $b['count'] * 100);
      ?>
      <div style="margin-top:8px;font-family:var(--mono);font-size:9px;color:var(--danger)">
        ▲ Peak: <?= $peakPct ?>% of bans occurred at <?= fmtDuration($peakData['lo']) ?>–<?= fmtDuration($peakData['hi']) ?> played
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Ban waves -->
    <?php if ($waves): ?>
    <div class="waves-wrap">
      <div class="waves-title">Detected ban waves (≥3 bans within 1 hour)</div>
      <div class="wave-list">
        <?php foreach ($waves as $wave): ?>
        <div class="wave-item">
          <span class="wave-date"><?= date('M j, H:i', $wave['ts']) ?></span>
          <div class="wave-bar-track">
            <div class="wave-bar" style="width:<?= round($wave['count']/$maxWave*100) ?>%"></div>
          </div>
          <span class="wave-count"><?= $wave['count'] ?> accts</span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; // end hasBanned ?>

    <div class="card-foot">
      <span class="foot-note">
        <?= number_format($cs['total']) ?> total ·
        <?= number_format($cs['banned_count']) ?> banned ·
        <?= number_format($cs['active_count']) ?> active ·
        all times = played_time (in-game hours)
      </span>
      <form method="post">
        <input type="hidden" name="action" value="remove_category">
        <input type="hidden" name="category_id" value="<?= htmlspecialchars($cs['id']) ?>">
        <button type="submit" class="btn btn-danger" style="padding:3px 10px;font-size:10px"
          onclick="return confirm('Remove <?= htmlspecialchars(addslashes($cs['name'])) ?>?')">Remove</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
  </div>

</div>

<script>
(function(){ function t(){ document.getElementById('clockEl').textContent=new Date().toLocaleTimeString('en-US',{hour12:false}); } t(); setInterval(t,1000); })();
</script>
</body>
</html>
