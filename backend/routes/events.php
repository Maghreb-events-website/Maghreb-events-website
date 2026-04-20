<?php

function register_event_routes(Router $router): void {

    // GET /api/events
    $router->get('/api/events', function() {
        $db     = Database::getInstance();
        $page   = $_GET['page']   ?? 1;
        $per    = $_GET['per']    ?? 20;
        $status = $_GET['status'] ?? '';

        if ($status) {
            $result = paginate($db, 'events', 'status = ?', [$status], $page, $per);
        } else {
            $result = paginate($db, 'events', '1=1', [], $page, $per);
        }

        json_response($result);
    });

    // GET /api/events/:id
    $router->get('/api/events/:id', function(array $params) {
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->execute([$params['id']]);
        $event = $stmt->fetch();
        if (!$event) json_error('Event not found', 404);
        json_response(['data' => $event]);
    });

    // POST /api/events
    $router->post('/api/events', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['title', 'start_time', 'end_time']);

        $stmt = $db->prepare("
            INSERT INTO events (title, description, start_time, end_time, status, banner_url)
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
        $id = $db->lastInsertId();

        $new = $db->prepare("SELECT * FROM events WHERE id = ?")->execute([$id]);
        $event = $db->query("SELECT * FROM events WHERE id = $id")->fetch();

        audit($db, $payload['sub'], 'CREATE_EVENT', 'event', (int)$id, "Created: {$body['title']}");
        json_response(['message' => 'Event created', 'data' => $event], 201);
    });

    // PUT /api/events/:id
    $router->put('/api/events/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();

        $stmt = $db->prepare("SELECT id FROM events WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Event not found', 404);

        $fields  = ['title', 'description', 'start_time', 'end_time', 'status', 'banner_url'];
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
        $db->prepare("UPDATE events SET " . implode(', ', $updates) . " WHERE id = ?")
           ->execute($values);

        $event = $db->query("SELECT * FROM events WHERE id = {$params['id']}")->fetch();
        audit($db, $payload['sub'], 'UPDATE_EVENT', 'event', (int)$params['id']);
        json_response(['message' => 'Event updated', 'data' => $event]);
    });

    // DELETE /api/events/:id
    $router->delete('/api/events/:id', function(array $params) {
        $payload = Auth::requireRole('superadmin');
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT title FROM events WHERE id = ?");
        $stmt->execute([$params['id']]);
        $event = $stmt->fetch();
        if (!$event) json_error('Event not found', 404);

        $db->prepare("DELETE FROM events WHERE id = ?")->execute([$params['id']]);
        audit($db, $payload['sub'], 'DELETE_EVENT', 'event', (int)$params['id'], "Deleted: {$event['title']}");
        json_response(['message' => 'Event deleted']);
    });

    // PATCH /api/events/:id/status
    $router->patch('/api/events/:id/status', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['status']);

        $valid = ['upcoming', 'active', 'completed', 'cancelled'];
        if (!in_array($body['status'], $valid)) {
            json_error('Invalid status. Must be one of: ' . implode(', ', $valid), 422);
        }

        $stmt = $db->prepare("SELECT id FROM events WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Event not found', 404);

        $db->prepare("UPDATE events SET status = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$body['status'], $params['id']]);

        audit($db, $payload['sub'], 'UPDATE_EVENT_STATUS', 'event', (int)$params['id'], "Status → {$body['status']}");
        json_response(['message' => 'Event status updated']);
    });
}
