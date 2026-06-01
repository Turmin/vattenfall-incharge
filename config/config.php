<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$webRoot = dirname($rootDir);
$credentialsFile = $webRoot . '/knmi.database.credentials.php';
$dbCredentials = [];

if (is_file($credentialsFile)) {
    $loadedCredentials = require $credentialsFile;
    if (is_array($loadedCredentials)) {
        $dbCredentials = $loadedCredentials;
    }
}

$dbHost = (string)($dbCredentials['host'] ?? 'localhost');
$dbName = (string)($dbCredentials['db_name'] ?? 'incharge_monitor');
$dbUser = (string)($dbCredentials['username'] ?? '');
$dbPassword = (string)($dbCredentials['password'] ?? '');

return [
    'timezone' => 'Europe/Amsterdam',

    'paths' => [
        'root' => $rootDir,
        'cache_dir' => $rootDir . '/var',
        'favorites_json' => $rootDir . '/favo.json',
        'schema_sql' => $rootDir . '/schema.sql',
    ],

    'db' => [
        'credentials_file' => $credentialsFile,
        'dsn' => 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4',
        'host' => $dbHost,
        'name' => $dbName,
        'user' => $dbUser,
        'password' => $dbPassword,
    ],

    'admin' => [
        'credentials_file' => $webRoot . '/incharge.admin.credentials.php',
        'legacy_credentials_file' => $rootDir . '/admin/admin_credentials.php',
    ],

    'cron' => [
        'token_file' => $webRoot . '/incharge.cron.credentials.php',
        'legacy_token_file' => $webRoot . '/knmi.cron.credentials.php',
        'retention_days' => 90,
    ],

    'api_token' => '',

    'incharge' => [
        'base_url' => 'https://businessspecificapimanglobal.azure-api.net/emobility/',
        'subscription_key' => '12c7d772faa84b92a8f13a22d7bd8638',
        'accept' => 'application/vnd.emobilitymobile.v16+json',
        'apk_sha1' => 'aa08ea4e0a721e5a5f8d81f1e7c7fbd87f8d3a5f',
        'apk_crc' => '0',
        'timeout' => 30,
        'device_path' => 'device',
        'search_path' => 'api/charging-points/charging_point/search',
        'search_coordinates' => [
            'latitude' => 52.132633,
            'longitude' => 5.291266,
        ],
        'page_size' => 40,
        'session_cache_file' => $rootDir . '/var/incharge-session.json',
    ],
];
