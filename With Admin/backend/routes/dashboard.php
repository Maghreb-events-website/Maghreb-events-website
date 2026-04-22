<?php

function register_dashboard_routes(Router $router): void {

    // GET /api/dashboard
    $router->get('/api/dashboard', function() {
        Auth::requireAuth();
        $db = Database::getInstance();

        $events = $db->query("
            SELECT
              COUNT(*) AS total,
              SUM(CASE WHEN status='upcoming'  THEN 1 ELSE 0 END) AS upcoming,
              SUM(CASE WHEN status='active'    THEN 1 ELSE 0 END) AS active,
              SUM(CASE WHEN status='completed' THEN 1 ELSE 0 END) AS completed
            FROM events
        ")->fetch();

        $partners = $db->query("
            SELECT
              COUNT(*) AS total,
              SUM(CASE WHEN status='verified'  THEN 1 ELSE 0 END) AS verified,
              SUM(CASE WHEN status='pending'   THEN 1 ELSE 0 END) AS pending
            FROM partners
        ")->fetch();

        $airlines = $db->query("
            SELECT
              COUNT(*)         AS total,
              SUM(pilot_count) AS total_pilots,
              SUM(CASE WHEN status='active'   THEN 1 ELSE 0 END) AS active,
              SUM(CASE WHEN status='observer' THEN 1 ELSE 0 END) AS observer
            FROM airlines
        ")->fetch();

        $recentEvents = $db->query("
            SELECT id, title, status, start_time, end_time
            FROM events ORDER BY id DESC LIMIT 5
        ")->fetchAll();

        $recentLog = $db->query("
            SELECT al.*, a.name AS admin_name
            FROM audit_log al
            LEFT JOIN admins a ON al.admin_id = a.id
            ORDER BY al.id DESC LIMIT 10
        ")->fetchAll();

        json_response([
            'events'        => $events,
            'partners'      => $partners,
            'airlines'      => $airlines,
            'recent_events' => $recentEvents,
            'recent_log'    => $recentLog,
        ]);
    });
}
