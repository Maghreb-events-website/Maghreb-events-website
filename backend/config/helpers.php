<?php

function json_response(mixed $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function json_error(string $message, int $status = 400): void {
    json_response(['error' => $message], $status);
    exit;
}

function body(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];

    // Accept both application/json and text/plain (the latter is used by
    // /callback to avoid CORS preflight on the OAuth code exchange).
    $data = json_decode($raw, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
        return $data;
    }

    // Fallback: try URL-encoded form data
    parse_str($raw, $form);
    if (!empty($form)) {
        return $form;
    }

    json_error('Invalid request body', 400);
}

function require_fields(array $data, array $fields): void {
    $missing = array_filter($fields, fn($f) => empty($data[$f]));
    if ($missing) {
        json_error('Missing required fields: ' . implode(', ', $missing), 422);
    }
}

function audit(PDO $db, ?int $adminId, string $action, string $entity = '', ?int $entityId = null, string $detail = ''): void {
    $db->prepare("
        INSERT INTO audit_log (admin_id, action, entity_type, entity_id, detail)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$adminId, $action, $entity ?: null, $entityId, $detail ?: null]);
}

function paginate(PDO $db, string $table, string $where = '1=1', array $binds = [], int $page = 1, int $per = 20): array {
    $page   = max(1, (int)$page);
    $per    = min(100, max(1, (int)$per));
    $offset = ($page - 1) * $per;

    $stmt = $db->prepare("SELECT COUNT(*) FROM $table WHERE $where");
    $stmt->execute($binds);
    $total = (int)$stmt->fetchColumn();

    $stmt2 = $db->prepare("SELECT * FROM $table WHERE $where ORDER BY id DESC LIMIT ? OFFSET ?");
    $stmt2->execute([...$binds, $per, $offset]);
    $items = $stmt2->fetchAll();

    return [
        'data'       => $items,
        'pagination' => [
            'page'       => $page,
            'per_page'   => $per,
            'total'      => $total,
            'total_pages'=> (int)ceil($total / $per),
        ]
    ];
}
