<?php

declare(strict_types=1);

/**
 * Smilo API — FlightPHP v3 front controller
 */

// Load .env
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

require __DIR__ . '/../vendor/autoload.php';

use Smilo\Model\Database;
use Smilo\Controller\ProductController;
use Smilo\Controller\SellerController;
use Smilo\Controller\AuthController;
use Smilo\Controller\MessageController;
use Smilo\Controller\ReviewController;

$config = require __DIR__ . '/../app/config/config.php';
$db = Database::connect($config['db']);
Database::migrate($db);
Database::seed($db);

// --- CORS preflight (OPTIONS only) ---
Flight::route('OPTIONS /*', function () {
    $config = require __DIR__ . '/../app/config/config.php';
    $origin = $config['cors']['origin'];
    header("Access-Control-Allow-Origin: $origin");
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    header('Access-Control-Allow-Credentials: true');
    http_response_code(204);
    Flight::stop();
});

// --- Health ---
Flight::route('GET /api/health', function () {
    \Flight::json([
        'status'  => 'ok',
        'service' => 'Smilo API',
        'version' => '0.2.0',
    ]);
});

// --- Auth ---
Flight::route('POST /api/auth/register', function () use ($db) {
    (new AuthController($db))->register();
});
Flight::route('POST /api/auth/login', function () use ($db) {
    (new AuthController($db))->login();
});
Flight::route('GET /api/auth/me', function () use ($db) {
    (new AuthController($db))->me();
});

// --- Products ---
Flight::route('GET /api/products', function () use ($db) {
    (new ProductController(Flight::app(), $db))->index();
});
Flight::route('GET /api/products/@id', function (string $id) use ($db) {
    (new ProductController(Flight::app(), $db))->show($id);
});
Flight::route('GET /api/categories', function () use ($db) {
    (new ProductController(Flight::app(), $db))->categories();
});

// --- Reviews (nested under products) ---
Flight::route('GET /api/products/@id/reviews', function (string $id) use ($db) {
    (new ReviewController($db))->index($id);
});
Flight::route('POST /api/products/@id/reviews', function (string $id) use ($db) {
    (new ReviewController($db))->create($id);
});
Flight::route('POST /api/reviews/@id/helpful', function (string $id) use ($db) {
    (new ReviewController($db))->helpful($id);
});

// --- Sellers ---
Flight::route('GET /api/sellers', function () use ($db) {
    (new SellerController(Flight::app(), $db))->index();
});
Flight::route('GET /api/sellers/@id', function (string $id) use ($db) {
    (new SellerController(Flight::app(), $db))->show($id);
});

// --- Messages (contact seller) ---
Flight::route('POST /api/messages', function () use ($db) {
    (new MessageController($db))->send();
});
Flight::route('GET /api/messages', function () use ($db) {
    (new MessageController($db))->index();
});
Flight::route('PUT /api/messages/@id/read', function (string $id) use ($db) {
    (new MessageController($db))->markRead($id);
});

// --- Handlers ---
Flight::map('notFound', function () {
    \Flight::json(['error' => 'Not found'], 404);
});

Flight::map('error', function (Throwable $e) {
    $debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN);
    \Flight::json([
        'error'   => $debug ? $e->getMessage() : 'Internal server error',
        'code'    => $e->getCode(),
    ], 500);
});

Flight::start();
