# Участие в разработке

Спасибо, что хотите улучшить BAS-IP Gate Manager!

## Окружение разработчика

```bash
git clone https://github.com/paulmann/BAS-IP.git && cd BAS-IP
cp .bas-ip.config.local.php.example .bas-ip.config.local.php   # и отредактируйте
php bin/bas-ip migrate && php bin/bas-ip db:seed
php bin/bas-ip serve                     # http://localhost:8080
```

Требования: PHP 8.2+ (рекомендуется 8.5), Node.js 20+ (только для пересборки
ассетов), одна из СУБД (для разработки удобнее всего SQLite).

### Ассеты (Tailwind / иконки / шрифты)

```bash
npm ci
npm run build          # css (tailwind) + js-проверка/вендоринг + спрайт иконок
npm run watch          # пересборка css при изменениях
npm run vendor:fonts   # обновить woff2 из @fontsource
```

Коммитить нужно и исходники (`resources/css`), и собранные файлы
(`public/assets`) — приложение не требует Node на сервере.

### Тесты

```bash
php tests/run.php                        # SQLite
php tests/run.php --filter=Billing       # один класс
php tests/run.php --verbose              # по методам
BASIP_TEST_DB=mysql php tests/run.php    # MySQL (нужны BASIP_TEST_DB_* env)
BASIP_TEST_DB=pgsql php tests/run.php    # PostgreSQL
php tools/smoke.php [mysql|pgsql]        # end-to-end по всем маршрутам
```

Тесты не требуют PHPUnit — собственный раннер в `tests/run.php`.
Новая функциональность сопровождается тестами: деньги и права — обязательно.

## Стиль кода

* PHP 8.2+: `declare(strict_types=1)`, типизированные сигнатуры, readonly
  где уместно, именованные аргументы приветствуются.
* PSR-12 + `.editorconfig`; ширина ~120; комментарии и сообщения — по-русски
  там, где их читает конечный пользователь, docblock'и — по-английски.
* Никаких внешних зависимостей в runtime: ни composer-пакетов, ни CDN.
  Новый вендор-файл — только через `tools/` с лицензией.
* SQL — только через `BasIp\Db\Query` (prepared statements); сырой SQL
  допускается в миграциях и там, где диалект требует (помечать комментарием).
* Всё, что меняет данные, пишет аудит (`AuditService::record`).
* Пользовательские сообщения — на русском, с actionable-подсказкой.

## Процесс

1. Fork → ветка от `BAS-IP_2` (основная ветка разработки v2).
2. Небольшие сфокусированные коммиты; сообщения в формате
   `type(scope): что сделано` (fix, feat, docs, refactor, test, chore).
3. Перед PR: `php tests/run.php` зелёный, `php tools/smoke.php` зелёный,
   `npm run build` без изменений сверх ожидаемых.
4. PR опишите: мотивация, что изменилось, как проверено, скриншоты для UI.

## Что особенно приветствуется

* Тесты на uncovered-ветки (устройства, каналы уведомлений, обновления).
* Драйверы устройств (новые модели панелей/реле) — реализуйте
  `BasIp\Device\GateDriver`.
* Каналы уведомлений — реализуйте `BasIp\Notify\Channel`.
* Локализации интерфейса (сейчас ru; слой `Core\Lang` готов).
* Документация и примеры в wiki.

## Кодекс поведения

Будьте уважительны, критикуйте код, а не людей. Спорные решения принимает
сопровождающий проекта; безопасность — только через [SECURITY.md](SECURITY.md).
