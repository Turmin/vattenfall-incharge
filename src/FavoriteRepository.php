<?php

declare(strict_types=1);

final class FavoriteRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function allActive(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, chargepoint_name, display_name, is_active, sort_order, created_at, updated_at
             FROM incharge_favorite_chargepoints
             WHERE is_active = 1
             ORDER BY sort_order ASC, display_name ASC, chargepoint_name ASC"
        );

        return $stmt->fetchAll();
    }

    public function all(): array
    {
        $stmt = $this->pdo->query(
            "SELECT id, chargepoint_name, display_name, is_active, sort_order, created_at, updated_at
             FROM incharge_favorite_chargepoints
             ORDER BY is_active DESC, sort_order ASC, display_name ASC, chargepoint_name ASC"
        );

        return $stmt->fetchAll();
    }

    public function find(int $id)
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, chargepoint_name, display_name, is_active, sort_order, created_at, updated_at
             FROM incharge_favorite_chargepoints
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    public function create(string $chargepointName, string $displayName, int $sortOrder = 0): int
    {
        $chargepointName = self::normalizeChargepointName($chargepointName);
        $displayName = trim($displayName) !== '' ? trim($displayName) : $chargepointName;

        $stmt = $this->pdo->prepare(
            "INSERT INTO incharge_favorite_chargepoints
                (chargepoint_name, display_name, is_active, sort_order, created_at, updated_at)
             VALUES
                (:chargepoint_name, :display_name, 1, :sort_order, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                display_name = VALUES(display_name),
                is_active = 1,
                sort_order = VALUES(sort_order),
                updated_at = NOW(),
                id = LAST_INSERT_ID(id)"
        );

        $stmt->execute([
            ':chargepoint_name' => $chargepointName,
            ':display_name' => $displayName,
            ':sort_order' => $sortOrder,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, string $chargepointName, string $displayName, int $sortOrder, bool $isActive)
    {
        $stmt = $this->pdo->prepare(
            "UPDATE incharge_favorite_chargepoints
             SET chargepoint_name = :chargepoint_name,
                 display_name = :display_name,
                 sort_order = :sort_order,
                 is_active = :is_active,
                 updated_at = NOW()
             WHERE id = :id"
        );

        $chargepointName = self::normalizeChargepointName($chargepointName);
        $displayName = trim($displayName) !== '' ? trim($displayName) : $chargepointName;

        $stmt->execute([
            ':chargepoint_name' => $chargepointName,
            ':display_name' => $displayName,
            ':sort_order' => $sortOrder,
            ':is_active' => $isActive ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public function setActive(int $id, bool $active)
    {
        $stmt = $this->pdo->prepare(
            "UPDATE incharge_favorite_chargepoints
             SET is_active = :is_active, updated_at = NOW()
             WHERE id = :id"
        );

        $stmt->execute([
            ':is_active' => $active ? 1 : 0,
            ':id' => $id,
        ]);
    }

    public function disable(int $id)
    {
        $this->setActive($id, false);
    }

    public function importFromJson(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('Favorites JSON not found: ' . $file);
        }

        $decoded = json_decode((string)file_get_contents($file), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Favorites JSON is invalid: ' . $file);
        }

        $imported = 0;
        $skipped = 0;
        $sortOrder = 0;

        foreach ($decoded as $favorite) {
            if (!is_array($favorite)) {
                $skipped++;
                continue;
            }

            $name = (string)($favorite['name'] ?? $favorite['chargepoint_name'] ?? '');
            $label = (string)($favorite['label'] ?? $favorite['display_name'] ?? $name);

            if (!self::isValidChargepointName($name)) {
                $skipped++;
                continue;
            }

            $this->create($name, $label, $sortOrder);
            $sortOrder += 10;
            $imported++;
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
        ];
    }

    public static function normalizeChargepointName(string $chargepointName): string
    {
        return strtoupper(trim($chargepointName));
    }

    public static function isValidChargepointName(string $chargepointName): bool
    {
        return (bool)preg_match('/^[A-Z0-9_-]{3,40}$/i', trim($chargepointName));
    }
}
