# BAS-IP Gate Manager — Wiki

Полная документация системы управления шлагбаумом BAS-IP для жилого комплекса.

> **Как опубликовать эту wiki на GitHub:** содержимое каталога `wiki/`
> предназначено для [GitHub Wiki](https://docs.github.com/repositories/managing-your-repositories-settings-and-security/enabling-features-for-your-repository/managing-your-repositorys-wiki)
> репозитория. Клонируйте wiki-репозиторий
> (`git clone https://github.com/paulmann/BAS-IP.wiki.git`), скопируйте туда
> файлы из `wiki/` (без самого каталога) и запушьте. `_Sidebar.md` станет
> боковым меню. Файлы также читабельны прямо в репозитории.

## Что это за продукт

BAS-IP Gate Manager — самодостаточное PHP-приложение (без фреймворков и внешних
сервисов), которое превращает панель/шлагбаум BAS-IP в полноценную систему
доступа для ЖК:

* **жители** — карточки с телефонами, машинами, квартирами и статусами;
* **деньги** — начисление общей суммы обслуживания на активных жителей,
  счета, оплаты, перерасчёты, должники и напоминания;
* **доступ** — открытие из панели оператора, из личного кабинета жителя,
  гостевыми ссылками с QR-кодом и по API;
* **порядок** — журнал каждого открывания, аудит действий, бэкапы, обновления.

Всё это — в одном репозитории, с веб-установщиком, консолью из 71 команды,
REST API и интерфейсом на Tailwind CSS + shadcn/ui (светлая/тёмная тема,
mobile-first, ни одного CDN).

## Быстрые ссылки

| Хочу… | Страница |
|---|---|
| Установить с нуля | [01 · Установка](01-Installation.md) |
| Настроить за 10 минут и открыть первый шлагбаум | [02 · Быстрый старт](02-Quick-Start.md) |
| Понять ключи конфига | [03 · Конфигурация](03-Configuration.md) |
| Разобраться в коде / доработать | [04 · Архитектура](04-Architecture.md) |
| Работать в админке каждый день | [05 · Гид администратора](05-Admin-Guide.md) |
| Дать доступ жителям | [06 · Кабинет жителя](06-Resident-Cabinet.md) |
| Пускать гостей и курьеров | [07 · Гостевой доступ](07-Guest-Access.md) |
| Начислять и принимать оплату | [08 · Биллинг](08-Billing.md) |
| Настроить письма/Telegram/SMS | [09 · Уведомления](09-Notifications.md) |
| Интегрировать внешние системы | [10 · REST API](10-API.md) |
| Подключить панель BAS-IP / реле | [11 · Устройства](11-Devices.md) |
| Проверить/усилить защиту | [12 · Безопасность](12-Security.md) |
| Настроить бэкапы, перенести базу | [13 · Бэкап и восстановление](13-Backup-Restore.md) |
| Обновляться без downtime-сюрпризов | [14 · Обновления](14-Updating.md) |
| Автоматизировать через консоль | [15 · CLI-справочник](15-CLI.md) |
| Починить проблему | [16 · Решение проблем](16-Troubleshooting.md) |
| Переехать с v1 (`bas-ip.php`) | [17 · Миграция с v1](17-Migration-from-v1.md) |
| Короткие ответы | [18 · FAQ](18-FAQ.md) |

## Краткая архитектура

```
Браузер / мобильный          Telegram-бот          Внешние системы
      │                          │                      │
      ▼                          ▼                      ▼
 public/index.php ── middleware (сессии, CSRF, права, rate-limit, CSP)
      │
 Router ── Контроллеры (Admin, Cabinet, Guest, Api, Auth, Install)
      │
 Services (Residents, Billing, Gate, GuestPass, Notify, Backup, Update…)
      │
 Db (MySQL · PostgreSQL · SQLite)      Device drivers (BAS-IP · relay · dry-run)
```

Подробнее — [04 · Архитектура](04-Architecture.md).

## Системные требования

| Компонент | Минимум | Рекомендуется |
|---|---|---|
| PHP | 8.2 | **8.5** |
| Расширения PHP | pdo, mbstring, curl, json, openssl | + sodium, zip, intl, pdo_mysql/pdo_pgsql |
| СУБД | MySQL 8.0 / MariaDB 10.6 / PostgreSQL 13 / SQLite 3 | MySQL 8.4 LTS или PostgreSQL 16 |
| Веб-сервер | любой с PHP (nginx, Apache, Caddy, встроенный) | nginx + php-fpm, HTTPS |
| Node.js | — (только для пересборки ассетов) | 20+ |

Приложение спроектировано для скромного железа: Raspberry Pi 4 / любой VPS
с 512 МБ ОЗУ обслуживают дом на сотни квартир.

## Лицензия

MIT © Mikhail Deynekin — [LICENSE](https://github.com/paulmann/BAS-IP/blob/main/LICENSE).
