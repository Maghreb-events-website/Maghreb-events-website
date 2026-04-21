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
        // X-Auth-Token is a custom header Apache NEVER strips — it is the
        // primary auth mechanism on shared hosting where Authorization is blocked.
        // The frontend sends the JWT as "Bearer <token>" in both headers.
        $candidates = [
            $_SERVER['HTTP_X_AUTH_TOKEN']                    ?? '',
            $_SERVER['HTTP_AUTHORIZATION']                   ?? '',
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION']          ?? '',
            $_SERVER['REDIRECT_REDIRECT_HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['Authorization']                        ?? '',
            getenv('HTTP_AUTHORIZATION')                     ?: '',
        ];

        if (function_exists('getallheaders')) {
            $h = getallheaders() ?: [];
            $candidates[] = $h['X-Auth-Token']  ?? '';
            $candidates[] = $h['x-auth-token']  ?? '';
            $candidates[] = $h['Authorization'] ?? '';
            $candidates[] = $h['authorization'] ?? '';
        }

        if (function_exists('apache_request_headers')) {
            $h = apache_request_headers() ?: [];
            $candidates[] = $h['X-Auth-Token']  ?? '';
            $candidates[] = $h['Authorization'] ?? '';
            $candidates[] = $h['authorization'] ?? '';
        }

        foreach ($candidates as $value) {
            $value = trim((string)$value);
            if ($value !== '') return $value;
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
