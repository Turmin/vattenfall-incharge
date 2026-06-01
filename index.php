<?php

declare(strict_types=1);

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/Status.php';
require __DIR__ . '/src/FavoriteRepository.php';
require __DIR__ . '/src/SnapshotRepository.php';

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function formatDurationFromSeconds($seconds): string
{
    if ($seconds === null || !is_numeric($seconds)) {
        return 'onbekend';
    }

    $totalMinutes = max(0, (int)floor(((float)$seconds) / 60));
    $hours = intdiv($totalMinutes, 60);
    $minutes = $totalMinutes % 60;

    if ($hours > 0 && $minutes > 0) {
        return $hours . ' u, ' . $minutes . ' m';
    }

    if ($hours > 0) {
        return $hours . ' u';
    }

    return $minutes . ' m';
}

function formatDateTime($value): string
{
    return $value ? date('d-m-Y H:i', strtotime($value)) : '-';
}

function extractPriceFromRaw($rawJson): string
{
    if (!$rawJson) {
        return 'Onbekend';
    }

    $raw = json_decode($rawJson, true);
    $station = is_array($raw) ? ($raw['station'] ?? null) : null;

    if (!is_array($station)) {
        return 'Onbekend';
    }

    $priceComponents = $station['priceComponents'] ?? null;

    if (!is_array($priceComponents)) {
        return 'Onbekend';
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
        } elseif ($type === 'FIXED' && (float)$elements[0]['price'] > 0) {
            $parts[] = $price . ' ' . $currency . ' vast';
        }
    }

    return $parts !== [] ? implode(' + ', $parts) : 'Onbekend';
}

function loadDashboardData(array $config): array
{
    try {
        $pdo = Database::connect($config);

        if (!Schema::tablesExist($pdo)) {
            return [
                'success' => false,
                'error' => 'Database tabellen ontbreken. Gebruik de admin om tabellen aan te maken en favo.json te importeren.',
                'setup_required' => true,
            ];
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
            $chargepoints[] = [
                'favorite' => $favorite,
                'latest' => $snapshotByFavoriteId[$favoriteId] ?? null,
            ];
        }

        return [
            'success' => true,
            'checked_at' => date(DATE_ATOM),
            'chargepoints' => $chargepoints,
            'stats' => Schema::stats($pdo),
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

$data = loadDashboardData($config);
$chargepoints = $data['chargepoints'] ?? [];
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>InCharge laadpalen</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="icons/favicon-16x16.png">
    <link rel="shortcut icon" href="icons/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="icons/apple-touch-icon.png">
    <link rel="manifest" href="icons/site.webmanifest">
    <style>
        :root {
            --bg: #eef3f8;
            --card: #ffffff;
            --text: #172033;
            --muted: #667085;
            --border: #d8dee4;
            --available: #138a43;
            --occupied: #c2410c;
            --faulted: #b42318;
            --unknown: #667085;
            --primary: #0a66c2;
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
            max-width: 1180px;
            margin: 0 auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 20px;
        }

        h1 {
            margin: 0 0 6px;
            font-size: 30px;
            letter-spacing: 0;
        }

        .header p {
            margin: 0;
            color: var(--muted);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .button {
            display: inline-flex;
            align-items: center;
            min-height: 38px;
            padding: 0 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font-weight: 700;
        }

        .button.primary {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(310px, 1fr));
            gap: 16px;
        }

        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
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
            letter-spacing: 0;
        }

        .subtitle {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 14px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
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

        .status-faulted {
            background: var(--faulted);
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

        .chart-shell {
            margin-top: 16px;
            border-top: 1px solid var(--border);
            padding-top: 14px;
        }

        .chart-title {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            align-items: baseline;
            margin-bottom: 10px;
            color: var(--muted);
            font-size: 13px;
        }

        .chart-title span:last-child {
            text-align: right;
        }

        .chart-canvas {
            position: relative;
            height: 118px;
            border: 1px solid #e4eaf0;
            border-radius: 8px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbfe 100%);
            padding: 8px 10px 6px;
        }

        .chart-canvas canvas {
            display: block;
            width: 100% !important;
            height: 100% !important;
        }

        .error,
        .empty {
            background: #fff;
            border: 1px solid var(--border);
            padding: 16px;
            border-radius: 8px;
        }

        .error {
            border-color: #fecdca;
            color: #a8071a;
            white-space: pre-wrap;
        }

        .page-footer {
            margin-top: 18px;
            color: var(--muted);
            font-size: 12px;
            text-align: center;
        }

        @media (max-width: 640px) {
            body {
                padding: 16px;
            }

            .header {
                display: block;
            }

            .actions {
                margin-top: 12px;
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <h1>InCharge laadpalen</h1>
            <p>Laatst geladen: <?= h($data['checked_at'] ?? date(DATE_ATOM)) ?></p>
        </div>
        <div class="actions">
            <a class="button" href="admin/">Admin</a>
        </div>
    </div>

    <?php if (($data['success'] ?? false) !== true): ?>
        <div class="error">
            <?= h($data['error'] ?? 'Onbekende fout.') ?>
        </div>
    <?php elseif (!is_array($chargepoints) || count($chargepoints) === 0): ?>
        <div class="empty">
            Geen actieve laadpalen gevonden. Voeg favorieten toe of importeer favo.json via admin.
        </div>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($chargepoints as $item): ?>
                <?php
                $favorite = $item['favorite'];
                $latest = $item['latest'];
                $status = is_array($latest) ? (string)$latest['status'] : 'UNKNOWN';
                $statusLabel = Status::label($status);
                $statusClass = Status::cssClass($status);
                $duration = is_array($latest) ? formatDurationFromSeconds($latest['seconds_in_current_status'] ?? null) : 'onbekend';
                $price = is_array($latest) ? extractPriceFromRaw($latest['raw_json'] ?? null) : 'Onbekend';
                ?>
                <article class="card" data-card="<?= h($favorite['id']) ?>">
                    <div class="card-header">
                        <div>
                            <h2 class="title"><?= h($favorite['display_name']) ?></h2>
                            <p class="subtitle"><?= h($favorite['chargepoint_name']) ?></p>
                        </div>
                        <span class="status <?= h($statusClass) ?>"><?= h($statusLabel) ?></span>
                    </div>

                    <div class="meta">
                        <div class="row">
                            <span class="label">Status sinds</span>
                            <span class="value"><?= h($duration) ?></span>
                        </div>
                        <div class="row">
                            <span class="label">Laatste meting</span>
                            <span class="value"><?= h(is_array($latest) ? formatDateTime((string)$latest['measured_at']) : '-') ?></span>
                        </div>
                        <div class="row">
                            <span class="label">Connectors</span>
                            <span class="value">
                                <?php if (is_array($latest) && $latest['total_connectors'] !== null): ?>
                                    <?= h((int)$latest['available_connectors']) ?> vrij / <?= h((int)$latest['total_connectors']) ?> totaal
                                <?php else: ?>
                                    onbekend
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="row">
                            <span class="label">Prijs</span>
                            <span class="value"><?= h($price) ?></span>
                        </div>
                    </div>

                    <div class="chart-shell">
                        <div class="chart-title">
                            <span>Beschikbaarheid 24 uur</span>
                            <span data-availability-summary="<?= h($favorite['id']) ?>">laden...</span>
                        </div>
                        <div class="chart-canvas">
                            <canvas data-availability-chart="<?= h($favorite['id']) ?>" aria-label="Beschikbaarheid 24 uur"></canvas>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (($data['success'] ?? false) === true): ?>
        <footer class="page-footer">
            Laatste meting: <?= h(formatDateTime($data['stats']['latest_snapshot_at'] ?? null)) ?>
        </footer>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
const colors = {
    available: '#138a43',
    occupied: '#c2410c',
    faulted: '#b42318',
    unknown: '#d0d5dd'
};

const labels = {
    available: 'Vrij',
    occupied: 'Bezet',
    faulted: 'Buiten gebruik',
    unknown: 'Onbekend'
};

const availabilityCharts = {};

function formatPercent(value) {
    return Number(value || 0).toFixed(1).replace('.', ',') + '%';
}

function formatDuration(seconds) {
    const totalMinutes = Math.max(0, Math.round(Number(seconds || 0) / 60));
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;

    if (hours > 0 && minutes > 0) {
        return hours + ' u ' + minutes + ' m';
    }

    if (hours > 0) {
        return hours + ' u';
    }

    return minutes + ' m';
}

function segmentHour(value, from, span) {
    return Math.max(0, Math.min(24, ((value - from) / span) * 24));
}

function drawAvailability(canvas, data) {
    if (!window.Chart) {
        throw new Error('Chart.js niet geladen');
    }

    const favoriteId = canvas.dataset.availabilityChart;
    const from = new Date(data.period.from).getTime();
    const to = new Date(data.period.to).getTime();
    const span = Math.max(1, to - from);
    const segments = data.segments || [];
    const points = [];
    const pointColors = [];

    for (const segment of segments) {
        const start = segmentHour(new Date(segment.from).getTime(), from, span);
        const end = segmentHour(new Date(segment.to).getTime(), from, span);
        const bucket = segment.status_bucket || 'unknown';

        if (end <= start) {
            continue;
        }

        points.push({
            x: [start, end],
            y: '24 uur',
            segment: segment
        });
        pointColors.push(colors[bucket] || colors.unknown);
    }

    if (availabilityCharts[favoriteId]) {
        availabilityCharts[favoriteId].destroy();
    }

    availabilityCharts[favoriteId] = new Chart(canvas, {
        type: 'bar',
        data: {
            labels: ['24 uur'],
            datasets: [{
                label: 'Beschikbaarheid',
                data: points,
                backgroundColor: pointColors,
                borderColor: '#ffffff',
                borderWidth: 0,
                hoverBorderWidth: 1,
                borderRadius: function (context) {
                    const count = context.dataset.data.length;
                    const first = context.dataIndex === 0;
                    const last = context.dataIndex === count - 1;

                    return {
                        topLeft: first ? 6 : 0,
                        bottomLeft: first ? 6 : 0,
                        topRight: last ? 6 : 0,
                        bottomRight: last ? 6 : 0
                    };
                },
                borderSkipped: false,
                barThickness: 26
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 350
            },
            parsing: {
                xAxisKey: 'x',
                yAxisKey: 'y'
            },
            layout: {
                padding: {
                    top: 4,
                    right: 4,
                    bottom: 0,
                    left: 0
                }
            },
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    displayColors: false,
                    callbacks: {
                        title: function () {
                            return 'Beschikbaarheid';
                        },
                        label: function (context) {
                            const segment = context.raw.segment || {};
                            const bucket = segment.status_bucket || 'unknown';
                            const label = segment.status_label || labels[bucket] || 'Onbekend';
                            return label + ': ' + formatDuration(segment.duration_seconds || 0);
                        },
                        afterLabel: function (context) {
                            const segment = context.raw.segment || {};
                            const start = segment.from ? new Date(segment.from) : null;
                            const end = segment.to ? new Date(segment.to) : null;

                            if (!start || !end) {
                                return '';
                            }

                            return start.toLocaleTimeString('nl-NL', {hour: '2-digit', minute: '2-digit'})
                                + ' - '
                                + end.toLocaleTimeString('nl-NL', {hour: '2-digit', minute: '2-digit'});
                        }
                    }
                }
            },
            scales: {
                x: {
                    type: 'linear',
                    min: 0,
                    max: 24,
                    grid: {
                        color: 'rgba(102, 112, 133, 0.14)',
                        drawBorder: false
                    },
                    ticks: {
                        stepSize: 6,
                        color: '#667085',
                        font: {
                            size: 11
                        },
                        callback: function (value) {
                            if (value === 0) {
                                return '24u geleden';
                            }

                            if (value === 24) {
                                return 'nu';
                            }

                            return '-' + (24 - value) + 'u';
                        }
                    }
                },
                y: {
                    display: false,
                    grid: {
                        display: false,
                        drawBorder: false
                    }
                }
            }
        }
    });
}

async function loadAvailability(canvas) {
    const favoriteId = canvas.dataset.availabilityChart;
    const summary = document.querySelector(`[data-availability-summary="${favoriteId}"]`);

    try {
        const response = await fetch(`api/availability.php?favorite_id=${encodeURIComponent(favoriteId)}&period=24h`, {
            headers: {Accept: 'application/json'}
        });
        const data = await response.json();

        if (!data.success) {
            const message = data.error && data.error.message ? data.error.message : 'API-fout';
            throw new Error(message);
        }

        drawAvailability(canvas, data);

        if (summary) {
            const available = data.summary && data.summary.available ? data.summary.available.percentage : 0;
            const occupied = data.summary && data.summary.occupied ? data.summary.occupied.percentage : 0;
            summary.textContent = 'vrij ' + formatPercent(available) + ' / bezet ' + formatPercent(occupied);
        }
    } catch (error) {
        if (summary) {
            summary.textContent = error.message;
        }
    }
}

document.querySelectorAll('[data-availability-chart]').forEach(function (canvas) {
    loadAvailability(canvas);
});
</script>
</body>
</html>
