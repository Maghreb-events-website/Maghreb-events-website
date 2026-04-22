<?php

function register_admin_routes(Router $router): void {

    // GET /api/admins  (superadmin only)
    $router->get('/api/admins', function() {
        Auth::requireRole('admin');
        $db     = Database::getInstance();
        $page   = $_GET['page'] ?? 1;
        $per    = $_GET['per']  ?? 20;
        $result = paginate($db, 'admins', '1=1', [], $page, $per);

        // Strip passwords
        $result['data'] = array_map(function($a) {
            unset($a['password']);
            return $a;
        }, $result['data']);

        json_response($result);
    });

    // POST /api/admins  (superadmin only)
    $router->post('/api/admins', function() {
        $payload = Auth::requireRole('admin');
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['cid', 'name', 'email', 'password']);

        if (strlen($body['password']) < 8) json_error('Password must be at least 8 characters', 422);

        // Uniqueness
        $dup = $db->prepare("SELECT id FROM admins WHERE email = ? OR cid = ?");
        $dup->execute([strtolower($body['email']), $body['cid']]);
        if ($dup->fetch()) json_error('Email or CID already registered', 409);

        $hash = password_hash($body['password'], PASSWORD_BCRYPT);
        $stmt = $db->prepare("
            INSERT INTO admins (cid, name, email, password, role)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $body['cid'],
            trim($body['name']),
            strtolower(trim($body['email'])),
            $hash,
            $body['role'] ?? 'admin',
        ]);
        $id    = $db->lastInsertId();
        $admin = $db->query("SELECT id, cid, name, email, role, created_at FROM admins WHERE id = $id")->fetch();

        audit($db, $payload['sub'], 'CREATE_ADMIN', 'admin', (int)$id, "Created: {$body['name']}");
        json_response(['message' => 'Admin created', 'data' => $admin], 201);
    });

    // DELETE /api/admins/:id  (superadmin only)
    $router->delete('/api/admins/:id', function(array $params) {
        $payload = Auth::requireRole('admin');

        if ((int)$params['id'] === (int)$payload['sub']) {
            json_error('Cannot delete your own account', 400);
        }

        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT name FROM admins WHERE id = ?");
        $stmt->execute([$params['id']]);
        $admin = $stmt->fetch();
        if (!$admin) json_error('Admin not found', 404);

        $db->prepare("DELETE FROM admins WHERE id = ?")->execute([$params['id']]);
        audit($db, $payload['sub'], 'DELETE_ADMIN', 'admin', (int)$params['id'], "Deleted: {$admin['name']}");
        json_response(['message' => 'Admin deleted']);
    });

    // GET /api/audit-log
    $router->get('/api/audit-log', function() {
        Auth::requireRole('admin');
        $db   = Database::getInstance();
        $page = $_GET['page'] ?? 1;
        $per  = $_GET['per']  ?? 50;

        $result = paginate($db, 'audit_log', '1=1', [], $page, $per);
        json_response($result);
    });

    // ── Allowed CIDs ───────────────────────────────────────────────────────

    // GET /api/allowed-cids  (admin+)
    $router->get('/api/allowed-cids', function() {
        Auth::requireRole('admin');
        $db   = Database::getInstance();
        $rows = $db->query("SELECT * FROM allowed_cids ORDER BY created_at ASC")->fetchAll();
        json_response(['data' => $rows]);
    });

    // POST /api/allowed-cids  (admin+)
    $router->post('/api/allowed-cids', function() {
        $payload = Auth::requireRole('admin');
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['cid']);

        $cid = trim($body['cid']);
        if (!preg_match('/^\d{4,10}$/', $cid)) {
            json_error('CID must be 4–10 digits', 422);
        }

        $dup = $db->prepare("SELECT id FROM allowed_cids WHERE cid = ?");
        $dup->execute([$cid]);
        if ($dup->fetch()) json_error('CID already in the allowed list', 409);

        $db->prepare("INSERT INTO allowed_cids (cid, note, added_by) VALUES (?, ?, ?)")
           ->execute([$cid, trim($body['note'] ?? ''), $payload['sub']]);

        $id  = $db->lastInsertId();
        $row = $db->query("SELECT * FROM allowed_cids WHERE id = $id")->fetch();
        audit($db, $payload['sub'], 'ADD_ALLOWED_CID', 'allowed_cids', (int)$id, "Added CID $cid");
        json_response(['message' => 'CID added', 'data' => $row], 201);
    });

    // DELETE /api/allowed-cids/:id  (admin+)
    $router->delete('/api/allowed-cids/:id', function(array $params) {
        $payload = Auth::requireRole('admin');
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM allowed_cids WHERE id = ?");
        $stmt->execute([$params['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('CID entry not found', 404);

        $db->prepare("DELETE FROM allowed_cids WHERE id = ?")->execute([$params['id']]);
        audit($db, $payload['sub'], 'REMOVE_ALLOWED_CID', 'allowed_cids', (int)$params['id'], "Removed CID {$row['cid']}");
        json_response(['message' => 'CID removed']);
    });

    // GET /api/allowed-cids/check/:cid  (public — used by callback)
    $router->get('/api/allowed-cids/check/:cid', function(array $params) {
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT id FROM allowed_cids WHERE cid = ?");
        $stmt->execute([trim($params['cid'])]);
        $found = (bool)$stmt->fetch();
        json_response(['allowed' => $found]);
    });
}
