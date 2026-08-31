# payments

Сервис-оркестратор платёжного потока (порт **8081**).

Принимает платёж, синхронно создаёт проводку в `ledger`, затем отправляет
уведомление в `notifications`. Часть полигона из трёх микросервисов
(см. `SERVICES-SPEC.md` в метарепозитории).

## Технологии

- PHP 8.3+, без фреймворков и composer-зависимостей.
- Один входной скрипт `public/index.php` с простым роутером.
- Хранение — JSON-файл `var/storage.json` (каталог `var/` в `.gitignore`,
  создаётся при первой записи).
- Суммы — целые числа в **копейках** (`amount: 150000` = 1 500,00 ₽), валюта `RUB`.

## Запуск

```bash
php -S localhost:8081 public/index.php
```

Адреса соседних сервисов переопределяются переменными окружения:

| Переменная | По умолчанию |
|---|---|
| `LEDGER_URL` | `http://localhost:8082` |
| `NOTIFICATIONS_URL` | `http://localhost:8083` |

```bash
LEDGER_URL=http://localhost:8082 NOTIFICATIONS_URL=http://localhost:8083 \
  php -S localhost:8081 public/index.php
```

## Ручки

### `GET /health`

Служебная ручка для smoke-тестов.

```bash
curl -s localhost:8081/health
# → 200 {"status":"ok","service":"payments"}
```

### `POST /payments`

Создать платёж и провести его до конца.

```bash
curl -s -X POST localhost:8081/payments \
  -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_petya","amount":100000}'
```

Успех — `201`:

```json
{
  "id": "pay_a1b2c3d4",
  "from": "acc_vasya",
  "to": "acc_petya",
  "amount": 100000,
  "status": "completed",
  "notification_sent": true,
  "created_at": "2026-08-31T12:00:00+03:00"
}
```

Валидация (`400`): оба счёта соответствуют формату `^acc_[a-z0-9_]+$`,
`from != to`, `amount` — целое > 0.

Поведение зависимостей:

- `ledger` недоступен или ответил не `201` → платёж сохраняется со статусом
  `failed`, клиенту `502 {"error":"ledger unavailable"}`.
- `notifications` недоступен → платёж **всё равно успешен**, но в нём
  проставляется `"notification_sent": false`.

Примеры ошибок:

```bash
# Невалидный платёж (счёт сам себе, нулевая сумма) → 400
curl -s -X POST localhost:8081/payments \
  -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_vasya","amount":0}'
```

### `GET /payments/{id}`

Вернуть платёж в том же формате. Нет такого — `404`.

```bash
curl -s localhost:8081/payments/pay_a1b2c3d4
```

## Вне скоупа v1

Проверка достаточности баланса (овердрафт разрешён), отмена платежа,
идемпотентность повторов, комиссии.
