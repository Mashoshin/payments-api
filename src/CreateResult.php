<?php

declare(strict_types=1);

namespace Payments;

/**
 * Результат POST /payments: тело платежа и HTTP-статус, которым его отдать.
 *
 * 201 — платёж проведён (новая пара (from, client_oid) либо повтор после failed),
 * 200 — повтор: платёж по этому ключу уже существует, ничего не делалось.
 */
final class CreateResult
{
    /**
     * @param array<string, mixed> $payment
     */
    public function __construct(
        public readonly int $status,
        public readonly array $payment,
    ) {
    }
}
