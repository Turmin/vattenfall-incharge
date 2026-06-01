<?php

declare(strict_types=1);

session_start();

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set((string)($config['timezone'] ?? date_default_timezone_get()));

$preferredCredentialsFile = (string)$config['admin']['credentials_file'];
$legacyCredentialsFile = (string)$config['admin']['legacy_credentials_file'];
$credentialsFile = is_file($preferredCredentialsFile) || !is_file($legacyCredentialsFile)
    ? $preferredCredentialsFile
    : $legacyCredentialsFile;
$credentialsOutsideWebRoot = $credentialsFile === $preferredCredentialsFile;
$flash = $_SESSION['admin_flash'] ?? null;
unset($_SESSION['admin_flash']);

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirectAdmin(?array $flash = null): void
{
    if ($flash !== null) {
        $_SESSION['admin_flash'] = $flash;
    }

    header('Location: index.php');
    exit;
}

function requireValidCsrf(): void
{
    $token = $_POST['csrf'] ?? '';

    if (!hash_equals($_SESSION['admin_csrf'] ?? '', (string)$token)) {
        redirectAdmin(['type' => 'danger', 'messages' => ['Ongeldig security token. Probeer opnieuw.']]);
    }
}

function loadCredentials(string $credentialsFile): ?array
{
    if (!is_file($credentialsFile)) {
        return null;
    }

    $credentials = require $credentialsFile;

    if (!is_array($credentials) || empty($credentials['username']) || empty($credentials['password_hash'])) {
        return null;
    }

    return $credentials;
}

function cronTokenConfigured(array $config): bool
{
    foreach ([(string)$config['cron']['token_file'], (string)$config['cron']['legacy_token_file']] as $file) {
        if ($file !== '' && is_file($file)) {
            return true;
        }
    }

    return false;
}

function formatDateTime(?string $value): string
{
    return $value ? date('d-m-Y H:i', strtotime($value)) : '-';
}

$credentials = loadCredentials($credentialsFile);
$setupRequired = $credentials === null;

if ($setupRequired && ($_POST['action'] ?? '') === 'setup_admin') {
    requireValidCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $passwordRepeat = (string)($_POST['password_repeat'] ?? '');

    if ($username === '' || strlen($password) < 10 || $password !== $passwordRepeat) {
        redirectAdmin(['type' => 'danger', 'messages' => ['Kies een gebruikersnaam en twee gelijke wachtwoorden van minimaal 10 tekens.']]);
    }

    $content = "<?php\nreturn " . var_export([
        'username' => $username,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date(DATE_ATOM),
    ], true) . ";\n";

    if (file_put_contents($preferredCredentialsFile, $content, LOCK_EX) === false) {
        redirectAdmin(['type' => 'danger', 'messages' => ['Kon admin credentials niet schrijven naar ' . $preferredCredentialsFile]]);
    }

    redirectAdmin(['type' => 'success', 'messages' => ['Admin account aangemaakt. Je kunt nu inloggen.']]);
}

$isLoggedIn = !$setupRequired && (($_SESSION['admin_logged_in'] ?? false) === true);

if (($_POST['action'] ?? '') === 'logout') {
    requireValidCsrf();
    $_SESSION = [];
    session_destroy();
    session_start();
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
    redirectAdmin(['type' => 'success', 'messages' => ['Uitgelogd.']]);
}

if (!$setupRequired && ($_POST['action'] ?? '') === 'login') {
    requireValidCsrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === $credentials['username'] && password_verify($password, $credentials['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_user'] = $credentials['username'];
        $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
        redirectAdmin(['type' => 'success', 'messages' => ['Ingelogd.']]);
    }

    redirectAdmin(['type' => 'danger', 'messages' => ['Ongeldige gebruikersnaam of wachtwoord.']]);
}

$pdo = null;
$stats = null;
$favorites = [];
$latestByFavorite = [];
$cronJobs = [];
$cronTaskOptions = [];
$adminError = null;
$cronTokenConfigured = cronTokenConfigured($config);

if ($isLoggedIn) {
    require_once __DIR__ . '/../src/Database.php';
    require_once __DIR__ . '/../src/Schema.php';
    require_once __DIR__ . '/../src/Status.php';
    require_once __DIR__ . '/../src/FavoriteRepository.php';
    require_once __DIR__ . '/../src/SnapshotRepository.php';
    require_once __DIR__ . '/../src/InchargeClient.php';
    require_once __DIR__ . '/../src/InChargePoller.php';
    require_once __DIR__ . '/../src/CronScheduleService.php';

    try {
        $pdo = Database::connect($config);
        $favoritesRepo = new FavoriteRepository($pdo);
        $snapshotsRepo = new SnapshotRepository($pdo);
        $client = new InChargeClient($config);
        $poller = new InChargePoller($favoritesRepo, $snapshotsRepo, $client);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            requireValidCsrf();
            $action = (string)$_POST['action'];
            $result = null;

            if ($action === 'setup_db') {
                $messages = Schema::install($pdo);
                $import = $favoritesRepo->importFromJson((string)$config['paths']['favorites_json']);
                $messages[] = $import['imported'] . ' favorieten geimporteerd';
                if ($import['skipped'] > 0) {
                    $messages[] = $import['skipped'] . ' favorieten overgeslagen';
                }
                $result = ['success' => true, 'messages' => $messages];
            } elseif ($action === 'favorite_save') {
                $id = (int)($_POST['id'] ?? 0);
                $chargepointName = (string)($_POST['chargepoint_name'] ?? '');
                $displayName = (string)($_POST['display_name'] ?? $chargepointName);
                $sortOrder = (int)($_POST['sort_order'] ?? 0);
                $isActive = !empty($_POST['is_active']);

                if (!FavoriteRepository::isValidChargepointName($chargepointName)) {
                    throw new InvalidArgumentException('Ongeldige laadpaalnaam.');
                }

                if ($id > 0) {
                    $favoritesRepo->update($id, $chargepointName, $displayName, $sortOrder, $isActive);
                    $result = ['success' => true, 'messages' => ['Favoriet opgeslagen.']];
                } else {
                    $favoritesRepo->create($chargepointName, $displayName, $sortOrder);
                    $result = ['success' => true, 'messages' => ['Favoriet toegevoegd.']];
                }
            } elseif ($action === 'favorite_disable') {
                $favoritesRepo->setActive((int)($_POST['id'] ?? 0), false);
                $result = ['success' => true, 'messages' => ['Favoriet uitgeschakeld.']];
            } elseif ($action === 'favorite_enable') {
                $favoritesRepo->setActive((int)($_POST['id'] ?? 0), true);
                $result = ['success' => true, 'messages' => ['Favoriet ingeschakeld.']];
            } elseif ($action === 'favorite_import_json') {
                $import = $favoritesRepo->importFromJson((string)$config['paths']['favorites_json']);
                $result = ['success' => true, 'messages' => [$import['imported'] . ' favorieten geimporteerd.', $import['skipped'] . ' overgeslagen.']];
            } elseif ($action === 'poll_now') {
                if (!Schema::tablesExist($pdo)) {
                    throw new RuntimeException('Database tabellen ontbreken. Draai setup eerst.');
                }
                $poll = $poller->pollActiveFavorites();
                $result = ['success' => (bool)$poll['success'], 'messages' => $poll['messages']];
            } elseif (in_array($action, ['cron_save', 'cron_delete', 'cron_run'], true)) {
                if (!Schema::tablesExist($pdo)) {
                    throw new RuntimeException('Database tabellen ontbreken. Draai setup eerst.');
                }
                $cron = new CronScheduleService($pdo, $poller, $snapshotsRepo, $config);
                if ($action === 'cron_save') {
                    $result = $cron->saveJob($_POST);
                } elseif ($action === 'cron_delete') {
                    $result = $cron->deleteJob((int)($_POST['id'] ?? 0));
                } else {
                    $result = $cron->runJob((int)($_POST['id'] ?? 0));
                }
            }

            if ($result !== null) {
                $_SESSION['admin_activity'][] = [
                    'time' => date('Y-m-d H:i:s'),
                    'action' => $action,
                    'success' => (bool)($result['success'] ?? false),
                    'messages' => $result['messages'] ?? [],
                ];
                $_SESSION['admin_activity'] = array_slice($_SESSION['admin_activity'], -6);

                redirectAdmin([
                    'type' => ($result['success'] ?? false) ? 'success' : 'danger',
                    'messages' => $result['messages'] ?? ['Actie afgerond.'],
                ]);
            }
        }

        $stats = Schema::stats($pdo);

        if ($stats['tables_exist']) {
            $favorites = $favoritesRepo->all();
            foreach ($snapshotsRepo->latestForFavorites() as $snapshot) {
                $latestByFavorite[(int)$snapshot['favorite_id']] = $snapshot;
            }
            $cron = new CronScheduleService($pdo, $poller, $snapshotsRepo, $config);
            $cronJobs = $cron->listJobs();
            $cronTaskOptions = CronScheduleService::taskOptions();
        }
    } catch (Throwable $e) {
        $adminError = $e->getMessage();
    }
}

$csrf = $_SESSION['admin_csrf'];
$adminUser = $_SESSION['admin_user'] ?? ($credentials['username'] ?? 'Admin');
$activity = array_reverse($_SESSION['admin_activity'] ?? []);
?>
<!doctype html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>InCharge Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.1/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="css/admin-style.css" rel="stylesheet">
</head>
<body>
<div class="container admin-container">
    <?php if ($setupRequired): ?>
        <div class="login-shell">
            <div class="admin-card">
                <div class="admin-header">
                    <h1 class="h4 mb-1">InCharge Admin Setup</h1>
                    <div>Maak de eerste admin-login aan.</div>
                </div>
                <div class="card-body">
                    <?php if ($flash): ?>
                        <div class="alert alert-<?= h($flash['type']) ?>">
                            <?php foreach ($flash['messages'] as $message): ?><div><?= h($message) ?></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="setup_admin">
                        <div class="mb-3">
                            <label class="form-label" for="username">Gebruikersnaam</label>
                            <input class="form-control" id="username" name="username" autocomplete="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password">Wachtwoord</label>
                            <input class="form-control" id="password" name="password" type="password" autocomplete="new-password" minlength="10" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="password_repeat">Herhaal wachtwoord</label>
                            <input class="form-control" id="password_repeat" name="password_repeat" type="password" autocomplete="new-password" minlength="10" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Admin aanmaken</button>
                    </form>
                </div>
            </div>
        </div>
    <?php elseif (!$isLoggedIn): ?>
        <div class="login-shell">
            <div class="admin-card">
                <div class="admin-header">
                    <h1 class="h4 mb-1">InCharge Admin Login</h1>
                    <div>Beheer favorieten, snapshots en cronplanningen.</div>
                </div>
                <div class="card-body">
                    <?php if ($flash): ?>
                        <div class="alert alert-<?= h($flash['type']) ?>">
                            <?php foreach ($flash['messages'] as $message): ?><div><?= h($message) ?></div><?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="login">
                        <div class="mb-3">
                            <label class="form-label" for="login_username">Gebruikersnaam</label>
                            <input class="form-control" id="login_username" name="username" autocomplete="username" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="login_password">Wachtwoord</label>
                            <input class="form-control" id="login_password" name="password" type="password" autocomplete="current-password" required>
                        </div>
                        <button class="btn btn-primary w-100" type="submit">Inloggen</button>
                    </form>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="admin-card">
            <div class="admin-header d-flex flex-column flex-md-row justify-content-between gap-3">
                <div>
                    <h1 class="h3 mb-1"><i class="bi bi-lightning-charge me-2"></i>InCharge Admin</h1>
                    <div>Favorieten, polling en availability-historie.</div>
                </div>
                <div class="admin-toolbar d-flex flex-wrap gap-2">
                    <span class="badge bg-light text-dark admin-user-badge"><i class="bi bi-person-circle me-1"></i><?= h($adminUser) ?></span>
                    <a class="btn btn-outline-light btn-sm" href="../"><i class="bi bi-house-door me-1"></i>Dashboard</a>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <input type="hidden" name="action" value="logout">
                        <button class="btn btn-outline-light btn-sm" type="submit"><i class="bi bi-box-arrow-right me-1"></i>Uitloggen</button>
                    </form>
                </div>
            </div>
        </div>

        <?php if ($flash): ?>
            <div class="alert alert-<?= h($flash['type']) ?>">
                <?php foreach ($flash['messages'] as $message): ?><div><?= h($message) ?></div><?php endforeach; ?>
            </div>
        <?php endif; ?>

        <?php if ($adminError): ?>
            <div class="alert alert-danger"><?= h($adminError) ?></div>
        <?php endif; ?>

        <div class="row g-3">
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="text-muted">Actieve favorieten</div>
                    <div class="stat-number"><?= h($stats['active_favorites'] ?? 0) ?></div>
                    <small><?= h($stats['favorites'] ?? 0) ?> totaal</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="text-muted">Snapshots</div>
                    <div class="stat-number"><?= h($stats['snapshots'] ?? 0) ?></div>
                    <small>Historie records</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="text-muted">Laatste meting</div>
                    <div class="stat-number"><?= h(formatDateTime($stats['latest_snapshot_at'] ?? null)) ?></div>
                    <small><?= h($stats['latest_snapshot_at'] ?? '-') ?></small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="text-muted">Cron jobs</div>
                    <div class="stat-number"><?= h($stats['cron_jobs'] ?? 0) ?></div>
                    <small><?= $cronTokenConfigured ? 'Token aanwezig' : 'Webtoken ontbreekt' ?></small>
                </div>
            </div>
        </div>

        <?php if (!($stats['tables_exist'] ?? false)): ?>
            <div class="admin-card mt-3">
                <div class="card-body">
                    <h2 class="h5 mb-3"><i class="bi bi-database-add text-primary me-2"></i>Database setup</h2>
                    <p class="text-muted">De InCharge-tabellen ontbreken nog. Draai setup om tabellen aan te maken en favo.json te importeren.</p>
                    <form method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <button class="btn btn-primary" type="submit" name="action" value="setup_db">Setup uitvoeren</button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3 mt-1">
                <div class="col-lg-5">
                    <div class="admin-card">
                        <div class="card-body">
                            <h2 class="h5 mb-3"><i class="bi bi-play-circle text-success me-2"></i>Polling</h2>
                            <div class="d-grid gap-2">
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <button class="btn btn-success w-100" type="submit" name="action" value="poll_now">Nu laadpalen ophalen</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                    <button class="btn btn-outline-primary w-100" type="submit" name="action" value="favorite_import_json">favo.json opnieuw importeren</button>
                                </form>
                                <div class="small text-muted">
                                    Server cron: <code>* * * * * php <?= h(dirname(__DIR__) . '/cron.php') ?> &gt;/dev/null 2&gt;&amp;1</code>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <div class="admin-card">
                        <div class="card-body">
                            <h2 class="h5 mb-3"><i class="bi bi-check2-circle text-primary me-2"></i>Systeem</h2>
                            <div class="d-grid gap-2">
                                <div class="status-row d-flex justify-content-between"><span>Database</span><span class="badge bg-<?= $pdo ? 'success' : 'danger' ?>"><?= $pdo ? 'Online' : 'Offline' ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>Tabellen</span><span class="badge bg-<?= ($stats['tables_exist'] ?? false) ? 'success' : 'warning' ?>"><?= ($stats['tables_exist'] ?? false) ? 'Aanwezig' : 'Ontbreekt' ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>Admin credentials</span><span class="badge bg-<?= $credentialsOutsideWebRoot ? 'success' : 'warning' ?>"><?= $credentialsOutsideWebRoot ? 'Buiten webroot' : 'Legacy locatie' ?></span></div>
                                <div class="status-row d-flex justify-content-between"><span>Cron webtoken</span><span class="badge bg-<?= $cronTokenConfigured ? 'success' : 'warning' ?>"><?= $cronTokenConfigured ? 'Aanwezig' : 'Ontbreekt' ?></span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="admin-card">
                <div class="card-body">
                    <h2 class="h5 mb-3"><i class="bi bi-star text-primary me-2"></i>Favorieten</h2>
                    <form class="favorite-row mb-3" method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label" for="new_chargepoint_name">Laadpaal</label>
                                <input class="form-control mono" id="new_chargepoint_name" name="chargepoint_name" placeholder="EB4429" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label" for="new_display_name">Label</label>
                                <input class="form-control" id="new_display_name" name="display_name" placeholder="Meidoornhof 81 Borne" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="new_sort_order">Volgorde</label>
                                <input class="form-control" id="new_sort_order" name="sort_order" type="number" value="0">
                            </div>
                            <div class="col-md-1">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="new_active" name="is_active" value="1" checked>
                                    <label class="form-check-label" for="new_active">Aan</label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-primary w-100" type="submit" name="action" value="favorite_save">Toevoegen</button>
                            </div>
                        </div>
                    </form>

                    <?php if ($favorites): ?>
                        <?php foreach ($favorites as $favorite): ?>
                            <?php
                            $favoriteId = (int)$favorite['id'];
                            $latest = $latestByFavorite[$favoriteId] ?? null;
                            ?>
                            <form class="favorite-row" method="post">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="id" value="<?= h($favoriteId) ?>">
                                <div class="row g-2 align-items-end">
                                    <div class="col-lg-2">
                                        <label class="form-label" for="chargepoint_<?= h($favoriteId) ?>">Laadpaal</label>
                                        <input class="form-control mono" id="chargepoint_<?= h($favoriteId) ?>" name="chargepoint_name" value="<?= h($favorite['chargepoint_name']) ?>" required>
                                    </div>
                                    <div class="col-lg-3">
                                        <label class="form-label" for="display_<?= h($favoriteId) ?>">Label</label>
                                        <input class="form-control" id="display_<?= h($favoriteId) ?>" name="display_name" value="<?= h($favorite['display_name']) ?>" required>
                                    </div>
                                    <div class="col-lg-1">
                                        <label class="form-label" for="sort_<?= h($favoriteId) ?>">Volgorde</label>
                                        <input class="form-control" id="sort_<?= h($favoriteId) ?>" name="sort_order" type="number" value="<?= h($favorite['sort_order']) ?>">
                                    </div>
                                    <div class="col-lg-1">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="active_<?= h($favoriteId) ?>" name="is_active" value="1" <?= (int)$favorite['is_active'] === 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="active_<?= h($favoriteId) ?>">Aan</label>
                                        </div>
                                    </div>
                                    <div class="col-lg-2 small">
                                        <div>Status</div>
                                        <?php if ($latest): ?>
                                            <span class="badge bg-secondary"><?= h(Status::label((string)$latest['status'])) ?></span>
                                            <div class="text-muted"><?= h(formatDateTime($latest['measured_at'])) ?></div>
                                        <?php else: ?>
                                            <span class="text-muted">Nog geen meting</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-lg-3">
                                        <div class="btn-group w-100" role="group">
                                            <button class="btn btn-outline-primary" type="submit" name="action" value="favorite_save">Opslaan</button>
                                            <?php if ((int)$favorite['is_active'] === 1): ?>
                                                <button class="btn btn-outline-warning" type="submit" name="action" value="favorite_disable" formnovalidate>Uit</button>
                                            <?php else: ?>
                                                <button class="btn btn-outline-success" type="submit" name="action" value="favorite_enable" formnovalidate>Aan</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted">Nog geen favorieten.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-card">
                <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                        <div>
                            <h2 class="h5 mb-1"><i class="bi bi-clock-history text-primary me-2"></i>Cronplanningen</h2>
                            <div class="small text-muted">Database-managed jobs. Laat de server elke minuut <code>cron.php</code> aanroepen.</div>
                        </div>
                    </div>

                    <form class="cron-row mb-3" method="post">
                        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                        <div class="row g-2 align-items-end">
                            <div class="col-md-3">
                                <label class="form-label" for="new_cron_name">Naam</label>
                                <input class="form-control" id="new_cron_name" name="name" value="Poll InCharge availability" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label" for="new_cron_task">Taak</label>
                                <select class="form-select" id="new_cron_task" name="task">
                                    <?php foreach ($cronTaskOptions as $taskKey => $taskLabel): ?>
                                        <option value="<?= h($taskKey) ?>"><?= h($taskLabel) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label" for="new_cron_schedule">Planning</label>
                                <input class="form-control" id="new_cron_schedule" name="schedule" value="*/5 * * * *" required>
                            </div>
                            <div class="col-md-2">
                                <div class="form-check mb-2">
                                    <input class="form-check-input" type="checkbox" id="new_cron_enabled" name="enabled" value="1">
                                    <label class="form-check-label" for="new_cron_enabled">Aan</label>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <button class="btn btn-primary w-100" type="submit" name="action" value="cron_save">Toevoegen</button>
                            </div>
                        </div>
                        <div class="small text-muted mt-2">Voorbeelden: <code>*/5 * * * *</code>, <code>@hourly</code>, <code>@daily</code>.</div>
                    </form>

                    <?php if ($cronJobs): ?>
                        <?php foreach ($cronJobs as $job): ?>
                            <form class="cron-row" method="post">
                                <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                                <input type="hidden" name="id" value="<?= h($job['id']) ?>">
                                <div class="row g-2 align-items-end">
                                    <div class="col-lg-3">
                                        <label class="form-label" for="cron_name_<?= h($job['id']) ?>">Naam</label>
                                        <input class="form-control" id="cron_name_<?= h($job['id']) ?>" name="name" value="<?= h($job['name']) ?>" required>
                                    </div>
                                    <div class="col-lg-3">
                                        <label class="form-label" for="cron_task_<?= h($job['id']) ?>">Taak</label>
                                        <select class="form-select" id="cron_task_<?= h($job['id']) ?>" name="task">
                                            <?php foreach ($cronTaskOptions as $taskKey => $taskLabel): ?>
                                                <option value="<?= h($taskKey) ?>" <?= $job['task'] === $taskKey ? 'selected' : '' ?>><?= h($taskLabel) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-lg-2">
                                        <label class="form-label" for="cron_schedule_<?= h($job['id']) ?>">Planning</label>
                                        <input class="form-control" id="cron_schedule_<?= h($job['id']) ?>" name="schedule" value="<?= h($job['schedule']) ?>" required>
                                    </div>
                                    <div class="col-lg-1">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input" type="checkbox" id="cron_enabled_<?= h($job['id']) ?>" name="enabled" value="1" <?= (int)$job['enabled'] === 1 ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="cron_enabled_<?= h($job['id']) ?>">Aan</label>
                                        </div>
                                    </div>
                                    <div class="col-lg-3">
                                        <div class="btn-group w-100" role="group">
                                            <button class="btn btn-outline-primary" type="submit" name="action" value="cron_save">Opslaan</button>
                                            <button class="btn btn-outline-success" type="submit" name="action" value="cron_run">Nu</button>
                                            <button class="btn btn-outline-danger" type="submit" name="action" value="cron_delete" formnovalidate onclick="return confirm('Cronplanning verwijderen?');">Weg</button>
                                        </div>
                                    </div>
                                </div>
                                <div class="small text-muted mt-2">
                                    Laatste run: <?= h($job['last_run_at'] ?: '-') ?>,
                                    status:
                                    <span class="badge bg-<?= ($job['last_status'] ?? '') === 'success' ? 'success' : (($job['last_status'] ?? '') === 'failed' ? 'danger' : 'secondary') ?>">
                                        <?= h($job['last_status'] ?: 'nooit') ?>
                                    </span>
                                    <?php if (!empty($job['last_message'])): ?>
                                        <span class="ms-1"><?= h($job['last_message']) ?></span>
                                    <?php endif; ?>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted">Nog geen cronplanningen.</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="admin-card">
                <div class="card-body">
                    <h2 class="h5 mb-3"><i class="bi bi-list-ul text-primary me-2"></i>Laatste acties</h2>
                    <?php if ($activity): ?>
                        <?php foreach ($activity as $item): ?>
                            <div class="status-row mb-2">
                                <div class="d-flex justify-content-between gap-2">
                                    <strong><?= h($item['action']) ?></strong>
                                    <span class="badge bg-<?= $item['success'] ? 'success' : 'danger' ?>"><?= h($item['time']) ?></span>
                                </div>
                                <?php foreach (($item['messages'] ?? []) as $message): ?>
                                    <div class="small text-muted"><?= h($message) ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="text-muted">Nog geen acties in deze sessie.</div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
</body>
</html>
