<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    header('Location: admin/', true, 302);
    exit;
}

require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Schema.php';
require __DIR__ . '/src/FavoriteRepository.php';

$config = require __DIR__ . '/config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

$isCli = PHP_SAPI === 'cli';
$shouldRun = $isCli || ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$messages = [];
$error = null;
$stats = null;

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

if ($shouldRun) {
    try {
        $pdo = Database::connect($config);
        $messages = array_merge($messages, Schema::install($pdo));

        $favorites = new FavoriteRepository($pdo);
        $import = $favorites->importFromJson((string)$config['paths']['favorites_json']);
        $messages[] = $import['imported'] . ' favorieten geimporteerd uit favo.json';

        if ($import['skipped'] > 0) {
            $messages[] = $import['skipped'] . ' favorieten overgeslagen';
        }

        $stats = Schema::stats($pdo);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
} else {
    try {
        $pdo = Database::connect($config);
        $stats = Schema::stats($pdo);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if ($isCli) {
    echo json_encode([
        'success' => $error === null,
        'messages' => $messages,
        'error' => $error,
        'stats' => $stats,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($error === null ? 0 : 1);
}
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>InCharge setup</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="icons/favicon-16x16.png">
    <link rel="shortcut icon" href="icons/favicon.ico">
    <link rel="apple-touch-icon" sizes="180x180" href="icons/apple-touch-icon.png">
    <link rel="manifest" href="icons/site.webmanifest">
    <style>
        :root {
            --bg: #eef3f8;
            --card: #fff;
            --text: #172033;
            --muted: #667085;
            --border: #d8dee4;
            --primary: #0a66c2;
            --success: #138a43;
            --danger: #b42318;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--text);
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            padding: 24px;
        }

        main {
            max-width: 860px;
            margin: 0 auto;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
        }

        header {
            padding: 20px;
            color: #fff;
            background: linear-gradient(135deg, #0a66c2 0%, #0f9488 100%);
        }

        h1 {
            margin: 0 0 6px;
            font-size: 26px;
        }

        .body {
            padding: 20px;
        }

        .muted {
            color: var(--muted);
        }

        .alert {
            border-radius: 8px;
            padding: 12px 14px;
            margin: 0 0 16px;
        }

        .alert-success {
            background: #ecfdf3;
            color: #05603a;
            border: 1px solid #abefc6;
        }

        .alert-danger {
            background: #fef3f2;
            color: var(--danger);
            border: 1px solid #fecdca;
        }

        dl {
            display: grid;
            grid-template-columns: minmax(140px, 220px) 1fr;
            gap: 8px 16px;
            margin: 18px 0;
        }

        dt {
            color: var(--muted);
        }

        dd {
            margin: 0;
            font-weight: 600;
            overflow-wrap: anywhere;
        }

        button,
        a.button {
            display: inline-flex;
            align-items: center;
            min-height: 42px;
            padding: 0 14px;
            border-radius: 8px;
            border: 1px solid var(--primary);
            background: var(--primary);
            color: #fff;
            font: inherit;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
        }

        a.button.secondary {
            background: #fff;
            color: var(--primary);
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 18px;
        }
    </style>
</head>
<body>
<main>
    <header>
        <h1>InCharge setup</h1>
        <div>Maak tabellen aan en importeer de laadpaalfavorieten uit favo.json.</div>
    </header>
    <div class="body">
        <?php if ($error): ?>
            <div class="alert alert-danger"><?= h($error) ?></div>
        <?php elseif ($messages): ?>
            <div class="alert alert-success">
                <?php foreach ($messages as $message): ?>
                    <div><?= h($message) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <dl>
            <dt>Database credentials</dt>
            <dd><?= h($config['db']['credentials_file'] ?? '-') ?></dd>
            <dt>Database</dt>
            <dd><?= h($config['db']['name'] ?? '-') ?></dd>
            <dt>Favorites seed</dt>
            <dd><?= h($config['paths']['favorites_json'] ?? '-') ?></dd>
            <dt>Tabellen aanwezig</dt>
            <dd><?= ($stats['tables_exist'] ?? false) ? 'Ja' : 'Nee' ?></dd>
            <dt>Actieve favorieten</dt>
            <dd><?= h($stats['active_favorites'] ?? 0) ?></dd>
            <dt>Snapshots</dt>
            <dd><?= h($stats['snapshots'] ?? 0) ?></dd>
            <dt>Laatste snapshot</dt>
            <dd><?= h($stats['latest_snapshot_at'] ?? '-') ?></dd>
        </dl>

        <p class="muted">
            Setup is idempotent: opnieuw draaien maakt ontbrekende tabellen aan en werkt bestaande favorieten bij op basis van laadpaalnaam.
        </p>

        <div class="actions">
            <form method="post">
                <button type="submit">Setup uitvoeren</button>
            </form>
            <a class="button secondary" href="./">Dashboard</a>
            <a class="button secondary" href="admin/">Admin</a>
        </div>
    </div>
</main>
</body>
</html>
