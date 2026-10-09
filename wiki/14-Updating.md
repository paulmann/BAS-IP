# 14 · Обновления

[← Home](Home.md)

Приложение умеет обновлять себя из GitHub-репозитория: проверка новых релизов,
скачивание, контрольные суммы, бэкап файлов и базы, режим обслуживания,
миграции и откат.

## Как это работает

```
update:check ──► GitHub API: /releases/latest (stable) или /releases (beta)
                 │ нет релизов? → fallback на теги
                 ▼
          сравнение версии (semver, без metadata)
                 ▼
update:run  ──► download zipball/artifact
                 ├─ verify_checksum (sha256; можно pin expected_sha256)
                 ├─ snapshot текущих файлов (keep_releases=5)
                 ├─ backup БД (если backup_database)
                 ├─ maintenance ON
                 ├─ распаковка поверх, PRESERVE-список не трогается:
                 │     .bas-ip.config.local.php · storage/ · .env ·
                 │     public/install.lock
                 ├─ auto_migrate (bin/bas-ip migrate)
                 └─ maintenance OFF, запись в аудит и update-лог
```

## Режимы

| Настройка | Значения |
|---|---|
| `update.mode` | `notify` (по умолчанию: только показать в админке) / `auto` (ставить автоматически из cron) |
| `update.channel` | `stable` (latest release) / `beta` (включая pre-release) |
| `update.check_interval` | секунд между проверками (86400) |
| `update.repo` | `paulmann/BAS-IP` (можно свой форк) |
| `update.token` | GitHub PAT — поднимает rate-limit (храните как secret) |

Проверка происходит: по cron (`schedule:run --update`), вручную в UI
(**Админка → Система → Обновления → Проверить**) и `bin/bas-ip update:check`.

## Обновление через UI

**Админка → Система → Обновления**:

* текущая версия, последняя доступная, дата проверки;
* changelog новой версии (из релиза и локального CHANGELOG.md);
* кнопка **«Установить обновление»**: показывает план (бэкап, preserve,
  миграции), требует подтверждения, выполняет и показывает лог шагов;
* **снимки версий** и кнопка **«Откатить»**;
* требования: PHP ≥ `update.php_min` (8.2), права на запись, наличие zip.

## Обновление через CLI

```bash
php bin/bas-ip update:check [--force] [--json]   # есть ли новая версия
php bin/bas-ip update:run [--yes] [--ref=v2.1.0] [--no-backup] [--archive=путь.zip]
php bin/bas-ip update:snapshots                  # сохранённые снимки
php bin/bas-ip update:rollback [--yes]           # откат на предыдущую
```

`--ref` ставит конкретный тег/ветку; `--archive` — установка из уже
скачанного zip (offline-серверы).

## Обновление через git (вручную)

Код не содержит сгенерированных артефактов, которые нельзя пересобрать,
поэтому обычный git-путь полностью поддержан:

```bash
cd /var/www/bas-ip
php bin/bas-ip maintenance --on
git pull origin BAS-IP_2
npm ci && npm run build        # только если менялся фронтенд и нет готовых ассетов в релизе
php bin/bas-ip migrate
php bin/bas-ip cache:clear
php bin/bas-ip maintenance --off
php bin/bas-ip doctor
```

`.bas-ip.config.local.php` и `storage/` в git не участвуют — настройки
и данные переживают обновление автоматически.

## Стратегии развёртывания

* `inplace` (default) — обновление прямо в каталоге + снимки для отката.
* `releases` — каждый релиз в `../releases/<version>`, `current` — symlink;
  переключение атомарное, откат = переключение symlink. Для аккуратных
  деплоев за балансировщиком.

## Откат

`update:rollback` (или кнопка в UI) восстанавливает предыдущий снимок файлов.
База данных откатывается отдельно — `backup:restore` соответствующей копии
(поэтому `backup_database: true` перед обновлением — не отключайте).
Миграции, применённые новой версией, автоматически не откатываются
(осознанно: схема совместима с предыдущей версией в пределах релиза).

## Рекомендации для мейнтейнеров релизов

* Публикуйте релизы GitHub с тегом `vX.Y.Z` и осмысленными release notes —
  их видят пользователи в админке.
* Прикладывайте артефакт `bas-ip-X.Y.Z.zip` (+ `.sha256`) — установка из
  asset быстрее и проверяемее zipball'а.
* Семантика версий: миграции схемы — только в minor/major; patch — без схемных
  изменений (тогда откат файлов безопасен).

## Частые проблемы

| Симптом | Причина / решение |
|---|---|
| «В репозитории нет ни релизов, ни тегов» | в репозитории ещё нет релизов — обновляйтесь через git или опубликуйте релиз |
| 403/429 от GitHub API | исчерпан анонимный rate-limit — укажите `update.token` |
| «Недостаточно прав» при установке | `storage/` и код должны быть доступны на запись пользователю php-fpm |
| Обновление не предлагает новую версию | `update.enabled=false` в локальном конфиге или кэш проверки (`--force`) |
| После обновления белый экран | `logs:tail error`; обычно права на `storage/` или незавершённые миграции — `migrate` + `doctor` |

---
Дальше: [15 · CLI-справочник](15-CLI.md)
