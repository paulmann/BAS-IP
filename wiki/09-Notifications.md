# 09 · Уведомления

[← Home](Home.md)

Рассылки об оплатах, гостевые ссылки, объявления и аварийные алерты — через
очередь с ретраями и любые каналы: e-mail (собственный SMTP-клиент),
Telegram, SMS, WhatsApp, webhook, журнал.

## Конвейер

```
Событие (начисление, пропуск, напоминание, рассылка из UI)
   │  Message::forResident(…) + шаблон + контекст
   ▼
NotificationService::resolve()      — каналы → контакты жителя
   ▼                                 (primary-first, флаг «слать сюда»,
таблица `outbox`                     fallback на email/phone карточки)
   ▼
QueueWorker (cron каждую минуту или queue:work демоном)
   — claim с lease (два воркера не задвоят), ретраи 60с→3ч,
     статусы pending/sent/failed, счётчики батча
   ▼
Каналы: log · mail · telegram · sms · whatsapp · webhook
```

Ничего не теряется: даже без настроенных каналов всё пишется в `log`.

## Каналы

| Канал | Включение | Реквизиты |
|---|---|---|
| `log` | всегда | уровень; полезно для отладки |
| `mail` | `notify.channels.mail.enabled` | SMTP: host/port/encryption(tls\|ssl\|none)/username/password/from/reply_to; **собственный SMTP-клиент** — внешних библиотек не нужно |
| `telegram` | `.channels.telegram.enabled` | `bot_token` (@BotFather), parse_mode HTML; личные сообщения — если житель указал @username (контакт типа telegram); служебные — в `admin_chat_id` |
| `sms` | `.channels.sms.enabled` | HTTP-шлюз: endpoint/login/password/sender/template |
| `whatsapp` | `.channels.whatsapp.enabled` | Cloud API: endpoint/token/phone_id |
| `webhook` | `.channels.webhook.enabled` | POST JSON {to, subject, body, meta} на url + HMAC-подпись `secret` |

Секреты храните шифрованными: `secret:set TELEGRAM_BOT_TOKEN` →
`'bot_token' => '${secret:TELEGRAM_BOT_TOKEN}'`.

**Куда попадёт сообщение**: каналы из `notify.default` (или из настроек UI /
конкретной рассылки), затем контакты жителя нужного типа, упорядоченные
`is_primary desc`; `one_per_channel: true` — берётся первый подходящий контакт
(не спамим на все номера). Контакты с `notify=0` пропускаются. Если контактов
нет — fallback на `residents.email`/`phone`.

## Шаблоны

Шаблоны живут в `config.notify.templates.*`, **переопределяются из UI**
(Админка → Уведомления → Шаблоны; хранение в БД, кнопка «сбросить к дефолту»).

Стандартный набор: `payment_new`, `payment_reminder`, `payment_overdue`,
`payment_received`, `charge_recalculated`, `guest_created`, `guest_invite`,
`guest_used`, `gate_opened`, `system_alert`, `welcome`.

**Плейсхолдеры**: `{name}` (короткое имя), `{full_name}`, `{apartment}`,
`{amount}` (уже отформатирован с символом валюты), `{currency}` (ISO-код),
`{period}`, `{due_date}`, `{deadline}`, `{pay_link}`, `{balance}`, `{debt}`,
`{invoice_no}`, `{charge_no}`, `{organisation}`, `{support_phone}`,
`{support_email}`, `{today}`, для гостей — `{code}`, `{link}`, `{pin}`,
`{expires_at}`, `{ttl_hours}`, `{guest_name}`, `{used_at}`, `{used_count}`.

**Модификаторы**: `{due_date|date:d.m.Y}`, `{due_date|datetime}`,
`{name|upper}`, `{name|lower}`, `{text|limit:80}`, `{amount_raw|money}`,
`{count|plural:житель,жителя,жителей}`, `{value|default:—}`, `{phone|mask}`.

Неизвестный плейсхолдер остаётся видимым `{like_this}` — опечатка заметна
сразу. Строки, где после подстановки осталось только «Подпись:» (пустая
ссылка на оплату), автоматически удаляются.

## Массовые рассылки

* **По начислению**: при выставлении счетов (`--notify` / галка в мастере)
  или повторно — карточка начисления → «Рассылка» (выборочно по жителям).
* **Напоминания должникам**: `/admin/billing/debtors` → «Напомнить»,
  `billing:remind`, cron по расписанию `billing.reminder`.
* **Произвольная**: Админка → Уведомления → «Рассылка» — текст/шаблон,
  аудитория (все активные / корпус / должники / с e-mail), каналы,
  тестовая отправка себе, очередь.
* **Объявления**: баннеры в UI жителей (не письма) — текст, срок, важность.

Батч рассылки виден в очереди: сколько встало, отправлено, упало, пропущено
(отключённые жители и рассылки **никогда** не учитывают неактивных).

## Очередь

* `queue:run` — разобрать раз (вызывается из `schedule:run`, т.е. из cron);
* `queue:work --sleep=5` — демон (systemd-юнит в [01 · Установка](01-Installation.md));
* `queue:stats` — состояние;
* ретраи: `max_attempts: 5`, задержки `[60, 300, 900, 3600, 10800]` сек;
* `throttle_ms: 150` — вежливая пауза между отправками (не злим SMTP/Telegram);
* UI: Админка → Уведомления → Очередь — failed можно ретраить вручную.
* `driver: sync` — отправка сразу (для тестов/микро-установок).

## Telegram-бот (интеграция)

`integrations.telegram_bot`: команды `/open` (для staff), `/status`,
`/guest` (выпустить пропуск), `/balance`, `/help`; webhook
`POST /webhook/telegram` с секретом (без CSRF/сессии). Алерты системы —
в `admin_chat_id`.

## Отладка

```bash
php bin/bas-ip notify:test --to=admin --message="Проверка"   # тестовая отправка
php bin/bas-ip notify:send --resident=5 --template=payment_reminder
php bin/bas-ip logs:tail notify --lines=50                   # журнал канала
php bin/bas-ip queue:stats
```

Журнал `notify` содержит: получателя (маскируется по политике логов), канал,
шаблон, результат, ошибку провайдера.

---
Дальше: [10 · REST API](10-API.md)
