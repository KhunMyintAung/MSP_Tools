<?php
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (str_starts_with($uri, '/api/')) {
    require_once __DIR__ . '/api.php';
    exit;
}

$publicFile = __DIR__ . '/../public' . ($uri === '/' ? '/index.html' : $uri);
if (is_file($publicFile)) {
    return false;
}

http_response_code(404);
echo 'Not Found';
