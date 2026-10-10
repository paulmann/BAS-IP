# 12 · Безопасность

[← Home](Home.md)

Модель угроз простая: это интернет-приложение, которое **физически открывает
ворота** и хранит персональные данные жителей. Поэтому защита многослойная.

## Роли и права

| Роль | Что может |
|---|---|
| `admin` | всё (`*`), включая настройки, пользователей, бэкапы, обновления |
| `operator` | жители (view/create/edit), касса (приём оплат, начисления, перерасчёт), шлагбаум (open, журнал), гостевые, рассылки, объявления, экспорт, просмотр логов/аудита |
| `resident` | свой кабинет: открыть свой шлагбаум, свои гостевые, свои счета |

40+ точечных прав (`Permissions::ALL`) применяются middleware `Authorize`
по опциям маршрутов + дополнительно в контроллерах
(`Guard::requirePermission`). Жители ограничены строково: каждый запрос
сверяет `resident_id` с сессией. API-токены дают **области**, а не роли —
утёкший токен автоматизации не станет админской сессией.

## Аутентификация

* пароли: `argon2id` (при наличии sodium) иначе bcrypt; политика длины/
  сложности, история паролей (нельзя повторять последние N), опциональная
  проверка утечек (haveibeenpwned, k-anonymity);
* блокировка после 5 неудач на 30 мин + журнал входов + защита от
  перечисления логинов (одинаковый ответ «неверная пара»);
* **2FA TOTP** (Google Authenticator и совместимые): обязательна для ролей
  из `two_factor.required_for` (default: admin) с grace-периодом 7 дней,
  recovery-коды (8 шт.); сброс админом `user:2fa-reset`;
* «запомнить меня» — подписанная ротационная cookie (30 дней), отзываемая;
* сессии: HttpOnly + SameSite=Lax + Secure(авто) + `__Secure-` prefix на
  HTTPS, absolute lifetime 12 ч, idle 60 мин, регенерация ID при логине,
  опциональная привязка к отпечатку UA/IP, таблица сессий с отзывом из UI,
  «выйти везде» при смене пароля.

## Транспорт и браузер

* CSP `default-src 'self'`, скрипты только `'self'` + per-request **nonce**
  (никаких inline-скриптов и `unsafe-eval`), стили `'unsafe-inline'`
  (нужен theme-bootstrap'у — одна переменная), `frame-ancestors 'self'`,
  `object-src 'none'`, `base-uri`/`form-action 'self'`;
* `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`,
  `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`
  (камера/микрофон/геолокация выключены), HSTS на HTTPS,
  `Cross-Origin-Opener/Resource-Policy`;
* `Cache-Control: no-store` на весь динамический HTML/JSON (приватные данные
  не должны оседать в кэшах); длинные кэши — только на fingerprinted-ассеты;
* заголовки ставятся на **всех** путях, включая страницы ошибок.

## Инъекции и подделки

* SQL — только prepared statements (PDO); сырые фрагменты — только через
  явные `Expression` в миграциях/диалектах;
* вывод — экранирование `$this->e()` во всех шаблонах; Markdown-рендер
  справки санитизирует HTML;
* CSRF — synchronizer-token на все POST (форма-поле `_token` или заголовок
  `X-CSRF-Token`), TTL 2 ч, ротация при логине; исключения — подписанные
  гостевые ссылки (HMAC вместо сессии) и telegram-webhook (свой секрет);
* подписанные URL гостевых пропусков: токен 48 hex + HMAC ключом приложения,
  подобрать нельзя, отзыв мгновенный;
* загрузки: whitelist расширений (csv/json/txt/xlsx), лимит 10 МБ, хранение
  вне web-рут, парсинг без выполнения;
* mass-assignment исключён: контроллеры берут явные поля через валидатор.

## Rate limiting и DoS

Отдельные лимиты: логин 5/15 мин, `gate_open` 12/мин + cooldown устройства,
`guest_create` 8/час, `guest_open` 20/мин, `password_reset` 3/час,
API 120/мин. `429` + `Retry-After`. Хранение — файл/кэш с GC по cron.

## Секреты

* `.bas-ip.config.local.php` вне git, права 0600 (установщик выставляет сам,
  `doctor` проверяет);
* шифрование секретов at-rest: `storage/` + ключ приложения (AES-256-GCM) —
  пароли устройств, бот-токены (`secret:set/list/forget/reencrypt`);
* маскирование в логах: `password, token, secret, authorization, cookie, otp`
  — автоматически (`logging.redact`);
* API-токены хранятся хэшами, показываются один раз;
* пароль устройства в устанавливаемом конфиге — `${secret:DEVICE_PASSWORD}`,
  не plaintext.

## Аудит и наблюдение

* `audit_log`: каждое значимое действие — актёр (user/api/system), действие,
  субъект (resident/charge/device/…), meta, IP, request_id; экспорт;
* журнал входов (успех/неудача/блокировки/2FA), сессии;
* каналы логов: app, access, audit, gate, device, notify, error, update —
  с ротацией по дням и политикой хранения (`prune`);
* `/health.json` + `/metrics` для мониторинга; `X-Request-Id` сквозной.

## Периметр

* документ-рут — только `public/`; корень закрыт `.htaccess`; nginx-конфиг
  из wiki запрещает dotfiles;
* `auth.ip_allowlist` — админка/API только со своих IP (опция);
* режим обслуживания закрывает UI, **не блокируя открытие ворот** (жители
  не должны страдать от деплоя);
* установщик самоблокируется (`install.lock`, 403 после установки);
* обновления: checksum артефакта (опционально pin SHA-256), бэкап файлов и
  БД перед установкой, preserve-список (конфиг/storage не перезаписываются),
  откат.

## Чек-лист production-развёртывания

1. HTTPS (Let's Encrypt), `app.url` с `https://`, HSTS включён.
2. `env=production`, `debug=false`.
3. Уникальный `app.key` (сгенерирован установщиком).
4. Права: `storage` 0750/www-data, конфиг 0600, код 0640 root.
5. 2FA включена у всех staff; пароли уникальные.
6. Отдельная БД-учётка без FILE/SUPER-прав; БД не наружу.
7. API-токены: минимальные scopes, сроки, отдельные на интеграцию.
8. `ip_allowlist` для админки, если есть постоянные IP.
9. cron: `schedule:run` + `backup:create`; бэкапы — offsite.
10. Мониторинг `/health` и логов `error`/`audit` (`is_security: true`).
11. `php bin/bas-ip doctor` — 0 ошибок, предупреждения закрыты.
12. Если использовался v1 — **отозвать старый `.api_token` панели**.

## Сообщение об уязвимостях

Только приватно — [SECURITY.md](https://github.com/paulmann/BAS-IP/blob/main/SECURITY.md). Публичные issue по
безопасности удаляются.

---
Дальше: [13 · Бэкап и восстановление](13-Backup-Restore.md)
