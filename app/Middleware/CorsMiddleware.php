<?php

declare(strict_types=1);

namespace Smilo\Middleware;

use flight\Engine;

class CorsMiddleware
{
    private Engine $app;

    public function before(array $params): void
    {
        $config = require __DIR__ . '/../config/config.php';
        $origin = $config['cors']['origin'];

        $this->app->response()->header('Access-Control-Allow-Origin', $origin);
        $this->app->response()->header('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $this->app->response()->header('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $this->app->response()->header('Access-Control-Allow-Credentials', 'true');
        $this->app->response()->header('Access-Control-Max-Age', '86400');

        // Handle preflight OPTIONS
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            Flight::stop();
        }
    }

    public function setApp(Engine $app): void
    {
        $this->app = $app;
    }
}
