<?php

class Auth {
    private static string $secret = '';
    private static int $ttl = 3600 * 8; // 8 hours

    private static function secret(): string {
        if (self::$secret === '') {
            self::$secret = defined('JWT_SECRET') ? JWT_SECRET : 'MAGHREB_EVENTS_SECRET_KEY_CHANGE_IN_PROD_2024';
        }
        return self::$secret;
    }

    public static function generateToken(array $payload): string {
        $header  = self::b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload['iat'] = time();
        $payload['exp'] = time() + self::$ttl;
        $body    = self::b64url(json_encode($payload));
        $sig     = self::b64url(hash_hmac('sha256', "$header.$body", self::secret(), true));
        return "$header.$body.$sig";
    }

    public static function verifyToken(string $token): ?array {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        [$header, $body, $sig] = $parts;
        $expected = self::b64url(hash_hmac('sha256', "$header.$body", self::secret(), true));

        if (!hash_equals($expected, $sig)) return null;

        $payload = json_decode(self::b64urlDecode($body), true);
        if (!$payload || $payload['exp'] < time()) return null;

        return $payload;
    }

    public static function requireAuth(): array {
        $header = self::readAuthorizationHeader();
        $token  = '';

        if (str_starts_with($header, 'Bearer ')) {
            $token = substr($header, 7);
        }

        $payload = self::verifyToken($token);
        if (!$payload) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized — invalid or expired token']);
            exit;
        }
        return $payload;
    }

    private static function readAuthorizationHeader(): string {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION']          ?? '',
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['Authorization']               ?? '',
            // Set by the .htaccess RewriteRule when Apache strips the header
            getenv('HTTP_AUTHORIZATION') ?: '',
        ];

        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (is_array($headers)) {
                $candidates[] = $headers['Authorization'] ?? '';
                $candidates[] = $headers['authorization'] ?? '';
            }
        }

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }
        return '';
    }

    public static function requireRole(string $role): array {
        $payload = self::requireAuth();
        $roles   = ['admin' => 1, 'superadmin' => 2];
        $required = $roles[$role] ?? 99;
        $actual   = $roles[$payload['role'] ?? ''] ?? 0;

        if ($actual < $required) {
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden — insufficient privileges']);
            exit;
        }
        return $payload;
    }

    private static function b64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
    }
}
