# 01 · Установка

[← Home](Home.md)

Содержание:
[требования](#требования) · [веб-установщик](#веб-установщик-рекомендуется) ·
[ручная установка](#ручная-установка) · [nginx](#nginx) · [Apache](#apache) ·
[systemd-очередь](#очередь-уведомлений-systemd) · [cron](#cron) ·
[обновление](#обновление) · [удаление](#удаление)

## Требования

| Компонент | Минимум | Рекомендуется |
|---|---|---|
| PHP | **8.2** | **8.5** |
| Расширения | `pdo`, `mbstring`, `curl`, `json`, `openssl` | + `sodium` (argon2id), `zip` (бэкапы), `intl`, `pdo_mysql` / `pdo_pgsql`, `pdo_sqlite` |
| СУБД | MySQL 8.0 / MariaDB 10.6 / PostgreSQL 13 / SQLite 3 | MySQL 8.4 LTS, PostgreSQL 16 |
| Диска | ~50 МБ приложение | + логи/бэкапы по политике хранения |
| ОЗУ | 256 МБ | 512 МБ+ |

Проверка: `php bin/bas-ip doctor` (после установки) или `php -m` (до).

> **PHP 8.5** — целевая версия проекта. Приложение работает на 8.2+
> (консоль предупредит), но на 8.5 вы получаете максимальную
> производительность и полную совместимость с будущими релизами.

## Веб-установщик (рекомендуется)

1. **Скачайте код** на сервер:

   ```bash
   cd /var/www
   git clone https://github.com/paulmann/BAS-IP.git bas-ip
   cd bas-ip
   ```

   (или загрузите zip релиза и распакуйте — обновление через UI работает
   в обоих случаях).

2. **Создайте базу** (для MySQL; для SQLite пропустите — файл создастся сам):

   ```sql
   CREATE DATABASE bas_ip CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'bas_ip'@'localhost' IDENTIFIED BY 'сильный-пароль';
   GRANT ALL PRIVILEGES ON bas_ip.* TO 'bas_ip'@'localhost';
   ```

3. **Настройте веб-сервер** на каталог `public/` (конфиги ниже) и права:

   ```bash
   chown -R www-data:www-data storage
   chmod -R ug+rwX storage
   chmod 750 storage
   ```

4. **Откройте сайт** в браузере — при отсутствии конфигурации приложение
   само перенаправит на `/install`. Мастер (5 шагов):
   * **Проверка** — требования (PHP, расширения, права на `storage/`);
   * **База** — драйвер (mysql / pgsql / sqlite), хост/порт/имя/учётные данные,
     дополнительно префикс таблиц и unix-сокет; кнопка **«Проверить
     соединение»** диагностирует подключение ДО установки (отличает «сервер
     не слушает» от «база не создана» — и сразу даёт готовый `CREATE
     DATABASE`);
   * **Приложение** — название ЖК, публичный URL, часовой пояс, демо-данные;
   * **Панель** — адрес шлагбаума (BAS-IP `/api/v0`, HTTP-реле или эмулятор),
     логин/пароль/канал; кнопка **«Проверить панель»** выполняет реальный
     handshake с устройством;
   * **Админ** — логин, ФИО, e-mail, **телефон**, пароль. Надёжный пароль
     **генерируется автоматически** при открытии шага (можно заменить или
     оставить поле пустым — сервер сгенерирует свой и покажет его один раз
     после установки).
   Кнопка **«Установить»** записывает `.bas-ip.config.local.php` (0600),
   генерирует `app.key`, применяет миграции, создаёт администратора,
   устройство и (опционально) демо-данные, создаёт `public/install.lock`.

   **Успех**: экран результата с логом шагов, сгенерированным паролем
   (показывается один раз, с кнопкой копирования) и кнопкой **«Перейти в
   админку»** — установщик сразу входит созданным администратором.

   **Ошибка**: подробный машиночитаемый отчёт (фаза установки, окружение,
   выполненные шаги, исключение с трейсом, подсказка) — кнопки «Скопировать»
   и «Скачать .txt»; тот же отчёт в JSON при `Accept: application/json`.
   Пароли и ключи в отчёте маскируются. Его можно целиком вставить в чат
   с ИИ или в issue — в нём достаточно данных для анализа.

5. **После установки** маршруты `/install` возвращают 403. Для повторного
   запуска мастера удалите `public/install.lock`.

Что делает установщик под капотом — и как повторить руками:

## Ручная установка

```bash
cd /var/www/bas-ip
cp .bas-ip.config.local.php.example .bas-ip.config.local.php
chmod 600 .bas-ip.config.local.php
nano .bas-ip.config.local.php       # БД, устройство, app.key…

php bin/bas-ip key:generate         # сгенерировать app.key (32 байта base64)
php bin/bas-ip migrate              # схема БД
php bin/bas-ip user:create --login=admin --role=admin   # пароль спросит/сгенерирует
php bin/bas-ip db:seed              # (опционально) демо-данные
php bin/bas-ip doctor               # самодиагностика
```

Минимальный рабочий `.bas-ip.config.local.php` (MySQL):

```php
<?php
declare(strict_types=1);

return [
    'app' => [
        'name'     => 'ЖК «Ромашка»',
        'env'      => 'production',
        'debug'    => false,
        'url'      => 'https://gate.example.com',
        'timezone' => 'Europe/Moscow',
        'key'      => 'base64:…32-байта…',      // php bin/bas-ip key:generate
    ],
    'db' => [
        'default' => 'mysql',
        'connections' => [
            'mysql' => [
                'driver'   => 'mysql',
                'host'     => '127.0.0.1',
                'port'     => 3306,
                'database' => 'bas_ip',
                'username' => 'bas_ip',
                'password' => 'сильный-пароль',
                'charset'  => 'utf8mb4',
            ],
        ],
    ],
    'device' => [
        'driver'   => 'basip',                    // basip | http-relay | dryrun
        'base_url' => 'http://192.168.1.20/api/v0/',
        'login'    => 'admin',
        'password' => '${secret:DEVICE_PASSWORD}', // php bin/bas-ip secret:set DEVICE_PASSWORD
    ],
];
```

Полный разбор ключей — [03 · Конфигурация](03-Configuration.md).

## nginx

```nginx
server {
    listen 80;
    server_name gate.example.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name gate.example.com;

    root /var/www/bas-ip/public;
    index index.php;

    ssl_certificate     /etc/letsencrypt/live/gate.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/gate.example.com/privkey.pem;

    # security-заголовки приложение ставит само; прячем версию PHP
    fastcgi_hide_header X-Powered-By;

    client_max_body_size 16m;               # импорт CSV/Excel

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_read_timeout 60s;
    }

    # Долгий кэш для fingerprinted-ассетов (?v=… обновляется при релизах)
    location /assets/ {
        expires 30d;
        add_header Cache-Control "public";
        try_files $uri =404;
    }

    location ~ /\. { deny all; }            # dotfiles
}
```

## Apache

В комплекте два файла: `.htaccess` в корне проекта (запрещает всё — защита
при неверном document root) и `public/.htaccess` (rewrite, security-заголовки,
кэш ассетов, запрет установщика после install.lock). Достаточно:

```apache
<VirtualHost *:443>
    ServerName gate.example.com
    DocumentRoot /var/www/bas-ip/public

    <Directory /var/www/bas-ip/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Включите модули: `a2enmod rewrite headers deflate expires ssl`.

## Очередь уведомлений (systemd)

Рассылки работают через файловую/БД-очередь. Варианты:

* **cron раз в минуту** (достаточно для дома): очередь разбирается внутри
  `schedule:run`;
* **постоянный воркер** (мгновенная доставка):

```ini
# /etc/systemd/system/bas-ip-queue.service
[Unit]
Description=BAS-IP notification queue worker
After=network.target mysql.service

[Service]
Type=simple
User=www-data
WorkingDirectory=/var/www/bas-ip
ExecStart=/usr/bin/php bin/bas-ip queue:work --sleep=5
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

```bash
systemctl daemon-reload && systemctl enable --now bas-ip-queue
```

## cron

```cron
# каждую минуту: планировщик (просрочка счетов, истечение гостевых,
# очистка сессий/кэшей, разбор очереди, авто-бэкап по настройкам)
* * * * * cd /var/www/bas-ip && /usr/bin/php bin/bas-ip schedule:run --quiet >/dev/null 2>&1

# ежедневный бэкап в 03:30 (если не включён авто-бэкап в настройках)
30 3 * * * cd /var/www/bas-ip && /usr/bin/php bin/bas-ip backup:create --quiet >/dev/null 2>&1
```

## Обновление

```bash
php bin/bas-ip update:check     # есть ли новая версия
php bin/bas-ip update:run       # скачать + бэкап + установить + миграции
php bin/bas-ip update:rollback  # откат, если что-то пошло не так
```

Или через UI: **Админка → Система → Обновления**. Механика, preserve-список
и стратегии — [14 · Обновления](14-Updating.md).

## Удаление

```bash
php bin/bas-ip maintenance --on          # (опционально) закрыть доступ
# удалите каталог проекта и базу:
#   rm -rf /var/www/bas-ip
#   DROP DATABASE bas_ip;
```

`storage/` содержит бэкапы и логи — при необходимости сохраните перед удалением.

---
Дальше: [02 · Быстрый старт](02-Quick-Start.md)
