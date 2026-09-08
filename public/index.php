<?php

declare(strict_types=1);

/**
 * Smilo API — FlightPHP v3 front controller
 */

// Load .env (simple key=value parser, no dependency needed)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (explode("\n", file_get_contents($envFile)) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            putenv(trim($parts[0]) . '=' . trim($parts[1]));
        }
    }
}

// Autoload
require __DIR__ . '/../vendor/autoload.php';

use Smilo\Model\Database;
use Smilo\Controller\ProductController;
use Smilo\Controller\SellerController;

// Load config
$config = require __DIR__ . '/../app/config/config.php';

// Connect DB + migrate + seed
$db = Database::connect($config['db']);
Database::migrate($db);
Database::seed($db);

// --- API routes (flat, with /api prefix) ---

// Health check
Flight::route('GET /api/health', function () {
    Flight::json([
        'status'  => 'ok',
        'service' => 'Smilo API',
        'version' => '0.1.0',
    ]);
});

// Products
Flight::route('GET /api/products', function () use ($db) {
    $controller = new ProductController(Flight::app(), $db);
    $controller->index();
});

Flight::route('GET /api/products/@id', function (string $id) use ($db) {
    $controller = new ProductController(Flight::app(), $db);
    $controller->show($id);
});

Flight::route('GET /api/categories', function () use ($db) {
    $controller = new ProductController(Flight::app(), $db);
    $controller->categories();
});

// Sellers
Flight::route('GET /api/sellers', function () use ($db) {
    $controller = new SellerController(Flight::app(), $db);
    $controller->index();
});

Flight::route('GET /api/sellers/@id', function (string $id) use ($db) {
    $controller = new SellerController(Flight::app(), $db);
    $controller->show($id);
});

// 404 handler
Flight::map('notFound', function () {
    Flight::json(['error' => 'Not found'], 404);
});

// Error handler
Flight::map('error', function (Throwable $e) {
    $debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    Flight::json([
        'error'   => $debug ? $e->getMessage() : 'Internal server error',
        'code'    => $e->getCode(),
    ], 500);
});

Flight::start();
