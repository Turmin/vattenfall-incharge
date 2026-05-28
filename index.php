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

/*
 * These paths may need adjustment after checking the exact Python method
 * async_bootstrap_device() and async_collect_station_points().
 */
const DEVICE_BOOTSTRAP_PATH = 'devices';
const STATION_SEARCH_PATH = 'charging-points/search';

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
    /*
     * The integration creates a local anonymous device session.
     * If this endpoint path is wrong, the response will show the HTTP error.
     */
    $deviceId = bin2hex(random_bytes(16));

    $response = requestInCharge(
        'POST',
        DEVICE_BOOTSTRAP_PATH,
        [
            'deviceId' => $deviceId,
            'platform' => 'Android',
        ],
        $deviceId,
        null
    );

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        throw new RuntimeException('Device bootstrap failed: HTTP ' . $response['status_code'] . ' - ' . $response['raw']);
    }

    /*
     * We do not know the exact field name until we see the real response.
     * Try common possibilities.
     */
    $json = $response['json'] ?? [];

    $xToken =
        $json['xToken']
        ?? $json['x_token']
        ?? $json['token']
        ?? $json['accessToken']
        ?? null;

    if (!is_string($xToken) || $xToken === '') {
        throw new RuntimeException('Device bootstrap succeeded, but no token field was found. Response: ' . $response['raw']);
    }

    return [
        'device_id' => $deviceId,
        'x_token' => $xToken,
        'bootstrap_response' => $json,
    ];
}

function searchStation(string $stationName, string $deviceId, string $xToken): array
{
    /*
     * The Home Assistant integration searches by visible charging point name,
     * for example AB1234 or XY6789.
     */
    $response = requestInCharge(
        'POST',
        STATION_SEARCH_PATH,
        [
            'searchTerm' => $stationName,
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