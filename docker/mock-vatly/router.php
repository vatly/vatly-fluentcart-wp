<?php
/**
 * php -S router for the mock Vatly API.
 */

declare(strict_types=1);

require __DIR__ . '/server.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

(new MockVatly())->handle($_SERVER['REQUEST_METHOD'] ?? 'GET', $path);
