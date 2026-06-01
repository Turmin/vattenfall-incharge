<?php

declare(strict_types=1);

final class SnapshotRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function insert(array $snapshot)
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO incharge_chargepoint_snapshots
                (
                    favorite_id,
                    measured_at,
                    status,
                    status_bucket,
                    available_connectors,
                    occupied_connectors,
                    total_connectors,
                    raw_json
                )
             VALUES
                (
                    :favorite_id,
                    :measured_at,
                    :status,
                    :status_bucket,
                    :available_connectors,
                    :occupied_connectors,
                    :total_connectors,
                    :raw_json
                )"
        );

        $status = Status::normalize((string)($snapshot['status'] ?? 'UNKNOWN'));

        $stmt->execute([
            ':favorite_id' => (int)$snapshot['favorite_id'],
            ':measured_at' => (string)$snapshot['measured_at'],
            ':status' => $status,
            ':status_bucket' => Status::bucket($status),
            ':available_connectors' => $snapshot['available_connectors'] ?? null,
            ':occupied_connectors' => $snapshot['occupied_connectors'] ?? null,
            ':total_connectors' => $snapshot['total_connectors'] ?? null,
            ':raw_json' => $snapshot['raw_json'] ?? null,
        ]);
    }

    public function latestForFavorites(): array
    {
        $sql = "
            SELECT s.*
            FROM incharge_chargepoint_snapshots s
            INNER JOIN (
                SELECT favorite_id, MAX(id) AS latest_id
                FROM incharge_chargepoint_snapshots
                GROUP BY favorite_id
            ) latest ON latest.latest_id = s.id
        ";

        $rows = $this->pdo->query($sql)->fetchAll();
        $now = time();

        foreach ($rows as &$row) {
            if ((string)($row['status_bucket'] ?? '') === 'unknown') {
                $known = $this->latestKnownForFavorite((int)$row['favorite_id']);

                if (is_array($known)) {
                    $row = $known;
                }
            }

            $state = $this->statusSince((int)$row['favorite_id'], (int)$row['id'], (string)$row['status_bucket']);
            $row['status_since'] = $state['status_since'];
            $row['seconds_in_current_status'] = max(0, $now - strtotime((string)$state['status_since']));
        }
        unset($row);

        return $rows;
    }

    public function latestForFavorite(int $favoriteId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM incharge_chargepoint_snapshots
             WHERE favorite_id = :favorite_id
             ORDER BY measured_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([':favorite_id' => $favoriteId]);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            return null;
        }

        if ((string)($row['status_bucket'] ?? '') === 'unknown') {
            $known = $this->latestKnownForFavorite((int)$row['favorite_id']);

            if (is_array($known)) {
                $row = $known;
            }
        }

        $state = $this->statusSince((int)$row['favorite_id'], (int)$row['id'], (string)$row['status_bucket']);
        $row['status_since'] = $state['status_since'];
        $row['seconds_in_current_status'] = max(0, time() - strtotime((string)$state['status_since']));

        return $row;
    }

    public function history(int $favoriteId, string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, measured_at, status, status_bucket, available_connectors, occupied_connectors, total_connectors
             FROM incharge_chargepoint_snapshots
             WHERE favorite_id = :favorite_id
               AND measured_at >= :from_date
               AND measured_at <= :to_date
             ORDER BY measured_at ASC, id ASC"
        );

        $stmt->execute([
            ':favorite_id' => $favoriteId,
            ':from_date' => $from,
            ':to_date' => $to,
        ]);

        return $stmt->fetchAll();
    }

    public function availability(int $favoriteId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $fromSql = $from->format('Y-m-d H:i:s');
        $toSql = $to->format('Y-m-d H:i:s');

        $points = [];
        $previous = $this->latestBefore($favoriteId, $fromSql);

        if ($previous) {
            $previous['measured_at'] = $fromSql;
            $previous['synthetic'] = true;
            $points[] = $previous;
        }

        foreach ($this->history($favoriteId, $fromSql, $toSql) as $row) {
            $row['synthetic'] = false;
            $points[] = $row;
        }

        if ($points === []) {
            return $this->emptyAvailability($from, $to);
        }

        $segments = [];
        $summary = $this->emptySummary();
        $periodSeconds = max(0, $to->getTimestamp() - $from->getTimestamp());
        $firstPointTime = strtotime((string)$points[0]['measured_at']);

        if (!$previous && $firstPointTime > $from->getTimestamp()) {
            $unknownDuration = min($firstPointTime, $to->getTimestamp()) - $from->getTimestamp();

            if ($unknownDuration > 0) {
                $summary['unknown']['seconds'] += $unknownDuration;
                $this->appendAvailabilitySegment($segments, [
                    'from' => $from->format(DATE_ATOM),
                    'to' => date(DATE_ATOM, min($firstPointTime, $to->getTimestamp())),
                    'status' => 'UNKNOWN',
                    'status_label' => Status::label('UNKNOWN'),
                    'status_bucket' => 'unknown',
                    'duration_seconds' => $unknownDuration,
                ]);
            }
        }

        for ($index = 0, $count = count($points); $index < $count; $index++) {
            $point = $points[$index];
            $segmentStart = max($from->getTimestamp(), strtotime((string)$point['measured_at']));
            $segmentEnd = $index + 1 < $count
                ? min($to->getTimestamp(), strtotime((string)$points[$index + 1]['measured_at']))
                : $to->getTimestamp();

            if ($segmentEnd <= $segmentStart) {
                continue;
            }

            $bucket = Status::bucket((string)$point['status']);
            $duration = $segmentEnd - $segmentStart;

            $summary[$bucket]['seconds'] += $duration;

            $this->appendAvailabilitySegment($segments, [
                'from' => date(DATE_ATOM, $segmentStart),
                'to' => date(DATE_ATOM, $segmentEnd),
                'status' => Status::normalize((string)$point['status']),
                'status_label' => Status::label((string)$point['status']),
                'status_bucket' => $bucket,
                'duration_seconds' => $duration,
            ]);
        }

        foreach ($summary as &$item) {
            $item['percentage'] = $periodSeconds > 0 ? round(($item['seconds'] / $periodSeconds) * 100, 1) : 0.0;
        }
        unset($item);

        return [
            'summary' => $summary,
            'segments' => $segments,
            'measurements' => array_map([$this, 'formatPoint'], $points),
        ];
    }

    public function deleteOlderThan(DateTimeImmutable $threshold): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM incharge_chargepoint_snapshots
             WHERE measured_at < :threshold"
        );
        $stmt->execute([':threshold' => $threshold->format('Y-m-d H:i:s')]);

        return $stmt->rowCount();
    }

    private function latestBefore(int $favoriteId, string $before)
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, measured_at, status, status_bucket, available_connectors, occupied_connectors, total_connectors
             FROM incharge_chargepoint_snapshots
             WHERE favorite_id = :favorite_id
               AND measured_at < :before_date
             ORDER BY measured_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([
            ':favorite_id' => $favoriteId,
            ':before_date' => $before,
        ]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function latestKnownForFavorite(int $favoriteId)
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM incharge_chargepoint_snapshots
             WHERE favorite_id = :favorite_id
               AND status_bucket <> 'unknown'
             ORDER BY measured_at DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([':favorite_id' => $favoriteId]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    private function statusSince(int $favoriteId, int $latestId, string $currentBucket): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, measured_at, status_bucket
             FROM incharge_chargepoint_snapshots
             WHERE favorite_id = :favorite_id
               AND id <= :latest_id
             ORDER BY measured_at DESC, id DESC
             LIMIT 1000"
        );
        $stmt->execute([
            ':favorite_id' => $favoriteId,
            ':latest_id' => $latestId,
        ]);

        $statusSince = null;

        foreach ($stmt->fetchAll() as $row) {
            if ((string)$row['status_bucket'] !== $currentBucket) {
                break;
            }

            $statusSince = (string)$row['measured_at'];
        }

        return [
            'status_since' => $statusSince ?: date('Y-m-d H:i:s'),
        ];
    }

    private function emptyAvailability(DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $seconds = max(0, $to->getTimestamp() - $from->getTimestamp());
        $summary = $this->emptySummary();
        $summary['unknown']['seconds'] = $seconds;
        $summary['unknown']['percentage'] = $seconds > 0 ? 100.0 : 0.0;

        return [
            'summary' => $summary,
            'segments' => [[
                'from' => $from->format(DATE_ATOM),
                'to' => $to->format(DATE_ATOM),
                'status' => 'UNKNOWN',
                'status_label' => Status::label('UNKNOWN'),
                'status_bucket' => 'unknown',
                'duration_seconds' => $seconds,
            ]],
            'measurements' => [],
        ];
    }

    private function appendAvailabilitySegment(array &$segments, array $segment)
    {
        $lastIndex = count($segments) - 1;

        if (
            $lastIndex >= 0
            && (string)$segments[$lastIndex]['to'] === (string)$segment['from']
            && (string)$segments[$lastIndex]['status_bucket'] === (string)$segment['status_bucket']
            && (string)$segments[$lastIndex]['status_label'] === (string)$segment['status_label']
        ) {
            $segments[$lastIndex]['to'] = $segment['to'];
            $segments[$lastIndex]['duration_seconds'] += $segment['duration_seconds'];
            return;
        }

        $segments[] = $segment;
    }

    private function emptySummary(): array
    {
        $summary = [];

        foreach (['available', 'occupied', 'faulted', 'unknown'] as $bucket) {
            $summary[$bucket] = [
                'seconds' => 0,
                'percentage' => 0.0,
            ];
        }

        return $summary;
    }

    private function formatPoint(array $point): array
    {
        return [
            'measured_at' => date(DATE_ATOM, strtotime((string)$point['measured_at'])),
            'status' => Status::normalize((string)$point['status']),
            'status_label' => Status::label((string)$point['status']),
            'status_bucket' => Status::bucket((string)$point['status']),
            'available_connectors' => $point['available_connectors'] ?? null,
            'occupied_connectors' => $point['occupied_connectors'] ?? null,
            'total_connectors' => $point['total_connectors'] ?? null,
            'synthetic' => (bool)($point['synthetic'] ?? false),
        ];
    }
}
