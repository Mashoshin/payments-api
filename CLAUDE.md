# CLAUDE.md — payments-api

Guidance for working in this repository.

## Что это

`payments` — сервис-оркестратор платёжного потока, порт **8081**. Один из трёх
микросервисов полигона (`payments` → `ledger` → `notifications`). Единственный
источник правды по требованиям — `SERVICES-SPEC.md` в метарепозитории уровнем выше.

Роль: принимает платёж, **синхронно** создаёт проводку в `ledger`, затем шлёт
уведомление получателю в `notifications`. `payments` — единственный сервис,
который ходит к соседям; сам никем не вызывается, кроме клиента.

## Что умеет

| Метод | Маршрут | Назначение |
|---|---|---|
| `GET`  | `/health` | Служебная проверка: `200 {"status":"ok","service":"payments"}` |
| `POST` | `/payments` | Создать платёж и провести его до конца |
| `GET`  | `/payments/{id}` | Вернуть платёж; нет такого — `404` |

### Поток `POST /payments`

1. Валидация тела (`400` при нарушении): `from`/`to` соответствуют
   `^acc_[a-z0-9_]+$`, `from != to`, `amount` — **целое число > 0** (копейки).
2. Платёж фиксируется со статусом `pending`.
3. `POST {LEDGER_URL}/entries` с `payment_id, debit=from, credit=to, amount`.
   Недоступен или ответ не `201` → статус `failed`, клиенту
   `502 {"error":"ledger unavailable"}` (платёж остаётся в хранилище как `failed`).
4. `POST {NOTIFICATIONS_URL}/notify` для счёта `to` с сообщением
   `"Зачисление 1000.00 RUB со счёта acc_vasya"` (сумма из копеек в рубли,
   два знака). Недоступен → платёж **всё равно успешен**, но
   `"notification_sent": false` (best effort).
5. Статус `completed`, ответ клиенту `201` с телом платежа.

## Архитектура и правила

- **PHP 8.3+, без фреймворков и без composer-зависимостей.** Требуется расширение
  `curl` (используется для вызова соседей).
- Вся логика — в одном входном скрипте `public/index.php` с простым роутером.
  Разбит на секции: HTTP-помощники, хранилище, утилиты предметной области,
  обработчики маршрутов, роутер. При доработке — сохранять эту структуру,
  не тащить фреймворк/composer.
- **Хранилище** — JSON-файл `var/storage.json` (ключ — `id` платежа). Каталог
  `var/` в `.gitignore`, создаётся при первой записи. Запись атомарная
  (temp-файл + `rename`).
- **Деньги** — только целые копейки, никаких float. Форматирование в рубли —
  через `intdiv`/`%` (см. `kopecksToRubles`), без float-погрешностей.
- **Идентификаторы** генерирует сервис: `pay_` + 8 hex-символов
  (`random_bytes`).
- **Время** — таймзона зафиксирована `Europe/Moscow`, `created_at` в формате ISO 8601
  (`date('c')`).
- Формат ответов — всегда JSON. Ошибки: `{"error":"<описание>"}` с кодами
  `400` / `404` / `502`.

### Конфигурация (env, со значениями по умолчанию)

| Переменная | По умолчанию |
|---|---|
| `LEDGER_URL` | `http://localhost:8082` |
| `NOTIFICATIONS_URL` | `http://localhost:8083` |

## Запуск

```bash
php -S localhost:8081 public/index.php
```

## Как это протестировать

Проверить синтаксис:

```bash
php -l public/index.php
```

### Быстрый smoke-тест без соседей

`payments` работает и без запущенных `ledger`/`notifications` — проверяются
валидация, роутинг и обработка недоступности зависимостей.

```bash
php -S localhost:8081 public/index.php &   # запустить сервис

curl -s localhost:8081/health
# → {"status":"ok","service":"payments"}

# Валидация: счёт сам себе + нулевая сумма → 400
curl -s -X POST localhost:8081/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_vasya","amount":0}'
# → 400 {"error":"..."}

# ledger не запущен → 502, платёж сохраняется как failed
curl -s -X POST localhost:8081/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_petya","amount":100000}'
# → 502 {"error":"ledger unavailable"}
```

### Полный сценарий с заглушками соседей

Пока `ledger` и `notifications` не реализованы, их можно заменить минимальными
заглушками (любой сервер, отвечающий `201` на `POST /entries` и `POST /notify`).
Пример на встроенном сервере PHP:

```bash
# ledger-заглушка (порт 8082): всегда 201 на /entries
cat > /tmp/mock_ledger.php <<'PHP'
<?php http_response_code(201); header('Content-Type: application/json');
echo json_encode(['id'=>'ent_test','created_at'=>date('c')]);
PHP
php -S localhost:8082 /tmp/mock_ledger.php &

# notifications-заглушка (порт 8083): всегда 201 на /notify
cat > /tmp/mock_notify.php <<'PHP'
<?php http_response_code(201); header('Content-Type: application/json');
echo json_encode(['id'=>'ntf_test','created_at'=>date('c')]);
PHP
php -S localhost:8083 /tmp/mock_notify.php &

# payments
php -S localhost:8081 public/index.php &

# Платёж проходит целиком → 201, status=completed, notification_sent=true
RESP=$(curl -s -X POST localhost:8081/payments -H 'Content-Type: application/json' \
  -d '{"from":"acc_vasya","to":"acc_petya","amount":100000}')
echo "$RESP"

# Получить платёж по id
ID=$(echo "$RESP" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["id"];')
curl -s localhost:8081/payments/$ID
```

Когда реальные `ledger` (8082) и `notifications` (8083) подняты — сценарий тот же,
плюс сквозной smoke-тест из раздела «Критерий готовности» в `SERVICES-SPEC.md`.

## Вне скоупа v1

Проверка баланса (овердрафт разрешён), отмена платежа, идемпотентность повторов,
комиссии, несколько валют.
