<?php

declare(strict_types=1);

/**
 * payments — оркестратор платёжного потока (порт 8081).
 *
 * Один входной скрипт с простым роутером. Хранилище — JSON-файл в var/.
 * Запуск: php -S localhost:8081 public/index.php
 */

date_default_timezone_set('Europe/Moscow');

const SERVICE_NAME = 'payments';
const ACCOUNT_REGEX = '/^acc_[a-z0-9_]+$/';

$ledgerUrl        = rtrim(getenv('LEDGER_URL') ?: 'http://localhost:8082', '/');
$notificationsUrl = rtrim(getenv('NOTIFICATIONS_URL') ?: 'http://localhost:8083', '/');
$storagePath      = __DIR__ . '/../var/storage.json';

// --- HTTP-помощники -------------------------------------------------------

/**
 * Отправить JSON-ответ и завершить обработку запроса.
 */
function sendJson(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sendError(int $status, string $message): void
{
    sendJson($status, ['error' => $message]);
}

/**
 * Прочитать и распарсить JSON-тело запроса.
 * Возвращает массив или null, если тело пустое/невалидное.
 */
function readJsonBody(): ?array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return null;
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : null;
}

/**
 * Синхронный POST-запрос с JSON-телом к соседнему сервису.
 *
 * @return array{ok: bool, status: int} ok=false — сервис недоступен (сетевая ошибка).
 */
function httpPostJson(string $url, array $body): array
{
    $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT        => 5,
    ]);

    $response = curl_exec($ch);
    $errno    = curl_errno($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    // curl_close() не вызываем: с PHP 8.0 он бесполезен, а с 8.5 — deprecated.
    unset($ch);

    if ($errno !== 0 || $response === false) {
        return ['ok' => false, 'status' => 0];
    }

    return ['ok' => true, 'status' => $status];
}

// --- Хранилище ------------------------------------------------------------

/**
 * Прочитать всё хранилище платежей. Нет файла — пустое хранилище.
 */
function loadStorage(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === '' || $raw === false) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Атомарно сохранить один платёж в хранилище.
 */
function savePayment(string $path, array $payment): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }

    $storage = loadStorage($path);
    $storage[$payment['id']] = $payment;

    $json = json_encode($storage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

    // Пишем во временный файл и переименовываем — защита от гонок при -S.
    $tmp = $path . '.tmp';
    file_put_contents($tmp, $json, LOCK_EX);
    rename($tmp, $path);
}

// --- Утилиты предметной области -------------------------------------------

function isValidAccount(mixed $value): bool
{
    return is_string($value) && preg_match(ACCOUNT_REGEX, $value) === 1;
}

/**
 * amount строго целое (int, либо строка/float без дробной части) > 0.
 */
function isValidAmount(mixed $value): bool
{
    if (is_int($value)) {
        return $value > 0;
    }
    // JSON-числа без дробной части приходят как int, но подстрахуемся.
    if (is_float($value)) {
        return $value > 0 && floor($value) === $value;
    }
    return false;
}

function generateId(string $prefix): string
{
    return $prefix . bin2hex(random_bytes(4));
}

/**
 * Копейки → строка рублей с двумя знаками, без float-погрешностей.
 * 100000 → "1000.00"
 */
function kopecksToRubles(int $kopecks): string
{
    return intdiv($kopecks, 100) . '.' . str_pad((string) ($kopecks % 100), 2, '0', STR_PAD_LEFT);
}

/**
 * Публичное представление платежа для ответа клиенту.
 */
function paymentView(array $p): array
{
    return [
        'id'                => $p['id'],
        'from'              => $p['from'],
        'to'                => $p['to'],
        'amount'            => $p['amount'],
        'status'            => $p['status'],
        'notification_sent' => $p['notification_sent'],
        'created_at'        => $p['created_at'],
    ];
}

// --- Обработчики маршрутов -------------------------------------------------

function handleHealth(): void
{
    sendJson(200, ['status' => 'ok', 'service' => SERVICE_NAME]);
}

function handleCreatePayment(string $storagePath, string $ledgerUrl, string $notificationsUrl): void
{
    $body = readJsonBody();
    if ($body === null) {
        sendError(400, 'invalid JSON body');
    }

    $from   = $body['from']   ?? null;
    $to     = $body['to']     ?? null;
    $amount = $body['amount'] ?? null;

    if (!isValidAccount($from)) {
        sendError(400, 'invalid "from" account');
    }
    if (!isValidAccount($to)) {
        sendError(400, 'invalid "to" account');
    }
    if ($from === $to) {
        sendError(400, '"from" and "to" must differ');
    }
    if (!isValidAmount($amount)) {
        sendError(400, '"amount" must be a positive integer (kopecks)');
    }

    $amount = (int) $amount;

    // 1. Фиксируем платёж со статусом pending.
    $payment = [
        'id'                => generateId('pay_'),
        'from'              => $from,
        'to'                => $to,
        'amount'            => $amount,
        'status'            => 'pending',
        'notification_sent' => false,
        'created_at'        => date('c'),
    ];
    savePayment($storagePath, $payment);

    // 2. Проводка в ledger (синхронно, обязательный шаг).
    $ledgerResult = httpPostJson($ledgerUrl . '/entries', [
        'payment_id' => $payment['id'],
        'debit'      => $from,
        'credit'     => $to,
        'amount'     => $amount,
    ]);

    if (!$ledgerResult['ok'] || $ledgerResult['status'] !== 201) {
        $payment['status'] = 'failed';
        savePayment($storagePath, $payment);
        sendError(502, 'ledger unavailable');
    }

    // 3. Уведомление получателю (best effort).
    $message = sprintf('Зачисление %s RUB со счёта %s', kopecksToRubles($amount), $from);
    $notifyResult = httpPostJson($notificationsUrl . '/notify', [
        'account' => $to,
        'message' => $message,
    ]);
    $payment['notification_sent'] = $notifyResult['ok'] && $notifyResult['status'] === 201;

    // 4. Платёж завершён.
    $payment['status'] = 'completed';
    savePayment($storagePath, $payment);

    sendJson(201, paymentView($payment));
}

function handleGetPayment(string $storagePath, string $id): void
{
    $storage = loadStorage($storagePath);
    if (!isset($storage[$id])) {
        sendError(404, 'payment not found');
    }
    sendJson(200, paymentView($storage[$id]));
}

// --- Роутер ---------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path   = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
if ($path === '') {
    $path = '/';
}

if ($method === 'GET' && $path === '/health') {
    handleHealth();
}

if ($method === 'POST' && $path === '/payments') {
    handleCreatePayment($storagePath, $ledgerUrl, $notificationsUrl);
}

if ($method === 'GET' && preg_match('#^/payments/([^/]+)$#', $path, $m) === 1) {
    handleGetPayment($storagePath, $m[1]);
}

sendError(404, 'route not found');
