<?php

declare(strict_types=1);

final class ApiResponse
{
    public static function json(array $data, int $statusCode = 200): never
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function error(string $message, int $statusCode = 400, array $extra = []): never
    {
        self::json([
            'success' => false,
            'error' => [
                'code' => $statusCode,
                'message' => $message,
            ] + $extra,
            'timestamp' => date(DATE_ATOM),
        ], $statusCode);
    }

    public static function requireToken(array $config): void
    {
        $expected = trim((string)($config['api_token'] ?? ''));

        if ($expected === '') {
            self::error('API token is not configured.', 403);
        }

        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            self::error('Missing bearer token.', 401);
        }

        if (!hash_equals($expected, $matches[1])) {
            self::error('Invalid bearer token.', 403);
        }
    }
}
