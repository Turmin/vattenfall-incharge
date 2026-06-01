<?php

declare(strict_types=1);

final class InChargeClient
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function getChargepointStatus(string $chargepointName): array
    {
        $chargepointName = FavoriteRepository::normalizeChargepointName($chargepointName);
        $session = $this->getOrCreateSession();
        $search = $this->searchStationWithSessionRefresh($chargepointName, $session);
        $station = $this->firstStationFromSearch($search, $chargepointName);

        if (!is_array($station)) {
            throw new RuntimeException('No InCharge station found for ' . $chargepointName);
        }

        $status = Status::normalize($this->stationStatus($station));
        $counts = Status::connectorCountsFromStation($station, $status);

        if (Status::bucket($status) === 'unknown' && (int)($counts['total_connectors'] ?? 0) > 0) {
            if ((int)($counts['available_connectors'] ?? 0) > 0) {
                $status = 'AVAILABLE';
            } elseif ((int)($counts['occupied_connectors'] ?? 0) > 0) {
                $status = 'OCCUPIED';
            }
        }

        if (Status::bucket($status) === 'unknown') {
            throw new RuntimeException('InCharge status is unknown for ' . $chargepointName);
        }

        return [
            'status' => $status,
            'status_bucket' => Status::bucket($status),
            'status_label' => Status::label($status),
            'available_connectors' => $counts['available_connectors'],
            'occupied_connectors' => $counts['occupied_connectors'],
            'total_connectors' => $counts['total_connectors'],
            'price_label' => $this->priceLabel($station),
            'station' => $station,
            'search' => [
                'station_name' => $chargepointName,
                'status_code' => $search['status_code'],
                'attempt' => $search['attempt'],
                'session_refreshed_after_401' => (bool)($search['session_refreshed_after_401'] ?? false),
            ],
            'raw' => [
                'station_name' => $chargepointName,
                'status_code' => $search['status_code'],
                'attempt' => $search['attempt'],
                'station' => $station,
                'response' => $search['data'],
            ],
        ];
    }

    private function searchStationWithSessionRefresh(string $stationName, array &$session): array
    {
        $result = $this->searchStation($stationName, $session['device_id'], $session['x_token']);

        if ((int)($result['status_code'] ?? 0) !== 401) {
            return $result;
        }

        $this->clearSession();
        $session = $this->bootstrapDevice();
        $this->saveSession($session['device_id'], $session['x_token']);

        $result = $this->searchStation($stationName, $session['device_id'], $session['x_token']);
        $result['session_refreshed_after_401'] = true;

        if ((int)($result['status_code'] ?? 0) === 401) {
            throw new RuntimeException('InCharge search failed after session refresh: HTTP 401');
        }

        return $result;
    }

    private function searchStation(string $stationName, string $deviceId, string $xToken): array
    {
        $response = $this->request(
            'POST',
            (string)$this->inchargeConfig('search_path'),
            $this->buildSearchBody($stationName),
            $deviceId,
            $xToken
        );

        if ((int)$response['status_code'] !== 401 && ((int)$response['status_code'] < 200 || (int)$response['status_code'] >= 300)) {
            throw new RuntimeException('InCharge search failed: HTTP ' . $response['status_code']);
        }

        if ((int)$response['status_code'] >= 200 && (int)$response['status_code'] < 300 && !is_array($response['json'])) {
            throw new RuntimeException('InCharge search returned invalid JSON.');
        }

        return [
            'station_name' => $stationName,
            'status_code' => $response['status_code'],
            'attempt' => $response['attempt'] ?? 1,
            'data' => $response['json'],
            'raw' => $response['json'] === null ? $response['raw'] : null,
        ];
    }

    private function buildSearchBody(string $stationName): array
    {
        return [
            'coordinates' => $this->inchargeConfig('search_coordinates'),
            'pagination' => [
                'pageNumber' => 0,
                'pageSize' => (int)$this->inchargeConfig('page_size'),
            ],
            'search' => $stationName,
        ];
    }

    private function getOrCreateSession(): array
    {
        $session = $this->loadSession();

        if (is_array($session)) {
            return $session;
        }

        $session = $this->bootstrapDevice();
        $this->saveSession($session['device_id'], $session['x_token']);

        return $session;
    }

    private function bootstrapDevice(): array
    {
        $deviceId = $this->uuidV4();

        $response = $this->request(
            'PUT',
            (string)$this->inchargeConfig('device_path'),
            [
                'brand' => 'nuon',
                'deviceId' => $deviceId,
                'language' => 'EN',
                'locale' => 'en_US',
                'osVersion' => '37',
                'pushToken' => 'php-' . $this->uuidV4(),
                'userAgent' => 'android',
                'versionCode' => 40505180,
                'versionName' => '4.8.7',
            ],
            $deviceId,
            null
        );

        if ((int)$response['status_code'] < 200 || (int)$response['status_code'] >= 300) {
            throw new RuntimeException('Device bootstrap failed: HTTP ' . $response['status_code']);
        }

        $json = $response['json'] ?? [];
        $xToken = $json['xToken'] ?? null;

        if (!is_string($xToken) || $xToken === '') {
            throw new RuntimeException('Device bootstrap succeeded, but no xToken was found.');
        }

        return [
            'device_id' => $deviceId,
            'x_token' => $xToken,
            'created_at' => date(DATE_ATOM),
        ];
    }

    private function request(
        string $method,
        string $path,
        array $body = null,
        $deviceId = null,
        $xToken = null
    ): array {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The PHP cURL extension is required.');
        }

        $maxAttempts = 3;
        $lastError = null;
        $lastResponse = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $url = rtrim((string)$this->inchargeConfig('base_url'), '/') . '/' . ltrim($path, '/');
            $headers = [
                'Accept: ' . $this->inchargeConfig('accept'),
                'Content-Type: application/json',
                'User-Agent: Android',
                'Ocp-Apim-Subscription-Key: ' . $this->inchargeConfig('subscription_key'),
                'Apk-SHA1: ' . $this->inchargeConfig('apk_sha1'),
                'Apk-CRC: ' . $this->inchargeConfig('apk_crc'),
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
                CURLOPT_TIMEOUT => (int)$this->inchargeConfig('timeout'),
                CURLOPT_HTTPHEADER => $headers,
            ]);

            if ($body !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }

            $responseBody = curl_exec($ch);

            if ($responseBody === false) {
                $lastError = curl_error($ch);
                curl_close($ch);

                if ($attempt < $maxAttempts) {
                    sleep(1);
                    continue;
                }

                throw new RuntimeException('InCharge cURL error: ' . $lastError);
            }

            $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $decoded = json_decode($responseBody, true);
            $lastResponse = [
                'status_code' => $statusCode,
                'raw' => $responseBody,
                'json' => is_array($decoded) ? $decoded : null,
                'attempt' => $attempt,
            ];

            if ($attempt < $maxAttempts && $this->shouldRetryResponse($statusCode, $decoded)) {
                sleep(1);
                continue;
            }

            return $lastResponse;
        }

        if (is_array($lastResponse)) {
            return $lastResponse;
        }

        throw new RuntimeException('InCharge request failed: ' . (string)$lastError);
    }

    private function shouldRetryResponse(int $statusCode, $decoded): bool
    {
        if ($statusCode === 408 || $statusCode === 429 || $statusCode >= 500) {
            return true;
        }

        return $statusCode >= 200 && $statusCode < 300 && !is_array($decoded);
    }

    private function firstStationFromSearch(array $search, string $chargepointName)
    {
        $candidates = $this->stationCandidates($search['data'] ?? null);

        if ($candidates === []) {
            return null;
        }

        foreach ($candidates as $candidate) {
            if ($this->stationMatchesChargepointName($candidate, $chargepointName)) {
                return $candidate;
            }
        }

        return $candidates[0];
    }

    private function stationCandidates($response): array
    {
        if (!is_array($response)) {
            return [];
        }

        if ($this->looksLikeStation($response)) {
            return [$response];
        }

        if (isset($response[0]) && is_array($response[0])) {
            return array_values(array_filter($response, 'is_array'));
        }

        foreach (['data', 'items', 'results', 'chargingPoints', 'charging_points', 'stations'] as $key) {
            if (isset($response[$key]) && is_array($response[$key])) {
                if (isset($response[$key][0]) && is_array($response[$key][0])) {
                    return array_values(array_filter($response[$key], 'is_array'));
                }

                $nested = $this->stationCandidates($response[$key]);

                if ($nested !== []) {
                    return $nested;
                }
            }
        }

        return [];
    }

    private function looksLikeStation(array $response): bool
    {
        if (isset($response['status']) || isset($response['state']) || isset($response['availability'])) {
            return isset($response['name'])
                || isset($response['id'])
                || isset($response['priceComponents'])
                || isset($response['connectors'])
                || isset($response['evses']);
        }

        return false;
    }

    private function stationMatchesChargepointName(array $station, string $chargepointName): bool
    {
        $chargepointName = FavoriteRepository::normalizeChargepointName($chargepointName);

        foreach (['name', 'id', 'identity', 'stationName', 'chargingPointId', 'chargePointId', 'publicId', 'externalId'] as $key) {
            if (!isset($station[$key]) || !is_scalar($station[$key])) {
                continue;
            }

            if (FavoriteRepository::normalizeChargepointName((string)$station[$key]) === $chargepointName) {
                return true;
            }
        }

        return false;
    }

    private function stationStatus($station): string
    {
        if (!is_array($station)) {
            return 'UNKNOWN';
        }

        foreach (['status', 'state', 'availability', 'availabilityStatus', 'connectorStatus'] as $key) {
            if (isset($station[$key]) && is_scalar($station[$key])) {
                return (string)$station[$key];
            }

            if (isset($station[$key]) && is_array($station[$key])) {
                foreach (['status', 'state', 'value', 'name'] as $nestedKey) {
                    if (isset($station[$key][$nestedKey]) && is_scalar($station[$key][$nestedKey])) {
                        return (string)$station[$key][$nestedKey];
                    }
                }
            }
        }

        return 'UNKNOWN';
    }

    private function priceLabel($station)
    {
        if (!is_array($station)) {
            return null;
        }

        $priceComponents = $station['priceComponents'] ?? null;

        if (!is_array($priceComponents)) {
            return null;
        }

        $currency = (string)($priceComponents['currency'] ?? 'EUR');
        $components = $priceComponents['components'] ?? [];
        $parts = [];

        foreach ($components as $component) {
            if (!is_array($component)) {
                continue;
            }

            $type = strtoupper((string)($component['type'] ?? ''));
            $elements = $component['elements'] ?? [];

            if (!is_array($elements) || !isset($elements[0]) || !is_array($elements[0])) {
                continue;
            }

            if (!isset($elements[0]['price']) || !is_numeric($elements[0]['price'])) {
                continue;
            }

            $price = number_format((float)$elements[0]['price'], 4, ',', '.');

            if ($type === 'KWH') {
                $parts[] = $price . ' ' . $currency . '/kWh';
            } elseif ($type === 'FIXED') {
                $parts[] = $price . ' ' . $currency . ' vast';
            }
        }

        return $parts !== [] ? implode(' + ', $parts) : null;
    }

    private function loadSession()
    {
        $file = $this->sessionCacheFile();

        if (!is_file($file)) {
            return null;
        }

        $session = json_decode((string)file_get_contents($file), true);

        if (!is_array($session) || empty($session['device_id']) || empty($session['x_token'])) {
            return null;
        }

        return $session;
    }

    private function saveSession(string $deviceId, string $xToken)
    {
        $file = $this->sessionCacheFile();
        $dir = dirname($file);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create cache directory: ' . $dir);
        }

        file_put_contents(
            $file,
            json_encode([
                'device_id' => $deviceId,
                'x_token' => $xToken,
                'created_at' => date(DATE_ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function clearSession()
    {
        $file = $this->sessionCacheFile();

        if (is_file($file)) {
            unlink($file);
        }
    }

    private function sessionCacheFile(): string
    {
        return (string)$this->inchargeConfig('session_cache_file');
    }

    private function inchargeConfig(string $key)
    {
        if (!array_key_exists($key, $this->config['incharge'] ?? [])) {
            throw new RuntimeException('Missing InCharge config: ' . $key);
        }

        return $this->config['incharge'][$key];
    }

    private function uuidV4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
