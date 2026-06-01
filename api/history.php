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
        ApiResponse::error('Database tables are missing. Run setup.php first.', 503);
    }

    $favoritesRepo = new FavoriteRepository($pdo);
    $favorite = $favoritesRepo->find((int)$favoriteId);

    if (!$favorite) {
        ApiResponse::error('Favorite not found.', 404);
    }

    $snapshotRepo = new SnapshotRepository($pdo);
    $measurements = $snapshotRepo->history((int)$favoriteId, $period['from_sql'], $period['to_sql']);

    foreach ($measurements as &$measurement) {
        $measurement['status'] = Status::normalize((string)$measurement['status']);
        $measurement['status_label'] = Status::label((string)$measurement['status']);
        $measurement['status_bucket'] = Status::bucket((string)$measurement['status']);
        $measurement['measured_at_iso'] = date(DATE_ATOM, strtotime((string)$measurement['measured_at']));
    }
    unset($measurement);

    ApiResponse::json([
        'success' => true,
        'favorite' => $favorite,
        'period' => [
            'key' => $period['key'],
            'from' => $period['from_iso'],
            'to' => $period['to_iso'],
            'seconds' => $period['seconds'],
        ],
        'measurements' => $measurements,
        'timestamp' => date(DATE_ATOM),
    ]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error($e->getMessage(), 422);
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 500);
}
