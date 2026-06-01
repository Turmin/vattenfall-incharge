<?php

declare(strict_types=1);

final class Status
{
    public static function normalize($status): string
    {
        $status = strtoupper(trim((string)$status));
        $status = str_replace(['-', ' '], '_', $status);

        if ($status === '') {
            return 'UNKNOWN';
        }

        switch ($status) {
            case 'FREE':
                return 'AVAILABLE';

            case 'BUSY':
            case 'IN_USE':
            case 'INUSE':
                return 'OCCUPIED';

            case 'ERROR':
            case 'FAULT':
            case 'OUTOFORDER':
            case 'OUT_OF_SERVICE':
            case 'OUTOFSERVICE':
                return 'OUT_OF_ORDER';

            default:
                return $status;
        }
    }

    public static function bucket($status): string
    {
        switch (self::normalize($status)) {
            case 'AVAILABLE':
                return 'available';

            case 'OCCUPIED':
            case 'CHARGING':
            case 'BLOCKED':
                return 'occupied';

            case 'OUT_OF_ORDER':
            case 'FAULTED':
            case 'UNAVAILABLE':
                return 'faulted';

            default:
                return 'unknown';
        }
    }

    public static function label($status): string
    {
        switch (self::normalize($status)) {
            case 'AVAILABLE':
                return 'Vrij';

            case 'OCCUPIED':
                return 'Bezet';

            case 'CHARGING':
                return 'Aan het laden';

            case 'OUT_OF_ORDER':
            case 'FAULTED':
                return 'Buiten gebruik';

            default:
                return 'Onbekend';
        }
    }

    public static function connectorCountsFromStation($station, string $stationStatus): array
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

    public static function cssClass($status): string
    {
        return 'status-' . self::bucket($status);
    }

    private static function collectConnectorStatuses(array $node, array &$statuses, int $depth = 0)
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
