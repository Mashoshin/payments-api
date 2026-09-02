<?php

declare(strict_types=1);

namespace Payments;

/**
 * Простое JSON-хранилище платежей в файле var/storage.json.
 *
 * Данные хранятся в виде {"payments": {"<id>": { ... }}} — карта по id
 * для быстрого поиска. Файла нет — считается пустым хранилищем и создаётся
 * при первой записи. Запись атомарна (усечение + запись под LOCK_EX).
 */
final class Storage
{
    public function __construct(
        private readonly string $file
    ) {
    }

    /**
     * Найти платёж по id или вернуть null.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $id): ?array
    {
        $payments = $this->readAll();

        return $payments[$id] ?? null;
    }

    /**
     * Найти платёж по ключу идемпотентности — паре (from, client_oid).
     * Один и тот же client_oid у разных отправителей — разные платежи.
     *
     * @return array<string, mixed>|null
     */
    public function findByClientOid(string $from, string $clientOid): ?array
    {
        foreach ($this->readAll() as $payment) {
            // Платежи, созданные до появления client_oid, ключа не имеют.
            if (($payment['from'] ?? null) === $from && ($payment['client_oid'] ?? null) === $clientOid) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * Создать/обновить платёж (upsert по ключу id) под эксклюзивной блокировкой.
     *
     * @param array<string, mixed> $payment должен содержать ключ 'id'
     */
    public function save(array $payment): void
    {
        $this->ensureDir();

        $handle = fopen($this->file, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open storage file for writing');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot acquire storage lock');
            }

            $raw = stream_get_contents($handle);
            $payments = [];
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['payments']) && is_array($decoded['payments'])) {
                    $payments = $decoded['payments'];
                }
            }

            $payments[$payment['id']] = $payment;

            $json = json_encode(
                ['payments' => $payments],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
            if ($json === false) {
                throw new \RuntimeException('Cannot encode storage data');
            }

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $json);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function readAll(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $raw = file_get_contents($this->file);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['payments']) || !is_array($data['payments'])) {
            return [];
        }

        return $data['payments'];
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
