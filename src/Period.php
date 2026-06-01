<?php

declare(strict_types=1);

final class Period
{
    public static function fromRequest(array $query, string $default = '24h'): array
    {
        $to = self::parseDateTime($query['to'] ?? null) ?? new DateTimeImmutable('now');

        if (!empty($query['from'])) {
            $from = self::parseDateTime($query['from']);
            if (!$from) {
                throw new InvalidArgumentException('Invalid from date. Use YYYY-MM-DD or YYYY-MM-DD HH:MM:SS.');
            }

            return self::build('custom', $from, $to);
        }

        $period = trim((string)($query['period'] ?? $default));

        if ($period === '') {
            $period = $default;
        }

        if ($period === 'today') {
            return self::build('today', $to->setTime(0, 0), $to);
        }

        if (!preg_match('/^(\d+)(m|h|d|w)$/', $period, $matches)) {
            throw new InvalidArgumentException('Invalid period. Use values like 30m, 24h, 7d or 4w.');
        }

        $amount = (int)$matches[1];
        $unit = $matches[2];

        if ($amount < 1 || $amount > 365) {
            throw new InvalidArgumentException('Invalid period length.');
        }

        switch ($unit) {
            case 'm':
                $seconds = $amount * 60;
                break;

            case 'h':
                $seconds = $amount * 3600;
                break;

            case 'd':
                $seconds = $amount * 86400;
                break;

            case 'w':
                $seconds = $amount * 604800;
                break;

            default:
                throw new InvalidArgumentException('Invalid period.');
        }

        return self::build($period, $to->modify('-' . $seconds . ' seconds'), $to);
    }

    private static function build(string $key, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        if ($from > $to) {
            throw new InvalidArgumentException('The from date must be before the to date.');
        }

        return [
            'key' => $key,
            'from' => $from,
            'to' => $to,
            'from_sql' => $from->format('Y-m-d H:i:s'),
            'to_sql' => $to->format('Y-m-d H:i:s'),
            'from_iso' => $from->format(DATE_ATOM),
            'to_iso' => $to->format(DATE_ATOM),
            'seconds' => max(0, $to->getTimestamp() - $from->getTimestamp()),
        ];
    }

    private static function parseDateTime($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        $formats = ['Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d'];

        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable) {
                return $format === 'Y-m-d' ? $date->setTime(0, 0) : $date;
            }
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Throwable $e) {
            return null;
        }
    }
}
