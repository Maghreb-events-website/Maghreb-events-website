<?php

function register_airline_routes(Router $router): void {

    // GET /api/airlines
    $router->get('/api/airlines', function() {
        $db     = Database::getInstance();
        $page   = $_GET['page']   ?? 1;
        $per    = $_GET['per']    ?? 20;
        $status = $_GET['status'] ?? '';
        $search = $_GET['search'] ?? '';

        $where = ['1=1'];
        $binds = [];

        if ($status) { $where[] = 'status = ?';                    $binds[] = $status; }
        if ($search) { $where[] = "(name LIKE ? OR icao LIKE ?)";  $binds[] = "%$search%"; $binds[] = "%$search%"; }

        $result = paginate($db, 'airlines', implode(' AND ', $where), $binds, $page, $per);

        // Stats summary
        $stats = $db->query("
            SELECT
              COUNT(*)                                  AS total,
              SUM(pilot_count)                          AS total_pilots,
              SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) AS active_count
            FROM airlines
        ")->fetch();

        $result['stats'] = $stats;
        json_response($result);
    });

    // GET /api/airlines/:id
    $router->get('/api/airlines/:id', function(array $params) {
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM airlines WHERE id = ?");
        $stmt->execute([$params['id']]);
        $airline = $stmt->fetch();
        if (!$airline) json_error('Airline not found', 404);
        json_response(['data' => $airline]);
    });

    // POST /api/airlines
    $router->post('/api/airlines', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['name', 'icao']);

        // Check ICAO uniqueness
        $dup = $db->prepare("SELECT id FROM airlines WHERE icao = ?");
        $dup->execute([strtoupper($body['icao'])]);
        if ($dup->fetch()) json_error("ICAO code '{$body['icao']}' already exists", 409);

        $stmt = $db->prepare("
            INSERT INTO airlines (name, icao, callsign, status, logo_url, description, pilot_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($body['name']),
            strtoupper(trim($body['icao'])),
            $body['callsign']     ?? null,
            $body['status']       ?? 'observer',
            $body['logo_url']     ?? null,
            $body['description']  ?? null,
            (int)($body['pilot_count'] ?? 0),
        ]);
        $id      = $db->lastInsertId();
        $airline = $db->query("SELECT * FROM airlines WHERE id = $id")->fetch();

        audit($db, $payload['sub'], 'CREATE_AIRLINE', 'airline', (int)$id, "Created: {$body['name']} ({$body['icao']})");
        json_response(['message' => 'Airline registered', 'data' => $airline], 201);
    });

    // PUT /api/airlines/:id
    $router->put('/api/airlines/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();

        $stmt = $db->prepare("SELECT id FROM airlines WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Airline not found', 404);

        // ICAO uniqueness check on update
        if (!empty($body['icao'])) {
            $dup = $db->prepare("SELECT id FROM airlines WHERE icao = ? AND id != ?");
            $dup->execute([strtoupper($body['icao']), $params['id']]);
            if ($dup->fetch()) json_error("ICAO code '{$body['icao']}' already in use", 409);
            $body['icao'] = strtoupper($body['icao']);
        }

        $fields  = ['name', 'icao', 'callsign', 'status', 'logo_url', 'description', 'pilot_count'];
        $updates = [];
        $values  = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) {
                $updates[] = "$f = ?";
                $values[]  = $body[$f];
            }
        }

        if (empty($updates)) json_error('No fields to update', 422);

        $updates[] = "updated_at = datetime('now')";
        $values[]  = $params['id'];
        $db->prepare("UPDATE airlines SET " . implode(', ', $updates) . " WHERE id = ?")
           ->execute($values);

        $airline = $db->query("SELECT * FROM airlines WHERE id = {$params['id']}")->fetch();
        audit($db, $payload['sub'], 'UPDATE_AIRLINE', 'airline', (int)$params['id']);
        json_response(['message' => 'Airline updated', 'data' => $airline]);
    });

    // PATCH /api/airlines/:id/status
    $router->patch('/api/airlines/:id/status', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['status']);

        $valid = ['active', 'observer', 'suspended', 'inactive'];
        if (!in_array($body['status'], $valid)) {
            json_error('Invalid status. Must be one of: ' . implode(', ', $valid), 422);
        }

        $stmt = $db->prepare("SELECT id FROM airlines WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Airline not found', 404);

        $db->prepare("UPDATE airlines SET status = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$body['status'], $params['id']]);

        audit($db, $payload['sub'], 'UPDATE_AIRLINE_STATUS', 'airline', (int)$params['id'], "Status → {$body['status']}");
        json_response(['message' => 'Airline status updated']);
    });

    // DELETE /api/airlines/:id
    $router->delete('/api/airlines/:id', function(array $params) {
        $payload = Auth::requireRole('superadmin');
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT name, icao FROM airlines WHERE id = ?");
        $stmt->execute([$params['id']]);
        $airline = $stmt->fetch();
        if (!$airline) json_error('Airline not found', 404);

        $db->prepare("DELETE FROM airlines WHERE id = ?")->execute([$params['id']]);
        audit($db, $payload['sub'], 'DELETE_AIRLINE', 'airline', (int)$params['id'], "Deleted: {$airline['name']} ({$airline['icao']})");
        json_response(['message' => 'Airline removed']);
    });
}
