<?php

function register_settings_routes(Router $router): void {

    // ── App Settings ────────────────────────────────────────────────────────

    // GET /api/settings/test-mode  (public — all pages need to know)
    $router->get('/api/settings/test-mode', function() {
        $db  = Database::getInstance();
        $row = $db->query("SELECT value FROM app_settings WHERE key = 'test_mode'")->fetch();
        json_response(['test_mode' => (bool)(int)($row['value'] ?? 0)]);
    });

    // PUT /api/settings/test-mode  (superadmin only)
    $router->put('/api/settings/test-mode', function() {
        $payload = Auth::requireRole('superadmin');
        $db      = Database::getInstance();
        $body    = body();

        if (!isset($body['enabled'])) json_error('Field "enabled" is required', 422);
        $val = $body['enabled'] ? '1' : '0';

        $db->prepare("
            INSERT INTO app_settings (key, value, updated_by, updated_at)
            VALUES ('test_mode', ?, ?, datetime('now'))
            ON CONFLICT(key) DO UPDATE SET value = excluded.value,
                                           updated_by = excluded.updated_by,
                                           updated_at = excluded.updated_at
        ")->execute([$val, $payload['sub']]);

        audit($db, $payload['sub'], 'SET_TEST_MODE', 'app_settings', null,
              'Test mode ' . ($val === '1' ? 'ENABLED' : 'DISABLED'));

        json_response(['message' => 'Test mode updated', 'test_mode' => (bool)(int)$val]);
    });

    // ── Test-data helpers ────────────────────────────────────────────────────

    // DELETE /api/settings/test-data  — wipe all test tables (superadmin only)
    $router->delete('/api/settings/test-data', function() {
        $payload = Auth::requireRole('superadmin');
        $db      = Database::getInstance();

        foreach (['test_events','test_partners','test_airlines','test_planning','test_hitsquad'] as $t) {
            $db->exec("DELETE FROM $t");
        }
        audit($db, $payload['sub'], 'CLEAR_TEST_DATA', 'test_data', null, 'All test data cleared');
        json_response(['message' => 'All test data cleared']);
    });

    // ── Test CRUD: Events ────────────────────────────────────────────────────

    $router->get('/api/test-events', function() {
        $db = Database::getInstance();
        ensureTestMode($db);
        $page   = $_GET['page']   ?? 1;
        $per    = $_GET['per']    ?? 20;
        $status = $_GET['status'] ?? '';
        if ($status) {
            $result = paginate($db, 'test_events', 'status = ?', [$status], $page, $per);
        } else {
            $result = paginate($db, 'test_events', '1=1', [], $page, $per);
        }
        json_response($result);
    });

    $router->post('/api/test-events', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        require_fields($body, ['title', 'start_time', 'end_time']);
        $stmt = $db->prepare("
            INSERT INTO test_events (title, description, start_time, end_time, status, banner_url)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($body['title']),
            $body['description'] ?? null,
            $body['start_time'],
            $body['end_time'],
            $body['status']     ?? 'upcoming',
            $body['banner_url'] ?? null,
        ]);
        $id    = $db->lastInsertId();
        $event = $db->query("SELECT * FROM test_events WHERE id = $id")->fetch();
        audit($db, $payload['sub'], 'CREATE_TEST_EVENT', 'test_events', (int)$id, "Created: {$body['title']}");
        json_response(['message' => 'Test event created', 'data' => $event], 201);
    });

    $router->put('/api/test-events/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        $fields  = ['title','description','start_time','end_time','status','banner_url'];
        [$updates, $values] = buildUpdates($fields, $body);
        if (empty($updates)) json_error('No fields to update', 422);
        $values[] = $params['id'];
        $db->prepare("UPDATE test_events SET " . implode(', ', $updates) . ", updated_at = datetime('now') WHERE id = ?")
           ->execute($values);
        $event = $db->query("SELECT * FROM test_events WHERE id = {$params['id']}")->fetch();
        json_response(['message' => 'Test event updated', 'data' => $event]);
    });

    $router->delete('/api/test-events/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $db->prepare("DELETE FROM test_events WHERE id = ?")->execute([$params['id']]);
        json_response(['message' => 'Test event deleted']);
    });

    // ── Test CRUD: Partners ──────────────────────────────────────────────────

    $router->get('/api/test-partners', function() {
        $db = Database::getInstance();
        ensureTestMode($db);
        $page   = $_GET['page'] ?? 1;
        $per    = $_GET['per']  ?? 20;
        $status = $_GET['status'] ?? '';
        $type   = $_GET['type']   ?? '';
        $where  = ['1=1']; $binds = [];
        if ($status) { $where[] = 'status = ?'; $binds[] = $status; }
        if ($type)   { $where[] = 'type = ?';   $binds[] = $type; }
        json_response(paginate($db, 'test_partners', implode(' AND ', $where), $binds, $page, $per));
    });

    $router->post('/api/test-partners', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        require_fields($body, ['name', 'type']);
        $stmt = $db->prepare("
            INSERT INTO test_partners (name, type, status, website, logo_url, description, contact_email)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($body['name']), $body['type'],
            $body['status'] ?? 'pending', $body['website'] ?? null,
            $body['logo_url'] ?? null, $body['description'] ?? null,
            $body['contact_email'] ?? null,
        ]);
        $id      = $db->lastInsertId();
        $partner = $db->query("SELECT * FROM test_partners WHERE id = $id")->fetch();
        audit($db, $payload['sub'], 'CREATE_TEST_PARTNER', 'test_partners', (int)$id, "Created: {$body['name']}");
        json_response(['message' => 'Test partner created', 'data' => $partner], 201);
    });

    $router->put('/api/test-partners/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        $fields  = ['name','type','status','website','logo_url','description','contact_email'];
        [$updates, $values] = buildUpdates($fields, $body);
        if (empty($updates)) json_error('No fields to update', 422);
        $values[] = $params['id'];
        $db->prepare("UPDATE test_partners SET " . implode(', ', $updates) . ", updated_at = datetime('now') WHERE id = ?")
           ->execute($values);
        $partner = $db->query("SELECT * FROM test_partners WHERE id = {$params['id']}")->fetch();
        json_response(['message' => 'Test partner updated', 'data' => $partner]);
    });

    $router->delete('/api/test-partners/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $db->prepare("DELETE FROM test_partners WHERE id = ?")->execute([$params['id']]);
        json_response(['message' => 'Test partner deleted']);
    });

    // ── Test CRUD: Airlines ──────────────────────────────────────────────────

    $router->get('/api/test-airlines', function() {
        $db = Database::getInstance();
        ensureTestMode($db);
        $page   = $_GET['page']   ?? 1;
        $per    = $_GET['per']    ?? 20;
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';
        $where  = ['1=1']; $binds = [];
        if ($status) { $where[] = 'status = ?'; $binds[] = $status; }
        if ($search) { $where[] = "(name LIKE ? OR icao LIKE ?)"; $binds[] = "%$search%"; $binds[] = "%$search%"; }
        $result = paginate($db, 'test_airlines', implode(' AND ', $where), $binds, $page, $per);
        $stats  = $db->query("SELECT COUNT(*) AS total, SUM(pilot_count) AS total_pilots, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_count FROM test_airlines")->fetch();
        $result['stats'] = $stats;
        json_response($result);
    });

    $router->post('/api/test-airlines', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        require_fields($body, ['name', 'icao']);
        $dup = $db->prepare("SELECT id FROM test_airlines WHERE icao = ?");
        $dup->execute([strtoupper($body['icao'])]);
        if ($dup->fetch()) json_error("ICAO code '{$body['icao']}' already exists in test data", 409);
        $stmt = $db->prepare("
            INSERT INTO test_airlines (name, icao, callsign, status, logo_url, description, pilot_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($body['name']), strtoupper(trim($body['icao'])),
            $body['callsign'] ?? null, $body['status'] ?? 'observer',
            $body['logo_url'] ?? null, $body['description'] ?? null,
            (int)($body['pilot_count'] ?? 0),
        ]);
        $id      = $db->lastInsertId();
        $airline = $db->query("SELECT * FROM test_airlines WHERE id = $id")->fetch();
        audit($db, $payload['sub'], 'CREATE_TEST_AIRLINE', 'test_airlines', (int)$id, "Created: {$body['name']}");
        json_response(['message' => 'Test airline registered', 'data' => $airline], 201);
    });

    $router->put('/api/test-airlines/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        $fields  = ['name','icao','callsign','status','logo_url','description','pilot_count'];
        [$updates, $values] = buildUpdates($fields, $body);
        if (empty($updates)) json_error('No fields to update', 422);
        $values[] = $params['id'];
        $db->prepare("UPDATE test_airlines SET " . implode(', ', $updates) . ", updated_at = datetime('now') WHERE id = ?")
           ->execute($values);
        $airline = $db->query("SELECT * FROM test_airlines WHERE id = {$params['id']}")->fetch();
        json_response(['message' => 'Test airline updated', 'data' => $airline]);
    });

    $router->delete('/api/test-airlines/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $db->prepare("DELETE FROM test_airlines WHERE id = ?")->execute([$params['id']]);
        json_response(['message' => 'Test airline deleted']);
    });

    // ── Test CRUD: Planning Team ─────────────────────────────────────────────

    $router->get('/api/test-planning', function() {
        $db = Database::getInstance();
        ensureTestMode($db);
        $rows = $db->query("SELECT * FROM test_planning ORDER BY sort_order ASC, id ASC")->fetchAll();
        json_response(['data' => $rows]);
    });

    $router->post('/api/test-planning', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        require_fields($body, ['name', 'subsection']);
        $db->prepare("INSERT INTO test_planning (name, role, cid, photo_url, bio, subsection) VALUES (?,?,?,?,?,?)")
           ->execute([trim($body['name']), $body['role'] ?? null, $body['cid'] ?? null, $body['photo_url'] ?? null, $body['bio'] ?? null, $body['subsection']]);
        $id  = $db->lastInsertId();
        $row = $db->query("SELECT * FROM test_planning WHERE id = $id")->fetch();
        json_response(['message' => 'Test planning member created', 'data' => $row], 201);
    });

    $router->put('/api/test-planning/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        $fields  = ['name','role','cid','photo_url','bio','subsection'];
        [$updates, $values] = buildUpdates($fields, $body);
        if (empty($updates)) json_error('No fields to update', 422);
        $values[] = $params['id'];
        $db->prepare("UPDATE test_planning SET " . implode(', ', $updates) . ", updated_at = datetime('now') WHERE id = ?")
           ->execute($values);
        $row = $db->query("SELECT * FROM test_planning WHERE id = {$params['id']}")->fetch();
        json_response(['message' => 'Test planning member updated', 'data' => $row]);
    });

    $router->delete('/api/test-planning/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $db->prepare("DELETE FROM test_planning WHERE id = ?")->execute([$params['id']]);
        json_response(['message' => 'Test planning member deleted']);
    });

    // ── Test CRUD: Hitsquad ──────────────────────────────────────────────────

    $router->get('/api/test-hitsquad', function() {
        $db = Database::getInstance();
        ensureTestMode($db);
        $rows = $db->query("SELECT * FROM test_hitsquad ORDER BY sort_order ASC, id ASC")->fetchAll();
        json_response(['data' => $rows]);
    });

    $router->post('/api/test-hitsquad', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        require_fields($body, ['name']);
        $db->prepare("INSERT INTO test_hitsquad (name, role, cid, photo_url, bio) VALUES (?,?,?,?,?)")
           ->execute([trim($body['name']), $body['role'] ?? null, $body['cid'] ?? null, $body['photo_url'] ?? null, $body['bio'] ?? null]);
        $id  = $db->lastInsertId();
        $row = $db->query("SELECT * FROM test_hitsquad WHERE id = $id")->fetch();
        json_response(['message' => 'Test hitsquad member created', 'data' => $row], 201);
    });

    $router->put('/api/test-hitsquad/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $body    = body();
        $fields  = ['name','role','cid','photo_url','bio'];
        [$updates, $values] = buildUpdates($fields, $body);
        if (empty($updates)) json_error('No fields to update', 422);
        $values[] = $params['id'];
        $db->prepare("UPDATE test_hitsquad SET " . implode(', ', $updates) . ", updated_at = datetime('now') WHERE id = ?")
           ->execute($values);
        $row = $db->query("SELECT * FROM test_hitsquad WHERE id = {$params['id']}")->fetch();
        json_response(['message' => 'Test hitsquad member updated', 'data' => $row]);
    });

    $router->delete('/api/test-hitsquad/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        ensureTestMode($db);
        $db->prepare("DELETE FROM test_hitsquad WHERE id = ?")->execute([$params['id']]);
        json_response(['message' => 'Test hitsquad member deleted']);
    });
}

// ── Helpers ──────────────────────────────────────────────────────────────────

/** Abort with 403 unless test mode is currently enabled */
function ensureTestMode(PDO $db): void {
    $row = $db->query("SELECT value FROM app_settings WHERE key = 'test_mode'")->fetch();
    if (!$row || !(int)$row['value']) {
        json_error('Test mode is not enabled', 403);
    }
}

/** Build SET clauses + values from a field allowlist */
function buildUpdates(array $fields, array $body): array {
    $updates = [];
    $values  = [];
    foreach ($fields as $f) {
        if (array_key_exists($f, $body)) {
            $updates[] = "$f = ?";
            $values[]  = $body[$f];
        }
    }
    return [$updates, $values];
}
