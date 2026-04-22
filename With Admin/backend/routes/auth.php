<?php

function register_auth_routes(Router $router): void {

    // POST /api/auth/login
    $router->post('/api/auth/login', function() {
        $db   = Database::getInstance();
        $body = body();
        require_fields($body, ['email', 'password']);

        $stmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
        $stmt->execute([strtolower(trim($body['email']))]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($body['password'], $admin['password'])) {
            json_error('Invalid email or password', 401);
        }

        $token = Auth::generateToken([
            'sub'   => $admin['id'],
            'cid'   => $admin['cid'],
            'name'  => $admin['name'],
            'email' => $admin['email'],
            'role'  => $admin['role'],
        ]);

        audit($db, $admin['id'], 'LOGIN', 'admin', $admin['id'], 'Successful login');

        json_response([
            'token' => $token,
            'admin' => [
                'id'    => $admin['id'],
                'cid'   => $admin['cid'],
                'name'  => $admin['name'],
                'email' => $admin['email'],
                'role'  => $admin['role'],
            ]
        ]);
    });

    // ── VATSIM OAuth2 callback ─────────────────────────────────────────────
    // POST /api/auth/vatsim/callback
    //
    // Receives { code, state } from the frontend callback page.
    // Exchanges the code with VATSIM's sandbox token endpoint, fetches the
    // user's VATSIM details, then looks up (or creates) the matching admin
    // record and returns a Maghreb Events JWT.
    //
    // Configuration — set these in your environment or directly here:
    //   VATSIM_CLIENT_ID      — from auth-dev.vatsim.net
    //   VATSIM_CLIENT_SECRET  — from auth-dev.vatsim.net
    //   VATSIM_REDIRECT_URI   — must match the one registered on auth-dev.vatsim.net
    //                           and the one used in /login
    $router->post('/api/auth/vatsim/callback', function() {
        // Top-level try/catch so any unexpected exception returns structured JSON
        // instead of a bare 500. The 'detail' field tells you exactly what failed.
        try {
        $db   = Database::getInstance();
        $body = body();
        require_fields($body, ['code']);

        // ── Read config (from vatsim_config.php or env fallback) ────────
        $clientId     = defined('VATSIM_CLIENT_ID')     ? VATSIM_CLIENT_ID     : (getenv('VATSIM_CLIENT_ID')     ?: 'YOUR_SANDBOX_CLIENT_ID');
        $clientSecret = defined('VATSIM_CLIENT_SECRET') ? VATSIM_CLIENT_SECRET : (getenv('VATSIM_CLIENT_SECRET') ?: 'YOUR_SANDBOX_CLIENT_SECRET');
        $redirectUri  = defined('VATSIM_REDIRECT_URI')  ? VATSIM_REDIRECT_URI  : (getenv('VATSIM_REDIRECT_URI')  ?: 'https://jamie-datson.com/callback');
        // Use VATSIM_SANDBOX=true in vatsim_config.php to target auth-dev.vatsim.net during development.
        $sandboxMode  = defined('VATSIM_SANDBOX') ? (bool)VATSIM_SANDBOX : false;
        $sandboxBase  = $sandboxMode ? 'https://auth-dev.vatsim.net' : 'https://auth.vatsim.net';

        if ($clientId === 'YOUR_SANDBOX_CLIENT_ID') {
            json_error('VATSIM OAuth is not configured on this server. Set VATSIM_CLIENT_ID, VATSIM_CLIENT_SECRET, and VATSIM_REDIRECT_URI.', 501);
        }

        // ── Exchange authorization code for access token ───────────────────
        $tokenRes = vatsim_http_post(
            "$sandboxBase/oauth/token",
            http_build_query([
                'grant_type'    => 'authorization_code',
                'client_id'     => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri'  => $redirectUri,
                'code'          => $body['code'],
            ]),
            'application/x-www-form-urlencoded'
        );

        if (isset($tokenRes['error'])) {
            http_response_code(401);
            echo json_encode([
                'error'  => 'VATSIM token exchange failed',
                'detail' => $tokenRes['error_description'] ?? $tokenRes['error'],
            ]);
            exit;
        }

        $accessToken = $tokenRes['access_token'] ?? null;
        if (!$accessToken) {
            json_error('No access token returned by VATSIM', 502);
        }

        // ── Fetch VATSIM user details ─────────────────────────────────────
        $userRes = vatsim_http_get(
            "$sandboxBase/api/user",
            $accessToken
        );

        if (!isset($userRes['data']['cid'])) {
            json_error('Failed to retrieve VATSIM user data', 502);
        }

        $vatsimUser = $userRes['data'];
        $cid        = (string)$vatsimUser['cid'];
        $name       = trim(($vatsimUser['personal']['name_first'] ?? '') . ' ' . ($vatsimUser['personal']['name_last'] ?? ''));
        $email      = strtolower($vatsimUser['personal']['email'] ?? '');

        // ── Look up or provision user record ──────────────────────────────
        $stmt = $db->prepare("SELECT * FROM admins WHERE cid = ?");
        $stmt->execute([$cid]);
        $admin = $stmt->fetch();

        // Check if CID is in the allowed list
        $isAllowed = false;
        $bootstrapAllowedCids = [
            // VATSIM sandbox test CIDs
            '10000000','10000001','10000002','10000003','10000004',
            '10000005','10000006','10000007','10000008','10000009',
            // Real project owner CIDs — always allowed
            '1635257','1797446',
        ];

        if (in_array($cid, $bootstrapAllowedCids, true)) {
            $isAllowed = true;
        }

        try {
            $cidCheck = $db->prepare("SELECT id FROM allowed_cids WHERE cid = ?");
            $cidCheck->execute([$cid]);
            $isAllowed = $isAllowed || (bool)$cidCheck->fetch();
        } catch (\Throwable $e) {
            // Table may not exist yet — bootstrap allowlist still applies
        }

        if (!$admin) {
            if (!$isAllowed) {
                // Not an allowed admin — return a signed-in token with role='user'
                $token = Auth::generateToken([
                    'sub'   => 0,
                    'cid'   => $cid,
                    'name'  => $name ?: "VATSIM $cid",
                    'email' => $email,
                    'role'  => 'user',
                ]);

                json_response([
                    'token' => $token,
                    'admin' => [
                        'id'    => 0,
                        'cid'   => $cid,
                        'name'  => $name ?: "VATSIM $cid",
                        'email' => $email,
                        'role'  => 'user',
                    ],
                ]);
                return;
            }

            // Real owner CIDs always get superadmin.
            // All other allowed CIDs: first user ever = superadmin, rest = admin.
            $ownerCids = ['1635257', '1797446'];
            if (in_array($cid, $ownerCids, true)) {
                $role = 'superadmin';
            } else {
                $count = (int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
                $role  = $count === 0 ? 'superadmin' : 'admin';
            }

            // Fallback email if VATSIM didn't return one (requires email scope)
            if (!$email) {
                $email = "cid{$cid}@vatsim.placeholder";
            }

            // Generate a random unusable password (login is via VATSIM OAuth only)
            $randomPw = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

            try {
                $db->prepare("
                    INSERT INTO admins (cid, name, email, password, role)
                    VALUES (?, ?, ?, ?, ?)
                ")->execute([$cid, $name ?: "VATSIM $cid", $email, $randomPw, $role]);

                $adminId = $db->lastInsertId();
                $admin   = $db->query("SELECT * FROM admins WHERE id = $adminId")->fetch();
                audit($db, $admin['id'], 'VATSIM_REGISTER', 'admin', $admin['id'], "Auto-provisioned from VATSIM CID $cid");
            } catch (\Throwable $e) {
                // Email or CID collision — load existing record
                $stmt = $db->prepare("SELECT * FROM admins WHERE cid = ? OR email = ?");
                $stmt->execute([$cid, $email]);
                $admin = $stmt->fetch();

                if (!$admin) {
                    json_error('Account conflict: could not create or find admin record.', 409);
                }
            }
        } else {
            // Sync name/email from VATSIM if changed
            if ($name && ($admin['name'] !== $name || ($email && $admin['email'] !== $email))) {
                $db->prepare("
                    UPDATE admins SET name = ?, email = COALESCE(NULLIF(?, ''), email), updated_at = datetime('now')
                    WHERE id = ?
                ")->execute([$name, $email, $admin['id']]);
                $admin['name']  = $name;
                $admin['email'] = $email ?: $admin['email'];
            }
        }

        // ── Issue Maghreb Events JWT ───────────────────────────────────────
        $token = Auth::generateToken([
            'sub'   => $admin['id'],
            'cid'   => $admin['cid'],
            'name'  => $admin['name'],
            'email' => $admin['email'],
            'role'  => $admin['role'],
        ]);

        audit($db, $admin['id'], 'VATSIM_LOGIN', 'admin', $admin['id'], "VATSIM OAuth login for CID $cid");

        json_response([
            'token' => $token,
            'admin' => [
                'id'    => $admin['id'],
                'cid'   => $admin['cid'],
                'name'  => $admin['name'],
                'email' => $admin['email'],
                'role'  => $admin['role'],
            ],
        ]);
        } catch (\Throwable $e) {
            // Return the real error so it appears in the browser/network tab
            // instead of a generic 500. Remove this catch block once stable.
            http_response_code(500);
            echo json_encode([
                'error'  => 'vatsim_callback_exception',
                'detail' => $e->getMessage(),
                'file'   => basename($e->getFile()),
                'line'   => $e->getLine(),
                'trace'  => array_map(
                    fn($f) => (isset($f['file']) ? basename($f['file']) : '?') . ':' . ($f['line'] ?? '?') . ' ' . ($f['function'] ?? ''),
                    array_slice($e->getTrace(), 0, 6)
                ),
            ]);
            exit;
        }
    });


    // GET /api/auth/vatsim/debug
    // Safe diagnostic — no secrets returned, no auth required.
    // Hit this if you get 500s on the callback to see what is wrong.
    $router->get('/api/auth/vatsim/debug', function() {
        $sandboxMode = defined('VATSIM_SANDBOX') ? (bool)VATSIM_SANDBOX : true;
        $base = $sandboxMode ? 'https://auth-dev.vatsim.net' : 'https://auth.vatsim.net';

        $curlAvailable = function_exists('curl_init');
        $urlFopenOn    = (bool)ini_get('allow_url_fopen');
        $clientIdSet   = defined('VATSIM_CLIENT_ID') && VATSIM_CLIENT_ID !== 'YOUR_SANDBOX_CLIENT_ID';
        $secretSet     = defined('VATSIM_CLIENT_SECRET') && VATSIM_CLIENT_SECRET !== 'YOUR_SANDBOX_CLIENT_SECRET';
        $redirectSet   = defined('VATSIM_REDIRECT_URI');

        $connectOk  = false;
        $connectErr = '';
        if ($curlAvailable) {
            $ch = curl_init("$base/oauth/token");
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_NOBODY => true]);
            curl_exec($ch);
            $connectErr = curl_error($ch);
            $connectOk  = curl_errno($ch) === 0;
            curl_close($ch);
        }

        json_response([
            'vatsim_endpoint'   => $base,
            'sandbox_mode'      => $sandboxMode,
            'curl_available'    => $curlAvailable,
            'allow_url_fopen'   => $urlFopenOn,
            'can_make_requests' => $curlAvailable || $urlFopenOn,
            'vatsim_reachable'  => $connectOk,
            'connect_error'     => $connectErr ?: null,
            'client_id_set'     => $clientIdSet,
            'client_secret_set' => $secretSet,
            'redirect_uri'      => $redirectSet ? VATSIM_REDIRECT_URI : null,
            'php_version'       => PHP_VERSION,
            'ready'             => $clientIdSet && $secretSet && $redirectSet && ($curlAvailable || $urlFopenOn) && $connectOk,
        ]);
    });

    // GET /api/auth/me
    $router->get('/api/auth/me', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();

        // VATSIM non-admin users have sub=0 — return the token payload directly
        // rather than doing a DB lookup that would return nothing.
        if ((int)$payload['sub'] === 0) {
            json_response(['admin' => [
                'id'    => 0,
                'cid'   => $payload['cid']   ?? null,
                'name'  => $payload['name']  ?? null,
                'email' => $payload['email'] ?? null,
                'role'  => $payload['role']  ?? 'user',
            ]]);
            return;
        }

        // Try DB first — if the admins table is empty (fresh install) fall back
        // to the signed JWT payload so the admin panel still loads.
        $admin = null;
        try {
            $stmt = $db->prepare("SELECT id, cid, name, email, role, created_at FROM admins WHERE id = ?");
            $stmt->execute([$payload['sub']]);
            $admin = $stmt->fetch() ?: null;

            // Also try by CID in case id changed between deploys
            if (!$admin && !empty($payload['cid'])) {
                $stmt2 = $db->prepare("SELECT id, cid, name, email, role, created_at FROM admins WHERE cid = ?");
                $stmt2->execute([$payload['cid']]);
                $admin = $stmt2->fetch() ?: null;
            }
        } catch (\Throwable $e) {
            // DB unavailable — fall through to JWT payload fallback
        }

        // If no DB row exists yet, trust the cryptographically signed token.
        // This handles a fresh server where the admins table is still empty.
        if (!$admin) {
            $role = $payload['role'] ?? 'user';
            // Only let through admins/superadmins — not plain 'user' tokens
            if (!in_array($role, ['admin', 'superadmin'], true)) {
                json_error('Admin not found', 404);
            }
            json_response(['admin' => [
                'id'    => (int)($payload['sub'] ?? 0),
                'cid'   => $payload['cid']   ?? null,
                'name'  => $payload['name']  ?? null,
                'email' => $payload['email'] ?? null,
                'role'  => $role,
            ]]);
            return;
        }

        json_response(['admin' => $admin]);
    });

    // POST /api/auth/logout
    $router->post('/api/auth/logout', function() {
        $payload = Auth::requireAuth();
        audit(Database::getInstance(), $payload['sub'], 'LOGOUT', 'admin', $payload['sub']);
        json_response(['message' => 'Logged out successfully']);
    });

    // PUT /api/auth/password
    $router->put('/api/auth/password', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['current_password', 'new_password']);

        if (strlen($body['new_password']) < 8) {
            json_error('New password must be at least 8 characters', 422);
        }

        $stmt = $db->prepare("SELECT password FROM admins WHERE id = ?");
        $stmt->execute([$payload['sub']]);
        $admin = $stmt->fetch();

        if (!password_verify($body['current_password'], $admin['password'])) {
            json_error('Current password is incorrect', 401);
        }

        $hash = password_hash($body['new_password'], PASSWORD_BCRYPT);
        $db->prepare("UPDATE admins SET password = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$hash, $payload['sub']]);

        audit($db, $payload['sub'], 'PASSWORD_CHANGE', 'admin', $payload['sub']);
        json_response(['message' => 'Password updated successfully']);
    });
}

// ── HTTP helpers (no Composer/Guzzle required) ─────────────────────────────
//
// Uses cURL as the primary transport (works even when allow_url_fopen=Off,
// which is common on shared/managed hosting).
// Falls back to file_get_contents + stream_context if cURL is not loaded.

function vatsim_http_post(string $url, string $body, string $contentType): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => [
                "Content-Type: $contentType",
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno || $raw === false) {
            return ['error' => 'network_error', 'error_description' => $error ?: "cURL error $errno contacting $url"];
        }
        return json_decode($raw, true) ?? ['error' => 'invalid_json', 'error_description' => 'Non-JSON response from VATSIM token endpoint'];
    }

    // Fallback: stream_context (requires allow_url_fopen=On)
    if (!ini_get('allow_url_fopen')) {
        return ['error' => 'server_misconfiguration', 'error_description' => 'Neither cURL nor allow_url_fopen is available. Enable the PHP cURL extension.'];
    }
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: $contentType\r\nAccept: application/json\r\n",
            'content'       => $body,
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['error' => 'network_error', 'error_description' => "Could not reach $url"];
    }
    return json_decode($raw, true) ?? ['error' => 'invalid_json'];
}

function vatsim_http_get(string $url, string $bearerToken): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer $bearerToken",
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno || $raw === false) {
            return ['error' => 'network_error', 'error_description' => $error ?: "cURL error $errno contacting $url"];
        }
        return json_decode($raw, true) ?? ['error' => 'invalid_json', 'error_description' => 'Non-JSON response from VATSIM user endpoint'];
    }

    // Fallback: stream_context
    if (!ini_get('allow_url_fopen')) {
        return ['error' => 'server_misconfiguration', 'error_description' => 'Neither cURL nor allow_url_fopen is available.'];
    }
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => "Authorization: Bearer $bearerToken\r\nAccept: application/json\r\n",
            'timeout'       => 15,
            'ignore_errors' => true,
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['error' => 'network_error'];
    }
    return json_decode($raw, true) ?? ['error' => 'invalid_json'];
}
