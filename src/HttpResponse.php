<?php

declare(strict_types=1);

namespace Payments;

/**
 * Результат исходящего HTTP-вызова.
 *
 * @property-read int $status HTTP-статус; 0 — сервис недоступен.
 */
final class HttpResponse
{
    /**
     * @param array<string, mixed>|null $body распарсенное JSON-тело, если было
     */
    public function __construct(
        public readonly int $status,
        public readonly ?array $body,
    ) {
    }

    /**
     * Соединение установить не удалось (таймаут / connection refused).
     */
    public function isUnavailable(): bool
    {
        return $this->status === 0;
    }
}
