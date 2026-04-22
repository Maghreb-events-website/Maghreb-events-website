<?php
// Generic CRUD for "people" (planning_team, hitsquad). Both tables share schema.

function register_people_routes(Router $router, string $table, string $base): void {
    // GET /api/{base}
    $router->get("/api/$base", function() use ($table) {
        $db   = Database::getInstance();
        $rows = $db->query("SELECT * FROM $table ORDER BY sort_order ASC, id ASC")->fetchAll();
        json_response(['data' => $rows]);
    });

    // POST /api/{base}  (admin)
    $router->post("/api/$base", function() use ($table) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        // For planning_team, subsection is required.
        $required = ['name'];
        if ($table === 'planning_team') { $required[] = 'subsection'; }
        require_fields($body, $required);

        $allowedSubsections = ['team_leads','communications','routes','technology','other'];
        $subsection = $body['subsection'] ?? null;
        if ($table === 'planning_team') {
            if (!in_array($subsection, $allowedSubsections, true)) {
                json_error('Invalid subsection. Must be one of: ' . implode(', ', $allowedSubsections), 422);
            }
        }

        // hitsquad table has no subsection column — only include it for planning_team
        if ($table === 'planning_team') {
            $stmt = $db->prepare("INSERT INTO $table (name, role, cid, photo_url, bio, subsection) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($body['name']),
                $body['role']       ?? null,
                $body['cid']        ?? null,
                $body['photo_url']  ?? null,
                $body['bio']        ?? null,
                $subsection,
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO $table (name, role, cid, photo_url, bio) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                trim($body['name']),
                $body['role']       ?? null,
                $body['cid']        ?? null,
                $body['photo_url']  ?? null,
                $body['bio']        ?? null,
            ]);
        }
        $id  = (int)$db->lastInsertId();
        $row = $db->query("SELECT * FROM $table WHERE id = $id")->fetch();
        audit($db, $payload['sub'], 'CREATE_'.strtoupper($table), $table, $id, "Added: {$body['name']}");
        json_response(['message' => 'Created', 'data' => $row], 201);
    });

    // PUT /api/{base}/:id  (admin)
    $router->put("/api/$base/:id", function(array $params) use ($table) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();

        $stmt = $db->prepare("SELECT id FROM $table WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Not found', 404);

        $fields  = ['name','role','cid','photo_url','bio','sort_order'];
        if ($table === 'planning_team') { $fields[] = 'subsection'; }
        $allowedSubsections = ['team_leads','communications','routes','technology','other'];
        if ($table === 'planning_team' && array_key_exists('subsection', $body)) {
            if (!in_array($body['subsection'], $allowedSubsections, true)) {
                json_error('Invalid subsection. Must be one of: ' . implode(', ', $allowedSubsections), 422);
            }
        }
        $updates = []; $values = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $body)) { $updates[] = "$f = ?"; $values[] = $body[$f]; }
        }
        if (!$updates) json_error('No fields to update', 422);
        $updates[] = "updated_at = datetime('now')";
        $values[]  = $params['id'];
        $db->prepare("UPDATE $table SET " . implode(', ', $updates) . " WHERE id = ?")->execute($values);
        $row = $db->query("SELECT * FROM $table WHERE id = " . (int)$params['id'])->fetch();
        audit($db, $payload['sub'], 'UPDATE_'.strtoupper($table), $table, (int)$params['id']);
        json_response(['message' => 'Updated', 'data' => $row]);
    });

    // DELETE /api/{base}/:id  (admin)
    $router->delete("/api/$base/:id", function(array $params) use ($table) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $db->prepare("DELETE FROM $table WHERE id = ?")->execute([$params['id']]);
        audit($db, $payload['sub'], 'DELETE_'.strtoupper($table), $table, (int)$params['id']);
        json_response(['message' => 'Deleted']);
    });
}

function register_planning_routes(Router $router): void {
    register_people_routes($router, 'planning_team', 'planning');
}
function register_hitsquad_routes(Router $router): void {
    register_people_routes($router, 'hitsquad', 'hitsquad');
}
