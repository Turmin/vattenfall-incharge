<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Status.php';
require __DIR__ . '/../src/ApiResponse.php';
require __DIR__ . '/../src/FavoriteRepository.php';
require __DIR__ . '/../src/SnapshotRepository.php';

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

try {
    $pdo = Database::connect($config);

    if (!Schema::tablesExist($pdo)) {
        ApiResponse::error('Database tables are missing. Use admin setup first.', 503);
    }

    $favoritesRepo = new FavoriteRepository($pdo);
    $snapshotRepo = new SnapshotRepository($pdo);

    $favorites = $favoritesRepo->allActive();
    $snapshots = $snapshotRepo->latestForFavorites();
    $snapshotByFavoriteId = [];

    foreach ($snapshots as $snapshot) {
        $snapshotByFavoriteId[(int)$snapshot['favorite_id']] = $snapshot;
    }

    $chargepoints = [];

    foreach ($favorites as $favorite) {
        $favoriteId = (int)$favorite['id'];
        $latest = $snapshotByFavoriteId[$favoriteId] ?? null;

        if (is_array($latest)) {
            $latest['status'] = Status::normalize((string)$latest['status']);
            $latest['status_label'] = Status::label((string)$latest['status']);
            $latest['status_bucket'] = Status::bucket((string)$latest['status']);
            $latest['measured_at_iso'] = date(DATE_ATOM, strtotime((string)$latest['measured_at']));
            $latest['status_since_iso'] = date(DATE_ATOM, strtotime((string)$latest['status_since']));
            unset($latest['raw_json']);
        }

        $chargepoints[] = [
            'id' => $favoriteId,
            'chargepoint_name' => $favorite['chargepoint_name'],
            'display_name' => $favorite['display_name'],
            'latest' => $latest,
        ];
    }

    ApiResponse::json([
        'success' => true,
        'checked_at' => date(DATE_ATOM),
        'chargepoints' => $chargepoints,
    ]);
} catch (Throwable $e) {
    ApiResponse::error($e->getMessage(), 500);
}
