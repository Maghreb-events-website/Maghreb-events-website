<?php
declare(strict_types=1);

// ── CORS headers — MUST be first, before any output ─────────────────────────
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [
    'https://jamie-datson.com',
    'https://www.jamie-datson.com',
    'http://localhost',
    'http://127.0.0.1',
];

if (in_array($origin, $allowed, true)) {
    header("Access-Control-Allow-Origin: $origin");
} else {
    header("Access-Control-Allow-Origin: https://jamie-datson.com");
}

header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Vary: Origin');
header('Content-Type: application/json; charset=UTF-8');

// Preflight — respond immediately before any other logic
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── Error handlers — registered FIRST so they catch boot errors too ─────────
set_exception_handler(function(Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode([
        'error'   => 'Internal server error',
        'message' => $e->getMessage(),
        'file'    => basename($e->getFile()),
        'line'    => $e->getLine(),
    ]);
});

set_error_handler(function(int $errno, string $errstr, string $errfile = '', int $errline = 0) {
    throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
});

// ── Load site config ─────────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/vatsim_config.php')) {
    require_once __DIR__ . '/vatsim_config.php';
}

// ── Bootstrap ────────────────────────────────────────────────────────────────
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/router.php';
require_once __DIR__ . '/config/helpers.php';

require_once __DIR__ . '/routes/auth.php';
require_once __DIR__ . '/routes/events.php';
require_once __DIR__ . '/routes/partners.php';
require_once __DIR__ . '/routes/airlines.php';
require_once __DIR__ . '/routes/admins.php';
require_once __DIR__ . '/routes/dashboard.php';
require_once __DIR__ . '/routes/people.php';
require_once __DIR__ . '/routes/briefing.php';
require_once __DIR__ . '/routes/settings.php';

// ── Router setup ─────────────────────────────────────────────────────────────
$router = new Router();

$router->get('/api/health', function() {
    json_response([
        'status'  => 'ok',
        'service' => 'Maghreb Events API',
        'version' => '1.0.0',
        'time'    => date('c'),
    ]);
});

register_auth_routes($router);
register_event_routes($router);
register_partner_routes($router);
register_airline_routes($router);
register_admin_routes($router);
register_dashboard_routes($router);
register_planning_routes($router);
register_hitsquad_routes($router);
register_briefing_routes($router);
register_settings_routes($router);

// ── Dispatch ─────────────────────────────────────────────────────────────────
$router->dispatch($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']);
