<?php

function register_giveaway_routes(Router $router): void {

    // ── GET /api/giveaways  (public) ─────────────────────────────────────────
    $router->get('/api/giveaways', function() {
        $db   = Database::getInstance();
        $rows = $db->query("SELECT * FROM giveaways ORDER BY created_at DESC")->fetchAll();
        foreach ($rows as &$row) {
            $row['prizes']        = json_decode($row['prizes'] ?? '[]', true) ?: [];
            $row['eligible_cids'] = json_decode($row['eligible_cids'] ?? '[]', true) ?: [];
            $row['entry_count']   = (int)$db->query("SELECT COUNT(*) FROM giveaway_entries WHERE giveaway_id = {$row['id']}")->fetchColumn();
        }
        json_response(['data' => $rows]);
    });

    // ── GET /api/giveaways/:id  (public) ────────────────────────────────────
    $router->get('/api/giveaways/:id', function(array $params) {
        $db   = Database::getInstance();
        $stmt = $db->prepare("SELECT * FROM giveaways WHERE id = ?");
        $stmt->execute([$params['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Giveaway not found', 404);

        $row['prizes']        = json_decode($row['prizes'] ?? '[]', true) ?: [];
        $row['eligible_cids'] = json_decode($row['eligible_cids'] ?? '[]', true) ?: [];
        $row['entry_count']   = (int)$db->query("SELECT COUNT(*) FROM giveaway_entries WHERE giveaway_id = {$row['id']}")->fetchColumn();

        json_response(['data' => $row]);
    });

    // ── POST /api/giveaways  (admin) ─────────────────────────────────────────
    $router->post('/api/giveaways', function() {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['title']);

        $prizes = isset($body['prizes']) && is_array($body['prizes']) ? $body['prizes'] : [];
        $prizes = array_values(array_filter(array_map('trim', $prizes)));

        $db->prepare("
            INSERT INTO giveaways (title, description, prizes, status, created_by)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([
            trim($body['title']),
            $body['description'] ?? null,
            json_encode($prizes),
            $body['status'] ?? 'upcoming',
            $payload['sub'],
        ]);

        $id  = $db->lastInsertId();
        $row = $db->query("SELECT * FROM giveaways WHERE id = $id")->fetch();
        $row['prizes']        = json_decode($row['prizes'] ?? '[]', true) ?: [];
        $row['eligible_cids'] = json_decode($row['eligible_cids'] ?? '[]', true) ?: [];

        audit($db, $payload['sub'], 'CREATE_GIVEAWAY', 'giveaway', (int)$id, "Created: {$body['title']}");
        json_response(['message' => 'Giveaway created', 'data' => $row], 201);
    });

    // ── PUT /api/giveaways/:id  (admin) ──────────────────────────────────────
    $router->put('/api/giveaways/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();

        $stmt = $db->prepare("SELECT id FROM giveaways WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Giveaway not found', 404);

        $updates = [];
        $values  = [];

        if (array_key_exists('title', $body)) {
            $updates[] = 'title = ?';
            $values[]  = trim($body['title']);
        }
        if (array_key_exists('description', $body)) {
            $updates[] = 'description = ?';
            $values[]  = $body['description'];
        }
        if (array_key_exists('status', $body)) {
            $valid = ['upcoming', 'open', 'closed', 'drawn'];
            if (!in_array($body['status'], $valid)) json_error('Invalid status', 422);
            $updates[] = 'status = ?';
            $values[]  = $body['status'];
        }
        if (array_key_exists('prizes', $body) && is_array($body['prizes'])) {
            $prizes    = array_values(array_filter(array_map('trim', $body['prizes'])));
            $updates[] = 'prizes = ?';
            $values[]  = json_encode($prizes);
        }
        if (array_key_exists('eligible_cids', $body) && is_array($body['eligible_cids'])) {
            $cids      = array_values(array_filter(array_map('trim', $body['eligible_cids'])));
            $updates[] = 'eligible_cids = ?';
            $values[]  = json_encode($cids);
        }

        if (empty($updates)) json_error('No fields to update', 422);

        $updates[] = "updated_at = datetime('now')";
        $values[]  = $params['id'];
        $db->prepare("UPDATE giveaways SET " . implode(', ', $updates) . " WHERE id = ?")
           ->execute($values);

        $row = $db->query("SELECT * FROM giveaways WHERE id = {$params['id']}")->fetch();
        $row['prizes']        = json_decode($row['prizes'] ?? '[]', true) ?: [];
        $row['eligible_cids'] = json_decode($row['eligible_cids'] ?? '[]', true) ?: [];

        audit($db, $payload['sub'], 'UPDATE_GIVEAWAY', 'giveaway', (int)$params['id']);
        json_response(['message' => 'Giveaway updated', 'data' => $row]);
    });

    // ── DELETE /api/giveaways/:id  (admin) ───────────────────────────────────
    $router->delete('/api/giveaways/:id', function(array $params) {
        $payload = Auth::requireRole('admin');
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT title FROM giveaways WHERE id = ?");
        $stmt->execute([$params['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Giveaway not found', 404);

        $db->prepare("DELETE FROM giveaway_entries WHERE giveaway_id = ?")->execute([$params['id']]);
        $db->prepare("DELETE FROM giveaways WHERE id = ?")->execute([$params['id']]);

        audit($db, $payload['sub'], 'DELETE_GIVEAWAY', 'giveaway', (int)$params['id'], "Deleted: {$row['title']}");
        json_response(['message' => 'Giveaway deleted']);
    });

    // ── POST /api/giveaways/:id/enter  (authenticated VATSIM user) ──────────
    //
    // Any signed-in user (role = 'user' OR admin) can enter.
    // CID is taken from the verified JWT — never from the request body.
    $router->post('/api/giveaways/:id/enter', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();

        $cid = (string)($payload['cid'] ?? '');
        if (!$cid) json_error('No CID found in your session. Please sign in again.', 401);

        // Load giveaway
        $stmt = $db->prepare("SELECT * FROM giveaways WHERE id = ?");
        $stmt->execute([$params['id']]);
        $giveaway = $stmt->fetch();
        if (!$giveaway) json_error('Giveaway not found', 404);

        if ($giveaway['status'] !== 'open') {
            json_error('This giveaway is not currently accepting entries.', 409);
        }

        // Check for duplicate entry
        $dup = $db->prepare("SELECT id FROM giveaway_entries WHERE giveaway_id = ? AND cid = ?");
        $dup->execute([$params['id'], $cid]);
        if ($dup->fetch()) {
            json_error('You have already entered this giveaway.', 409);
        }

        $db->prepare("
            INSERT INTO giveaway_entries (giveaway_id, cid, name)
            VALUES (?, ?, ?)
        ")->execute([$params['id'], $cid, $payload['name'] ?? null]);

        json_response(['message' => 'Entry submitted successfully! Good luck!'], 201);
    });

    // ── POST /api/giveaways/:id/admin-entry  (admin) ────────────────────────────
    // Manually add a user to a giveaway by CID (skips the status=open check
    // and the duplicate guard is still enforced).
    $router->post('/api/giveaways/:id/admin-entry', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();

        if (empty($body['cid'])) json_error('CID is required', 422);
        $cid = trim((string)$body['cid']);

        $stmt = $db->prepare("SELECT id, status FROM giveaways WHERE id = ?");
        $stmt->execute([$params['id']]);
        $giveaway = $stmt->fetch();
        if (!$giveaway) json_error('Giveaway not found', 404);

        // Check for duplicate
        $dup = $db->prepare("SELECT id FROM giveaway_entries WHERE giveaway_id = ? AND cid = ?");
        $dup->execute([$params['id'], $cid]);
        if ($dup->fetch()) json_error("CID $cid has already entered this giveaway.", 409);

        $db->prepare("INSERT INTO giveaway_entries (giveaway_id, cid, name) VALUES (?, ?, ?)")
           ->execute([$params['id'], $cid, $body['name'] ?? null]);

        audit($db, $payload['sub'], 'ADMIN_ADD_ENTRY', 'giveaway', (int)$params['id'],
              "Manually added CID $cid");

        json_response(['message' => "Entry added for CID $cid"], 201);
    });

    // ── GET /api/giveaways/:id/entries  (admin) ──────────────────────────────
    $router->get('/api/giveaways/:id/entries', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT id FROM giveaways WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Giveaway not found', 404);

        $entries = $db->prepare("SELECT * FROM giveaway_entries WHERE giveaway_id = ? ORDER BY entered_at ASC");
        $entries->execute([$params['id']]);

        json_response(['data' => $entries->fetchAll()]);
    });

    // ── POST /api/giveaways/:id/draw  (admin) ────────────────────────────────
    //
    // Closes the giveaway and picks a winner.
    // The admin provides eligible_cids in the request body (or they're already
    // saved on the giveaway record). The backend intersects those CIDs with the
    // actual entry list, then randomly selects one winner and assigns them a
    // prize from the prize pool (also random if multiple prizes exist).
    $router->post('/api/giveaways/:id/draw', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();

        $stmt = $db->prepare("SELECT * FROM giveaways WHERE id = ?");
        $stmt->execute([$params['id']]);
        $giveaway = $stmt->fetch();
        if (!$giveaway) json_error('Giveaway not found', 404);

        if ($giveaway['status'] === 'drawn') {
            json_error('A winner has already been drawn for this giveaway.', 409);
        }

        // Determine eligible CIDs: prefer body param, fall back to stored value
        $eligibleCids = [];
        if (isset($body['eligible_cids']) && is_array($body['eligible_cids'])) {
            $eligibleCids = array_values(array_filter(array_map('trim', $body['eligible_cids'])));
            // Persist to giveaway record
            $db->prepare("UPDATE giveaways SET eligible_cids = ?, updated_at = datetime('now') WHERE id = ?")
               ->execute([json_encode($eligibleCids), $params['id']]);
        } else {
            $eligibleCids = json_decode($giveaway['eligible_cids'] ?? '[]', true) ?: [];
        }

        if (empty($eligibleCids)) {
            json_error('No eligible CIDs provided. Please supply an eligible_cids list.', 422);
        }

        // Fetch all entries
        $entriesStmt = $db->prepare("SELECT cid, name FROM giveaway_entries WHERE giveaway_id = ?");
        $entriesStmt->execute([$params['id']]);
        $entries = $entriesStmt->fetchAll();

        if (empty($entries)) {
            json_error('No entries have been submitted for this giveaway.', 422);
        }

        // Intersect: entries whose CID is in the eligible list
        $eligibleSet = array_flip($eligibleCids);
        $qualified   = array_values(array_filter($entries, fn($e) => isset($eligibleSet[$e['cid']])));

        if (empty($qualified)) {
            json_error('No entries match the eligible CID list. Cannot draw a winner.', 422);
        }

        // Pick a random winner
        $winner = $qualified[array_rand($qualified)];

        // Pick a random prize
        $prizes     = json_decode($giveaway['prizes'] ?? '[]', true) ?: [];
        $prize      = !empty($prizes) ? $prizes[array_rand($prizes)] : 'Prize TBD';

        // Mark giveaway as drawn
        $db->prepare("
            UPDATE giveaways
            SET status = 'drawn',
                winner_cid   = ?,
                winner_name  = ?,
                winner_prize = ?,
                updated_at   = datetime('now')
            WHERE id = ?
        ")->execute([$winner['cid'], $winner['name'], $prize, $params['id']]);

        audit($db, $payload['sub'], 'DRAW_GIVEAWAY', 'giveaway', (int)$params['id'],
              "Winner: CID {$winner['cid']} — Prize: $prize. Qualified pool: " . count($qualified) . " of " . count($entries) . " entries.");

        json_response([
            'message'         => 'Winner drawn successfully!',
            'winner_cid'      => $winner['cid'],
            'winner_name'     => $winner['name'],
            'winner_prize'    => $prize,
            'total_entries'   => count($entries),
            'qualified_pool'  => count($qualified),
        ]);
    });

    // ── PATCH /api/giveaways/:id/status  (admin) ─────────────────────────────
    $router->patch('/api/giveaways/:id/status', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();
        $body    = body();
        require_fields($body, ['status']);

        $valid = ['upcoming', 'open', 'closed', 'drawn'];
        if (!in_array($body['status'], $valid)) {
            json_error('Invalid status. Must be one of: ' . implode(', ', $valid), 422);
        }

        $stmt = $db->prepare("SELECT id FROM giveaways WHERE id = ?");
        $stmt->execute([$params['id']]);
        if (!$stmt->fetch()) json_error('Giveaway not found', 404);

        $db->prepare("UPDATE giveaways SET status = ?, updated_at = datetime('now') WHERE id = ?")
           ->execute([$body['status'], $params['id']]);

        audit($db, $payload['sub'], 'UPDATE_GIVEAWAY_STATUS', 'giveaway', (int)$params['id'], "Status → {$body['status']}");
        json_response(['message' => 'Giveaway status updated']);
    });
}
