<?php

function register_partner_routes(Router $router): void {

    // GET /api/partners
    $router->get('/api/partners', function() {
        $db     = Database::getInstance();
        $page   = $_GET['page']   ?? 1;
        $per    = $_GET['per']    ?? 20;
        $status = $_GET['status'] ?? '';
        $type   = $_GET['type']   ?? '';

        $where  = ['1=1'];
        $binds  = [];

        if ($status) { $where[] = 'status = ?'; $binds[] = $status; }
        if ($type)   { $where[] = 'type = ?';   $binds[] = $type; }

        $result = paginate($db, 'partners', implode(' AND ', $where), $binds, $page, $per);
        json_response($result);
    });

    // GET /api/partners/:id
    $router->get('/api/partners/:id', function(array $params) {
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM partners WHERE id = ?");
        $stmt->execute([$params['id']]);
        $partner = $stmt->fetch();
        if (!$partner) json_error('Partner not found', 404);
        json_response(['data' => $partner]);
    });

    // POST /api/partners
    $router->post('/api/partners', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['name', 'type']);

        $stmt = $db->prepare("
            INSERT INTO partners (name, type, status, website, logo_url, description, contact_email)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            trim($body['name']),
            $body['type'],
            $body['status']        ?? 'pending',
            $body['website']       ?? null,
            $body['logo_url']      ?? null,
            $body['description']   ?? null,
            $body['contact_email'] ?? null,
        ]);
        $id      = $db->lastInsertId();
        $partner = $db->query("SELECT * FROM partners WHERE id = $id")->fetch();

        audit($db, $payload['sub'], 'CREATE_PARTNER', 'partner', (int)$id, "Created: {$body['name']}");
        json_response(['message' => 'Partner created', 'data' => $partner], 201);
    });

    // PUT /api/partners/:id
    $router->put('/api/partners/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();

        $stmt = $db->prepare("SELECT id FROM partners WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Partner not found', 404);

        $fields  = ['name', 'type', 'status', 'website', 'logo_url', 'description', 'contact_email'];
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
        $db->prepare("UPDATE partners SET " . implode(', ', $updates) . " WHERE id = ?")
           ->execute($values);

        $partner = $db->query("SELECT * FROM partners WHERE id = {$params['id']}")->fetch();
        audit($db, $payload['sub'], 'UPDATE_PARTNER', 'partner', (int)$params['id']);
        json_response(['message' => 'Partner updated', 'data' => $partner]);
    });

    // PATCH /api/partners/:id/status
    $router->patch('/api/partners/:id/status', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['status']);

        $valid = ['pending', 'verified', 'suspended', 'inactive'];
        if (!in_array($body['status'], $valid)) {
            json_error('Invalid status. Must be one of: ' . implode(', ', $valid), 422);
        }

        $stmt = $db->prepare("SELECT id FROM partners WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Partner not found', 404);

        $db->prepare("UPDATE partners SET status = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$body['status'], $params['id']]);

        audit($db, $payload['sub'], 'UPDATE_PARTNER_STATUS', 'partner', (int)$params['id'], "Status → {$body['status']}");
        json_response(['message' => 'Partner status updated']);
    });

    // DELETE /api/partners/:id
    $router->delete('/api/partners/:id', function(array $params) {
        $payload = Auth::requireRole('superadmin');
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT name FROM partners WHERE id = ?");
        $stmt->execute([$params['id']]);
        $partner = $stmt->fetch();
        if (!$partner) json_error('Partner not found', 404);

        $db->prepare("DELETE FROM partners WHERE id = ?")->execute([$params['id']]);
        audit($db, $payload['sub'], 'DELETE_PARTNER', 'partner', (int)$params['id'], "Deleted: {$partner['name']}");
        json_response(['message' => 'Partner deleted']);
    });
}
