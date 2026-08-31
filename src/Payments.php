<?php

declare(strict_types=1);

namespace Payments;

/**
 * Оркестратор платёжного потока: платёж → проводка (ledger) → уведомление
 * (notifications).
 *
 * - Проводка в ledger — обязательный синхронный шаг. Провал → платёж failed,
 *   клиенту 502.
 * - Уведомление в notifications — best effort. Провал → платёж всё равно
 *   completed, но с флагом notification_sent=false.
 */
final class Payments
{
    public function __construct(
        private readonly Storage $storage,
        private readonly HttpClient $http,
        private readonly Config $config,
    ) {
    }

    /**
     * Создать платёж и провести его до конца.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed> итоговая запись платежа (status=completed)
     * @throws ValidationException        некорректный ввод → 400
     * @throws LedgerUnavailableException ledger недоступен/не 201 → 502
     */
    public function create(array $input): array
    {
        $from = Validator::account($input['from'] ?? null, 'from');
        $to = Validator::account($input['to'] ?? null, 'to');
        $amount = Validator::amount($input['amount'] ?? null);

        if ($from === $to) {
            throw new ValidationException('from and to must differ');
        }

        // 1. Зафиксировать платёж со статусом pending.
        $payment = [
            'id' => 'pay_' . bin2hex(random_bytes(4)),
            'from' => $from,
            'to' => $to,
            'amount' => $amount,
            'status' => 'pending',
            'notification_sent' => false,
            'created_at' => Clock::now(),
        ];
        $this->storage->save($payment);

        // 2. Проводка в ledger — обязательна.
        $ledgerResponse = $this->http->postJson($this->config->ledgerUrl . '/entries', [
            'payment_id' => $payment['id'],
            'debit' => $from,
            'credit' => $to,
            'amount' => $amount,
        ]);

        if ($ledgerResponse->status !== 201) {
            $payment['status'] = 'failed';
            $this->storage->save($payment);

            throw new LedgerUnavailableException('ledger unavailable');
        }

        // 3. Уведомление в notifications — best effort.
        $message = sprintf(
            'Зачисление %s RUB со счёта %s',
            Money::kopecksToRubles($amount),
            $from
        );
        $notifyResponse = $this->http->postJson($this->config->notificationsUrl . '/notify', [
            'account' => $to,
            'message' => $message,
        ]);

        $payment['notification_sent'] = $notifyResponse->status === 201;

        // 4. Платёж завершён.
        $payment['status'] = 'completed';
        $this->storage->save($payment);

        return $payment;
    }

    /**
     * Вернуть платёж по id или null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        return $this->storage->find($id);
    }
}
