<?php

declare(strict_types=1);

final class Schema
{
    public static function install(PDO $pdo): array
    {
        $messages = [];

        foreach (self::statements() as $label => $sql) {
            $pdo->exec($sql);
            $messages[] = $label . ' ok';
        }

        self::seedDefaultCronJob($pdo);
        $messages[] = 'Default cron job ok';

        return $messages;
    }

    public static function tablesExist(PDO $pdo): bool
    {
        foreach (['incharge_favorite_chargepoints', 'incharge_chargepoint_snapshots', 'incharge_cron_jobs'] as $table) {
            if (!self::tableExists($pdo, $table)) {
                return false;
            }
        }

        return true;
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare('
            SELECT COUNT(*)
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = :table_name
        ');
        $stmt->execute([':table_name' => $table]);

        return (int)$stmt->fetchColumn() > 0;
    }

    public static function stats(PDO $pdo): array
    {
        $stats = [
            'tables_exist' => self::tablesExist($pdo),
            'favorites' => 0,
            'active_favorites' => 0,
            'snapshots' => 0,
            'latest_snapshot_at' => null,
            'cron_jobs' => 0,
        ];

        if (self::tableExists($pdo, 'incharge_favorite_chargepoints')) {
            $row = $pdo->query('
                SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_total
                FROM incharge_favorite_chargepoints
            ')->fetch() ?: [];

            $stats['favorites'] = (int)($row['total'] ?? 0);
            $stats['active_favorites'] = (int)($row['active_total'] ?? 0);
        }

        if (self::tableExists($pdo, 'incharge_chargepoint_snapshots')) {
            $row = $pdo->query('
                SELECT COUNT(*) AS total, MAX(measured_at) AS latest_snapshot_at
                FROM incharge_chargepoint_snapshots
            ')->fetch() ?: [];

            $stats['snapshots'] = (int)($row['total'] ?? 0);
            $stats['latest_snapshot_at'] = $row['latest_snapshot_at'] ?? null;
        }

        if (self::tableExists($pdo, 'incharge_cron_jobs')) {
            $stats['cron_jobs'] = (int)$pdo->query('SELECT COUNT(*) FROM incharge_cron_jobs')->fetchColumn();
        }

        return $stats;
    }

    public static function seedDefaultCronJob(PDO $pdo)
    {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM incharge_cron_jobs')->fetchColumn();

        if ($count > 0) {
            return;
        }

        $stmt = $pdo->prepare('
            INSERT INTO incharge_cron_jobs
                (name, task, schedule, enabled, created_at, updated_at)
            VALUES
                (:name, :task, :schedule, 0, NOW(), NOW())
        ');
        $stmt->execute([
            ':name' => 'Poll InCharge availability',
            ':task' => 'poll',
            ':schedule' => '*/5 * * * *',
        ]);
    }

    private static function statements(): array
    {
        return [
            'Favorites table' => '
                CREATE TABLE IF NOT EXISTS incharge_favorite_chargepoints (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    chargepoint_name VARCHAR(40) NOT NULL,
                    display_name VARCHAR(160) NOT NULL,
                    is_active TINYINT(1) NOT NULL DEFAULT 1,
                    sort_order INT NOT NULL DEFAULT 0,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_chargepoint_name (chargepoint_name),
                    INDEX active_sort (is_active, sort_order, display_name)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ',
            'Snapshots table' => '
                CREATE TABLE IF NOT EXISTS incharge_chargepoint_snapshots (
                    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    favorite_id INT UNSIGNED NOT NULL,
                    measured_at DATETIME NOT NULL,
                    status VARCHAR(50) NOT NULL,
                    status_bucket VARCHAR(20) NOT NULL,
                    available_connectors INT NULL,
                    occupied_connectors INT NULL,
                    total_connectors INT NULL,
                    raw_json LONGTEXT NULL,
                    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_favorite_time (favorite_id, measured_at),
                    INDEX idx_favorite_status_time (favorite_id, status_bucket, measured_at),
                    INDEX idx_measured_at (measured_at),
                    CONSTRAINT fk_incharge_snapshots_favorite
                        FOREIGN KEY (favorite_id)
                        REFERENCES incharge_favorite_chargepoints(id)
                        ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ',
            'Cron table' => '
                CREATE TABLE IF NOT EXISTS incharge_cron_jobs (
                    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    name VARCHAR(120) NOT NULL,
                    task VARCHAR(40) NOT NULL,
                    schedule VARCHAR(40) NOT NULL,
                    enabled TINYINT(1) NOT NULL DEFAULT 0,
                    last_run_at DATETIME NULL,
                    last_status VARCHAR(20) NULL,
                    last_message TEXT NULL,
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    INDEX enabled_schedule (enabled, schedule),
                    INDEX last_run_at (last_run_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ',
        ];
    }
}
