<?php

declare(strict_types=1);

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/Status.php';
require __DIR__ . '/src/FavoriteRepository.php';
require __DIR__ . '/src/SnapshotRepository.php';
require __DIR__ . '/src/InchargeClient.php';
require __DIR__ . '/src/InChargePoller.php';
require __DIR__ . '/src/CronScheduleService.php';

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

$isCli = PHP_SAPI === 'cli';

function loadInchargeCronToken(array $config)
{
    $files = [
        (string)($config['cron']['token_file'] ?? ''),
        (string)($config['cron']['legacy_token_file'] ?? ''),
    ];

    foreach ($files as $file) {
        if ($file === '' || !is_file($file)) {
            continue;
        }

        $credentials = require $file;

        if (is_array($credentials) && !empty($credentials['token'])) {
            return (string)$credentials['token'];
        }
    }

    return null;
}

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');

    $expectedToken = loadInchargeCronToken($config);
    $providedToken = (string)($_GET['token'] ?? '');

    if (!$expectedToken || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error' => 'Invalid or missing cron token.',
            'timestamp' => date(DATE_ATOM),
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

try {
    $pdo = Database::connect($config);

    if (!Schema::tablesExist($pdo)) {
        throw new RuntimeException('Database tables are missing. Run setup.php first.');
    }

    $favorites = new FavoriteRepository($pdo);
    $snapshots = new SnapshotRepository($pdo);
    $client = new InChargeClient($config);
    $poller = new InChargePoller($favorites, $snapshots, $client);
    $cron = new CronScheduleService($pdo, $poller, $snapshots, $config);
    $results = $cron->runDueJobs();

    $payload = [
        'success' => true,
        'ran' => count($results),
        'results' => $results,
        'timestamp' => date(DATE_ATOM),
    ];
} catch (Throwable $e) {
    http_response_code(500);
    $payload = [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date(DATE_ATOM),
    ];
}

echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($isCli) {
    echo PHP_EOL;
}
