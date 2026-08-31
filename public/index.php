<?php

declare(strict_types=1);

namespace Payments;

/*
 * Точка входа сервиса payments (порт 8081).
 * Запуск: php -S localhost:8081 public/index.php
 *
 * Маршруты:
 *   GET  /health          — служебная проверка
 *   POST /payments        — создать платёж и провести до конца
 *   GET  /payments/{id}   — вернуть платёж
 *
 * Соседи (переопределяются через env):
 *   LEDGER_URL         (по умолчанию http://localhost:8082)
 *   NOTIFICATIONS_URL  (по умолчанию http://localhost:8083)
 */

require __DIR__ . '/../src/ValidationException.php';
require __DIR__ . '/../src/LedgerUnavailableException.php';
require __DIR__ . '/../src/Validator.php';
require __DIR__ . '/../src/Clock.php';
require __DIR__ . '/../src/Money.php';
require __DIR__ . '/../src/Config.php';
require __DIR__ . '/../src/HttpResponse.php';
require __DIR__ . '/../src/HttpClient.php';
require __DIR__ . '/../src/Storage.php';
require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Payments.php';

$storageFile = __DIR__ . '/../var/storage.json';
$service = new Payments(
    new Storage($storageFile),
    new HttpClient(),
    Config::fromEnv(),
);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if ($path === '') {
    $path = '/';
}

try {
    // GET /health
    if ($method === 'GET' && $path === '/health') {
        Http::json(200, ['status' => 'ok', 'service' => 'payments']);
        return;
    }

    // POST /payments
    if ($method === 'POST' && $path === '/payments') {
        $payment = $service->create(Http::jsonBody());
        Http::json(201, $payment);
        return;
    }

    // GET /payments/{id}
    if ($method === 'GET' && preg_match('#^/payments/([^/]+)$#', $path, $m) === 1) {
        $payment = $service->find(urldecode($m[1]));
        if ($payment === null) {
            Http::json(404, ['error' => 'payment not found']);
            return;
        }
        Http::json(200, $payment);
        return;
    }

    // Неизвестный маршрут.
    Http::json(404, ['error' => 'not found']);
} catch (ValidationException $e) {
    Http::json(400, ['error' => $e->getMessage()]);
} catch (LedgerUnavailableException $e) {
    Http::json(502, ['error' => $e->getMessage()]);
} catch (\Throwable $e) {
    Http::json(500, ['error' => 'internal server error']);
}
