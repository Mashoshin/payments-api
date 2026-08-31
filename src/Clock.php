<?php

declare(strict_types=1);

namespace Payments;

/**
 * Источник времени. Отдаёт метку в формате ISO 8601 с таймзоной,
 * например 2026-08-31T12:00:00+03:00.
 */
final class Clock
{
    public static function now(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Moscow')))
            ->format(\DateTimeInterface::ATOM);
    }
}
