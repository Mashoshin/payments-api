<?php

declare(strict_types=1);

namespace Payments;

/**
 * Оркестратор платёжного потока: платёж → проводка (ledger) → уведомление
 * (notifications).
 *
 * - Платёж идемпотентен по паре (from, client_oid): повтор с теми же to/amount
 *   не создаёт ничего нового, повтор с другими — конфликт (409).
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
     * Создать платёж и провести его до конца — идемпотентно по паре
     * (from, client_oid).
     *
     * @param array<string, mixed> $input
     * @return CreateResult статус 201 (проведён) либо 200 (повтор, ничего не делали)
     * @throws ValidationException        некорректный ввод → 400
     * @throws ConflictException          ключ занят другими to/amount → 409
     * @throws LedgerUnavailableException ledger недоступен/ответил не 200|201 → 502
     */
    public function create(array $input): CreateResult
    {
        $from = Validator::account($input['from'] ?? null, 'from');
        $to = Validator::account($input['to'] ?? null, 'to');
        $amount = Validator::amount($input['amount'] ?? null);
        $clientOid = Validator::clientOid($input['client_oid'] ?? null);

        if ($from === $to) {
            throw new ValidationException('from and to must differ');
        }

        // 0. Ключ идемпотентности — пара (from, client_oid). Всё ветвление здесь,
        //    до любых побочных эффектов: ни 200, ни 409 не порождают ни проводок,
        //    ни уведомлений.
        $existing = $this->storage->findByClientOid($from, $clientOid);
        if ($existing !== null) {
            // Ключ освобождается только под точный повтор: сверяем to и amount
            // (from уже часть ключа). Расхождение — конфликт при любом статусе.
            if ($existing['to'] !== $to || $existing['amount'] !== $amount) {
                throw new ConflictException('client_oid already used with different to/amount');
            }

            // completed или pending — отдаём как есть, ничего не делаем.
            if ($existing['status'] !== 'failed') {
                return new CreateResult(200, $existing);
            }
            // failed — проводим заново ниже, переиспользуя прежний id.
        }

        // Комиссия 1% «сверху», удерживается с отправителя дополнительно к сумме.
        // Ставка 1% — зашитая константа (инвариант v1), целочисленный ceil(amount/100),
        // минимум 1 копейка: (amount + 99) div 100. Без float.
        $fee = intdiv($amount + 99, 100);
        $total = $amount + $fee;

        // 1. Зафиксировать платёж со статусом pending. Повтор после failed
        //    обновляет прежнюю запись на месте: id и created_at сохраняются.
        //    Переиспользование id принципиально — ledger дедуплицирует проводки
        //    по payment_id, и только тот же id не даст записать второй комплект,
        //    если в прошлый раз ledger успел записать, а ответ не дошёл.
        $payment = [
            'id' => $existing['id'] ?? 'pay_' . bin2hex(random_bytes(4)),
            'from' => $from,
            'to' => $to,
            'amount' => $amount,
            'client_oid' => $clientOid,
            'fee' => $fee,
            'total' => $total,
            'status' => 'pending',
            'notification_sent' => false,
            'created_at' => $existing['created_at'] ?? Clock::now(),
        ];
        $this->storage->save($payment);

        // 2. Проводка в ledger — обязательна. Оба плеча одним запросом (массив):
        //    сначала перевод, затем комиссия на счёт acc_fee. Запись атомарна на
        //    стороне ledger — при сбое второго запроса не делаем, «фантома» нет.
        $ledgerResponse = $this->http->postJson($this->config->ledgerUrl . '/entries', [
            [
                'payment_id' => $payment['id'],
                'debit' => $from,
                'credit' => $to,
                'amount' => $amount,
            ],
            [
                'payment_id' => $payment['id'],
                'debit' => $from,
                'credit' => 'acc_fee',
                'amount' => $fee,
            ],
        ]);

        // Ledger идемпотентен по payment_id: 200 значит «проводки под этим
        // payment_id уже записаны» — успех наравне с 201. Всё остальное
        // (недоступен, 4xx, 5xx) — платёж failed, клиенту 502.
        if (!in_array($ledgerResponse->status, [200, 201], true)) {
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

        return new CreateResult(201, $payment);
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
