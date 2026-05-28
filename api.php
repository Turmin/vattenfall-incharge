<?php

declare(strict_types=1);

/*
 * Minimal Vattenfall InCharge public station API.
 * No database. No personal InCharge login.
 */

header('Content-Type: application/json; charset=utf-8');

const MOBILE_BASE_URL = 'https://businessspecificapimanglobal.azure-api.net/emobility/';
const MOBILE_APIM_KEY = '12c7d772faa84b92a8f13a22d7bd8638';
const APP_ACCEPT = 'application/vnd.emobilitymobile.v16+json';
const MOBILE_APP_SHA1 = 'aa08ea4e0a721e5a5f8d81f1e7c7fbd87f8d3a5f';
const MOBILE_APP_CRC = '0';

const DEVICE_BOOTSTRAP_PATH = 'device';
const STATION_SEARCH_PATH = 'api/charging-points/charging_point/search';

function jsonOut(array $data, int $statusCode = 200)
{
    http_response_code($statusCode);

    echo json_encode(
        $data,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    exit;
}

function generateUuidV4(): string
{
    $data = random_bytes(16);

    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function getSessionCacheFile(): string
{
    return __DIR__ . '/session-cache.json';
}

function loadInChargeSession(): ?array
{
    $file = getSessionCacheFile();

    if (!is_file($file)) {
        return null;
    }

    $data = json_decode((string) file_get_contents($file), true);

    if (!is_array($data)) {
        return null;
    }

    if (empty($data['device_id']) || empty($data['x_token'])) {
        return null;
    }

    return $data;
}

function saveInChargeSession(string $deviceId, string $xToken): void
{
    file_put_contents(
        getSessionCacheFile(),
        json_encode(
            [
                'device_id' => $deviceId,
                'x_token' => $xToken,
                'created_at' => date(DATE_ATOM),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
        LOCK_EX
    );
}

function clearInChargeSession(): void
{
    $file = getSessionCacheFile();

    if (is_file($file)) {
        unlink($file);
    }
}

function requestInCharge(
    string $method,
    string $path,
    ?array $body = null,
    ?string $deviceId = null,
    ?string $xToken = null,
    bool $retryUnauthorized = false
): array {
    $attempts = $retryUnauthorized ? 3 : 1;
    $lastResponse = null;

    for ($attempt = 1; $attempt <= $attempts; $attempt++) {
        $url = rtrim(MOBILE_BASE_URL, '/') . '/' . ltrim($path, '/');

        $headers = [
            'Accept: ' . APP_ACCEPT,
            'Content-Type: application/json',
            'User-Agent: Android',
            'Ocp-Apim-Subscription-Key: ' . MOBILE_APIM_KEY,
            'Apk-SHA1: ' . MOBILE_APP_SHA1,
            'Apk-CRC: ' . MOBILE_APP_CRC,
        ];

        if ($deviceId !== null && $deviceId !== '') {
            $headers[] = 'Device-Id: ' . $deviceId;
        }

        if ($xToken !== null && $xToken !== '') {
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
            curl_setopt(
                $ch,
                CURLOPT_POSTFIELDS,
                json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
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

        $lastResponse = [
            'status_code' => $statusCode,
            'url' => $url,
            'raw' => $responseBody,
            'json' => is_array($decoded) ? $decoded : null,
            'attempt' => $attempt,
        ];

        if ($statusCode !== 401) {
            return $lastResponse;
        }

        if ($attempt < $attempts) {
            sleep(1);
        }
    }

    return $lastResponse;
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
        null,
        false
    );

    if ($response['status_code'] < 200 || $response['status_code'] >= 300) {
        throw new RuntimeException(
            'Device bootstrap failed: HTTP ' . $response['status_code'] . ' - ' . $response['raw']
        );
    }

    $json = $response['json'] ?? [];
    $xToken = $json['xToken'] ?? null;

    if (!is_string($xToken) || $xToken === '') {
        throw new RuntimeException('Device bootstrap succeeded, but no xToken was found. Response: ' . $response['raw']);
    }

    return [
        'device_id' => $deviceId,
        'x_token' => $xToken,
        'bootstrap_response' => $json,
    ];
}

function getOrCreateInChargeSession(): array
{
    $session = loadInChargeSession();

    if (is_array($session)) {
        return $session;
    }

    $session = bootstrapDevice();

    saveInChargeSession($session['device_id'], $session['x_token']);

    return $session;
}

function buildSearchBody(string $stationName): array
{
    return [
        'coordinates' => [
            'latitude' => 52.132633,
            'longitude' => 5.291266,
        ],
        'pagination' => [
            'pageNumber' => 0,
            'pageSize' => 40,
        ],
        'search' => $stationName,
    ];
}

function searchStation(string $stationName, string $deviceId, string $xToken): array
{
    $response = requestInCharge(
        'POST',
        STATION_SEARCH_PATH,
        buildSearchBody($stationName),
        $deviceId,
        $xToken,
        true
    );

    return [
        'station_name' => $stationName,
        'status_code' => $response['status_code'],
        'attempt' => $response['attempt'] ?? 1,
        'url' => $response['url'],
        'data' => $response['json'],
        'raw' => $response['json'] === null ? $response['raw'] : null,
    ];
}

function searchStationWithSessionRefresh(string $stationName, array &$session): array
{
    $result = searchStation(
        $stationName,
        $session['device_id'],
        $session['x_token']
    );

    if (($result['status_code'] ?? null) !== 401) {
        return $result;
    }

    clearInChargeSession();

    $session = bootstrapDevice();
    saveInChargeSession($session['device_id'], $session['x_token']);

    $result = searchStation(
        $stationName,
        $session['device_id'],
        $session['x_token']
    );

    $result['session_refreshed_after_401'] = true;

    return $result;
}

function getCurrentStatusFromResult(array $result): string
{
    $data = $result['incharge']['data'] ?? null;

    if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
        return 'UNKNOWN';
    }

    $status = $data[0]['status'] ?? 'UNKNOWN';

    return is_string($status) ? strtoupper($status) : 'UNKNOWN';
}

function updateStatusCache(array $results): array
{
    $cacheFile = __DIR__ . '/status-cache.json';
    $now = date(DATE_ATOM);

    $cache = [];

    if (is_file($cacheFile)) {
        $decoded = json_decode((string) file_get_contents($cacheFile), true);

        if (is_array($decoded)) {
            $cache = $decoded;
        }
    }

    $enrichedResults = [];

    foreach ($results as $result) {
        $favorite = $result['favorite'] ?? [];
        $stationName = (string) ($favorite['name'] ?? '');

        if ($stationName === '') {
            $enrichedResults[] = $result;
            continue;
        }

        $currentStatus = getCurrentStatusFromResult($result);
        $previous = $cache[$stationName] ?? null;

        if (
            is_array($previous)
            && isset($previous['status'], $previous['status_since'])
            && $previous['status'] === $currentStatus
        ) {
            $statusSince = $previous['status_since'];
        } else {
            $statusSince = $now;
        }

        $secondsInCurrentStatus = strtotime($now) - strtotime($statusSince);

        if ($secondsInCurrentStatus < 0) {
            $secondsInCurrentStatus = 0;
        }

        $cache[$stationName] = [
            'status' => $currentStatus,
            'status_since' => $statusSince,
            'last_seen' => $now,
        ];

        $result['local_tracking'] = [
            'status' => $currentStatus,
            'status_since' => $statusSince,
            'last_seen' => $now,
            'seconds_in_current_status' => $secondsInCurrentStatus,
            'minutes_in_current_status' => round($secondsInCurrentStatus / 60, 1),
        ];

        $enrichedResults[] = $result;
    }

    file_put_contents(
        $cacheFile,
        json_encode($cache, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        LOCK_EX
    );

    return $enrichedResults;
}

try {
    $favoritesPath = __DIR__ . '/favorieten.json';

    if (!is_file($favoritesPath)) {
        jsonOut([
            'ok' => false,
            'error' => 'favorieten.json not found',
        ], 500);
    }

    $favorites = json_decode((string) file_get_contents($favoritesPath), true);

    if (!is_array($favorites)) {
        jsonOut([
            'ok' => false,
            'error' => 'favorieten.json is invalid JSON',
        ], 500);
    }

    $session = getOrCreateInChargeSession();
    $results = [];

    foreach ($favorites as $favorite) {
        $stationName = trim((string) ($favorite['name'] ?? ''));

        if ($stationName === '') {
            continue;
        }

        $results[] = [
            'favorite' => $favorite,
            'incharge' => searchStationWithSessionRefresh($stationName, $session),
        ];
    }

    $results = updateStatusCache($results);

    jsonOut([
        'ok' => true,
        'checked_at' => date(DATE_ATOM),
        'session' => [
            'device_id' => $session['device_id'],
            'x_token_prefix' => substr($session['x_token'], 0, 8),
            'session_cache_file_exists' => is_file(getSessionCacheFile()),
        ],
        'results' => $results,
    ]);
} catch (Throwable $e) {
    jsonOut([
        'ok' => false,
        'error' => $e->getMessage(),
    ], 500);
}