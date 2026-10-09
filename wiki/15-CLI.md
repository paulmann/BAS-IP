# 15 · CLI-справочник (bin/bas-ip)

[← Home](Home.md)

Консоль управления — 71 команда: установка, миграции, жители, оплаты, шлагбаум,
гостевые пропуска, уведомления, бэкапы, обновления, диагностика.

```bash
php bin/bas-ip                 # список команд по группам
php bin/bas-ip <команда> --help
```

Глобальные опции: `--help/-h`, `--verbose/-v` (стектрейсы), `--quiet/-q`
(для cron), `--no-ansi`. Неизвестная опция — ошибка с подсказкой (опечатки
не проходят молча). Код возврата: 0 — успех, 1 — ошибка (удобно для cron
и мониторинга).

## Содержание

* [База данных](#база-данных)
* [Гостевой доступ](#гостевой-доступ)
* [Диагностика](#диагностика)
* [Жители](#жители)
* [Обновления](#обновления)
* [Обслуживание](#обслуживание)
* [Оплаты](#оплаты)
* [Планировщик](#планировщик)
* [Пользователи](#пользователи)
* [Разработка](#разработка)
* [Резервные копии](#резервные-копии)
* [Уведомления](#уведомления)
* [Установка](#установка)
* [Шлагбаум](#шлагбаум)

## База данных

### `db:seed`

Загрузить демо-данные (жители, машины, начисления, оплаты)

```
bin/bas-ip db:seed [--residents=24]
```

| Параметр | Описание |
|---|---|
| `--residents` | Сколько жителей создать |

### `migrate`

Применить ожидающие миграции

```
bin/bas-ip migrate [--step=N] [--force] [--seed]
```

| Параметр | Описание |
|---|---|
| `--step` | Применить только N миграций |
| `--force` | Продолжать при ошибках |
| `--seed` | Заполнить демо-данными после миграций |

### `migrate:fresh`

Снести схему и накатить заново (ОПАСНО)

```
bin/bas-ip migrate:fresh [--seed] [--yes]
```

| Параметр | Описание |
|---|---|
| `--seed` | Заполнить демо-данными |
| `--yes` | Не спрашивать подтверждения |

### `migrate:rollback`

Откатить последний пакет миграций

```
bin/bas-ip migrate:rollback [--step=1]
```

| Параметр | Описание |
|---|---|
| `--step` | Сколько пакетов откатить (по умолчанию 1) |
| `--force` | Без подтверждения |

### `migrate:status`

Показать состояние миграций

```
bin/bas-ip migrate:status
```

## Гостевой доступ

### `guest:create`

Создать гостевой доступ (по умолчанию 12 часов)

```
bin/bas-ip guest:create --resident=ID [--hours=12] [--name=…] [--plate=…] [--phone=…]
```

| Параметр | Описание |
|---|---|
| `--resident` | ID жителя (обязательно) |
| `--apartment` | Номер квартиры вместо ID |
| `--hours` | Срок действия в часах |
| `--name` | Имя гостя |
| `--phone` | Телефон гостя |
| `--plate` | Госномер |
| `--purpose` | Цель визита |
| `--uses` | Лимит использований (0 = без лимита) |
| `--url` | База для ссылки (по умолчанию app.url) |

### `guest:expire`

Пометить истёкшие гостевые доступы (обычно вызывает cron)

```
bin/bas-ip guest:expire
```

### `guest:list`

Список гостевых доступов

```
bin/bas-ip guest:list [--status=active] [--resident=ID] [--limit=20]
```

| Параметр | Описание |
|---|---|
| `--status` | active|expired|revoked|all |
| `--resident` | ID жителя |
| `--limit` | Количество |

### `guest:revoke`

Отозвать гостевой доступ по коду или ID

```
bin/bas-ip guest:revoke <код|ID> [--reason=…]
```

| Параметр | Описание |
|---|---|
| `pass` (аргумент) | Код (7K4M-Q2XD) или числовой ID |
| `--reason` | Причина отзыва |

## Диагностика

### `about`

Сводка об установке: версия, окружение, база, счётчики

```
bin/bas-ip about
```

### `config:show`

Показать итоговую конфигурацию (секреты скрыты)

```
bin/bas-ip config:show [ключ] [--json]
```

| Параметр | Описание |
|---|---|
| `key` (аргумент) | Точечный ключ, например db.default |
| `--json` | Вывод в JSON |

### `doctor`

Самодиагностика: PHP, БД, права, безопасность, устройство

```
bin/bas-ip doctor [--json] [--fix]
```

| Параметр | Описание |
|---|---|
| `--json` | Вывод в JSON |
| `--fix` | Исправить то, что можно автоматически |

### `health`

Проверка состояния (для мониторинга; код возврата 0 = OK)

```
bin/bas-ip health [--deep] [--json]
```

| Параметр | Описание |
|---|---|
| `--deep` | Проверить и устройство |
| `--json` | Вывод в JSON |

### `version`

Версия приложения и окружения

```
bin/bas-ip version
```

## Жители

### `residents:count`

Сводка по базе жителей (учитывается в расчётах)

```
bin/bas-ip residents:count
```

### `residents:export`

Выгрузить базу жителей (CSV/JSON/TXT)

```
bin/bas-ip residents:export [--format=csv] [--status=active] [--out=FILE]
```

| Параметр | Описание |
|---|---|
| `--format` | csv | json | txt |
| `--status` | active | inactive | all | deleted | debtors |
| `--out` | Куда сохранить (по умолчанию storage/app/exports) |
| `--include` | Что включать: contacts,cars,payments,invoices |

### `residents:import`

Импорт жителей из CSV/JSON

```
bin/bas-ip residents:import FILE [--mode=upsert] [--dry-run] [--delimiter=auto]
```

| Параметр | Описание |
|---|---|
| `file` (аргумент) | Путь к CSV или JSON |
| `--mode` | dry-run | upsert | insert | replace |
| `--dry-run` | Только проверить, ничего не менять |
| `--delimiter` | Разделитель CSV (auto, ;, ,, tab) |
| `--match` | Поля сопоставления через запятую (apartment,full_name) |
| `--skip-errors` | Продолжать при ошибках в строках |

### `residents:list`

Список жителей (по умолчанию только активные)

```
bin/bas-ip residents:list [--status=active|inactive|all|debtors] [--q=поиск] [--limit=25]
```

| Параметр | Описание |
|---|---|
| `--status` | active (по умолчанию) | inactive | all | no_access | deleted | debtors |
| `--q` | Поиск по ФИО, квартире, телефону, почте, машине |
| `--building` | Корпус |
| `--limit` | Количество записей |
| `--json` | Вывод в JSON |
| `--sort` | apartment | full_name | balance | debt |
| `--dir` | asc | desc |

### `residents:reindex`

Перестроить поисковые индексы и синхронизировать основные контакты

```
bin/bas-ip residents:reindex
```

### `residents:set-flag`

Массово включить/выключить жителей или доступ

```
bin/bas-ip residents:set-flag --flag=is_active --value=1 [--ids=1,2,3] [--all] [--yes]
```

| Параметр | Описание |
|---|---|
| `--flag` | is_active | access_granted | is_billed | notify_enabled | allow_guest_pass |
| `--value` | 1 или 0 |
| `--ids` | Список ID через запятую |
| `--all` | Применить ко всем активным |
| `--yes` | Без подтверждения |

### `residents:show`

Карточка жителя: контакты, машины, счета, оплаты, журнал

```
bin/bas-ip residents:show ID
```

| Параметр | Описание |
|---|---|
| `id` (аргумент) | ID жителя |

## Обновления

### `update:check`

Проверить наличие новой версии в репозитории

```
bin/bas-ip update:check [--force] [--json]
```

| Параметр | Описание |
|---|---|
| `--force` | Игнорировать кэш |
| `--json` | Вывод в JSON |

### `update:rollback`

Откатиться к предыдущей версии из снимка

```
bin/bas-ip update:rollback [--yes]
```

| Параметр | Описание |
|---|---|
| `--yes` | Без подтверждения |

### `update:run`

Скачать и установить новую версию

```
bin/bas-ip update:run [--yes] [--no-backup] [--ref=v2.1.0]
```

| Параметр | Описание |
|---|---|
| `--yes` | Без подтверждения |
| `--no-backup` | Пропустить резервную копию |
| `--ref` | Конкретный тег/ветка |
| `--archive` | Путь к уже скачанному zip |

### `update:snapshots`

Показать сохранённые снимки версий

```
bin/bas-ip update:snapshots
```

## Обслуживание

### `api:token`

Создать API-токен для внешних интеграций

```
bin/bas-ip api:token --name="Home Assistant" [--scopes=gate.open,gate.log] [--days=365]
```

| Параметр | Описание |
|---|---|
| `--name` | Название токена |
| `--scopes` | Области доступа через запятую |
| `--days` | Срок действия в днях (0 = бессрочно) |

### `api:tokens`

Список API-токенов

```
bin/bas-ip api:tokens [--revoke=ID]
```

| Параметр | Описание |
|---|---|
| `--revoke` | Отозвать токен по ID |

### `cache:clear`

Очистить кэш приложения и снимки состояния устройств

```
bin/bas-ip cache:clear
```

### `logs:list`

Список файлов журналов с размерами

```
bin/bas-ip logs:list [--truncate=канал]
```

| Параметр | Описание |
|---|---|
| `--truncate` | Очистить указанный канал |

### `logs:tail`

Показать последние строки журнала

```
bin/bas-ip logs:tail [канал] [--lines=100] [--level=error] [--follow]
```

| Параметр | Описание |
|---|---|
| `channel` (аргумент) | app | audit | gate | device | notify | error | access | update |
| `--lines` | Сколько строк |
| `--level` | Фильтр по уровню |
| `--q` | Поиск |
| `--follow` | Следить (как tail -f) |

### `maintenance`

Включить/выключить режим обслуживания

```
bin/bas-ip maintenance [--on] [--off] [--message=…]
```

| Параметр | Описание |
|---|---|
| `--on` | Включить |
| `--off` | Выключить |
| `--message` | Сообщение для посетителей |

### `prune`

Удалить устаревшие записи согласно политике хранения

```
bin/bas-ip prune [--gate-days=730] [--audit-days=365] [--yes]
```

| Параметр | Описание |
|---|---|
| `--gate-days` | Хранить журнал шлагбаума, дней |
| `--audit-days` | Хранить аудит, дней |
| `--login-days` | Хранить попытки входа, дней |
| `--outbox-days` | Хранить отправленные уведомления, дней |
| `--history-days` | Хранить историю начислений, дней |
| `--guest-days` | Хранить закрытые гостевые доступы, дней |
| `--yes` | Без подтверждения |

## Оплаты

### `billing:balances`

Пересчитать кешированные балансы всех жителей

```
bin/bas-ip billing:balances
```

### `billing:charge`

Создать начисление и выставить счета активным жителям

```
bin/bas-ip billing:charge --total=15000 [--period="09.2026"] [--due-days=14] [--notify] [--dry-run]
```

| Параметр | Описание |
|---|---|
| `--total` | Общая сумма |
| `--allocation` | Способ распределения |
| `--period` | Период, например 09.2026 |
| `--title` | Название |
| `--due-days` | Срок оплаты в днях |
| `--due-date` | Точная дата (YYYY-MM-DD) |
| `--pay-link` | Ссылка на оплату |
| `--message` | Текст сообщения |
| `--notify` | Сразу поставить рассылку в очередь |
| `--template` | Шаблон письма |
| `--dry-run` | Только показать расчёт, ничего не создавать |
| `--no-issue` | Создать черновик без выставления счетов |
| `--yes` | Не спрашивать подтверждение |

### `billing:charges`

Список начислений

```
bin/bas-ip billing:charges [--limit=20]
```

| Параметр | Описание |
|---|---|
| `--limit` | Количество |
| `--status` | Фильтр по статусу |

### `billing:debtors`

Список должников

```
bin/bas-ip billing:debtors [--limit=50] [--all] [--remind]
```

| Параметр | Описание |
|---|---|
| `--limit` | Количество |
| `--all` | Включая отключённых |
| `--remind` | Отправить напоминания |
| `--json` | Вывод в JSON |

### `billing:overdue`

Пометить просроченные счета (cron)

```
bin/bas-ip billing:overdue
```

### `billing:payment`

Провести оплату жителя

```
bin/bas-ip billing:payment --resident=ID --amount=5000 [--invoice=ID] [--method=transfer] [--date=YYYY-MM-DD]
```

| Параметр | Описание |
|---|---|
| `--resident` | ID жителя |
| `--apartment` | Квартира вместо ID |
| `--amount` | Сумма |
| `--invoice` | Привязать к конкретному счёту |
| `--method` | cash|card|transfer|online|sbp|other |
| `--date` | Дата оплаты |
| `--reference` | Номер платежа |
| `--note` | Примечание |

### `billing:preview`

Показать, как сумма разделится между активными жителями

```
bin/bas-ip billing:preview --total=15000 [--allocation=equal] [--limit=15]
```

| Параметр | Описание |
|---|---|
| `--total` | Общая сумма на обслуживание шлагбаума |
| `--allocation` | equal | by_persons | by_area | by_share |
| `--limit` | Сколько строк показать |
| `--json` | Вывод в JSON |

### `billing:recalc`

Пересчитать начисление и выставить дополнительные счета

```
bin/bas-ip billing:recalc CHARGE_ID [--total=…] [--allocation=…] [--dry-run]
```

| Параметр | Описание |
|---|---|
| `charge` (аргумент) | ID начисления |
| `--total` | Новая общая сумма |
| `--allocation` | Новый способ распределения |
| `--reason` | Причина перерасчёта |
| `--due-date` | Новый срок оплаты |
| `--include-new` | Включить новых активных жителей (по умолчанию да) |
| `--exclude-inactive` | Сторнировать счета отключённых (по умолчанию да) |
| `--dry-run` | Показать, что изменится, не применяя |
| `--notify` | Отправить уведомления о перерасчёте |

### `billing:remind`

Отправить напоминания всем активным должникам

```
bin/bas-ip billing:remind [--channels=mail,telegram] [--yes]
```

| Параметр | Описание |
|---|---|
| `--channels` | Список каналов через запятую |
| `--yes` | Без подтверждения |

## Планировщик

### `schedule:run`

Выполнить плановые задачи (добавьте в cron каждую минуту)

```
bin/bas-ip schedule:run [--queue] [--backup] [--update]
```

| Параметр | Описание |
|---|---|
| `--queue` | Также обработать очередь уведомлений |
| `--backup` | Создать резервную копию, если подошёл срок |
| `--update` | Проверить обновления |
| `--verbose-jobs` | Показать результат каждой задачи |

## Пользователи

### `user:2fa-reset`

Сбросить 2FA (например, при утере телефона)

```
bin/bas-ip user:2fa-reset LOGIN [--yes]
```

| Параметр | Описание |
|---|---|
| `login` (аргумент) | Логин |
| `--yes` | Без подтверждения |

### `user:create`

Создать учётную запись

```
bin/bas-ip user:create --login=admin --password=… [--role=admin] [--email=…] [--name=…]
```

| Параметр | Описание |
|---|---|
| `--login` | Логин |
| `--password` | Пароль (иначе сгенерируется) |
| `--role` | admin | operator | resident |
| `--email` | E-mail |
| `--name` | ФИО |
| `--resident` | ID жителя (для роли resident) |

### `user:list`

Список учётных записей

```
bin/bas-ip user:list [--role=admin]
```

| Параметр | Описание |
|---|---|
| `--role` | Фильтр по роли |

### `user:password`

Сменить пароль учётной записи

```
bin/bas-ip user:password LOGIN [--password=…]
```

| Параметр | Описание |
|---|---|
| `login` (аргумент) | Логин или e-mail |
| `--password` | Новый пароль |

### `user:unlock`

Снять блокировку после неудачных попыток входа

```
bin/bas-ip user:unlock LOGIN
```

| Параметр | Описание |
|---|---|
| `login` (аргумент) | Логин |

## Разработка

### `serve`

Запустить встроенный сервер PHP для разработки

```
bin/bas-ip serve [--host=127.0.0.1] [--port=8080]
```

| Параметр | Описание |
|---|---|
| `--host` | Адрес |
| `--port` | Порт |

## Резервные копии

### `backup:create`

Создать резервную копию

```
bin/bas-ip backup:create [--type=db|full] [--note=…]
```

| Параметр | Описание |
|---|---|
| `--type` | db (только база) или full (база + конфиги) |
| `--note` | Примечание |

### `backup:list`

Список резервных копий

```
bin/bas-ip backup:list
```

### `backup:restore`

Восстановить из резервной копии (ОПАСНО)

```
bin/bas-ip backup:restore ID [--yes]
```

| Параметр | Описание |
|---|---|
| `id` (аргумент) | ID копии из backup:list |
| `--yes` | Без подтверждения |

## Уведомления

### `notify:send`

Отправить произвольное сообщение жителю или на адрес

```
bin/bas-ip notify:send --resident=ID --subject=… --body=… [--channels=mail,telegram]
```

| Параметр | Описание |
|---|---|
| `--resident` | ID жителя |
| `--to` | Или прямой адрес (e-mail/телефон/@telegram) |
| `--channel` | Канал для прямого адреса |
| `--subject` | Тема |
| `--body` | Текст |
| `--channels` | Список каналов через запятую |
| `--now` | Отправить сразу, минуя очередь |

### `notify:test`

Отправить тестовое сообщение через канал

```
bin/bas-ip notify:test --channel=mail --to=admin@example.com
```

| Параметр | Описание |
|---|---|
| `--channel` | log|mail|telegram|sms|webhook|whatsapp |
| `--to` | Получатель |

### `queue:run`

Разобрать очередь уведомлений один раз (для cron)

```
bin/bas-ip queue:run [--limit=100] [--seconds=50]
```

| Параметр | Описание |
|---|---|
| `--limit` | Максимум сообщений |
| `--seconds` | Ограничение по времени |

### `queue:stats`

Статистика очереди уведомлений

```
bin/bas-ip queue:stats [--retry-failed]
```

| Параметр | Описание |
|---|---|
| `--retry-failed` | Вернуть ошибки в очередь |

### `queue:work`

Запустить постоянного работника очереди (systemd/ supervisord)

```
bin/bas-ip queue:work [--sleep=5]
```

| Параметр | Описание |
|---|---|
| `--sleep` | Пауза при пустой очереди, сек |

## Установка

### `install`

Установка: конфиг, ключ, миграции, администратор

```
bin/bas-ip install [--db=mysql] [--admin=admin] [--password=…] [--demo] [--force]
```

| Параметр | Описание |
|---|---|
| `--db` | Драйвер БД: mysql (по умолчанию), pgsql, sqlite |
| `--db-host` | Хост БД |
| `--db-port` | Порт БД |
| `--db-name` | Имя базы |
| `--db-user` | Пользователь БД |
| `--db-password` | Пароль БД |
| `--admin` | Логин администратора |
| `--password` | Пароль администратора |
| `--email` | E-mail администратора |
| `--name` | ФИО администратора |
| `--app-name` | Название (ЖК/организация) |
| `--url` | Публичный URL |
| `--timezone` | Часовой пояс |
| `--device-url` | URL панели BAS-IP |
| `--device-login` | Логин панели |
| `--device-password` | Пароль панели |
| `--demo` | Загрузить демо-данные |
| `--force` | Перезаписать существующий конфиг |
| `--no-interaction` | Не задавать вопросов (только опции) |

### `key:generate`

Сгенерировать app.key (шифрование секретов, подписи, CSRF)

```
bin/bas-ip key:generate [--show] [--write]
```

| Параметр | Описание |
|---|---|
| `--show` | Показать ключ, не записывая |
| `--write` | Дописать в .bas-ip.config.local.php |

### `secret:forget`

Удалить секрет

```
bin/bas-ip secret:forget NAME
```

| Параметр | Описание |
|---|---|
| `name` (аргумент) | Имя секрета |

### `secret:list`

Список сохранённых секретов (без значений)

```
bin/bas-ip secret:list
```

### `secret:reencrypt`

Перешифровать секреты новым app.key

```
bin/bas-ip secret:reencrypt --old-key=…
```

| Параметр | Описание |
|---|---|
| `--old-key` | Предыдущий app.key (base64 или raw) |

### `secret:set`

Сохранить секрет в зашифрованное хранилище

```
bin/bas-ip secret:set NAME [value] 
```

| Параметр | Описание |
|---|---|
| `name` (аргумент) | Имя секрета (A-Z, 0-9, _) |

## Шлагбаум

### `gate:close`

Закрыть шлагбаум

```
bin/bas-ip gate:close [--device=main]
```

| Параметр | Описание |
|---|---|
| `--device` | Slug устройства |
| `--channel` | Канал |

### `gate:log`

Показать журнал открываний

```
bin/bas-ip gate:log [--limit=20] [--access=guest] [--result=denied] [--from=YYYY-MM-DD]
```

| Параметр | Описание |
|---|---|
| `--limit` | Сколько записей показать |
| `--access` | Фильтр по типу доступа |
| `--result` | success|denied|error |
| `--from` | Дата с |
| `--to` | Дата по |
| `--resident` | ID жителя |
| `--json` | Вывод в JSON |
| `--stats` | Только сводка |
| `--days` | Период сводки в днях (со --stats, по умолчанию 30) |

### `gate:open`

Открыть шлагбаум (и записать событие в журнал)

```
bin/bas-ip gate:open [--resident=ID] [--apartment=12] [--device=main] [--note=…]
```

| Параметр | Описание |
|---|---|
| `--resident` | ID жителя |
| `--apartment` | Номер квартиры |
| `--plate` | Госномер |
| `--device` | Slug устройства |
| `--channel` | Канал реле |
| `--note` | Комментарий в журнал |
| `--access` | Тип доступа: own|guest|manual|remote|emergency|api (по умолчанию remote) |
| `--force` | Открыть без проверки доступа (аварийное открытие) |

### `gate:purge`

Удалить старые записи журнала

```
bin/bas-ip gate:purge --days=730 [--yes]
```

| Параметр | Описание |
|---|---|
| `--days` | Хранить дней |
| `--yes` | Без подтверждения |

### `gate:reboot`

Перезагрузить панель

```
bin/bas-ip gate:reboot [--device=main] [--yes]
```

| Параметр | Описание |
|---|---|
| `--device` | Slug устройства |
| `--yes` | Без подтверждения |

### `gate:sip`

Включить или выключить SIP на панели

```
bin/bas-ip gate:sip --enable | --disable [--device=main]
```

| Параметр | Описание |
|---|---|
| `--enable` | Включить SIP |
| `--disable` | Выключить SIP |
| `--device` | Slug устройства |

### `gate:snapshot`

Сохранить кадр с камеры в файл

```
bin/bas-ip gate:snapshot [out.jpg] [--device=main]
```

| Параметр | Описание |
|---|---|
| `out` (аргумент) | Файл назначения (по умолчанию storage/app/exports/snapshot-<ts>.jpg) |
| `--device` | Slug устройства |

### `gate:status`

Состояние панели, время устройства и SIP

```
bin/bas-ip gate:status [--device=main] [--json]
```

| Параметр | Описание |
|---|---|
| `--device` | Slug устройства |
| `--json` | Вывод в JSON |
| `--fresh` | Игнорировать кэш |

### `gate:test`

Проверить связь с каждым устройством

```
bin/bas-ip gate:test [--device=slug]
```

| Параметр | Описание |
|---|---|
| `--device` | Только указанное устройство |

---
## Рецепты для cron

```cron
# каждая минута — планировщик (просрочка, истечение пропусков, очередь, GC)
* * * * * cd /var/www/bas-ip && php bin/bas-ip schedule:run --quiet

# 03:30 — бэкап БД
30 3 * * * cd /var/www/bas-ip && php bin/bas-ip backup:create --quiet

# 04:00 — чистка старых записей по политике хранения (раз в неделю)
0 4 * * 0 cd /var/www/bas-ip && php bin/bas-ip prune --yes --quiet

# 06:00 — проверка обновлений (раз в день)
0 6 * * * cd /var/www/bas-ip && php bin/bas-ip update:check --quiet
```

Дальше: [16 · Решение проблем](16-Troubleshooting.md)
