# 04 · Архитектура

[← Home](Home.md)

Для тех, кто хочет понять код, доработать его или написать свой драйвер/канал.

## Принципы

1. **Без фреймворков и runtime-зависимостей.** Ни composer-пакетов, ни CDN:
   только PHP 8.2+ и PDO. Ядро — ~20 файлов, которые можно прочитать за вечер.
   Это осознанно: приложение управляет воротами дома и должно заводиться
   на любом хостинге с PHP и переживать 3 часа ночи без black-box магии.
2. **Прогрессивное улучшение.** Каждая страница работает без JavaScript
   (обычные формы), JS добавляет диалоги, AJAX, палитру команд, графики, QR.
3. **Деньги — целыми копейками.** Суммы хранятся и считаются в минимальных
   единицах (`Money::parse/toFloat/split`), расщепление без потерь,
   округление документируемое.
4. **Всё пишется в журнал.** Открывания, аудит действий, входы, устройства,
   уведомления — отдельные каналы логов с request-id.
5. **Один контейнер — одна точка сборки.** `src/bootstrap.php` связывает
   сервисы; все точки входа (web, CLI, тесты) используют его.

## Каталог

```
├── bin/bas-ip                 # консольный вход (Kernel + 71 команда)
├── config/
│   ├── config.php             # все значения по умолчанию (документированы)
│   └── routes.php             # единая таблица маршрутов с middleware-опциями
├── database/
│   ├── migrations/            # 5 файлов схемы (переносимы между СУБД)
│   └── seeds/demo.php         # демо-данные (24 жителя, машины, счета, события)
├── public/                    # ДОКУМЕНТ-РУТ
│   ├── index.php              # front controller
│   ├── router.php             # для php -S
│   ├── bas-ip.php, bas_ip.php, get_last_image.php   # legacy v1 (форвардеры)
│   └── assets/                # собранные css/js/шрифты/иконки (локально)
├── resources/
│   ├── css/                   # исходники: app.css (вход), tokens (shadcn),
│   │                          #   components (примитивы), fonts (woff2)
│   ├── views/                 # PHP-шаблоны: layouts, admin, cabinet, guest,
│   │                          #   auth, help, errors, components
│   └── help/help.php          # встроенная справка (36 разделов, данные)
├── src/BasIp/
│   ├── Core/                  # App, Container, Router, Request, Response,
│   │                          #   Session, Csrf, Crypto, Secrets, Logger,
│   │                          #   RateLimiter, Cache, Clock, Config, View,
│   │                          #   Lang, HttpClient(+Response), Events
│   ├── Db/                    # Database (PDO+диалекты), Query builder,
│   │                          #   Schema/Blueprint, Migrator, Expression
│   ├── Auth/                  # Guard, User, AuthService, Permissions,
│   │                          #   ApiPrincipal, Totp
│   ├── Http/                  # Controller, RoutingException,
│   │   ├── Controllers/       #   Admin\*, Api\*, Auth, Cabinet, Guest,
│   │   │                      #   Panel, Dashboard, Help, Install, System
│   │   └── Middleware/        #   TrustProxies, SecurityHeaders, Maintenance,
│   │                          #   IpAllowlist, StartSession, VerifyCsrf,
│   │                          #   RateLimit, Authenticate, Authorize
│   ├── Domain/
│   │   ├── Model/             # Entity, Resident, Contact, Car, Charge,
│   │   │                      #   Invoice, Payment, GuestPass, GateEvent, Device
│   │   └── Repository/        # ResidentRepository (гидратация, batch-relations)
│   ├── Services/              # Resident, Billing, Gate, GuestPass, Notify-
│   │                          #   смежные, Import/Export, Backup, Settings,
│   │                          #   Statistics, Audit, ApiToken, Announcement,
│   │                          #   User, Data
│   ├── Notify/                # NotificationService, QueueWorker, Message,
│   │   └── Channel/           #   TemplateRenderer, SmtpClient + log/mail/
│   │                          #   telegram/sms/whatsapp/webhook
│   ├── Device/                # DeviceManager, GateDriver (интерфейс),
│   │   └── Driver/            #   BasIpDriver, HttpRelayDriver, DryRunDriver
│   ├── Update/                # UpdateService, Release
│   ├── Console/               # Kernel, Command, Input, Output + commands/
│   ├── Support/               # Arr, Str, Money, Phone, Plate, Validator,
│   │                          #   Paginator, Markdown, Ip, Fs, Env
│   ├── autoload.php           # PSR-4 автозагрузчик (без composer)
│   └── bootstrap.php          # сборка контейнера
├── storage/                   # runtime (вне git): logs, app/{backups,exports,
│                              #   queue,tokens,cache,updates}, framework/{sessions,views}
├── tests/                     # Feature + Unit + Support (TestApp/TestCase),
│                              #   run.php — собственный раннер
├── tools/                     # smoke.php, migrate-smoke.php, build-*.mjs
└── wiki/                      # эта документация
```

## Жизненный цикл запроса

```
public/index.php
  └─ Config::boot(root)          # config.php ← .bas-ip.config.local.php ← .env
  └─ App::boot(bootstrap.php)    # контейнер: все биндинги ленивые
  └─ App::run()
       ├─ Request::capture()
       └─ App::handle(Request)
            ├─ сброс request-scoped состояния (Guard::reset, auth.*/route)
            ├─ Session::start + ageFlash, access-логгер с request-id
            ├─ Router::dispatch(method, path)      # 404/405 → RoutingException
            ├─ MiddlewarePipeline (луковица):
            │    TrustProxies → SecurityHeaders → MaintenanceMode →
            │    IpAllowlist → StartSession → VerifyCsrf → RateLimit →
            │    Authenticate → Authorize → [контроллер]
            ├─ callAction(handler)                 # контроллер из контейнера
            └─ Response
       └─ finish(): debug-заголовки, session touch, логгер close
       └─ Response::emit()
```

Middleware читают **опции маршрута** из `config/routes.php`:
`auth` (`true` — сессия, `'api'` — Bearer-токен), `roles`, `permission`,
`permissions` (ALL), `any` (ANY), `scopes` (ANY-OF для API-токенов),
`throttle` (имя пресета или `'N,секунд'`), `csrf_except`, `name`.

Ошибки рендерит `App::renderThrowable`: для HTML — шаблоны `errors/*`
(400/401/403/404/405/419/422/429/500/503), для JSON — стабильная форма
`{ok:false, error, status, request_id}` (+ стектрейс только при `debug`).
Базовые security-заголовки добавляются и на error-пути.

## Контейнер

`Core\Container` — ~150 строк: `bind/factory/set/get/extend/forget`,
ленивое разрешение с автовайрингом конструкторов по типам. Синглтоны:
Config, Database, Session, Guard, сервисы. Request-scoped ключи
(`auth.user`, `api.principal`, `cspNonce`, `route`) сбрасываются в начале
каждого `handle()` — ядро ре-ентерабельно (тесты, очереди, демоны).

## Слой БД

* `Database` — обёртка PDO: ленивое соединение, `reload()` (смена конфига
  на лету — нужно установщику), транзакции с вложенностью (savepoints),
  статистика запросов, диалектные хелперы (`isMySql/isPgsql`,
  `quoteIdentifier`, upsert-синтаксис).
* `Query` — билдер: select/join/where-группы/orderBy/limit/offset,
  `insertGetId`, `insertBatch`, `value`, `pluck`, пагинация; только
  prepared statements. `Expression`/`SubQuery` для сырых фрагментов.
* `Schema`/`Blueprint` — переносимые миграции: типы колонок маппятся в
  диалект (MySQL/PostgreSQL/SQLite), индексы, внешние ключи с cascade.
* `Migrator` — журнал `migrations`, up/down, `--step`, fresh/rollback/status.

Схема (5 миграций): `residents`, `contacts`, `cars`, `users`, `sessions_meta`,
`login_attempts`, `charges`, `invoices`, `payments`, `billing_history`,
`gate_events`, `guest_passes`, `devices`, `access_schedules`, `announcements`,
`api_tokens`, `outbox`, `notification_batches`, `audit_log`, `settings`,
`app_state`, `backups`, `import_jobs`, `export_jobs`.

## Домен и сервисы

Модели — лёгкие сущности (`fromRow`/`toArray`, доменные методы:
`Resident::shortName()`, `Invoice::isOverdue()`, `User::can()` через
`Permissions`). Логика — в сервисах, контроллеры тонкие:

| Сервис | Зона ответственности |
|---|---|
| `ResidentService` | CRUD жителей, контакты, машины, флаги, поиск, история |
| `BillingService` | preview/charge/recalc/invoices/payments(FIFO)/balances/debtors/reminders |
| `GateService` | открытие/закрытие, журнал событий, снимки, статистика, cooldown |
| `GuestPassService` | выпуск/использование/отзыв пропусков, коды, подписи, PIN |
| `ImportExportService` | инспекция, маппинг, upsert-импорт, экспорт CSV/JSON |
| `BackupService` | дампы БД (mysqldump/pg_dump/sqlite), zip, ротация, восстановление |
| `NotificationService` + `QueueWorker` | resolve получателей, очередь (outbox), ретраи, батчи |
| `AuditService` | журнал действий с актёром, субъектом и meta |
| `SettingsService` | key-value поверх таблицы `settings` (приоритет над конфигом) |
| `ApiTokenService` | токены с областями, хэширование, срок, отзыв |
| `UpdateService` | проверка GitHub, скачивание, checksum, установка, снапшоты, откат |
| `StatisticsService` | сводки для дашбордов |

## Устройства

Интерфейс `GateDriver`: `open/close/status/reboot/snapshot/sip…` +
`supports(capability)`. Реализации:

* **`BasIpDriver`** — Camdroid `/api/v0`: логин → токен устройства (TTL,
  авто-рефреш, хранение в БД/файле/кэше), открытие замка по каналу, снимки
  камеры, SIP, перезагрузка; таймауты/ретраи из конфига.
* **`HttpRelayDriver`** — произвольный HTTP-контроллер: URL/метод/тело
  настраиваются, для DIY-реле и сторонних шлюзов.
* **`DryRunDriver`** — эмулятор: всё «открывается», пишет события; для
  разработки, демо и тестов.

`DeviceManager` маршрутизирует между несколькими устройствами (`device.gates`),
кеширует статусы, пишет канал `device` в лог.

## Уведомления

`Message` (immutable fluent) → `NotificationService::resolve()` (каналы →
контакты жителя, fallback на денормализованные email/phone) → таблица
`outbox` → `QueueWorker` (claim с lease, ретраи с экспоненциальной задержкой,
статусы, батчи) → `Channel`-ы. `TemplateRenderer` — подстановки с
модификаторами и удалением «повисших» строк-подписей. SMTP-клиент собственный
(`SmtpClient`: STARTTLS/AUTH/plain).

## Представления

`Core\View` — PHP-шаблоны с layout-ами, экранированием `e()`, компонентами
(`include('components/…')`), хелперами: `url()`, `asset()` (cache-busting по
mtime), `icon()` (SVG-спрайт Lucide), `csrf()`, `flashPayload()`, `setting()`,
Markdown-рендер для справки. Компиляция не нужна; кэш видов опционален.

## Фронтенд

* **Tailwind CSS 3** (JIT, локальный CLI) + **дизайн-токены shadcn/ui**
  (HSL-переменные light/dark в `tokens.css`) + **hand-ported shadcn-примитивы**
  (`components.css`: btn, card, input, dialog, sheet, dropdown, tabs, toast,
  badge, table…).
* **theme.js** (инлайннится в `<head>` до отрисовки): порядок выбора темы —
  сохранённый выбор пользователя → localStorage `basip:theme` →
  `prefers-color-scheme` → light; переключатель в шапке, системная тема
  отслеживается live.
* **app.js/admin.js/panel.js/guest.js** — vanilla ES2020: диалоги, тосты,
  AJAX-формы, командная палитра (Ctrl+K), поллинг статусов, графики,
  QR (vendored qrcode-generator с фиксом transpose-бага), countdown,
  copy-to-clipboard, confirmation-диалоги.
* **Сборка** (`npm run build`): `tools/build-js.mjs` (проверка синтаксиса +
  вендоринг QR с патчем), `tools/build-icons.mjs` (сканирует `icon('…')` по
  всему коду → спрайт из lucide-static + манифест; падает на неизвестном
  имени), tailwind CLI → `public/assets/css/app.css`.
  `tools/vendor-fonts.mjs` — woff2 из @fontsource (Inter, JetBrains Mono;
  latin+cyrillic) со сверкой с `fonts.css`.

## Тесты

Собственный раннер `tests/run.php` (без PHPUnit): классы в `tests/Unit` и
`tests/Feature`, `TestCase` с ассертами, `TestApp` — поднимает приложение на
временной SQLite (или MySQL/PostgreSQL через `BASIP_TEST_DB`), мигрирует,
даёт фикстуры (`makeAdmin/makeResident`) и диспетчер запросов через полный
стек (`TestApp::request`). Покрытие: деньги (до копейки), права, CSRF,
заголовки, гостевые пропуска, импорт/экспорт, TOTP, обновления, support-утилиты.
`tools/smoke.php` — end-to-end по всем значимым маршрутам (68 проверок)
на любой из трёх СУБД.

## Что читать дальше в коде

* Точка входа: `public/index.php` → `src/bootstrap.php` → `Core/App.php`.
* Маршруты и права: `config/routes.php` (одна таблица — вся карта приложения).
* Деньги: `Services/BillingService.php` + `Support/Money.php` + `tests/Feature/BillingTest.php`.
* Открытие ворот: `Services/GateService.php` + `Device/Driver/BasIpDriver.php`.
* Безопасность: `Http/Middleware/*` + `Auth/*`.

Дальше: [05 · Гид администратора](05-Admin-Guide.md)
