<?php

declare(strict_types=1);

namespace Payments;

/**
 * Тонкие хелперы для входящего HTTP: разбор JSON-тела и отправка JSON-ответа.
 */
final class Http
{
    /**
     * Прочитать и разобрать JSON-тело запроса.
     *
     * @return array<string, mixed>
     * @throws ValidationException если тело не является JSON-объектом
     */
    public static function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            throw new ValidationException('request body must be a JSON object');
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            throw new ValidationException('request body must be a valid JSON object');
        }

        return $data;
    }

    /**
     * Отправить JSON-ответ с заданным статусом.
     *
     * @param array<string, mixed> $payload
     */
    public static function json(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
