<?php
ini_set('memory_limit', '32M');

$guiConfig = __DIR__ . '/gui_config.json';

function loadGui(string $p): array {
    if (!file_exists($p)) return [];
    return json_decode(file_get_contents($p), true) ?? [];
}
function saveGui(string $p, array $d): void {
    file_put_contents($p, json_encode($d, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

$gui        = loadGui($guiConfig);
$creatorDir = rtrim($gui['creator_dir'] ?? '', '/\\');

$accountsFile  = $creatorDir ? $creatorDir . DIRECTORY_SEPARATOR . 'accounts.jsonl' : '';
$convertedFile = $creatorDir
    ? $creatorDir . DIRECTORY_SEPARATOR . 'utils' . DIRECTORY_SEPARATOR
      . 'convert_accounts' . DIRECTORY_SEPARATOR . 'converted_accounts.txt'
    : '';

// ── POST: run convert ──────────────────────────────────────────────────────────
$convertMsg   = '';
$convertError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'convert') {
    if (!$creatorDir)     { $convertError = 'Creator directory not set.'; }
    elseif (!file_exists($accountsFile))  { $convertError = 'accounts.jsonl not found in creator directory.'; }
    else {
        // Parse accounts.jsonl directly in PHP — no Python/uv dependency needed at all.
        // The jsonl format is simple enough: each line is a JSON object we decode ourselves.
        // This avoids ALL module import issues (pyotp, pydantic, etc).
        $count = 0;
        $errors = [];

        // Ensure output directory exists
        $outDir = dirname($convertedFile);
        if (!is_dir($outDir)) mkdir($outDir, 0755, true);

        $fhIn  = @fopen($accountsFile, 'r');
        $fhOut = @fopen($convertedFile, 'w');

        if (!$fhIn)  { $convertError = 'Cannot open accounts.jsonl for reading.'; }
        elseif (!$fhOut) { $convertError = 'Cannot open output file for writing: ' . $convertedFile; }
        else {
            while (($line = fgets($fhIn)) !== false) {
                $line = trim($line);
                if (!$line) continue;
                $d = json_decode($line, true);
                if (!$d) { $errors[] = 'Invalid JSON line skipped'; continue; }

                $emailObj = $d['email'] ?? null;
                if (is_array($emailObj)) {
                    $email = ($emailObj['username'] ?? '') . '@' . ($emailObj['domain'] ?? '');
                } else {
                    $email = (string)($emailObj ?? '');
                }
                $password = $d['password'] ?? '';
                $tfaKey   = $d['tfa']['setup_key'] ?? null;

                if (!$email || !$password) { $errors[] = 'Missing email/password, line skipped'; continue; }

                $row = $email . ':' . $password;
                if ($tfaKey) $row .= ':' . $tfaKey;
                fwrite($fhOut, $row . "
");
                $count++;
            }
            fclose($fhIn);
            fclose($fhOut);

            if ($count > 0) {
                $convertMsg = '✓ Converted ' . $count . ' accounts → ' . basename($convertedFile);
                if ($errors) $convertMsg .= ' (' . count($errors) . ' lines skipped)';
            } else {
                $convertError = 'No accounts converted. ' . implode('; ', $errors);
            }
        }
        if ($fhIn  && is_resource($fhIn))  fclose($fhIn);
        if ($fhOut && is_resource($fhOut)) fclose($fhOut);
    }
}

// ── POST: clear accounts ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_accounts') {
    if ($accountsFile && file_exists($accountsFile)) {
        file_put_contents($accountsFile, '');
        header('Location: ' . $_SERVER['PHP_SELF'] . '?cleared=1'); exit;
    }
}

// ── Load accounts from jsonl ───────────────────────────────────────────────────
$accounts = [];
$totalAccounts = 0;
$with2fa = 0; $withProxy = 0;
if ($accountsFile && file_exists($accountsFile)) {
    $fh = @fopen($accountsFile, 'r');
    if ($fh) {
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if (!$line) continue;
            $d = json_decode($line, true);
            if (!$d) continue;
            $totalAccounts++;
            if (!empty($d['tfa'])) $with2fa++;
            if (!empty($d['proxy'])) $withProxy++;
            $accounts[] = $d;
        }
        fclose($fh);
    }
}
// Show newest first
$accounts = array_reverse($accounts);

$convertedExists = $convertedFile && file_exists($convertedFile);
$convertedCount  = 0;
if ($convertedExists) {
    $fh = @fopen($convertedFile, 'r');
    if ($fh) { while (fgets($fh) !== false) $convertedCount++; fclose($fh); }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Account History</title>
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

.wrap{max-width:1440px;margin:0 auto;padding:24px;display:flex;flex-direction:column;gap:20px}

/* Stat cards */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.sc{background:var(--card);border:1px solid var(--border);border-radius:8px;padding:14px 16px;position:relative;overflow:hidden}
.sc::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
.sc.s-total::before{background:linear-gradient(90deg,var(--accent2),transparent)}
.sc.s-2fa::before{background:linear-gradient(90deg,var(--accent),transparent)}
.sc.s-proxy::before{background:linear-gradient(90deg,var(--purple),transparent)}
.sc.s-conv::before{background:linear-gradient(90deg,var(--warn),transparent)}
.sc .sl{font-family:var(--mono);font-size:9px;letter-spacing:2px;color:var(--text2);text-transform:uppercase;margin-bottom:5px}
.sc .sv{font-family:var(--mono);font-size:26px;line-height:1}
.sv.c-blue{color:var(--accent2)}.sv.c-green{color:var(--accent)}.sv.c-purple{color:var(--purple)}.sv.c-yellow{color:var(--warn)}

/* Convert panel */
.panel{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.panel-top{height:2px}
.panel-top.yellow{background:linear-gradient(90deg,var(--warn),transparent)}
.panel-top.green{background:linear-gradient(90deg,var(--accent),transparent)}
.panel-top.blue{background:linear-gradient(90deg,var(--accent2),transparent)}
.panel-head{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;
  background:linear-gradient(90deg,rgba(0,255,136,.03),transparent 60%)}
.panel-head h2{font-family:var(--head);font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.panel-head .sub{margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--text2)}
.panel-body{padding:16px}

.btn{padding:8px 18px;border-radius:4px;border:none;font-family:var(--head);font-size:12px;font-weight:700;
  letter-spacing:1.5px;text-transform:uppercase;cursor:pointer;transition:all .15s}
.btn-accent{background:rgba(0,255,136,.1);color:var(--accent);border:1px solid rgba(0,255,136,.25)}
.btn-accent:hover{background:rgba(0,255,136,.18);box-shadow:0 0 10px rgba(0,255,136,.1)}
.btn-warn{background:rgba(255,184,46,.1);color:var(--warn);border:1px solid rgba(255,184,46,.25)}
.btn-warn:hover{background:rgba(255,184,46,.18)}
.btn-danger{background:rgba(255,59,92,.08);color:var(--danger);border:1px solid rgba(255,59,92,.2)}
.btn-danger:hover{background:rgba(255,59,92,.15)}
.btn-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px}

.msg-ok{font-family:var(--mono);font-size:11px;color:var(--accent);padding:8px 12px;background:rgba(0,255,136,.06);border:1px solid rgba(0,255,136,.2);border-radius:5px}
.msg-err{font-family:var(--mono);font-size:11px;color:var(--danger);padding:8px 12px;background:rgba(255,59,92,.06);border:1px solid rgba(255,59,92,.2);border-radius:5px}
.file-path{font-family:var(--mono);font-size:11px;color:var(--text2);background:var(--surface);border:1px solid var(--border);padding:7px 12px;border-radius:4px;word-break:break-all}
.file-path strong{color:var(--warn)}

/* Table */
.tbl-wrap{background:var(--card);border:1px solid var(--border);border-radius:8px;overflow:hidden}
.tbl-head-bar{padding:11px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;
  background:linear-gradient(90deg,rgba(0,255,136,.03),transparent 60%)}
.tbl-head-bar h2{font-family:var(--head);font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase}
.tbl-head-bar .sub{font-family:var(--mono);font-size:10px;color:var(--text2)}
.tbl-top{height:2px;background:linear-gradient(90deg,var(--accent),transparent)}

table{width:100%;border-collapse:collapse}
thead tr{background:var(--card2)}
th{padding:9px 14px;font-family:var(--mono);font-size:9px;letter-spacing:2px;text-transform:uppercase;color:var(--text2);text-align:left;border-bottom:1px solid var(--border);white-space:nowrap}
td{padding:10px 14px;border-bottom:1px solid rgba(26,35,53,.6);vertical-align:middle;font-family:var(--mono);font-size:11px}
tr:last-child td{border-bottom:none}
tr:hover td{background:rgba(0,255,136,.02)}

.badge{display:inline-flex;align-items:center;padding:2px 8px;border-radius:8px;font-family:var(--mono);font-size:9px;font-weight:600}
.b-yes{background:rgba(0,255,136,.1);color:var(--accent);border:1px solid rgba(0,255,136,.2)}
.b-no{background:rgba(26,35,53,.5);color:var(--muted);border:1px solid var(--border)}
.b-proxy{background:rgba(167,139,250,.1);color:var(--purple);border:1px solid rgba(167,139,250,.2)}

.tfa-key{color:var(--warn);cursor:pointer;position:relative}
.tfa-key:hover .tfa-full{display:block}
.tfa-full{display:none;position:absolute;left:0;top:100%;z-index:100;background:var(--card2);border:1px solid var(--border);padding:6px 10px;border-radius:4px;white-space:nowrap;color:var(--text);font-size:10px}

.copy-btn{background:none;border:none;color:var(--text2);cursor:pointer;font-family:var(--mono);font-size:9px;padding:2px 6px;border-radius:3px;transition:color .15s}
.copy-btn:hover{color:var(--accent)}

.empty-row td{text-align:center;padding:48px;color:var(--muted);font-size:12px}
.banner{background:rgba(255,184,46,.06);border:1px solid rgba(255,184,46,.2);border-radius:6px;padding:10px 16px;font-family:var(--mono);font-size:11px;color:var(--warn);display:flex;align-items:center;gap:8px}
.banner a{color:var(--warn);text-decoration:underline}

#toast{position:fixed;bottom:22px;right:22px;background:var(--card);border:1px solid var(--accent);color:var(--accent);font-family:var(--mono);font-size:11px;padding:9px 16px;border-radius:5px;opacity:0;transform:translateY(8px);transition:all .2s;pointer-events:none;z-index:10000}
#toast.show{opacity:1;transform:translateY(0)}

@media(max-width:800px){.stat-row{grid-template-columns:1fr 1fr}}
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
  <a class="nav-link active" href="accounts.php">Accounts</a>
  <a class="nav-link" href="lifespan.php">Playtime</a>
  <a class="nav-link" href="mover.php">Trade Mover</a>
  <div class="nav-right">
    <div class="clock" id="clockEl"></div>
  </div>
</nav>

<div class="wrap">

  <?php if (!$creatorDir): ?>
  <div class="banner">⚠ Creator directory not set. <a href="index.php">Go to Creator GUI → Settings.</a></div>
  <?php endif; ?>

  <!-- Stats row -->
  <div class="stat-row">
    <div class="sc s-total">
      <div class="sl">Total Created</div>
      <div class="sv c-blue"><?= number_format($totalAccounts) ?></div>
    </div>
    <div class="sc s-2fa">
      <div class="sl">With 2FA</div>
      <div class="sv c-green"><?= number_format($with2fa) ?></div>
    </div>
    <div class="sc s-proxy">
      <div class="sl">With Proxy</div>
      <div class="sv c-purple"><?= number_format($withProxy) ?></div>
    </div>
    <div class="sc s-conv">
      <div class="sl">Last Converted</div>
      <div class="sv c-yellow"><?= $convertedExists ? number_format($convertedCount) : '—' ?></div>
    </div>
  </div>

  <!-- Convert panel -->
  <div class="panel">
    <div class="panel-top yellow"></div>
    <div class="panel-head">
      <h2>⇄ Convert for EternalFarm Import</h2>
      <span class="sub">email:password:2fa_key format</span>
    </div>
    <div class="panel-body">
      <?php if ($convertMsg): ?>
        <div class="msg-ok" style="margin-bottom:12px"><?= htmlspecialchars($convertMsg) ?></div>
      <?php endif; ?>
      <?php if ($convertError): ?>
        <div class="msg-err" style="margin-bottom:12px"><?= htmlspecialchars($convertError) ?></div>
      <?php endif; ?>

      <div style="font-family:var(--mono);font-size:11px;color:var(--text2);margin-bottom:10px;line-height:1.6">
        Reads <strong style="color:var(--text)">accounts.jsonl</strong> and outputs
        <strong style="color:var(--text)">email:password:2fa_key</strong> — one account per line,
        ready to import into EternalFarm.
      </div>

      <?php if ($accountsFile): ?>
      <div class="file-path" style="margin-bottom:8px">
        📄 Input: <strong><?= htmlspecialchars($accountsFile) ?></strong>
        <?php if (!file_exists($accountsFile)): ?>
          <span style="color:var(--danger)"> (not found)</span>
        <?php else: ?>
          <span style="color:var(--accent)"> (<?= $totalAccounts ?> accounts)</span>
        <?php endif; ?>
      </div>
      <div class="file-path" style="margin-bottom:14px">
        📤 Output: <strong><?= htmlspecialchars($convertedFile) ?></strong>
        <?php if ($convertedExists): ?>
          <span style="color:var(--accent)"> (<?= $convertedCount ?> lines, last converted)</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="btn-row">
        <form method="post">
          <input type="hidden" name="action" value="convert">
          <button type="submit" class="btn btn-warn"
            <?= (!$creatorDir || !file_exists($accountsFile ?? '')) ? 'disabled' : '' ?>>
            ⇄ Convert accounts.jsonl
          </button>
        </form>
        <form method="post" onsubmit="return confirm('Clear all accounts from accounts.jsonl? This cannot be undone.')">
          <input type="hidden" name="action" value="clear_accounts">
          <button type="submit" class="btn btn-danger"
            <?= (!$accountsFile || !file_exists($accountsFile)) ? 'disabled' : '' ?>>
            🗑 Clear accounts.jsonl
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- Accounts table -->
  <div class="tbl-wrap">
    <div class="tbl-top"></div>
    <div class="tbl-head-bar">
      <h2>📋 Account History</h2>
      <span class="sub" style="margin-left:8px"><?= $totalAccounts ?> total · newest first</span>
      <span style="margin-left:auto;font-family:var(--mono);font-size:10px;color:var(--muted)">
        <?= $accountsFile ? htmlspecialchars($accountsFile) : 'no path set' ?>
      </span>
    </div>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Email</th>
          <th>Password</th>
          <th>2FA Key</th>
          <th>Birthday</th>
          <th>Real IP</th>
          <th>Proxy</th>
          <th>Copy</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($accounts)): ?>
          <tr class="empty-row"><td colspan="8">No accounts found. Run the creator to generate accounts.</td></tr>
        <?php endif; ?>
        <?php foreach ($accounts as $i => $acc):
          $num    = $totalAccounts - $i;
          $emailObj = $acc['email'] ?? null;
          $email  = is_array($emailObj) ? ($emailObj['username'] ?? '') . '@' . ($emailObj['domain'] ?? '') : (string)($emailObj ?? '—');
          $pass   = $acc['password'] ?? '—';
          $tfa    = $acc['tfa']['setup_key'] ?? null;
          $bd     = isset($acc['birthday'])
            ? sprintf('%02d/%02d/%04d', $acc['birthday']['day'], $acc['birthday']['month'], $acc['birthday']['year'])
            : '—';
          $ip     = $acc['real_ip'] ?? '—';
          $proxy  = $acc['proxy']   ?? null;
          $copyVal= $email . ':' . $pass . ($tfa ? ':' . $tfa : '');
        ?>
        <tr>
          <td style="color:var(--muted)"><?= $num ?></td>
          <td style="color:var(--accent2)"><?= htmlspecialchars($email) ?></td>
          <td>
            <span class="pw-hidden" data-pw="<?= htmlspecialchars($pass) ?>" onclick="this.textContent=this.dataset.pw;this.style.cursor='default'" style="cursor:pointer;color:var(--muted)">••••••••</span>
          </td>
          <td>
            <?php if ($tfa): ?>
              <span class="tfa-key">
                <?= substr($tfa, 0, 6) ?>…
                <span class="tfa-full"><?= htmlspecialchars($tfa) ?></span>
              </span>
            <?php else: ?>
              <span style="color:var(--muted)">—</span>
            <?php endif; ?>
          </td>
          <td style="color:var(--text2)"><?= htmlspecialchars($bd) ?></td>
          <td style="color:var(--text2);font-size:10px"><?= htmlspecialchars($ip) ?></td>
          <td>
            <?php if ($proxy): ?>
              <span class="badge b-proxy"><?= htmlspecialchars($proxy['ip'] . ':' . $proxy['port']) ?></span>
            <?php else: ?>
              <span class="badge b-no">NONE</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="copy-btn" onclick="copyRow(<?= htmlspecialchars(json_encode($copyVal)) ?>)">⧉ COPY</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

</div>

<div id="toast"></div>

<script>
(function(){ function t(){ document.getElementById('clockEl').textContent=new Date().toLocaleTimeString('en-US',{hour12:false}); } t(); setInterval(t,1000); })();

// Toast
function showToast(msg) {
  const t = document.getElementById('toast');
  t.textContent = msg; t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2200);
}

// Copy row as email:pass:2fa
function copyRow(val) {
  navigator.clipboard.writeText(val).then(() => showToast('✓ Copied to clipboard'));
}

// Flash convert message as toast
<?php if ($convertMsg): ?>
  showToast(<?= json_encode($convertMsg) ?>);
<?php endif; ?>
<?php if (isset($_GET['cleared'])): ?>
  showToast('✓ accounts.jsonl cleared');
<?php endif; ?>
</script>
</body>
</html>
