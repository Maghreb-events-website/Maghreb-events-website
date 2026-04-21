<?php
// ── Maghreb Events — Diagnostic + Setup page ──────────────────────────────
header('Content-Type: text/html; charset=UTF-8');

function ok($msg)   { echo "<li style='color:#4ade80'>✅ $msg</li>"; }
function fail($msg) { echo "<li style='color:#f87171'>❌ $msg</li>"; }
function warn($msg) { echo "<li style='color:#facc15'>⚠️  $msg</li>"; }

// Load config exactly as index.php does
if (file_exists(__DIR__ . '/vatsim_config.php')) {
    require_once __DIR__ . '/vatsim_config.php';
}

$jwtSecret = defined('JWT_SECRET') ? JWT_SECRET : 'MAGHREB_EVENTS_SECRET_KEY_CHANGE_IN_PROD_2024';
$usingDefaultSecret = $jwtSecret === 'MAGHREB_EVENTS_SECRET_KEY_CHANGE_IN_PROD_2024';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'>
<title>Maghreb API Diagnostics</title>
<style>body{font-family:monospace;background:#0f0f0f;color:#e5e5e5;padding:32px;max-width:860px}
h2{color:#a78bfa}h3{color:#818cf8;margin-top:28px}ul{line-height:2}code{background:#1f1f1f;padding:2px 6px;border-radius:4px}
table{border-collapse:collapse;width:100%;margin-top:8px}td,th{border:1px solid #333;padding:6px 10px;text-align:left;font-size:13px}
th{color:#a78bfa}.btn{display:inline-block;margin:4px 4px 4px 0;padding:8px 16px;background:#4f46e5;color:#fff;border:none;border-radius:6px;cursor:pointer;font-family:monospace;font-size:13px;text-decoration:none}
.btn-red{background:#dc2626}.btn-green{background:#16a34a}
.msg{margin-top:12px;padding:10px 14px;border-radius:6px;font-size:13px}
.msg.ok{background:#052e16;color:#4ade80;border:1px solid #166534}
.msg.err{background:#2d0a0a;color:#f87171;border:1px solid #7f1d1d}
.msg.warn{background:#1c1500;color:#facc15;border:1px solid #854d0e}
input[type=text]{padding:7px 10px;background:#1f1f1f;color:#e5e5e5;border:1px solid #333;border-radius:6px;font-family:monospace}
</style></head><body>
<h2>Maghreb Events — API Diagnostics &amp; Setup</h2><ul>";

// PHP
$ver = PHP_VERSION;
if (version_compare($ver, '8.1.0', '>=')) ok("PHP $ver");
else fail("PHP $ver — needs 8.1+");

// SQLite
if (extension_loaded('pdo_sqlite')) ok("pdo_sqlite loaded");
else fail("pdo_sqlite NOT loaded");

// vatsim_config.php
if (file_exists(__DIR__ . '/vatsim_config.php')) {
    ok("vatsim_config.php found");
    if ($usingDefaultSecret)
        fail("JWT_SECRET is still the DEFAULT value — tokens signed by callback won't verify on auth/me!");
    else
        ok("JWT_SECRET is set to a custom value ✓");
    if (defined('VATSIM_CLIENT_ID') && VATSIM_CLIENT_ID !== 'YOUR_SANDBOX_CLIENT_ID')
        ok("VATSIM_CLIENT_ID: <code>" . htmlspecialchars(VATSIM_CLIENT_ID) . "</code>");
    else fail("VATSIM_CLIENT_ID not set");
} else {
    fail("vatsim_config.php NOT FOUND — JWT_SECRET is using default, causing 401 on /auth/me");
}

// data dir
$dataDir = __DIR__ . '/data';
$dbOk = false; $db = null;
if (is_dir($dataDir) && is_writable($dataDir)) ok("data/ writable");
else fail("data/ missing or not writable");

if (is_dir($dataDir) && is_writable($dataDir)) {
    try {
        $db = new PDO('sqlite:' . $dataDir . '/maghreb.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON;');
        $tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        ok("DB connected — tables: <code>" . implode(', ', $tables) . "</code>");
        $dbOk = true;
    } catch (Exception $e) { fail("DB error: " . htmlspecialchars($e->getMessage())); }
}
echo "</ul>";

if (!$dbOk) { echo "<p style='color:#f87171'>Fix DB errors above first.</p></body></html>"; exit; }

// ── Ensure tables ──────────────────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS allowed_cids (
    id INTEGER PRIMARY KEY AUTOINCREMENT, cid TEXT NOT NULL UNIQUE,
    note TEXT, added_by INTEGER, created_at TEXT NOT NULL DEFAULT (datetime('now'))
)");

// ── Actions ────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';
$message = ''; $msgType = 'ok';

if ($action === 'seed_cids') {
    $defaultCids = ['10000000','10000001','10000002','10000003','10000004',
                    '10000005','10000006','10000007','10000008','10000009','1635257','1797446'];
    $ins = $db->prepare("INSERT OR IGNORE INTO allowed_cids (cid, note) VALUES (?, 'Default seed')");
    $added = 0;
    foreach ($defaultCids as $c) { $ins->execute([$c]); $added += $db->changes(); }
    $message = "Seeded — $added new CIDs inserted.";
}

if ($action === 'add_cid') {
    $cid = trim($_POST['cid'] ?? ''); $note = trim($_POST['note'] ?? '');
    if (preg_match('/^\d{4,10}$/', $cid)) {
        $db->prepare("INSERT OR IGNORE INTO allowed_cids (cid, note) VALUES (?, ?)")->execute([$cid, $note ?: 'Added via test.php']);
        $message = "CID $cid added.";
    } else { $message = "Invalid CID."; $msgType = 'err'; }
}

if ($action === 'remove_cid') {
    $db->prepare("DELETE FROM allowed_cids WHERE id = ?")->execute([(int)($_POST['id'] ?? 0)]);
    $message = "CID removed.";
}

if ($action === 'make_superadmin') {
    $cid = trim($_POST['cid'] ?? '');
    $r = $db->prepare("SELECT id FROM admins WHERE cid = ?"); $r->execute([$cid]);
    if ($r->fetch()) {
        $db->prepare("UPDATE admins SET role = 'superadmin' WHERE cid = ?")->execute([$cid]);
        $message = "CID $cid is now superadmin.";
    } else { $message = "CID $cid not in admins table yet."; $msgType = 'err'; }
}

// ── Generate bypass token ──────────────────────────────────────────────────
if ($action === 'gen_token') {
    $cid = trim($_POST['cid'] ?? '');
    $r = $db->prepare("SELECT * FROM admins WHERE cid = ?"); $r->execute([$cid]);
    $admin = $r->fetch();
    if ($admin) {
        // Generate token using same algorithm as Auth::generateToken
        $b64url = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
        $header  = $b64url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
        $payload = ['sub'=>$admin['id'],'cid'=>$admin['cid'],'name'=>$admin['name'],
                    'email'=>$admin['email'],'role'=>$admin['role'],
                    'iat'=>time(),'exp'=>time()+28800];
        $body    = $b64url(json_encode($payload));
        $sig     = $b64url(hash_hmac('sha256', "$header.$body", $jwtSecret, true));
        $token   = "$header.$body.$sig";
        $userJson = json_encode(['id'=>$admin['id'],'cid'=>$admin['cid'],'name'=>$admin['name'],'email'=>$admin['email'],'role'=>$admin['role']]);
        echo "<h3>Bypass Token for CID $cid</h3>
        <p style='color:#facc15;font-size:13px'>Run this in your browser console on the jamie-datson.com domain, then go to /admin:</p>
        <textarea style='width:100%;height:80px;background:#1f1f1f;color:#4ade80;border:1px solid #333;border-radius:6px;padding:8px;font-family:monospace;font-size:11px;word-break:break-all' onclick='this.select()'>localStorage.setItem(\"me_token\",\"$token\"); localStorage.setItem(\"me_user\",'" . addslashes($userJson) . "');</textarea>
        <p style='font-size:12px;color:#6b7280'>Token expires in 8 hours. Secret used: <code>" . ($usingDefaultSecret ? 'DEFAULT (mismatch risk!)' : 'custom ✓') . "</code></p>";
    } else { echo "<div class='msg err'>CID $cid not found in admins table.</div>"; }
}

if ($message) echo "<div class='msg $msgType'>$message</div>";

// ── JWT Secret warning ─────────────────────────────────────────────────────
if ($usingDefaultSecret) {
    echo "<div class='msg err' style='margin-top:16px'>
    ⚠️ <strong>vatsim_config.php not found or JWT_SECRET not set.</strong><br>
    This means the VATSIM callback signs tokens with one secret but /auth/me verifies with another → 401 → logout loop.<br>
    Make sure vatsim_config.php is uploaded to the same folder as index.php.
    </div>";
}

// ── allowed_cids ───────────────────────────────────────────────────────────
echo "<h3>allowed_cids table</h3>";
$rows = $db->query("SELECT * FROM allowed_cids ORDER BY created_at")->fetchAll(PDO::FETCH_ASSOC);
if ($rows) {
    echo "<table><tr><th>id</th><th>cid</th><th>note</th><th>created_at</th><th></th></tr>";
    foreach ($rows as $r) {
        echo "<tr><td>{$r['id']}</td><td><code>{$r['cid']}</code></td><td>" . htmlspecialchars($r['note']??'') . "</td><td>{$r['created_at']}</td>
        <td><form method='post' style='margin:0'><input type='hidden' name='action' value='remove_cid'/><input type='hidden' name='id' value='{$r['id']}'/><button class='btn btn-red'>Remove</button></form></td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:#f87171'>Table is EMPTY — seed it below.</p>";
}
echo "<form method='post' style='margin-top:10px'><input type='hidden' name='action' value='seed_cids'/><button class='btn'>Seed default CIDs</button></form>
<form method='post' style='margin-top:6px;display:flex;gap:8px'>
  <input type='hidden' name='action' value='add_cid'/>
  <input type='text' name='cid' placeholder='CID'/>
  <input type='text' name='note' placeholder='Note' style='flex:1'/>
  <button class='btn'>Add CID</button>
</form>";

// ── admins ─────────────────────────────────────────────────────────────────
echo "<h3>admins table</h3>";
$admins = $db->query("SELECT id,cid,name,email,role,created_at FROM admins ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
if ($admins) {
    echo "<table><tr><th>id</th><th>cid</th><th>name</th><th>role</th><th>created_at</th><th>actions</th></tr>";
    foreach ($admins as $a) {
        echo "<tr><td>{$a['id']}</td><td><code>{$a['cid']}</code></td><td>".htmlspecialchars($a['name'])."</td><td><code>{$a['role']}</code></td><td>{$a['created_at']}</td>
        <td style='display:flex;gap:4px'>
          <form method='post' style='margin:0'><input type='hidden' name='action' value='make_superadmin'/><input type='hidden' name='cid' value='{$a['cid']}'/><button class='btn'>superadmin</button></form>
          <form method='post' style='margin:0'><input type='hidden' name='action' value='gen_token'/><input type='hidden' name='cid' value='{$a['cid']}'/><button class='btn btn-green'>Get Token</button></form>
        </td></tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color:#facc15'>No admins yet.</p>";
}

echo "<hr style='border-color:#333;margin:28px 0'>
<p style='color:#6b7280;font-size:12px'>⚠️ Delete test.php once working — no authentication on this page.</p>
</body></html>";


// ── Auth debug endpoint — add ?action=auth_debug&token=YOUR_TOKEN ──────────
if (($_GET['action'] ?? '') === 'auth_debug') {
    header('Content-Type: application/json');
    $token = trim($_GET['token'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (str_starts_with($token, 'Bearer ')) $token = substr($token, 7);

    // Collect every possible place the Authorization header could appear
    $allHeaders = [];
    if (function_exists('getallheaders')) $allHeaders = getallheaders() ?: [];
    if (function_exists('apache_request_headers')) {
        foreach ((apache_request_headers() ?: []) as $k => $v) $allHeaders["apache_$k"] = $v;
    }

    $serverAuth = [];
    foreach ($_SERVER as $k => $v) {
        if (stripos($k, 'auth') !== false || stripos($k, 'http_') === 0) {
            $serverAuth[$k] = $v;
        }
    }

    // Try to verify the token with the current secret
    $jwtSecret = defined('JWT_SECRET') ? JWT_SECRET : 'MAGHREB_EVENTS_SECRET_KEY_CHANGE_IN_PROD_2024';
    $b64urlDecode = fn($d) => base64_decode(strtr($d, '-_', '+/') . str_repeat('=', (4 - strlen($d) % 4) % 4));
    $b64url = fn($d) => rtrim(strtr(base64_encode($d), '+/', '-_'), '=');

    $tokenResult = ['provided' => $token !== '', 'token_preview' => $token ? substr($token, 0, 20) . '…' : '(none)'];
    if ($token) {
        $parts = explode('.', $token);
        if (count($parts) === 3) {
            [$h, $b, $sig] = $parts;
            $expected = $b64url(hash_hmac('sha256', "$h.$b", $jwtSecret, true));
            $tokenResult['sig_match'] = hash_equals($expected, $sig);
            $payload = json_decode($b64urlDecode($b), true);
            $tokenResult['expired']   = $payload ? ($payload['exp'] < time()) : null;
            $tokenResult['exp_human'] = $payload ? date('c', $payload['exp']) : null;
            $tokenResult['payload']   = $payload;
        } else {
            $tokenResult['error'] = 'not 3 parts';
        }
    }

    echo json_encode([
        'jwt_secret_source'  => defined('JWT_SECRET') ? 'vatsim_config.php' : 'HARDCODED_DEFAULT',
        'jwt_secret_preview' => substr($jwtSecret, 0, 8) . '…',
        'token_check'        => $tokenResult,
        'auth_headers'       => $allHeaders,
        'server_auth_keys'   => $serverAuth,
        'php_sapi'           => PHP_SAPI,
        'server_software'    => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
    ], JSON_PRETTY_PRINT);
    exit;
}
