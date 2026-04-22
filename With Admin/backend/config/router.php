<?php

class Router {
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void {
        $this->routes[] = [
            'method'  => strtoupper($method),
            'pattern' => $this->toRegex($pattern),
            'handler' => $handler,
        ];
    }

    public function get(string $p, callable $h): void  { $this->add('GET',    $p, $h); }
    public function post(string $p, callable $h): void  { $this->add('POST',   $p, $h); }
    public function put(string $p, callable $h): void   { $this->add('PUT',    $p, $h); }
    public function patch(string $p, callable $h): void { $this->add('PATCH',  $p, $h); }
    public function delete(string $p, callable $h): void{ $this->add('DELETE', $p, $h); }

    public function dispatch(string $method, string $uri): void {
        $method = strtoupper($method);
        $path   = parse_url($uri, PHP_URL_PATH);
        $path   = rtrim($path, '/') ?: '/';

        // Strip /index.php prefix however it appears in the path
        $path = preg_replace('#^/index\.php#', '', $path);
        $path = $path ?: '/';

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method && $route['method'] !== 'ANY') continue;
            if (preg_match($route['pattern'], $path, $matches)) {
                $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
                ($route['handler'])($params);
                return;
            }
        }

        http_response_code(404);
        echo json_encode(['error' => "Route not found: $method $path"]);
    }

    private function toRegex(string $pattern): string {
        $regex = preg_replace('/\/:([a-zA-Z_]+)/', '/(?P<$1>[^/]+)', $pattern);
        return '#^' . $regex . '$#';
    }
}
