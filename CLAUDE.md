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

0. **Идемпотентность.** Ключ — пара (`from`, `client_oid`); один и тот же
   `client_oid` у разных отправителей — разные платежи. Если платёж по ключу
   уже есть, сверяются ровно `to` и `amount`:

   | Найденный платёж | `to`/`amount` запроса | Ответ |
   |---|---|---|
   | `completed` | совпали | `200` + найденный платёж, побочных эффектов нет |
   | `pending` | совпали | `200` + найденный платёж (`pending`), эффектов нет |
   | `failed` | совпали | провести заново, **переиспользуя прежний `id`** |
   | любой | разошлись | `409 {"error":"..."}`, эффектов нет |

   Повтор после `failed` обновляет запись на месте: `id` и `created_at`
   прежние, `status` снова `pending`. Тот же `id` критичен — ledger
   дедуплицирует проводки по `payment_id`, и только совпадение идентификатора
   не даёт записать второй комплект проводок.
1. Сгенерировать `id` (`pay_` + 8 hex), зафиксировать платёж как `pending`.
2. **ledger `POST /entries`** (`debit=from`, `credit=to`, `amount`, `payment_id`),
   оба плеча (перевод + комиссия) одним массивом. `201` (записали) и `200`
   (проводки под этим `payment_id` уже были) — оба успех. Недоступен или любой
   другой статус → `failed`, сохранить, клиенту **502**
   `{"error":"ledger unavailable"}`.
3. **notifications `POST /notify`** для счёта `to` с сообщением
   `"Зачисление <рубли> RUB со счёта <from>"` (сумма из копеек, 2 знака).
   Недоступен/не `201` → платёж **всё равно** `completed`, но
   `notification_sent=false`. При ответе `200` (повтор) `/notify` не зовётся.
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
src/Storage.php                  JSON-хранилище (карта id→payment), upsert под flock,
                                 поиск по паре (from, client_oid)
src/HttpClient.php               исходящий POST JSON поверх cURL (status 0 = недоступен)
src/HttpResponse.php             результат исходящего вызова
src/Config.php                   адреса соседей из env
src/Money.php                    копейки → рубли ("1000.00") без float
src/Validator.php                валидация account/amount/client_oid
src/CreateResult.php             результат create(): платёж + статус 200|201
src/ValidationException.php      → HTTP 400
src/ConflictException.php        → HTTP 409 (конфликт client_oid)
src/LedgerUnavailableException.php → HTTP 502
src/Http.php                     разбор JSON-тела, отправка JSON-ответа
src/Clock.php                    метка времени ISO 8601 (+03:00)
```

Маппинг исключений в `index.php`: `ValidationException → 400`,
`ConflictException → 409`, `LedgerUnavailableException → 502`,
прочее `\Throwable → 500`.

## Ручки

| Метод + путь | Назначение | Успех |
|---|---|---|
| `GET /health` | служебная проверка | `200 {"status":"ok","service":"payments"}` |
| `POST /payments` | создать платёж и провести | `201` новый / `200` повтор |
| `GET /payments/{id}` | вернуть платёж | `200` / `404` |

`POST /payments` тело: `{from, to, amount, client_oid}` — все поля обязательны.
Валидация (`400`): формат обоих счетов, `from != to`, `amount` — целое > 0,
`client_oid` — строка по маске `^[A-Za-z0-9_-]{1,64}$`. Вся валидация — до
любых побочных эффектов.

`409` — платёж с этой парой (`from`, `client_oid`) уже есть, но с другими
`to` и/или `amount` (см. «Поток и правила отказов», п. 0).

Тело платежа:
`{id, from, to, amount, client_oid, fee, total, status, notification_sent, created_at}`,
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
  -d '{"from":"acc_vasya","to":"acc_petya","amount":100000,"client_oid":"order-42"}'
# → 201, status=completed, notification_sent=true

# тот же запрос повторно — 200, тот же id, ничего нового не списано
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_petya","amount":100000,"client_oid":"order-42"}'
# → 200, тот же платёж

# тот же ключ, другая сумма — 409
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_petya","amount":500,"client_oid":"order-42"}'
# → 409 {"error":"client_oid already used with different to/amount"}

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
  -d '{"from":"acc_vasya","to":"acc_vasya","amount":0,"client_oid":"a1"}'
# дробный amount
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_a","to":"acc_b","amount":10.5,"client_oid":"a2"}'
# неверный формат счёта
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"Acc_A","to":"acc_b","amount":100,"client_oid":"a3"}'
# client_oid отсутствует / пустой / длиннее 64 символов
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_a","to":"acc_b","amount":100}'
```

Отказы зависимостей:

```bash
# ledger погашен → 502 и платёж failed
curl -s -X POST $B/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_x","to":"acc_y","amount":77700,"client_oid":"retry-1"}'
# → 502 {"error":"ledger unavailable"} (запись в var/storage.json со status=failed)
# ledger поднят, тот же запрос повторно → 201, тот же id, статус completed

# notifications погашен, ledger жив → 201 completed, notification_sent=false
```

## Границы (вне скоупа v1)

Проверка достаточности баланса (овердрафт разрешён), отмена платежа.
Идемпотентность есть, но без защиты от гонки: параллельные запросы с одним
ключом не сериализуются (отдельный тикет), блокировки на весь поток нет.
Не добавлять без явного тикета.
