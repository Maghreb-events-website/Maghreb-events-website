<?php

/**
 * Announcement routes
 * ===================
 * POST /api/announcements/send
 *
 * Forwards the announcement payload to the Discord bot's local HTTP bridge
 * (http_bridge.py running on the same VPS), which sends the embed through
 * the bot — not a Discord webhook.
 *
 * Setup: add these two lines to vatsim_config.php (or as server env vars):
 *   define('BOT_BRIDGE_TOKEN', 'your_secret_token');          // must match .env BOT_BRIDGE_TOKEN
 *   define('BOT_BRIDGE_URL',   'http://127.0.0.1:5001/send'); // default, change if needed
 */

function register_announcement_routes(Router $router): void {

    $router->post('/api/announcements/send', function () {

        // Any authenticated admin can send announcements
        Auth::requireRole('admin');

        $body = body();
        require_fields($body, ['title', 'description', 'channel']);

        $title       = trim($body['title']);
        $description = trim($body['description']);
        $para2       = trim($body['para2']     ?? '');
        $para3       = trim($body['para3']     ?? '');
        $para4       = trim($body['para4']     ?? '');
        $image_url   = trim($body['image_url'] ?? '');
        $channel     = $body['channel'];

        if (!in_array($channel, ['event', 'announcement'], true)) {
            json_error('channel must be "event" or "announcement"', 422);
        }

        // ── Read bridge config ───────────────────────────────────────────────
        $bridge_url   = defined('BOT_BRIDGE_URL')
            ? BOT_BRIDGE_URL
            : (getenv('BOT_BRIDGE_URL') ?: 'http://127.0.0.1:5001/send');

        $bridge_token = defined('BOT_BRIDGE_TOKEN')
            ? BOT_BRIDGE_TOKEN
            : (getenv('BOT_BRIDGE_TOKEN') ?: '');

        if (!$bridge_token) {
            json_error('BOT_BRIDGE_TOKEN is not configured on this server. Add it to vatsim_config.php.', 503);
        }

        // ── Build payload ────────────────────────────────────────────────────
        $payload = [
            'channel'     => $channel,
            'title'       => $title,
            'description' => $description,
        ];
        if ($para2 !== '')     $payload['para2']     = $para2;
        if ($para3 !== '')     $payload['para3']     = $para3;
        if ($para4 !== '')     $payload['para4']     = $para4;
        if ($image_url !== '') $payload['image_url'] = $image_url;

        // ── POST to bot bridge ───────────────────────────────────────────────
        $ch = curl_init($bridge_url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Bridge-Token: ' . $bridge_token,
            ],
        ]);

        $resp_body = curl_exec($ch);
        $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_err  = curl_error($ch);
        curl_close($ch);

        if ($curl_err) {
            json_error(
                'Cannot reach the Discord bot bridge — is the bot running? Error: ' . $curl_err,
                503
            );
        }

        $resp_data = $resp_body ? json_decode($resp_body, true) : [];

        if ($http_code < 200 || $http_code >= 300) {
            $msg = ($resp_data['error'] ?? null) ?: ('Bot bridge returned HTTP ' . $http_code);
            json_error('Bot bridge error: ' . $msg, 502);
        }

        json_response(['ok' => true, 'message' => 'Announcement sent via bot']);
    });
}
