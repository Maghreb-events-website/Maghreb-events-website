<?php
// Pilot briefing PDF — admin uploads, public reads "latest".

function register_briefing_routes(Router $router): void {
    $storageDir = __DIR__ . '/../storage/briefings';
    if (!is_dir($storageDir)) mkdir($storageDir, 0755, true);

    // GET /api/briefing/latest  (public)
    $router->get('/api/briefing/latest', function() {
        $db  = Database::getInstance();
        $row = $db->query("SELECT * FROM pilot_briefs ORDER BY id DESC LIMIT 1")->fetch();
        if (!$row) { http_response_code(404); echo json_encode(['error' => 'No briefing uploaded']); return; }

        // Build absolute URL to the file under /storage/briefings/...
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $url    = "$scheme://$host/storage/briefings/" . basename($row['file_path']);

        json_response([
            'id'         => $row['id'],
            'title'      => $row['title'],
            'url'        => $url,
            'created_at' => $row['created_at'],
        ]);
    });

    // POST /api/briefing  (admin) — multipart/form-data with field "pdf"
    $router->post('/api/briefing', function() use ($storageDir) {
        $payload = Auth::requireAuth();

        if (empty($_FILES['pdf']) || ($_FILES['pdf']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            json_error('PDF file is required (field name "pdf")', 422);
        }
        $file = $_FILES['pdf'];

        // Basic validation
        if ($file['size'] > 25 * 1024 * 1024) json_error('File too large (max 25MB)', 413);
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if ($mime !== 'application/pdf') json_error('Only PDF files are allowed', 422);

        $name = 'brief_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.pdf';
        $dest = $storageDir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            json_error('Failed to store uploaded file', 500);
        }

        $title = isset($_POST['title']) ? trim((string)$_POST['title']) : null;

        $db = Database::getInstance();
        $stmt = $db->prepare("INSERT INTO pilot_briefs (title, file_path, uploaded_by) VALUES (?, ?, ?)");
        $stmt->execute([$title ?: null, $name, $payload['sub']]);
        $id = (int)$db->lastInsertId();

        audit($db, $payload['sub'], 'UPLOAD_BRIEFING', 'pilot_brief', $id, $name);
        json_response(['message' => 'Briefing uploaded', 'id' => $id], 201);
    });

    // GET /api/briefing  (admin) — list all uploaded briefings
    $router->get('/api/briefing', function() {
        $db   = Database::getInstance();
        $rows = $db->query("SELECT * FROM pilot_briefs ORDER BY id DESC")->fetchAll();

        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';

        $data = array_map(function($row) use ($scheme, $host) {
            return [
                'id'         => $row['id'],
                'title'      => $row['title'],
                'url'        => "$scheme://$host/storage/briefings/" . basename($row['file_path']),
                'file_path'  => $row['file_path'],
                'created_at' => $row['created_at'],
            ];
        }, $rows);

        json_response(['data' => $data]);
    });

    // DELETE /api/briefing/:id  (admin)
    $router->delete('/api/briefing/:id', function(array $params) use ($storageDir) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM pilot_briefs WHERE id = ?");
        $stmt->execute([$params['id']]);
        $row = $stmt->fetch();
        if (!$row) json_error('Briefing not found', 404);

        // Delete the physical file
        $filePath = $storageDir . '/' . basename($row['file_path']);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }

        $db->prepare("DELETE FROM pilot_briefs WHERE id = ?")->execute([$params['id']]);
        audit($db, $payload['sub'], 'DELETE_BRIEFING', 'pilot_brief', (int)$params['id'], "Deleted: {$row['file_path']}");
        json_response(['message' => 'Briefing deleted']);
    });
}
