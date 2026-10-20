<?php

// Router for the PHP built-in server used by RealTransportTest. It never
// leaves the machine: the test points a made-up hostname at 127.0.0.1.

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

switch ($path) {
    case '/article':
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><title>Fixture</title></head><body><p>Served to '
            .htmlspecialchars($_SERVER['HTTP_HOST'] ?? '').'</p></body></html>';
        break;

    case '/redirect':
        header('Location: /article', true, 302);
        break;

    case '/declared-large':
        header('Content-Type: text/html');
        header('Content-Length: 50000');
        echo str_repeat('a', 50000);
        break;

    case '/streamed-large':
        // No Content-Length: the cap has to trip while the body arrives.
        header('Content-Type: text/html');
        for ($i = 0; $i < 50; $i++) {
            echo str_repeat('b', 1000);
            flush();
        }
        break;

    default:
        http_response_code(404);
}
