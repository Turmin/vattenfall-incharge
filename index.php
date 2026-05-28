<?php

declare(strict_types=1);

/*
 * Simple InCharge dashboard.
 * Reads local api.php and renders favorite charging points.
 */

function fetchApiData(): array
{
    $apiPath = __DIR__ . '/api.php';

    if (!is_file($apiPath)) {
        return [
            'ok' => false,
            'error' => 'api.php niet gevonden.',
        ];
    }

    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $directory = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');

    $url = $scheme . '://' . $host . $directory . '/api.php';

    $json = @file_get_contents($url);

    if ($json === false) {
        return [
            'ok' => false,
            'error' => 'Kon api.php niet ophalen via ' . $url,
        ];
    }

    $data = json_decode($json, true);

    if (!is_array($data)) {
        return [
            'ok' => false,
            'error' => 'api.php gaf geen geldige JSON terug.',
            'raw' => $json,
        ];
    }

    return $data;
}

function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function formatStatus(string $status): string
{
    switch (strtoupper($status)) {
        case 'AVAILABLE':
            return 'Vrij';

        case 'OCCUPIED':
            return 'Bezet';

        case 'CHARGING':
            return 'Aan het laden';

        case 'OUT_OF_ORDER':
        case 'OUTOFORDER':
        case 'FAULTED':
            return 'Buiten gebruik';

        case 'UNKNOWN':
            return 'Onbekend';

        default:
            return ucfirst(strtolower($status));
    }
}

function statusClass(string $status): string
{
    switch (strtoupper($status)) {
        case 'AVAILABLE':
            return 'status-available';

        case 'OCCUPIED':
        case 'CHARGING':
            return 'status-occupied';

        case 'OUT_OF_ORDER':
        case 'OUTOFORDER':
        case 'FAULTED':
            return 'status-error';

        default:
            return 'status-unknown';
    }
}

function formatDurationFromMinutes($minutes): string
{
    if ($minutes === null || !is_numeric($minutes)) {
        return 'onbekend';
    }

    $totalMinutes = max(0, (int) floor((float) $minutes));
    $hours = intdiv($totalMinutes, 60);
    $remainingMinutes = $totalMinutes % 60;

    if ($hours > 0 && $remainingMinutes > 0) {
        return $hours . ' u, ' . $remainingMinutes . ' m';
    }

    if ($hours > 0) {
        return $hours . ' u';
    }

    return $remainingMinutes . ' m';
}

function extractStation(array $result): ?array
{
    $data = $result['incharge']['data'] ?? null;

    if (!is_array($data) || !isset($data[0]) || !is_array($data[0])) {
        return null;
    }

    return $data[0];
}

function extractPrice(array $station): string
{
    $priceComponents = $station['priceComponents'] ?? null;

    if (!is_array($priceComponents)) {
        return 'Onbekend';
    }

    $currency = $priceComponents['currency'] ?? 'EUR';
    $components = $priceComponents['components'] ?? [];

    $kwhPrice = null;
    $fixedPrice = null;

    foreach ($components as $component) {
        if (!is_array($component)) {
            continue;
        }

        $type = strtoupper((string) ($component['type'] ?? ''));
        $elements = $component['elements'] ?? [];

        if (!is_array($elements) || !isset($elements[0]) || !is_array($elements[0])) {
            continue;
        }

        if (!isset($elements[0]['price']) || !is_numeric($elements[0]['price'])) {
            continue;
        }

        $price = (float) $elements[0]['price'];

        if ($type === 'KWH') {
            $kwhPrice = $price;
        }

        if ($type === 'FIXED') {
            $fixedPrice = $price;
        }
    }

    $parts = [];

    if ($kwhPrice !== null) {
        $parts[] = number_format($kwhPrice, 4, ',', '.') . ' ' . $currency . '/kWh';
    }

    if ($fixedPrice !== null && $fixedPrice > 0) {
        $parts[] = number_format($fixedPrice, 4, ',', '.') . ' ' . $currency . ' vast';
    }

    return $parts !== [] ? implode(' + ', $parts) : 'Onbekend';
}

function getStatusFromResult(array $result, ?array $station): string
{
    if (is_array($station) && isset($station['status']) && is_string($station['status'])) {
        return strtoupper($station['status']);
    }

    $trackingStatus = $result['local_tracking']['status'] ?? null;

    if (is_string($trackingStatus) && $trackingStatus !== '') {
        return strtoupper($trackingStatus);
    }

    return 'UNKNOWN';
}

$data = fetchApiData();
$results = $data['results'] ?? [];

?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>InCharge laadpalen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <meta http-equiv="refresh" content="60">

    <style>
        :root {
            --bg: #f4f6f8;
            --card: #ffffff;
            --text: #17212b;
            --muted: #667085;
            --border: #d8dee4;
            --available: #138a43;
            --occupied: #c2410c;
            --error: #b42318;
            --unknown: #667085;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 24px;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: var(--bg);
            color: var(--text);
        }

        .page {
            max-width: 1100px;
            margin: 0 auto;
        }

        .header {
            margin-bottom: 24px;
        }

        .header h1 {
            margin: 0 0 6px;
            font-size: 28px;
        }

        .header p {
            margin: 0;
            color: var(--muted);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
            gap: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .title {
            font-size: 20px;
            font-weight: 700;
            margin: 0;
        }

        .subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 14px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            white-space: nowrap;
        }

        .status-available {
            background: var(--available);
        }

        .status-occupied {
            background: var(--occupied);
        }

        .status-error {
            background: var(--error);
        }

        .status-unknown {
            background: var(--unknown);
        }

        .meta {
            display: grid;
            gap: 10px;
        }

        .row {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            border-top: 1px solid var(--border);
            padding-top: 10px;
        }

        .label {
            color: var(--muted);
        }

        .value {
            font-weight: 600;
            text-align: right;
        }

        .small {
            font-size: 13px;
            color: var(--muted);
        }

        .error {
            background: #fff1f0;
            border: 1px solid #ffccc7;
            color: #a8071a;
            padding: 16px;
            border-radius: 12px;
            white-space: pre-wrap;
        }

        .empty {
            background: #fff;
            border: 1px solid var(--border);
            padding: 16px;
            border-radius: 12px;
            color: var(--muted);
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <h1>InCharge laadpalen</h1>
        <p>
            Laatst gecontroleerd:
            <?= h($data['checked_at'] ?? date(DATE_ATOM)) ?>
        </p>
    </div>

    <?php if (($data['ok'] ?? false) !== true): ?>
        <div class="error">
            <?= h($data['error'] ?? 'Onbekende API-fout.') ?>
        </div>
    <?php elseif (!is_array($results) || count($results) === 0): ?>
        <div class="empty">
            Geen laadpalen gevonden.
        </div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($results as $result): ?>
                <?php
                if (!is_array($result)) {
                    continue;
                }

                $favorite = $result['favorite'] ?? [];
                $station = extractStation($result);

                $name = (string) ($favorite['name'] ?? 'Onbekend');
                $label = (string) ($favorite['label'] ?? $name);

                $status = getStatusFromResult($result, $station);
                $price = $station ? extractPrice($station) : 'Onbekend';

                $minutes = $result['local_tracking']['minutes_in_current_status'] ?? null;
                $duration = formatDurationFromMinutes($minutes);

                $statusCode = $result['incharge']['status_code'] ?? null;
                $attempt = $result['incharge']['attempt'] ?? null;
                ?>
                <article class="card">
                    <div class="card-header">
                        <div>
                            <h2 class="title"><?= h($label) ?></h2>
                            <p class="subtitle"><?= h($name) ?></p>
                        </div>

                        <span class="status <?= h(statusClass($status)) ?>">
                            <?= h(formatStatus($status)) ?>
                        </span>
                    </div>

                    <div class="meta">
                        <div class="row">
                            <span class="label">Naam</span>
                            <span class="value"><?= h($name) ?></span>
                        </div>

                        <div class="row">
                            <span class="label">Label</span>
                            <span class="value"><?= h($label) ?></span>
                        </div>

                        <div class="row">
                            <span class="label">Status</span>
                            <span class="value"><?= h(formatStatus($status)) ?></span>
                        </div>

                        <div class="row">
                            <span class="label">Prijs</span>
                            <span class="value"><?= h($price) ?></span>
                        </div>

                        <div class="row">
                            <span class="label">
                                <?= strtoupper($status) === 'OCCUPIED' ? 'Bezet sinds' : 'Status sinds' ?>
                            </span>
                            <span class="value"><?= h($duration) ?></span>
                        </div>

                        <div class="row">
                            <span class="label">API</span>
                            <span class="value small">
                                HTTP <?= h($statusCode ?? '-') ?><?= $attempt ? ', poging ' . h($attempt) : '' ?>
                            </span>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</body>
</html>