# 03 · Конфигурация

[← Home](Home.md)

## Как устроена конфигурация

Приложение читает настройки слоями (последующие перекрывают предыдущие):

1. **`config/config.php`** — полный набор значений по умолчанию. Под git,
   не редактируйте в production (файл перезаписывается при обновлении).
2. **`.bas-ip.config.local.php`** в корне проекта — **ваш** файл: секреты,
   БД, устройство, overrides любых ключей. В `.gitignore`: ключи не попадут
   в репозиторий, а настройки **не потеряются при обновлении из git**
   (обновление никогда не трогает этот файл). Альтернативные расположения:
   `config/.bas-ip.config.local.php`, `config/config.local.php`.
3. **`.env`** в корне — простые `KEY=value` пары (шаблон: `.env.example`).
   Удобно для docker/12-factor. Секреты из `.env` подставляются в конфиг
   через `${secret:NAME}` и `env('KEY')`.
4. **Таблица `settings`** (Админка → Настройки) — операционные настройки,
   которые правятся из UI без деплоя: каналы уведомлений, лимиты гостевого
   доступа, ссылка на оплату, тексты, автобэкап и т.д. Значения из UI имеют
   приоритет над конфигом там, где это предусмотрено.
5. **Шифрованные секреты** — `php bin/bas-ip secret:set DEVICE_PASSWORD`
   хранит значение в `storage/` зашифрованным ключом приложения (AES-256-GCM);
   в конфиге используется как `'password' => '${secret:DEVICE_PASSWORD}'`.

Формат локального файла — обычный PHP-массив, глубокий merge с дефолтами:
достаточно указать только отличия.

```php
<?php
declare(strict_types=1);

return [
    'app' => ['name' => 'ЖК «Ромашка»', 'url' => 'https://gate.example.com'],
    'db'  => ['default' => 'mysql'],
    // …только то, что отличается от config/config.php
];
```

Проверить итоговую конфигурацию: `php bin/bas-ip config:show` (секреты
маскируются) или `config:show db.default`.

---

## `app` — приложение

| Ключ | По умолчанию | Описание |
|---|---|---|
| `name` / `short_name` | BAS-IP Gate Manager | Название ЖК в шапке, письмах, PWA |
| `env` | `production` | `production` / `staging` / `development` / `local` |
| `debug` | `false` | **Никогда `true` в production**: показ стектрейсов |
| `url` | `''` (авто) | Базовый URL — ссылки в письмах и гостевых пропусках |
| `timezone` | `Europe/Moscow` | Часовой пояс журнала, счетов, расписаний |
| `locale` / `fallback_locale` | `ru` / `en` | Язык интерфейса |
| `key` | `null` | **base64:32 байта** — CSRF, подписи ссылок, шифрование секретов. `bin/bas-ip key:generate` |
| `key_cipher` | `aes-256-gcm` | Шифр секретов at-rest |
| `maintenance` | `false` | Режим обслуживания: UI закрыт, **шлагбаум продолжает открываться** |
| `maintenance_message` | `null` | Текст на странице 503 |
| `behind_proxy` / `trusted_proxies` / `ip_header` | — | За nginx/Cloudflare: доверенные прокси и заголовок реального IP |
| `strict_transport_security` | `true` | HSTS на HTTPS-запросах |

## `db` — база данных

| Ключ | По умолчанию | Описание |
|---|---|---|
| `default` | `mysql` | Активное соединение: `mysql` / `pgsql` / `sqlite` |
| `prefix` | `''` | Префикс таблиц (несколько установок в одной БД) |
| `foreign_keys` | `true` | Контроль внешних ключей |
| `connections.mysql.*` | — | `host`, `port`, `database`, `username`, `password`, `unix_socket`, `charset` (utf8mb4), `collation` (для MariaDB < 10.6 — `utf8mb4_unicode_ci`), `engine`, `persistent`, `options` (PDO) |
| `connections.pgsql.*` | — | то же + `schema` (по умолчанию `public`) |
| `connections.sqlite.*` | — | `database` — путь к файлу (по умолчанию `storage/app/bas-ip.sqlite`), `busy_timeout` |

MySQL — рекомендуемая боевая СУБД (проект развивается на MySQL 8 / PHP 8.5).
SQLite годится для небольшого дома и демо. Переключение БД = смена
`db.default` + миграции (`bin/bas-ip migrate`); перенос данных —
экспорт/импорт или бэкап ([13](13-Backup-Restore.md)).

## `device` — шлагбаум / панель

| Ключ | По умолчанию | Описание |
|---|---|---|
| `driver` | `basip` | `basip` (панель Camdroid `/api/v0`), `http-relay` (сторонний контроллер), `dryrun` (эмулятор) |
| `base_url` | `http://192.168.1.20/api/v0/` | Адрес панели |
| `login` / `password` | — | Учётка панели; пароль лучше `${secret:DEVICE_PASSWORD}` |
| `timeout` / `connect_timeout` / `retries` / `retry_delay_ms` | 10 / 4 / 2 / 250 | Таймауты и повторы при сетевых ошибках и 5xx |
| `verify_tls` / `ca_bundle` | `true` / null | Проверка сертификата панели (для самоподписанных — свой CA) |
| `token.ttl` / `refresh_skew` / `store` | 1600 / 120 / `db` | Токен устройства: время жизни, досрочное обновление, где хранить (`db`/`file`/`cache`) |
| `lock.endpoint` | `access/general/lock/open/remote/accepted/{channel}` | Endpoint открытия замка (BAS-IP API) |
| `lock.channel` / `channels` | 1 / `{1: "Main gate"}` | Канал реле и подписи каналов |
| `lock.pulse_ms` | 1000 | Длительность импульса |
| `lock.cooldown_seconds` | 3 | Анти-даблтап между открытиями |
| `sip.reregister_time` | 315 | Интервал перерегистрации SIP (команды sip_enable/disable) |
| `camera.enabled` / `mode` | true / `snapshot` | `off` / `snapshot` / `proxy` / `mjpeg` |
| `camera.endpoint` / `stream_url` / `cache_ttl` / `width` / `auth` | `security/ipcam` / — / 2 / 1280 / `token` | Параметры снимков и потока |
| `gates` | `[]` | Дополнительные устройства: `['north' => ['base_url' => …, …]]` — наследуют общие настройки |

Подробности по драйверам — [11 · Устройства](11-Devices.md).

## `auth` — аутентификация и права

| Группа | Ключи |
|---|---|
| Роли | `roles: [admin, operator, resident]`, `default_role`, `allow_registration: false` (жителей создают админы) |
| Пароли | `password.algo: auto` (argon2id при наличии sodium, иначе bcrypt), `min_length: 10`, `require_mixed_case/digit/symbol`, `breach_check` (haveibeenpwned k-anonymity), `history: 3` (запрет повтора), `max_age_days: 0` |
| Логин | `login.field: login` (login/email/either), `max_attempts: 5`, `decay_minutes: 15`, `lockout_minutes: 30`, `captcha_after: 3`, `username_enumeration_protection: true` |
| Сессии | `session.name`, `lifetime: 720` мин (абсолютный), `idle_timeout: 60` мин, `secure: null` (авто по HTTPS), `httponly`, `samesite: Lax`, `regenerate_on_login`, `single_session_per_user`, `remember_me: {enabled, days: 30}` |
| 2FA | `two_factor.enabled`, `required_for: [admin]` (обязательна для ролей), `issuer`, `digits: 6`, `period: 30`, `window: ±1`, `grace_period_minutes: 10080` (7 дней на подключение), `recovery_codes: 8` |
| IP-allowlist | `ip_allowlist.enabled`, `ips`, `cidrs`, `apply_to: [admin, api]` (+ `panel`/`all`), `deny_action: block` или `log-only` |

Матрица ролей и прав — [12 · Безопасность](12-Security.md).

## `security` — защита

| Группа | Ключи |
|---|---|
| Заголовки | `headers.csp: true`, `csp_report_only`, `csp_extra` (добавить директивы, напр. `['img-src' => 'https:']`), `frame_ancestors: 'self'`, `frame_options: SAMEORIGIN`, `referrer_policy`, `permissions_policy`, `hsts_max_age`, `custom` |
| Rate-limit | `rate_limits`: `login 5/15мин`, `api 120/мин`, `gate_open 12/мин`, `guest_create 8/час`, `guest_open 20/мин`, `password_reset 3/час`, `status_poll 60/мин`; произвольные `'N,сек'` в маршрутах |
| Шифрование | `encryption.cipher: aes-256-gcm`, `encrypt_secrets_at_rest: true` |
| CSRF | `csrf.enabled`, `csrf.except` (имена маршрутов/паттерны `webhook.*`), `token_ttl: 7200` |
| Загрузки | `upload.max_bytes: 10 МБ`, `allowed_import_extensions: csv, json, txt, xlsx` |
| Cookie | `cookies.prefix_secure` (`__Secure-` на HTTPS) |
| Аудит | `audit.enabled`, `log_payload: false`, `retention_days: 365` |

## `billing` — оплаты

| Ключ | По умолчанию | Описание |
|---|---|---|
| `currency` / `currency_symbol` / `precision` | RUB / ₽ / 2 | Валюта и округление |
| `rounding` | `half_up` | half_up / up / down / half_even |
| `allocation` | `equal` | equal / by_persons / by_area / by_share — способ деления суммы |
| `period_unit` | `month` | month / quarter / year / custom |
| `due_days` | 14 | Срок оплаты по умолчанию |
| `invoice_prefix` / `charge_prefix` | СЧ- / НАЧ- | Префиксы номеров |
| `allow_partial` / `allow_overpay` / `overpay_to_balance` | true | Частичные оплаты, переплата → на баланс |
| `late_fee` | выкл | Пени: `percent`/`fixed` после `after_days` |
| `auto_mark_overdue` | true | Cron помечает просроченные счета |
| `auto_adjust` | true | Перерасчёт создаёт корректирующие счета |
| `count_only_active` | **true** | В расчёте участвуют только активные жители (не отключайте) |
| `count_only_with_access` | false | Дополнительно требовать флаг «доступ» |
| `share_column` | `share` | Поле доли для `by_share` |
| `reminder.before_due_days` / `after_due_days` | [3,1] / [1,7,30] | Напоминания до и после срока |

Модель денег целиком — [08 · Биллинг](08-Billing.md).

## `guest` — гостевой доступ

| Ключ | По умолчанию | Описание |
|---|---|---|
| `default_ttl_hours` | **12** | Срок пропуска по умолчанию |
| `max_ttl_hours` | 72 | Верхняя граница |
| `ttl_presets_hours` | 1…72 | Кнопки-пресеты в UI |
| `max_uses` | 0 | 0 = безлимита открытий в пределах срока |
| `max_active_per_resident` / `max_active_total` | 5 / 200 | Лимиты активных пропусков |
| `code_length` / `code_alphabet` | 6 / без 0,O,1,I… | Короткий код для клавиатуры/телефона |
| `signed_link` | true | HMAC-подпись ссылки (защита от подбора) |
| `require_guest_name` / `require_plate` | false | Обязательные поля гостя |
| `allow_self_service` | true | Жители сами создают пропуска |
| `rate_limit_per_resident_day` | 10 | Лимит созданий в день |
| `notify.on_create/on_use/on_expire` | true/false/false | Уведомления жителя |
| `auto_revoke_expired` | true | Cron гасит истёкшие |
| `pin_fallback` | true | PIN для клавиатуры панели |
| `car_sync` | false | Пуш госномера в whitelist устройства (driver-specific) |

## `notify` — уведомления

* `default: ['log']` — каналы по умолчанию (добавьте `mail`, `telegram`…).
* `one_per_channel: true` — не слать на каждый телефон человека.
* `channels.*` — включение и реквизиты: `mail` (SMTP), `telegram` (bot_token),
  `sms` (http-шлюз), `webhook` (url+secret), `whatsapp` (Cloud API), `log`.
* `mail.*` — SMTP: host/port/encryption/username/password/from/reply_to
  (собственный SMTP-клиент, phpmailer не нужен).
* `queue.*` — очередь: `enabled`, `driver: database|sync`, `max_attempts: 5`,
  `retry_backoff: [60,300,900,3600,10800]`, `batch_size`, `throttle_ms: 150`.
* `templates.*` — шаблоны `payment_new`, `payment_reminder`, `payment_overdue`,
  `payment_received`, `charge_recalculated`, `guest_created`, `guest_invite`,
  `guest_used`, `gate_opened`, `system_alert`, `welcome`. Подстановки:
  `{name}`, `{apartment}`, `{amount}` (уже с символом валюты), `{currency}`,
  `{period}`, `{due_date}`, `{pay_link}`, `{balance}`, `{code}`, `{link}`,
  `{expires_at}` и др.; модификаторы `{due_date|date:d.m.Y}`,
  `{name|upper}`, `{text|limit:80}`, `{count|plural:житель,жителя,жителей}`.
  Шаблоны переопределяются из UI (Админка → Уведомления → Шаблоны) и в
  таблице `settings` без деплоя.

Подробности — [09 · Уведомления](09-Notifications.md).

## `logging`, `cache`, `api`

* `logging.level` (`debug…critical`), `channels` (app, audit, gate, device,
  notify, error, access, update — отдельные файлы), `stderr`,
  `redact: [password, token, secret, authorization, cookie, otp]` — маскирование
  в логах автоматически.
* `cache.driver: file` (file/apcu/array), `ttl: 300`, `prefix`, `path`.
* `api.version: v1`, префикс `/api/v1`, `docs` — встроенная документация.

## `update` — автообновление

| Ключ | По умолчанию | Описание |
|---|---|---|
| `enabled` | true | Проверка новых версий |
| `channel` | `stable` | `stable` (latest release) / `beta` (включая pre-release) |
| `mode` | `notify` | `notify` (только уведомить) / `auto` (устанавливать само) |
| `repo` | `paulmann/BAS-IP` | Откуда обновляться |
| `api_url` / `download_url` | GitHub API | Замените на свой forge при необходимости |
| `token` | null | GitHub PAT (повышает rate-limit), храните как secret |
| `check_interval` | 86400 | Секунды между проверками |
| `verify_checksum` / `expected_sha256` | true / null | Проверка артефакта, pin точного SHA-256 |
| `backup_before` / `backup_database` | true | Бэкап файлов и БД перед установкой |
| `keep_releases` | 5 | Сколько снимков версий хранить для отката |
| `auto_migrate` | true | Прогнать миграции после обновления |
| `maintenance_during_update` | true | Включать режим обслуживания на время установки |
| `preserve` | `.bas-ip.config.local.php`, `storage`, `.env`, `public/install.lock` | **Никогда не перезаписывается** |
| `strategy` | `inplace` | `inplace` / `releases` (symlink-каталоги) |
| `allow_rollback` | true | Разрешить откат |
| `php_min` | 8.2.0 | Минимальный PHP для установки обновления |

## `backup`, `data`, `ui`, `integrations`

* `backup`: `keep: 14`, `include_db`, `include_storage`, `compress`,
  `encrypt` (ключом приложения), `schedule: '0 3 * * *'`, `offsite` (sftp).
* `data`: форматы экспорта `csv/json/sql`, импорта `csv/json`; `max_rows: 100000`;
  CSV: разделитель `;`, BOM для Excel; импорт: `mode: upsert`,
  `match_on: [apartment, full_name]`, `batch_size`, `skip_errors`.
* `ui`: `theme: system` (light/dark/system + личный выбор пользователя),
  `accent`, `radius`, `density`, `per_page`, `show_camera`, интервалы
  автообновления статусов/событий/камеры, `brand` (логотип/фавикон/название),
  `command_palette`, `animations`.
* `integrations`: `telegram_bot` (token, admin_chat_id, команды open/status/
  guest/balance/help, webhook+secret), `home_assistant`, `prometheus` (token).

---

## `.env` (опционально)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://gate.example.com
APP_KEY=base64:…
DB_DRIVER=mysql
DB_HOST=127.0.0.1
DB_DATABASE=bas_ip
DB_USERNAME=bas_ip
DB_PASSWORD=секрет
DEVICE_PASSWORD=секрет-панели
```

`.env` тоже в `.gitignore`. Шаблон — `.env.example`.

## Частые рецепты

**Сменить валюту/символ**: `billing.currency: 'KZT'`, `currency_symbol: '₸'`.

**Дом за NAT: доступ к админке только для своих**:
`auth.ip_allowlist: {enabled: true, cidrs: ['203.0.113.0/24'], apply_to: ['admin']}`.

**Панель с самоподписанным сертификатом**: `device.verify_tls: false`
(или `ca_bundle` с вашим CA) — и держите панель в изолированном VLAN.

**Всё в SQLite, один дом**: `db.default: 'sqlite'` — файл в `storage/app/`,
нулевое администрирование.

**Отключить камеру в панели оператора**: `ui.show_camera: false`.

Дальше: [04 · Архитектура](04-Architecture.md)
