<?php

declare(strict_types=1);

namespace Ecotech\Chat;

final class Config
{
    public static function load(): void
    {
        $envPath = dirname(__DIR__);
        if (file_exists($envPath . '/.env')) {
            \Dotenv\Dotenv::createImmutable($envPath)->safeLoad();
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return (string) $value;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
