<?php

declare(strict_types=1);

namespace Payments;

/**
 * Минимальный исходящий HTTP-клиент поверх cURL для вызова соседних сервисов.
 */
final class HttpClient
{
    public function __construct(
        private readonly int $timeoutSeconds = 5
    ) {
    }

    /**
     * Отправить POST с JSON-телом.
     *
     * @param array<string, mixed> $body
     * @return HttpResponse статус 0 означает, что сервис недоступен
     *                      (соединение не установлено / таймаут).
     */
    public function postJson(string $url, array $body): HttpResponse
    {
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return new HttpResponse(0, null);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // curl_close() не вызываем: с PHP 8.0 это no-op (в 8.5 помечен deprecated),
        // ресурс освобождается автоматически при выходе $ch из области видимости.

        // Ошибка соединения / таймаут: curl_exec === false и статус 0.
        if ($raw === false) {
            return new HttpResponse(0, null);
        }

        $decoded = json_decode((string) $raw, true);

        return new HttpResponse($status, is_array($decoded) ? $decoded : null);
    }
}
