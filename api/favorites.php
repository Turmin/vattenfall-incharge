<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/ApiResponse.php';
require __DIR__ . '/../src/FavoriteRepository.php';

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

try {
    $pdo = Database::connect($config);

    if (!Schema::tablesExist($pdo)) {
        ApiResponse::error('Database tables are missing. Use admin setup first.', 503);
    }

    $favorites = new FavoriteRepository($pdo);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($method === 'GET') {
        $includeInactive = (string)($_GET['include_inactive'] ?? '') === '1';
        ApiResponse::json([
            'success' => true,
            'favorites' => $includeInactive ? $favorites->all() : $favorites->allActive(),
            'timestamp' => date(DATE_ATOM),
        ]);
    }

    ApiResponse::requireToken($config);

    if ($method === 'POST') {
        $input = json_decode((string)file_get_contents('php://input'), true);

        if (!is_array($input)) {
            ApiResponse::error('Invalid JSON.', 400);
        }

        $chargepointName = (string)($input['chargepoint_name'] ?? '');
        $displayName = (string)($input['display_name'] ?? $chargepointName);
        $sortOrder = (int)($input['sort_order'] ?? 0);

        if (!FavoriteRepository::isValidChargepointName($chargepointName)) {
            ApiResponse::error('Invalid chargepoint_name.', 422);
        }

        $id = $favorites->create($chargepointName, $displayName, $sortOrder);

        ApiResponse::json([
            'success' => true,
            'id' => $id,
            'message' => 'Favorite saved.',
            'timestamp' => date(DATE_ATOM),
        ], 201);
    }

    if ($method === 'DELETE') {
        $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

        if (!$id) {
            ApiResponse::error('Missing or invalid id.', 422);
        }

        $favorites->disable((int)$id);

        ApiResponse::json([
            'success' => true,
            'message' => 'Favorite disabled.',
            'timestamp' => date(DATE_ATOM),
        ]);
    }

    ApiResponse::error('Method not allowed.', 405);
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 500);
}
