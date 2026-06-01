<?php

declare(strict_types=1);

final class CronScheduleService
{
    private $pdo;
    private $poller;
    private $snapshots;
    private $config;

    public function __construct(PDO $pdo, InChargePoller $poller, SnapshotRepository $snapshots, array $config)
    {
        $this->pdo = $pdo;
        $this->poller = $poller;
        $this->snapshots = $snapshots;
        $this->config = $config;
        $this->ensureTable();
        Schema::seedDefaultCronJob($this->pdo);
    }

    public static function taskOptions(): array
    {
        return [
            'poll' => 'Laadpaalstatus ophalen',
            'poll_cleanup' => 'Ophalen en oude snapshots opschonen',
            'cleanup' => 'Oude snapshots opschonen',
        ];
    }

    public function listJobs(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM incharge_cron_jobs ORDER BY enabled DESC, name ASC');

        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function saveJob(array $data): array
    {
        $id = isset($data['id']) ? (int)$data['id'] : 0;
        $name = trim((string)($data['name'] ?? ''));
        $task = (string)($data['task'] ?? '');
        $schedule = $this->normalizeSchedule((string)($data['schedule'] ?? ''));
        $enabled = !empty($data['enabled']) ? 1 : 0;

        if ($name === '') {
            throw new InvalidArgumentException('Cronnaam is verplicht.');
        }

        if (!array_key_exists($task, self::taskOptions())) {
            throw new InvalidArgumentException('Onbekende crontaak.');
        }

        if (!$this->isValidSchedule($schedule)) {
            throw new InvalidArgumentException('Gebruik een 5-delige cronplanning, bijvoorbeeld "*/5 * * * *".');
        }

        if ($id > 0) {
            $stmt = $this->pdo->prepare('
                UPDATE incharge_cron_jobs
                SET name = :name,
                    task = :task,
                    schedule = :schedule,
                    enabled = :enabled,
                    updated_at = NOW()
                WHERE id = :id
            ');
            $stmt->execute([
                ':name' => $name,
                ':task' => $task,
                ':schedule' => $schedule,
                ':enabled' => $enabled,
                ':id' => $id,
            ]);

            return ['success' => true, 'messages' => ['Cronplanning opgeslagen.']];
        }

        $stmt = $this->pdo->prepare('
            INSERT INTO incharge_cron_jobs
                (name, task, schedule, enabled, created_at, updated_at)
            VALUES
                (:name, :task, :schedule, :enabled, NOW(), NOW())
        ');
        $stmt->execute([
            ':name' => $name,
            ':task' => $task,
            ':schedule' => $schedule,
            ':enabled' => $enabled,
        ]);

        return ['success' => true, 'messages' => ['Cronplanning toegevoegd.']];
    }

    public function deleteJob(int $id): array
    {
        $stmt = $this->pdo->prepare('DELETE FROM incharge_cron_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return ['success' => true, 'messages' => ['Cronplanning verwijderd.']];
    }

    public function runJob(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM incharge_cron_jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$job) {
            throw new InvalidArgumentException('Cronplanning niet gevonden.');
        }

        return $this->executeJob($job);
    }

    public function runDueJobs(DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $results = [];

        foreach ($this->listJobs() as $job) {
            if ((int)$job['enabled'] !== 1) {
                continue;
            }

            if (!$this->isDue($job, $now)) {
                continue;
            }

            $results[] = $this->executeJob($job, $now);
        }

        return $results;
    }

    private function ensureTable()
    {
        $this->pdo->exec('
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
        ');
    }

    private function executeJob(array $job, DateTimeImmutable $now = null): array
    {
        $now = $now ?: new DateTimeImmutable('now');
        $messages = [];
        $success = false;

        try {
            if ($job['task'] === 'poll') {
                $result = $this->poller->pollActiveFavorites();
                $success = (bool)$result['success'];
                $messages = $result['messages'] ?? [];
            } elseif ($job['task'] === 'poll_cleanup') {
                $result = $this->poller->pollActiveFavorites();
                $success = (bool)$result['success'];
                $messages = $result['messages'] ?? [];
                $deleted = $this->cleanupOldSnapshots();
                $messages[] = $deleted . ' oude snapshots verwijderd';
            } elseif ($job['task'] === 'cleanup') {
                $deleted = $this->cleanupOldSnapshots();
                $success = true;
                $messages[] = $deleted . ' oude snapshots verwijderd';
            } else {
                throw new InvalidArgumentException('Onbekende crontaak.');
            }
        } catch (Throwable $e) {
            $success = false;
            $messages[] = $e->getMessage();
        }

        $messageText = implode(' | ', $messages);
        $stmt = $this->pdo->prepare('
            UPDATE incharge_cron_jobs
            SET last_run_at = :last_run_at,
                last_status = :last_status,
                last_message = :last_message,
                updated_at = NOW()
            WHERE id = :id
        ');
        $stmt->execute([
            ':last_run_at' => $now->format('Y-m-d H:i:s'),
            ':last_status' => $success ? 'success' : 'failed',
            ':last_message' => $messageText,
            ':id' => (int)$job['id'],
        ]);

        return [
            'id' => (int)$job['id'],
            'name' => $job['name'],
            'task' => $job['task'],
            'success' => $success,
            'messages' => $messages ?: ['Crontaak afgerond.'],
        ];
    }

    private function cleanupOldSnapshots(): int
    {
        $days = max(1, (int)($this->config['cron']['retention_days'] ?? 90));

        return $this->snapshots->deleteOlderThan(new DateTimeImmutable('-' . $days . ' days'));
    }

    private function normalizeSchedule(string $schedule): string
    {
        $schedule = trim((string)preg_replace('/\s+/', ' ', $schedule));
        $aliases = [
            '@hourly' => '0 * * * *',
            '@daily' => '15 8 * * *',
            '@weekly' => '15 8 * * 1',
            '@monthly' => '15 8 1 * *',
        ];

        return $aliases[$schedule] ?? $schedule;
    }

    private function isValidSchedule(string $schedule): bool
    {
        $parts = explode(' ', $schedule);

        if (count($parts) !== 5) {
            return false;
        }

        $ranges = [
            [0, 59],
            [0, 23],
            [1, 31],
            [1, 12],
            [0, 7],
        ];

        foreach ($parts as $index => $part) {
            if (!$this->isValidCronField($part, $ranges[$index][0], $ranges[$index][1])) {
                return false;
            }
        }

        return true;
    }

    private function isValidCronField(string $field, int $min, int $max): bool
    {
        foreach (explode(',', $field) as $segment) {
            if ($segment === '*') {
                continue;
            }

            if (preg_match('/^\*\/(\d+)$/', $segment, $match)) {
                $step = (int)$match[1];
                if ($step < 1 || $step > $max) {
                    return false;
                }
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)$/', $segment, $match)) {
                $start = (int)$match[1];
                $end = (int)$match[2];
                if ($start < $min || $end > $max || $start > $end) {
                    return false;
                }
                continue;
            }

            if (!ctype_digit($segment)) {
                return false;
            }

            $value = (int)$segment;
            if ($value < $min || $value > $max) {
                return false;
            }
        }

        return true;
    }

    private function isDue(array $job, DateTimeImmutable $now): bool
    {
        if (!$this->scheduleMatches((string)$job['schedule'], $now)) {
            return false;
        }

        if (empty($job['last_run_at'])) {
            return true;
        }

        $lastRun = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', (string)$job['last_run_at']);

        return !$lastRun || $lastRun->format('Y-m-d H:i') !== $now->format('Y-m-d H:i');
    }

    private function scheduleMatches(string $schedule, DateTimeImmutable $date): bool
    {
        $parts = explode(' ', $schedule);

        if (count($parts) !== 5) {
            return false;
        }

        $values = [
            (int)$date->format('i'),
            (int)$date->format('G'),
            (int)$date->format('j'),
            (int)$date->format('n'),
            (int)$date->format('w'),
        ];

        foreach ($parts as $index => $field) {
            if (!$this->fieldMatches($field, $values[$index])) {
                if ($index === 4 && $values[$index] === 0 && $this->fieldMatches($field, 7)) {
                    continue;
                }

                return false;
            }
        }

        return true;
    }

    private function fieldMatches(string $field, int $value): bool
    {
        foreach (explode(',', $field) as $segment) {
            if ($segment === '*') {
                return true;
            }

            if (preg_match('/^\*\/(\d+)$/', $segment, $match)) {
                if ($value % (int)$match[1] === 0) {
                    return true;
                }
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)$/', $segment, $match)) {
                if ($value >= (int)$match[1] && $value <= (int)$match[2]) {
                    return true;
                }
                continue;
            }

            if (ctype_digit($segment) && (int)$segment === $value) {
                return true;
            }
        }

        return false;
    }
}
