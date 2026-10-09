# 10 · REST API

[← Home](Home.md)

Токен-аутентифицированный API для внешних систем: платёжные сервисы,
Home Assistant, боты, распознавание номеров, мониторинг. Интерактивная
документация — `GET /api/v1/docs` (JSON-манифест) и раздел «API» встроенной
справки.

## Аутентификация

```
Authorization: Bearer <токен>        (или заголовок X-API-Key: <токен>)
Accept: application/json
```

Токен создаётся в **Админка → API-токены** или
`bin/bas-ip api:token --name="Home Assistant" --scopes=gate.open,gate.status --days=365`.
Показывается **один раз** при создании; хранится хэшем. У токена:

* **области доступа (scopes)** — `gate.open`, `gate.close`, `gate.status`,
  `gate.log`, `guest.create`, `guest.view`, `guest.revoke`, `residents.view`,
  `billing.payment.record`, `billing.view`, `notifications.send`, `*`;
  маршрут пропускает запрос, если у токена есть **любая** из перечисленных
  в маршруте областей;
* срок действия (0 = бессрочно), имя, возможность отзыва;
* API-токен — **не** сессия: роли не даёт, аудит пишет как `api`.

Без токена / с недействительным — `401`; с токеном без нужной области — `403`.

## Формат ответов

Успех: `200/201`, тело `{"ok": true, …}`. Ошибка — стабильная форма:

```json
{"ok": false, "error": "сообщение", "status": 422, "request_id": "4d7abc6d458d6e3a"}
```

`request_id` возвращается и заголовком `X-Request-Id` — по нему находится
запись в логах. Списки — `{"ok":true,"data":[…],"meta":{page,per_page,total,…}}`.
Даты — `YYYY-MM-DD HH:MM:SS` в `app.timezone`; деньги — числом (рубли) и
строкой `*_formatted`.

Rate-limit: `429` + `Retry-After` (пресеты `gate_open 12/мин`, `api 120/мин`
и т.д. — [03 · Конфигурация](03-Configuration.md#security--защита)).

## Эндпоинты

### Мета (публичные)

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/api/v1/meta` | версия, возможности (`capabilities`), часовой пояс |
| GET | `/api/v1/health` | состояние: БД, устройство, очередь (200/503) |
| GET | `/health`, `/health.json` | то же на корне (для uptime-мониторов) |
| GET | `/metrics` | Prometheus-текст (`basip_up`, жители, открытия, очередь…) |
| GET | `/api/v1/docs` | машинно-читаемая документация |

### Шлагбаум

| Метод | Путь | Scopes | Тело/параметры |
|---|---|---|---|
| GET | `/api/v1/gate/status` | `gate.status`, `gate.open`, `*` | — (состояние устройства, SIP, cooldown) |
| POST | `/api/v1/gate/open` | `gate.open`, `*` | `apartment` или `resident_id` или `plate` или `code`; `note`, `device` |
| POST | `/api/v1/gate/close` | `gate.close`, `*` | `device` |
| GET | `/api/v1/gate/events` | `gate.log`, `*` | `from`, `to`, `access`, `limit`, `page` |
| GET | `/api/v1/gate/snapshot` | `gate.status`, `*` | JPEG снимка камеры (или JSON-обёртка) |

`POST /gate/open` → `200 {"ok":true,"message":"Шлагбаум открыт…","event_id":…}`;
отказ (нет доступа/cooldown/тихие часы) → `422` с `error_code`.

### Гостевые пропуска

| Метод | Путь | Scopes |
|---|---|---|
| POST | `/api/v1/guest/passes` | `guest.create`, `*` |
| GET | `/api/v1/guest/passes` | `guest.view`, `*` |
| DELETE | `/api/v1/guest/passes/{id}` | `guest.revoke`, `*` |

Создание: `{"resident_id": 5, "ttl_hours": 6, "guest_name": "Курьер",
"plate": "А123ВС77"}` → `201` с `{id, code, pin, link, expires_at}` —
`link` сразу готов к отправке гостю, `code` — для клавиатуры.

### Жители и оплаты

| Метод | Путь | Scopes |
|---|---|---|
| GET | `/api/v1/residents` | `residents.view`, `*` |
| GET | `/api/v1/residents/{id}` | `residents.view`, `*` |
| POST | `/api/v1/payments` | `billing.payment.record`, `*` |
| GET | `/api/v1/stats` | `billing.view`, `*` |
| POST | `/api/v1/notifications` | `notifications.send`, `*` |

`GET /residents` — пагинация/поиск/фильтр статуса; в карточке: ФИО, квартира,
`is_active`, `access_granted`, баланс/долг (контакты — только при явном scope
и включённом флаге; PII минимизирован).

`POST /payments` (вебхук платёжного сервиса):

```json
{
  "resident_id": 5,          // или "apartment": "12", или "invoice_id": 42
  "amount": 750.00,
  "method": "online",
  "external_id": "pay_8f3k2", // идемпотентность: дубль не проведётся дважды
  "date": "2026-10-09",
  "note": "CloudTips"
}
```

→ `201`, счёт закрывается FIFO, баланс и журнал обновляются.

### Webhook Telegram

`POST /webhook/telegram` — без сессии/CSRF, проверяется `secret`
(`integrations.telegram_bot`); команды бота `/open`, `/status`, `/guest`,
`/balance`, `/help`.

## Legacy v1 (совместимость)

Старые автоматизации продолжают работать:

| v1 | v2 |
|---|---|
| `GET /bas_ip.php?cmd=lock_open` | форвард на `POST /api/v1/gate/open` |
| `cmd=status` / `get_time` | `GET /api/v1/gate/status` |
| `cmd=get_cameras` | `GET /api/v1/gate/snapshot` |
| `cmd=reboot`, `sip_enable`, `sip_disable` | соответствующие endpoints |
| `GET /bas-ip.php` (панель) | 301 → `/panel` |
| `GET /get_last_image.php` | снимок камеры (требует авторизацию) |

Отличие от v1: **все legacy-вызовы теперь требуют аутентификацию** (сессия,
API-токен или IP из allowlist) и пишутся в аудит.

## Примеры (curl)

```bash
TOK="…ваш-токен…"
BASE="https://gate.example.com"

# статус
curl -H "Authorization: Bearer $TOK" $BASE/api/v1/gate/status

# открыть для квартиры 12
curl -X POST -H "Authorization: Bearer $TOK" -H "Content-Type: application/json" \
     -d '{"apartment":"12","note":"курьер"}' $BASE/api/v1/gate/open

# гостевой пропуск на 6 часов
curl -X POST -H "Authorization: Bearer $TOK" -H "Content-Type: application/json" \
     -d '{"resident_id":5,"ttl_hours":6,"guest_name":"Такси"}' \
     $BASE/api/v1/guest/passes

# провести оплату (вебхук оплаты)
curl -X POST -H "Authorization: Bearer $TOK" -H "Content-Type: application/json" \
     -d '{"apartment":"12","amount":750,"method":"online","external_id":"pay_1"}' \
     $BASE/api/v1/payments

# события за сегодня
curl -H "Authorization: Bearer $TOK" "$BASE/api/v1/gate/events?from=2026-10-09"

# метрики для Prometheus
curl $BASE/metrics
```

## Home Assistant

```yaml
rest_command:
  gate_open:
    url: http://gate.local/api/v1/gate/open
    method: POST
    headers:
      Authorization: "Bearer {{ states('input_text.basip_token') }}"
    content_type: application/json
    payload: '{"apartment": "{{ apartment }}"}'
```

Плюс `binary_sensor` на `/health.json` и скрейпинг `/metrics`
(`integrations.prometheus`).

## Безопасность API

* минимальные scopes под задачу (не выдавайте `*` без нужды);
* отдельные токены на каждую интеграцию (отзыв одной не ломает остальные);
* срок действия + ротация;
* rate-limit на каждый маршрут; аудит каждого вызова (`audit_log`,
  actor `api:<имя токена>`);
* при желании — `auth.ip_allowlist.apply_to: ['api']` (только свои IP).

---
Дальше: [11 · Устройства](11-Devices.md)
