<?php

declare(strict_types=1);

require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/Schema.php';
require __DIR__ . '/../src/Status.php';
require __DIR__ . '/../src/FavoriteRepository.php';
require __DIR__ . '/../src/SnapshotRepository.php';
require __DIR__ . '/../src/InchargeClient.php';
require __DIR__ . '/../src/InChargePoller.php';

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

$pdo = Database::connect($config);

if (!Schema::tablesExist($pdo)) {
    throw new RuntimeException('Database tables are missing. Use admin setup first.');
}

$favorites = new FavoriteRepository($pdo);
$snapshots = new SnapshotRepository($pdo);
$client = new InChargeClient($config);
$poller = new InChargePoller($favorites, $snapshots, $client);

$result = $poller->pollActiveFavorites();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
