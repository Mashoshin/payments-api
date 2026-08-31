# CLAUDE.md — payments

Гид для агентов по этому репозиторию. Держи его в актуальном состоянии при
изменении кода.

## Что это

`payments` — сервис 1 платёжного потока (порт **8081**). Оркестратор: принимает
платёж, синхронно создаёт проводку в `ledger`, затем отправляет уведомление в
`notifications`. Это единственный сервис, который вызывает других.

Спецификация всего полигона — `../SERVICES-SPEC.md` (раздел «Сервис 1: payments»).
README.md — пользовательская документация с примерами curl.

## Технологии и ограничения

- **PHP 8.3+**, **без фреймворков и без composer-зависимостей**. Только stdlib
  (включая расширение `curl` для исходящих вызовов).
- Один входной скрипт `public/index.php` с простым роутером.
- Хранение — JSON-файл `var/storage.json` (карта `id → payment`). Файла нет →
  пустое хранилище, создаётся при первой записи. Каталог `var/` в `.gitignore`.
- Деньги — целые числа в **копейках**, никаких float. Валюта одна — `RUB`,
  в API не передаётся. `amount` всегда строго > 0.
- Счёт — строка `^acc_[a-z0-9_]+$`.

## Поток и правила отказов

```
client → POST /payments → [payments :8081]
                              ├─(1) POST /entries → [ledger :8082]        обязательно
                              └─(2) POST /notify  → [notifications :8083] best effort
```

1. Сгенерировать `id` (`pay_` + 8 hex), зафиксировать платёж как `pending`.
2. **ledger `POST /entries`** (`debit=from`, `credit=to`, `amount`, `payment_id`).
   Недоступен или ответил не `201` → статус `failed`, сохранить, клиенту **502**
   `{"error":"ledger unavailable"}`.
3. **notifications `POST /notify`** для счёта `to` с сообщением
   `"Зачисление <рубли> RUB со счёта <from>"` (сумма из копеек, 2 знака).
   Недоступен/не `201` → платёж **всё равно** `completed`, но
   `notification_sent=false`.
4. Статус `completed`, ответ клиенту **201**.

## Конфигурация (env)

| Переменная | По умолчанию |
|---|---|
| `LEDGER_URL` | `http://localhost:8082` |
| `NOTIFICATIONS_URL` | `http://localhost:8083` |

## Структура кода

```
public/index.php                 точка входа + роутер + маппинг исключений на статусы
src/Payments.php                 оркестрация: create(), find()
src/Storage.php                  JSON-хранилище (карта id→payment), upsert под flock
src/HttpClient.php               исходящий POST JSON поверх cURL (status 0 = недоступен)
src/HttpResponse.php             результат исходящего вызова
src/Config.php                   адреса соседей из env
src/Money.php                    копейки → рубли ("1000.00") без float
src/Validator.php                валидация account/amount
src/ValidationException.php      → HTTP 400
src/LedgerUnavailableException.php → HTTP 502
src/Http.php                     разбор JSON-тела, отправка JSON-ответа
src/Clock.php                    метка времени ISO 8601 (+03:00)
```

Маппинг исключений в `index.php`: `ValidationException → 400`,
`LedgerUnavailableException → 502`, прочее `\Throwable → 500`.

## Ручки

| Метод + путь | Назначение | Успех |
|---|---|---|
| `GET /health` | служебная проверка | `200 {"status":"ok","service":"payments"}` |
| `POST /payments` | создать платёж и провести | `201` с телом платежа |
| `GET /payments/{id}` | вернуть платёж | `200` / `404` |

`POST /payments` валидация (`400`): формат обоих счетов, `from != to`,
`amount` — целое > 0.

Тело платежа: `{id, from, to, amount, status, notification_sent, created_at}`,
`status ∈ {pending, completed, failed}`.

## Запуск

```bash
php -S localhost:8081 public/index.php
```

Если PHP не в PATH (Homebrew): полный путь, например `/opt/homebrew/opt/php/bin/php`.

## Как протестировать

Синтаксис:

```bash
php -l public/index.php && for f in src/*.php; do php -l "$f"; done
```

Полный сквозной тест требует запущенных соседей. `ledger` — соседний репозиторий
`../ledger-api` (`php -S localhost:8082 public/index.php`). Для `notifications`
на 8083 достаточно любой заглушки, отвечающей `201` на `POST /notify` (пока сервис
не реализован).

Smoke-тест (все три сервиса запущены):

```bash
B=localhost:8081

curl -s $B/health
# → {"status":"ok","service":"payments"}

# успешный платёж
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_petya","amount":100000}'
# → 201, status=completed, notification_sent=true

# эффекты: ledger обновлён
curl -s localhost:8082/accounts/acc_petya/balance   # balance 100000
curl -s localhost:8082/accounts/acc_vasya/balance   # balance -100000

# получить платёж
curl -s $B/payments/<id>          # 200 | 404 {"error":"payment not found"}
```

Проверки валидации (все → `400`):

```bash
# from == to и amount = 0
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_vasya","amount":0}'
# дробный amount
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_a","to":"acc_b","amount":10.5}'
# неверный формат счёта
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"Acc_A","to":"acc_b","amount":100}'
```

Отказы зависимостей:

```bash
# ledger погашен → 502 и платёж failed
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_x","to":"acc_y","amount":77700}'
# → 502 {"error":"ledger unavailable"} (запись в var/storage.json со status=failed)

# notifications погашен, ledger жив → 201 completed, notification_sent=false
```

## Границы (вне скоупа v1)

Проверка достаточности баланса (овердрафт разрешён), отмена платежа,
идемпотентность повторов, комиссии. Не добавлять без явного тикета.
