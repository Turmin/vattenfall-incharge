<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Status.php';
require __DIR__ . '/../src/Period.php';
require __DIR__ . '/../src/ApiResponse.php';
require __DIR__ . '/../src/FavoriteRepository.php';
require __DIR__ . '/../src/SnapshotRepository.php';

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

try {
    $favoriteId = filter_input(INPUT_GET, 'favorite_id', FILTER_VALIDATE_INT);

    if (!$favoriteId) {
        ApiResponse::error('Missing or invalid favorite_id.', 422);
    }

    $period = Period::fromRequest($_GET, '24h');
    $pdo = Database::connect($config);

    if (!Schema::tablesExist($pdo)) {
        ApiResponse::error('Database tables are missing. Use admin setup first.', 503);
    }

    $favoritesRepo = new FavoriteRepository($pdo);
    $favorite = $favoritesRepo->find((int)$favoriteId);

    if (!$favorite) {
        ApiResponse::error('Favorite not found.', 404);
    }

    $snapshotRepo = new SnapshotRepository($pdo);
    $availability = $snapshotRepo->availability((int)$favoriteId, $period['from'], $period['to']);
    $latest = $snapshotRepo->latestForFavorite((int)$favoriteId);

    if ($latest) {
        $latest = [
            'id' => (int)$latest['id'],
            'measured_at' => date(DATE_ATOM, strtotime((string)$latest['measured_at'])),
            'status' => Status::normalize((string)$latest['status']),
            'status_label' => Status::label((string)$latest['status']),
            'status_bucket' => Status::bucket((string)$latest['status']),
            'status_since' => date(DATE_ATOM, strtotime((string)$latest['status_since'])),
            'seconds_in_current_status' => (int)$latest['seconds_in_current_status'],
            'available_connectors' => $latest['available_connectors'],
            'occupied_connectors' => $latest['occupied_connectors'],
            'total_connectors' => $latest['total_connectors'],
        ];
    }

    ApiResponse::json([
        'success' => true,
        'favorite' => $favorite,
        'period' => [
            'key' => $period['key'],
            'from' => $period['from_iso'],
            'to' => $period['to_iso'],
            'seconds' => $period['seconds'],
        ],
        'current' => $latest,
        'summary' => $availability['summary'],
        'segments' => $availability['segments'],
        'measurements' => $availability['measurements'],
        'timestamp' => date(DATE_ATOM),
    ]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error($e->getMessage(), 422);
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 500);
}
