<?php

declare(strict_types=1);

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/FavoriteRepository.php';

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function loadHistoryPageData(array $config): array
{
    try {
        $pdo = Database::connect($config);

        if (!Schema::tablesExist($pdo)) {
            return [
                'success' => false,
                'error' => 'Database tabellen ontbreken. Gebruik de admin om tabellen aan te maken en favo.json te importeren.',
            ];
        }

        $favoritesRepo = new FavoriteRepository($pdo);

        return [
            'success' => true,
            'favorites' => $favoritesRepo->allActive(),
        ];
    } catch (Throwable $e) {
        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}

$data = loadHistoryPageData($config);
$favorites = $data['favorites'] ?? [];
$favoriteById = [];

foreach ($favorites as $favorite) {
    $favoriteById[(int)$favorite['id']] = $favorite;
}

$periodOptions = [
    '48h' => '48 uur',
    '7d' => '7 dagen',
    '30d' => '30 dagen',
    '12w' => '12 weken',
];

$selectedPeriod = (string)($_GET['period'] ?? '7d');
if (!isset($periodOptions[$selectedPeriod])) {
    $selectedPeriod = '7d';
}

$selectedFavoriteId = isset($_GET['favorite_id']) ? (int)$_GET['favorite_id'] : 0;
if (!isset($favoriteById[$selectedFavoriteId]) && count($favorites) > 0) {
    $selectedFavoriteId = (int)$favorites[0]['id'];
}

$selectedFavorite = $favoriteById[$selectedFavoriteId] ?? null;
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>InCharge historie</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
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

        .button,
        .period-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 38px;
            padding: 0 12px;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #fff;
            color: var(--text);
            text-decoration: none;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
        }

        .button.primary,
        .period-button.active {
            background: var(--primary);
            border-color: var(--primary);
            color: #fff;
        }

        .panel {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 18px;
            box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
            margin-bottom: 16px;
        }

        .filters {
            display: grid;
            grid-template-columns: minmax(220px, 1fr) auto;
            gap: 12px;
            align-items: end;
        }

        .field label {
            display: block;
            color: var(--muted);
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 6px;
        }

        select {
            width: 100%;
            min-height: 40px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: #fff;
            color: var(--text);
            font: inherit;
            padding: 0 10px;
        }

        .periods {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            justify-content: flex-end;
        }

        .history-head {
            display: flex;
            justify-content: space-between;
            gap: 16px;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .history-head h2 {
            margin: 0 0 4px;
            font-size: 22px;
            letter-spacing: 0;
        }

        .history-head p {
            margin: 0;
            color: var(--muted);
        }

        .metrics {
            display: grid;
            grid-template-columns: repeat(4, minmax(120px, 1fr));
            gap: 10px;
            margin-bottom: 14px;
        }

        .metric {
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 10px 12px;
            background: #fbfdff;
        }

        .metric span {
            display: block;
            color: var(--muted);
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .metric strong {
            display: block;
            font-size: 16px;
        }

        .chart-canvas {
            position: relative;
            height: 220px;
            border: 1px solid #e4eaf0;
            border-radius: 8px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbfe 100%);
            padding: 12px 14px 8px;
            margin-bottom: 16px;
        }

        .chart-canvas canvas {
            display: block;
            width: 100% !important;
            height: 100% !important;
        }

        .table-wrap {
            overflow-x: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
        }

        table {
            width: 100%;
            min-width: 620px;
            border-collapse: collapse;
            background: #fff;
        }

        th,
        td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
            text-align: left;
            font-size: 14px;
        }

        th {
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0;
        }

        tr:last-child td {
            border-bottom: 0;
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 4px 9px;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
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

        .error[hidden] {
            display: none;
        }

        @media (max-width: 760px) {
            body {
                padding: 16px;
            }

            .header,
            .history-head,
            .filters {
                display: block;
            }

            .actions,
            .periods {
                margin-top: 12px;
                justify-content: flex-start;
            }

            .metrics {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <h1>InCharge historie</h1>
            <p>Langere availability-historie per laadpaal.</p>
        </div>
        <div class="actions">
            <a class="button" href="index.php">Dashboard</a>
            <a class="button" href="admin/">Admin</a>
        </div>
    </div>

    <?php if (($data['success'] ?? false) !== true): ?>
        <div class="error">
            <?= h($data['error'] ?? 'Onbekende fout.') ?>
        </div>
    <?php elseif (!is_array($favorites) || count($favorites) === 0): ?>
        <div class="empty">
            Geen actieve laadpalen gevonden. Voeg favorieten toe of importeer favo.json via admin.
        </div>
    <?php else: ?>
        <form class="panel filters" method="get" action="history.php">
            <input type="hidden" name="period" value="<?= h($selectedPeriod) ?>">
            <div class="field">
                <label for="favorite_id">Laadpaal</label>
                <select id="favorite_id" name="favorite_id" onchange="this.form.submit()">
                    <?php foreach ($favorites as $favorite): ?>
                        <option value="<?= h($favorite['id']) ?>" <?= (int)$favorite['id'] === $selectedFavoriteId ? 'selected' : '' ?>>
                            <?= h($favorite['display_name']) ?> (<?= h($favorite['chargepoint_name']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="periods">
                <?php foreach ($periodOptions as $periodKey => $periodLabel): ?>
                    <button class="period-button <?= $periodKey === $selectedPeriod ? 'active' : '' ?>" type="submit" name="period" value="<?= h($periodKey) ?>">
                        <?= h($periodLabel) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </form>

        <section class="panel">
            <div class="history-head">
                <div>
                    <h2><?= h(is_array($selectedFavorite) ? $selectedFavorite['display_name'] : 'Historie') ?></h2>
                    <p id="history-period"><?= h($periodOptions[$selectedPeriod]) ?> tot nu</p>
                </div>
                <div class="status-pill status-unknown" id="history-current">laden...</div>
            </div>

            <div class="metrics">
                <div class="metric">
                    <span>Vrij</span>
                    <strong data-summary-bucket="available">-</strong>
                </div>
                <div class="metric">
                    <span>Bezet</span>
                    <strong data-summary-bucket="occupied">-</strong>
                </div>
                <div class="metric">
                    <span>Buiten gebruik</span>
                    <strong data-summary-bucket="faulted">-</strong>
                </div>
                <div class="metric">
                    <span>Onbekend</span>
                    <strong data-summary-bucket="unknown">-</strong>
                </div>
            </div>

            <div class="chart-canvas">
                <canvas
                    id="history-chart"
                    data-availability-favorite="<?= h($selectedFavoriteId) ?>"
                    data-availability-period="<?= h($selectedPeriod) ?>"
                    aria-label="Beschikbaarheid historie"></canvas>
            </div>

            <div class="error" id="history-error" hidden></div>

            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Status</th>
                        <th>Van</th>
                        <th>Tot</th>
                        <th>Duur</th>
                    </tr>
                    </thead>
                    <tbody id="segments-body">
                    <tr>
                        <td colspan="4">laden...</td>
                    </tr>
                    </tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script src="js/availability-chart.js"></script>
<script>
(function () {
    const chartApi = window.InChargeAvailabilityChart;
    const canvas = document.getElementById('history-chart');

    if (!chartApi || !canvas) {
        return;
    }

    function formatDateTime(value) {
        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return '-';
        }

        return date.toLocaleString('nl-NL', {
            day: '2-digit',
            month: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function setText(selector, text) {
        const element = document.querySelector(selector);

        if (element) {
            element.textContent = text;
        }
    }

    function setCurrent(data) {
        const element = document.getElementById('history-current');
        const current = data.current || {};
        const bucket = current.status_bucket || 'unknown';

        if (!element) {
            return;
        }

        element.className = 'status-pill status-' + bucket;
        element.textContent = current.status_label || chartApi.labels[bucket] || 'Onbekend';
    }

    function setSummary(data) {
        const summary = data.summary || {};

        ['available', 'occupied', 'faulted', 'unknown'].forEach(function (bucket) {
            const item = summary[bucket] || {};
            setText(
                '[data-summary-bucket="' + bucket + '"]',
                chartApi.formatPercent(item.percentage || 0) + ' / ' + chartApi.formatDuration(item.seconds || 0)
            );
        });
    }

    function appendCell(row, text) {
        const cell = document.createElement('td');
        cell.textContent = text;
        row.appendChild(cell);
        return cell;
    }

    function renderSegments(segments) {
        const body = document.getElementById('segments-body');

        if (!body) {
            return;
        }

        body.textContent = '';

        if (!segments || segments.length === 0) {
            const row = document.createElement('tr');
            const cell = appendCell(row, 'Geen metingen in deze periode.');
            cell.colSpan = 4;
            body.appendChild(row);
            return;
        }

        segments.slice().reverse().forEach(function (segment) {
            const row = document.createElement('tr');
            const statusCell = document.createElement('td');
            const bucket = segment.status_bucket || 'unknown';
            const pill = document.createElement('span');

            pill.className = 'status-pill status-' + bucket;
            pill.textContent = segment.status_label || chartApi.labels[bucket] || 'Onbekend';
            statusCell.appendChild(pill);
            row.appendChild(statusCell);

            appendCell(row, formatDateTime(segment.from));
            appendCell(row, formatDateTime(segment.to));
            appendCell(row, chartApi.formatDuration(segment.duration_seconds || 0));

            body.appendChild(row);
        });
    }

    chartApi.load(canvas, {
        onData: function (data) {
            const period = document.getElementById('history-period');
            const error = document.getElementById('history-error');

            if (period && data.period) {
                period.textContent = chartApi.formatPeriod(data.period.seconds) + ' tot nu';
            }

            if (error) {
                error.hidden = true;
                error.textContent = '';
            }

            setCurrent(data);
            setSummary(data);
            renderSegments(data.segments || []);
        },
        onError: function (error) {
            const element = document.getElementById('history-error');

            if (element) {
                element.hidden = false;
                element.textContent = error.message;
            }
        }
    }).catch(function () {});
})();
</script>
</body>
</html>
