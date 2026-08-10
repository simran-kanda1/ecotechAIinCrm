<?php

declare(strict_types=1);

// Router for PHP built-in server: php -S localhost:8080 router.php

$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$public = __DIR__ . DIRECTORY_SEPARATOR . 'public';
$file = $public . str_replace('/', DIRECTORY_SEPARATOR, $uri);

// API routes — must run before static file handler (never readfile .php)
if (str_starts_with($uri, '/api/')) {
    $apiFile = $public . str_replace('/', DIRECTORY_SEPARATOR, $uri);
    if (is_file($apiFile)) {
        require $apiFile;
        return true;
    }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Not found']);
    return true;
}

// Static assets only (never serve .php as raw text)
$staticTypes = [
    'css' => 'text/css; charset=utf-8',
    'js' => 'application/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'html' => 'text/html; charset=utf-8',
    'htm' => 'text/html; charset=utf-8',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
    'woff2' => 'font/woff2',
];

if ($uri !== '/' && is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (isset($staticTypes[$ext])) {
        header('Content-Type: ' . $staticTypes[$ext]);
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        return true;
    }
}

require $public . DIRECTORY_SEPARATOR . 'index.php';
