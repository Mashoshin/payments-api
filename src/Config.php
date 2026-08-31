<?php

declare(strict_types=1);

namespace Payments;

/**
 * Конфигурация сервиса. Адреса соседей захардкожены по умолчанию,
 * но переопределяются переменными окружения.
 */
final class Config
{
    public function __construct(
        public readonly string $ledgerUrl,
        public readonly string $notificationsUrl,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            rtrim(self::env('LEDGER_URL', 'http://localhost:8082'), '/'),
            rtrim(self::env('NOTIFICATIONS_URL', 'http://localhost:8083'), '/'),
        );
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return ($value === false || $value === '') ? $default : $value;
    }
}
