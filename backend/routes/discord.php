<?php

function register_discord_routes(Router $router): void {

    // GET /api/discord/health
    // Proxies the bridge health check so the frontend can confirm the bot is up.
    $router->get('/api/discord/health', function() {
        $bridgeUrl = defined('BOT_BRIDGE_URL') ? BOT_BRIDGE_URL : (getenv('BOT_BRIDGE_URL') ?: 'http://127.0.0.1:5001');
        // Strip trailing /send if present — health is at /health
        $baseUrl = rtrim(preg_replace('#/send$#', '', $bridgeUrl), '/');

        $ch = curl_init("$baseUrl/health");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 3,
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno || $raw === false) {
            json_response(['ok' => false, 'error' => 'Bridge not reachable']);
            return;
        }
        $data = json_decode($raw, true);
        json_response($data ?? ['ok' => false, 'error' => 'Invalid response from bridge']);
    });

    // POST /api/discord/announce
    // Sends an announcement or event post to Discord via the local bot bridge.
    // Body: { channel, title, description, para2?, para3?, para4?, image_url? }
    $router->post('/api/discord/announce', function() {
        $payload = Auth::requireAuth();
        $body    = body();
        require_fields($body, ['channel', 'title', 'description']);

        $channel = trim($body['channel'] ?? '');
        if (!in_array($channel, ['event', 'announcement'], true)) {
            json_error("channel must be 'event' or 'announcement'", 422);
        }

        $bridgeUrl   = defined('BOT_BRIDGE_URL')   ? BOT_BRIDGE_URL   : (getenv('BOT_BRIDGE_URL')   ?: 'http://127.0.0.1:5001/send');
        $bridgeToken = defined('BOT_BRIDGE_TOKEN') ? BOT_BRIDGE_TOKEN : (getenv('BOT_BRIDGE_TOKEN') ?: '');

        if (!$bridgeToken) {
            http_response_code(502);
            echo json_encode([
                'error' => 'Discord bot bridge is not configured',
                'hint'  => 'Set BOT_BRIDGE_TOKEN in vatsim_config.php',
            ]);
            exit;
        }

        $requestBody = json_encode([
            'channel'     => $channel,
            'title'       => trim($body['title']       ?? ''),
            'description' => trim($body['description'] ?? ''),
            'para2'       => trim($body['para2']       ?? ''),
            'para3'       => trim($body['para3']       ?? ''),
            'para4'       => trim($body['para4']       ?? ''),
            'image_url'   => trim($body['image_url']   ?? ''),
        ]);

        $result = discord_bridge_post($bridgeUrl, $bridgeToken, $requestBody);

        if (isset($result['error'])) {
            http_response_code(502);
            echo json_encode([
                'error'  => 'Discord bridge error',
                'detail' => $result['error'],
                'hint'   => 'Make sure the Discord bot is running on the server (python3 main.py)',
            ]);
            exit;
        }

        $db = Database::getInstance();
        audit($db, $payload['sub'], 'DISCORD_ANNOUNCE', 'discord', 0,
              "Sent to #{$channel}: " . substr(trim($body['title']), 0, 80));

        json_response(['ok' => true, 'message' => 'Announcement sent to Discord']);
    });

    // POST /api/discord/event/:id
    // Publishes an existing event to the Discord event channel.
    $router->post('/api/discord/event/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->execute([$params['id']]);
        $event = $stmt->fetch();
        if (!$event) json_error('Event not found', 404);

        $bridgeUrl   = defined('BOT_BRIDGE_URL')   ? BOT_BRIDGE_URL   : (getenv('BOT_BRIDGE_URL')   ?: 'http://127.0.0.1:5001/send');
        $bridgeToken = defined('BOT_BRIDGE_TOKEN') ? BOT_BRIDGE_TOKEN : (getenv('BOT_BRIDGE_TOKEN') ?: '');

        if (!$bridgeToken) {
            http_response_code(502);
            echo json_encode([
                'error' => 'Discord bot bridge is not configured',
                'hint'  => 'Set BOT_BRIDGE_TOKEN in vatsim_config.php',
            ]);
            exit;
        }

        // Format times nicely
        $start = $event['start_time'] ? date('D j M Y, H:i \U\T\C', strtotime($event['start_time'])) : '—';
        $end   = $event['end_time']   ? date('D j M Y, H:i \U\T\C', strtotime($event['end_time']))   : '—';

        $description = $event['description'] ?? '';
        $timeInfo    = "**Start:** {$start}\n**End:** {$end}";

        $requestBody = json_encode([
            'channel'     => 'event',
            'title'       => $event['title'],
            'description' => $description ?: 'New event from Maghreb vACC',
            'para2'       => $timeInfo,
            'para3'       => '',
            'para4'       => '',
            'image_url'   => $event['banner_url'] ?? '',
        ]);

        $result = discord_bridge_post($bridgeUrl, $bridgeToken, $requestBody);

        if (isset($result['error'])) {
            http_response_code(502);
            echo json_encode([
                'error'  => 'Discord bridge error',
                'detail' => $result['error'],
                'hint'   => 'Make sure the Discord bot is running on the server (python3 main.py)',
            ]);
            exit;
        }

        audit($db, $payload['sub'], 'DISCORD_EVENT_POST', 'event', (int)$params['id'],
              "Published to Discord: {$event['title']}");

        json_response(['ok' => true, 'message' => 'Event posted to Discord']);
    });
    // POST /api/discord/schedule-event/:id
    // Creates a Discord scheduled event in the guild from an existing event record.
    $router->post('/api/discord/schedule-event/:id', function(array $params) {
        $payload = Auth::requireAuth();
        $db      = Database::getInstance();

        $stmt = $db->prepare("SELECT * FROM events WHERE id = ?");
        $stmt->execute([$params['id']]);
        $event = $stmt->fetch();
        if (!$event) json_error('Event not found', 404);

        $bridgeUrl   = defined('BOT_BRIDGE_URL')   ? BOT_BRIDGE_URL   : (getenv('BOT_BRIDGE_URL')   ?: 'http://127.0.0.1:5001/send');
        $bridgeToken = defined('BOT_BRIDGE_TOKEN') ? BOT_BRIDGE_TOKEN : (getenv('BOT_BRIDGE_TOKEN') ?: '');

        // Hit /create-event instead of /send
        $baseUrl    = rtrim(preg_replace('#/send$#', '', $bridgeUrl), '/');
        $createUrl  = "$baseUrl/create-event";

        if (!$bridgeToken) {
            http_response_code(502);
            echo json_encode(['error' => 'Discord bot bridge is not configured']);
            exit;
        }

        if (empty($event['start_time']) || empty($event['end_time'])) {
            json_error('Event must have a start time and end time before creating a Discord scheduled event.', 422);
        }

        $requestBody = json_encode([
            'title'       => $event['title'],
            'description' => $event['description'] ?: 'Maghreb vACC Event',
            'location'    => 'Online — VATSIM',
            'start_time'  => $event['start_time'],
            'end_time'    => $event['end_time'],
            'banner_url'  => $event['banner_url'] ?? '',
        ]);

        // Use a longer timeout here — the bot may download a banner image
        // before responding, which can take several seconds on top of the
        // Discord API call itself.
        $result = discord_bridge_post($createUrl, $bridgeToken, $requestBody, 25);

        if (isset($result['error'])) {
            http_response_code(502);
            // Combine into a single 'error' key so api() on the frontend shows the detail
            echo json_encode([
                'error' => 'Failed to create Discord event: ' . $result['error'],
            ]);
            exit;
        }

        audit($db, $payload['sub'], 'DISCORD_SCHEDULE_EVENT', 'event', (int)$params['id'],
              "Created Discord event: {$event['title']}");

        json_response([
            'ok'        => true,
            'message'   => 'Discord scheduled event created',
            'event_url' => $result['event_url'] ?? null,
        ]);
    });

}

// ── Internal HTTP helper ──────────────────────────────────────────────────

function discord_bridge_post(string $url, string $token, string $jsonBody, int $timeout = 10): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $jsonBody,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                "X-Bridge-Token: $token",
            ],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno || $raw === false) {
            return ['error' => "Cannot reach Discord bot bridge at $url — is the bot running? (cURL: $error)"];
        }
        return json_decode($raw, true) ?? ['error' => 'Invalid JSON from bridge'];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['error' => 'Neither cURL nor allow_url_fopen available'];
    }
    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\nX-Bridge-Token: $token\r\n",
        'content'       => $jsonBody,
        'timeout'       => $timeout,
        'ignore_errors' => true,
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        return ['error' => "Could not reach bridge at $url — is the bot running?"];
    }
    return json_decode($raw, true) ?? ['error' => 'Invalid JSON from bridge'];
}
