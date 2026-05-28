<?php

declare(strict_types=1);

/*
 * Minimal Vattenfall InCharge public station test.
 * No database. No login. No hard-coded personal credentials.
 */

header('Content-Type: application/json; charset=utf-8');

const MOBILE_BASE_URL = 'https://businessspecificapimanglobal.azure-api.net/emobility/';
const MOBILE_APIM_KEY = '12c7d772faa84b92a8f13a22d7bd8638';
const APP_ACCEPT = 'application/vnd.emobilitymobile.v16+json';
const MOBILE_APP_SHA1 = 'aa08ea4e0a721e5a5f8d81f1e7c7fbd87f8d3a5f';
const MOBILE_APP_CRC = '0';

const DEVICE_BOOTSTRAP_PATH = 'device';
const STATION_SEARCH_PATH = 'api/charging-points/charging_point/search';
const NEIGHBOURS_PATH_TEMPLATE = 'api/charging-points/charging_point/%s/neighbours';
const CHARGING_POINTS_PATH = 'api/charging-points/charging_points';

function jsonOut(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function requestInCharge(
    string $method,
    string $path,
    ?array $body = null,
    ?string $deviceId = null,
    ?string $xToken = null
): array {
    $url = rtrim(MOBILE_BASE_URL, '/') . '/' . ltrim($path, '/');

    $headers = [
        'Accept: ' . APP_ACCEPT,
        'Content-Type: application/json',
        'User-Agent: Android',
        'Ocp-Apim-Subscription-Key: ' . MOBILE_APIM_KEY,
        'Apk-SHA1: ' . MOBILE_APP_SHA1,
        'Apk-CRC: ' . MOBILE_APP_CRC,
    ];

    if ($deviceId !== null) {
        $headers[] = 'Device-Id: ' . $deviceId;
    }

    if ($xToken !== null) {
        $headers[] = 'X-Token: ' . $xToken;
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $responseBody = curl_exec($ch);

    if ($responseBody === false) {
        $error = curl_error($ch);
        curl_close($ch);

        throw new RuntimeException('cURL error: ' . $error);
    }

    $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($responseBody, true);

    return [
        'status_code' => $statusCode,
        'url' => $url,
        'raw' => $responseBody,
        'json' => is_array($decoded) ? $decoded : null,
    ];
}

function bootstrapDevice(): array
{
    $deviceId = generateUuidV4();

    $response = requestInCharge(
        'PUT',
        DEVICE_BOOTSTRAP_PATH,
        [
            'brand' => 'nuon',
            'deviceId' => $deviceId,
            'language' => 'EN',
            'locale' => 'en_US',
            'osVersion' => '37',
            'pushToken' => 'php-' . generateUuidV4(),
            'userAgent' => 'android',
            'versionCode' => 40505180,
            'versionName' => '4.8.7',
        ],
        $deviceId,
        null
    );

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        throw new RuntimeException(
            'Device bootstrap failed: HTTP ' . $response['status_code'] . ' - ' . $response['raw']
        );
    }

    $xToken = $response['json']['xToken'] ?? null;

    if (!is_string($xToken) || $xToken === '') {
        throw new RuntimeException('No xToken found. Response: ' . $response['raw']);
    }

    return [
        'device_id' => $deviceId,
        'x_token' => $xToken,
        'bootstrap_response' => $response['json'],
    ];
}

function generateUuidV4(): string
{
    $data = random_bytes(16);

    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function searchStation(string $stationName, string $deviceId, string $xToken): array
{
    $response = requestInCharge(
        'POST',
        STATION_SEARCH_PATH,
        [
            'coordinates' => [
                'latitude' => 37.4219983,
                'longitude' => -122.084,
            ],
            'pagination' => [
                'pageNumber' => 0,
                'pageSize' => 40,
            ],
            'search' => $stationName,
        ],
        $deviceId,
        $xToken
    );

    return [
        'station_name' => $stationName,
        'status_code' => $response['status_code'],
        'url' => $response['url'],
        'data' => $response['json'],
        'raw' => $response['json'] === null ? $response['raw'] : null,
    ];
}

try {
    $favoritesPath = __DIR__ . '/favo.json';

    if (!is_file($favoritesPath)) {
        jsonOut(['error' => 'favo.json not found'], 500);
    }

    $favorites = json_decode((string) file_get_contents($favoritesPath), true);

    if (!is_array($favorites)) {
        jsonOut(['error' => 'favo.json is invalid JSON'], 500);
    }

    $session = bootstrapDevice();

    $results = [];

    foreach ($favorites as $favorite) {
        $stationName = trim((string) ($favorite['name'] ?? ''));

        if ($stationName === '') {
            continue;
        }

        $results[] = [
            'favorite' => $favorite,
            'incharge' => searchStation(
                $stationName,
                $session['device_id'],
                $session['x_token']
            ),
        ];
    }

    jsonOut([
        'ok' => true,
        'checked_at' => date(DATE_ATOM),
        'session' => [
            'device_id' => $session['device_id'],
            /*
             * Deliberately do not expose the full token in output.
             */
            'x_token_prefix' => substr($session['x_token'], 0, 8),
        ],
        'results' => $results,
    ]);
} catch (Throwable $e) {
    jsonOut([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}