<?php

declare(strict_types=1);

final class Database
{
    public static function connect(array $config): PDO
    {
        $db = $config['db'] ?? [];

        foreach (['dsn', 'user', 'password'] as $key) {
            if (!array_key_exists($key, $db) || $db[$key] === '') {
                $source = (string)($db['credentials_file'] ?? 'config/config.php');
                throw new RuntimeException('Database configuration is missing "' . $key . '" in ' . $source);
            }
        }

        return new PDO(
            (string)$db['dsn'],
            (string)$db['user'],
            (string)$db['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
}
