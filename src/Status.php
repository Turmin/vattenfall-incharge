<?php

declare(strict_types=1);

final class Status
{
    public static function normalize(?string $status): string
    {
        $status = strtoupper(trim((string)$status));
        $status = str_replace(['-', ' '], '_', $status);

        if ($status === '') {
            return 'UNKNOWN';
        }

        return match ($status) {
            'FREE' => 'AVAILABLE',
            'BUSY', 'IN_USE', 'INUSE' => 'OCCUPIED',
            'ERROR', 'FAULT', 'OUTOFORDER', 'OUT_OF_SERVICE', 'OUTOFSERVICE' => 'OUT_OF_ORDER',
            default => $status,
        };
    }

    public static function bucket(?string $status): string
    {
        return match (self::normalize($status)) {
            'AVAILABLE' => 'available',
            'OCCUPIED', 'CHARGING', 'BLOCKED' => 'occupied',
            'OUT_OF_ORDER', 'FAULTED', 'UNAVAILABLE' => 'faulted',
            default => 'unknown',
        };
    }

    public static function label(?string $status): string
    {
        return match (self::normalize($status)) {
            'AVAILABLE' => 'Vrij',
            'OCCUPIED' => 'Bezet',
            'CHARGING' => 'Aan het laden',
            'OUT_OF_ORDER', 'FAULTED' => 'Buiten gebruik',
            default => 'Onbekend',
        };
    }

    public static function connectorCountsFromStation(?array $station, string $stationStatus): array
    {
        $connectorStatuses = [];

        if (is_array($station)) {
            self::collectConnectorStatuses($station, $connectorStatuses);
        }

        $available = 0;
        $occupied = 0;
        $faulted = 0;

        foreach ($connectorStatuses as $connectorStatus) {
            $bucket = self::bucket($connectorStatus);

            if ($bucket === 'available') {
                $available++;
            } elseif ($bucket === 'occupied') {
                $occupied++;
            } elseif ($bucket === 'faulted') {
                $faulted++;
            }
        }

        $total = count($connectorStatuses);

        if ($total === 0) {
            $bucket = self::bucket($stationStatus);

            return [
                'available_connectors' => $bucket === 'available' ? 1 : 0,
                'occupied_connectors' => $bucket === 'occupied' ? 1 : 0,
                'total_connectors' => in_array($bucket, ['available', 'occupied', 'faulted'], true) ? 1 : null,
            ];
        }

        return [
            'available_connectors' => $available,
            'occupied_connectors' => $occupied,
            'total_connectors' => $total,
        ];
    }

    public static function cssClass(?string $status): string
    {
        return 'status-' . self::bucket($status);
    }

    private static function collectConnectorStatuses(array $node, array &$statuses, int $depth = 0): void
    {
        if ($depth > 5) {
            return;
        }

        foreach (['connectors', 'connectorStatuses', 'evses', 'sockets', 'outlets'] as $key) {
            if (!isset($node[$key]) || !is_array($node[$key])) {
                continue;
            }

            foreach ($node[$key] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                foreach (['status', 'state', 'availability'] as $statusKey) {
                    if (isset($item[$statusKey]) && is_string($item[$statusKey])) {
                        $statuses[] = self::normalize($item[$statusKey]);
                        break;
                    }
                }

                self::collectConnectorStatuses($item, $statuses, $depth + 1);
            }
        }
    }
}
