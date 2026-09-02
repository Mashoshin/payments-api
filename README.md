# payments

Оркестратор платёжного потока — сервис 1. Принимает платёж, синхронно создаёт
проводку в `ledger`, затем отправляет уведомление в `notifications`.

- **PHP 8.3+**, без фреймворков и composer-зависимостей.
- Хранение — JSON-файл `var/storage.json` (создаётся при первой записи).
- Порт по умолчанию — **8081**.

## Поток

```
client → POST /payments → [payments :8081]
                              ├─(1) POST /entries → [ledger :8082]        (обязательно)
                              └─(2) POST /notify  → [notifications :8083] (best effort)
```

- Проводка в `ledger` — обязательный шаг. `ledger` недоступен или ответил не
  `201` → платёж `failed`, клиенту `502`.
- Уведомление в `notifications` — best effort. Недоступен → платёж всё равно
  `completed`, но с флагом `notification_sent: false`.

## Конфигурация

Адреса соседей захардкожены по умолчанию, переопределяются через env:

| Переменная | По умолчанию |
|---|---|
| `LEDGER_URL` | `http://localhost:8082` |
| `NOTIFICATIONS_URL` | `http://localhost:8083` |

## Деньги и счета

Суммы — целые числа в копейках (`amount: 100000` = 1 000,00 ₽), без float.
Валюта одна — `RUB` (в API не передаётся). `amount` строго > 0.
Счёт — строка `^acc_[a-z0-9_]+$`.

## Запуск

```bash
php -S localhost:8081 public/index.php
# со своими адресами соседей:
LEDGER_URL=http://localhost:9002 NOTIFICATIONS_URL=http://localhost:9003 \
  php -S localhost:8081 public/index.php
```

## Ручки

### GET /health

```bash
curl -s localhost:8081/health
# 200 {"status":"ok","service":"payments"}
```

### POST /payments

Создать платёж и провести его до конца. Операция идемпотентна по клиентскому
ключу `client_oid` в пределах отправителя — ключ это пара (`from`, `client_oid`).

Тело: `{from, to, amount, client_oid}` — все поля обязательны.
Валидация (`400`): оба счёта соответствуют формату, `from != to`,
`amount` — целое > 0, `client_oid` — строка по маске `^[A-Za-z0-9_-]{1,64}$`.

```bash
curl -s -X POST localhost:8081/payments \
  -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_petya","amount":100000,"client_oid":"order-42"}'
```

Ответ `201` (платёж проведён):

```json
{
  "id": "pay_a1b2c3d4",
  "from": "acc_vasya",
  "to": "acc_petya",
  "amount": 100000,
  "client_oid": "order-42",
  "fee": 1000,
  "total": 101000,
  "status": "completed",
  "notification_sent": true,
  "created_at": "2026-08-31T12:00:00+03:00"
}
```

Повторы с тем же ключом:

- те же `to` и `amount`, найденный платёж `completed` или `pending` → `200`
  с этим же платежом; ничего не создаётся и не списывается;
- те же `to` и `amount`, найденный платёж `failed` → платёж проводится заново
  под **тем же** `id`, ответ `201`;
- другие `to` и/или `amount` → `409 {"error":"..."}`, эффектов нет.

Ошибки:

```bash
# from == to и amount = 0
curl -s -X POST localhost:8081/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_vasya","amount":0,"client_oid":"order-1"}'
# 400 {"error":"from and to must differ"}

# тот же client_oid у того же отправителя, но другая сумма
# 409 {"error":"client_oid already used with different to/amount"}

# ledger недоступен
# 502 {"error":"ledger unavailable"}, платёж сохранён со статусом failed
```

### GET /payments/{id}

Вернуть платёж в том же формате. Нет такого — `404`.

```bash
curl -s localhost:8081/payments/pay_a1b2c3d4
# 200 { ... }   |   404 {"error":"payment not found"}
```

## Форматы ответов

- Тела запросов и ответов — JSON, `Content-Type: application/json`.
- Успех: `200` (чтение) / `201` (создание).
- Ошибка валидации: `400 {"error":"..."}`.
- Конфликт ключа идемпотентности: `409 {"error":"..."}`.
- Не найдено / неизвестный маршрут: `404 {"error":"..."}`.
- Ошибка зависимого сервиса (`ledger`): `502 {"error":"ledger unavailable"}`.

## Вне скоупа v1

Проверка достаточности баланса (овердрафт разрешён), отмена платежа.
Идемпотентность повторов есть, но без защиты от гонки: параллельные запросы
с одним ключом не сериализуются.
