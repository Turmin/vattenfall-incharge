<?php

declare(strict_types=1);

final class InChargePoller
{
    private $favorites;
    private $snapshots;
    private $client;

    public function __construct(FavoriteRepository $favorites, SnapshotRepository $snapshots, InChargeClient $client)
    {
        $this->favorites = $favorites;
        $this->snapshots = $snapshots;
        $this->client = $client;
    }

    public function pollActiveFavorites(): array
    {
        $favorites = $this->favorites->allActive();
        $checkedAt = date('Y-m-d H:i:s');
        $items = [];
        $successCount = 0;
        $errorCount = 0;

        foreach ($favorites as $favorite) {
            $favoriteId = (int)$favorite['id'];
            $chargepointName = (string)$favorite['chargepoint_name'];

            try {
                $status = $this->client->getChargepointStatus($chargepointName);

                $this->snapshots->insert([
                    'favorite_id' => $favoriteId,
                    'measured_at' => $checkedAt,
                    'status' => $status['status'],
                    'available_connectors' => $status['available_connectors'],
                    'occupied_connectors' => $status['occupied_connectors'],
                    'total_connectors' => $status['total_connectors'],
                    'raw_json' => json_encode($status['raw'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);

                $successCount++;
                $items[] = [
                    'favorite_id' => $favoriteId,
                    'chargepoint_name' => $chargepointName,
                    'success' => true,
                    'status' => $status['status'],
                    'message' => Status::label($status['status']),
                ];
            } catch (Throwable $e) {
                $errorCount++;
                $items[] = [
                    'favorite_id' => $favoriteId,
                    'chargepoint_name' => $chargepointName,
                    'success' => false,
                    'status' => null,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'success' => $errorCount === 0,
            'checked_at' => date(DATE_ATOM, strtotime($checkedAt)),
            'total' => count($favorites),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'items' => $items,
            'messages' => [
                $successCount . ' laadpalen bijgewerkt',
                $errorCount . ' niet bijgewerkt',
            ],
        ];
    }
}
