<?php

/**
 * Core CLI commands: installation, keys, migrations, secrets, accounts, config.
 */

declare(strict_types=1);

use BasIp\Auth\AuthService;
use BasIp\Auth\Permissions;
use BasIp\Console\Command;
use BasIp\Console\Input;
use BasIp\Console\Output;
use BasIp\Core\App;
use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Core\Crypto;
use BasIp\Db\Migrator;
use BasIp\Services\DataService;
use BasIp\Services\SettingsService;
use BasIp\Services\UserService;
use BasIp\Support\Fs;

return [

    /* ══════════════════ установка ══════════════════ */

    new Command(
        name: 'install',
        description: 'Установка: конфиг, ключ, миграции, администратор',
        usage: 'bin/bas-ip install [--db=mysql] [--admin=admin] [--password=…] [--demo] [--force]',
        options: [
            'db' => 'Драйвер БД: mysql (по умолчанию), pgsql, sqlite',
            'db-host' => 'Хост БД', 'db-port' => 'Порт БД', 'db-name' => 'Имя базы',
            'db-user' => 'Пользователь БД', 'db-password' => 'Пароль БД',
            'admin' => 'Логин администратора', 'password' => 'Пароль администратора',
            'email' => 'E-mail администратора', 'name' => 'ФИО администратора',
            'app-name' => 'Название (ЖК/организация)', 'url' => 'Публичный URL',
            'timezone' => 'Часовой пояс', 'device-url' => 'URL панели BAS-IP',
            'device-login' => 'Логин панели', 'device-password' => 'Пароль панели',
            'demo' => 'Загрузить демо-данные', 'force' => 'Перезаписать существующий конфиг',
            'no-interaction' => 'Не задавать вопросов (только опции)',
        ],
        arguments: [],
        group: 'Установка',
        needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $interactive = !$in->bool('no-interaction');
            $localFile = $config->localFilePath();

            if (is_file($localFile) && !$in->bool('force')) {
                $out->warn('Локальный конфиг уже существует: ' . basename($localFile));
                if (!$interactive || !$out->confirm('Перезаписать?', false)) {
                    $out->info('Продолжаю с существующим конфигом.');
                } else {
                    $in = new Input(array_merge(['bas-ip', 'install'], array_slice($_SERVER['argv'] ?? [], 2), ['--force']));
                }
            }

            $out->title('Установка BAS-IP Gate Manager v' . App::VERSION);

            $ask = static fn (string $label, string $option, string $default) => $interactive
                ? $out->ask($label, $in->str($option, $default) ?: $default)
                : ($in->str($option, $default) ?: $default);

            $driver = $ask('Драйвер БД (mysql/pgsql/sqlite)', 'db', $config->string('db.default', 'mysql'));
            $host = $ask('Хост БД', 'db-host', $config->string('db.connections.' . $driver . '.host', '127.0.0.1'));
            $port = (int) $ask('Порт БД', 'db-port', (string) $config->int('db.connections.' . $driver . '.port', $driver === 'pgsql' ? 5432 : 3306));
            $dbName = $driver === 'sqlite'
                ? $ask('Файл SQLite', 'db-name', 'storage/app/bas-ip.sqlite')
                : $ask('Имя базы', 'db-name', $config->string('db.connections.' . $driver . '.database', 'bas_ip'));
            $dbUser = $driver === 'sqlite' ? '' : $ask('Пользователь БД', 'db-user', $config->string('db.connections.' . $driver . '.username', 'bas_ip'));
            $dbPass = $driver === 'sqlite' ? '' : ($interactive ? $out->askHidden('Пароль БД') : $in->str('db-password', ''));

            $appName = $ask('Название (ЖК / организация)', 'app-name', $config->string('app.name', 'Шлагбаум'));
            $timezone = $ask('Часовой пояс', 'timezone', $config->string('app.timezone', 'Europe/Moscow'));
            $url = $ask('Публичный URL', 'url', $config->string('app.url', ''));

            $deviceUrl = $ask('URL панели BAS-IP (пусто = пропустить)', 'device-url', $config->string('device.base_url', ''));
            $deviceLogin = $deviceUrl !== '' ? $ask('Логин панели', 'device-login', 'admin') : '';
            $devicePass = $deviceUrl !== '' ? ($interactive ? $out->askHidden('Пароль панели') : $in->str('device-password', '')) : '';

            $adminLogin = $ask('Логин администратора', 'admin', 'admin');
            $adminPass = $in->str('password', '') ?: ($interactive ? $out->askHidden('Пароль администратора') : '');
            if ($adminPass === '') {
                /** @var AuthService $authSvc */
                $authSvc = $c->get(AuthService::class);
                $adminPass = $authSvc->generatePassword(16);
                $out->warn('Пароль сгенерирован автоматически: <bold>' . $adminPass . '</bold>');
            }
            $adminEmail = $ask('E-mail администратора', 'email', '');
            $adminName = $ask('ФИО администратора', 'name', 'Администратор');

            // ── ключ приложения ────────────────────────────────────────────
            $key = $config->string('app.key', '');
            if ($key === '') {
                $key = Crypto::randomBase64(32);
                $out->success('Сгенерирован app.key');
            }

            // ── запись локального конфига ──────────────────────────────────
            $connection = [
                'driver' => $driver,
                'host' => $driver === 'sqlite' ? null : $host,
                'port' => $driver === 'sqlite' ? null : $port,
                'database' => $driver === 'sqlite'
                    ? (str_starts_with($dbName, '/') ? $dbName : $config->root() . '/' . ltrim($dbName, '/'))
                    : $dbName,
                'username' => $dbUser !== '' ? $dbUser : null,
                'password' => $dbPass !== '' ? $dbPass : null,
            ];

            $contents = "<?php\n\n/**\n * BAS-IP — локальная конфигурация (создана bin/bas-ip install " . date('d.m.Y H:i') . ").\n *\n * Файл в .gitignore: секреты не попадут в репозиторий\n * и не потеряются при обновлении из GitHub.\n */\n\ndeclare(strict_types=1);\n\nreturn [\n\n"
                . "    'app' => [\n"
                . "        'name'     => " . var_export($appName, true) . ",\n"
                . "        'env'      => 'production',\n"
                . "        'debug'    => false,\n"
                . "        'url'      => " . var_export($url, true) . ",\n"
                . "        'timezone' => " . var_export($timezone, true) . ",\n"
                . "        'key'      => " . var_export($key, true) . ",\n"
                . "    ],\n\n"
                . "    'db' => [\n"
                . "        'default' => " . var_export($driver, true) . ",\n"
                . "        'connections' => [\n"
                . "            " . var_export($driver, true) . " => " . str_replace("\n", "\n            ", var_export($connection, true)) . ",\n"
                . "        ],\n"
                . "    ],\n\n"
                . ($deviceUrl !== ''
                    ? "    'device' => [\n"
                        . "        'driver'     => 'basip',\n"
                        . "        'base_url'   => " . var_export($deviceUrl, true) . ",\n"
                        . "        'login'      => " . var_export($deviceLogin, true) . ",\n"
                        . "        'password'   => '\${secret:DEVICE_PASSWORD}',\n"
                        . "        'verify_tls' => false,\n"
                        . "    ],\n\n"
                    : '')
                . "    'billing' => [\n"
                . "        'currency'        => 'RUB',\n"
                . "        'currency_symbol' => '₽',\n"
                . "        'due_days'        => 14,\n"
                . "        'invoice_prefix'  => 'СЧ-',\n"
                . "        'charge_prefix'   => 'НАЧ-',\n"
                . "    ],\n\n"
                . "    'notify' => ['default' => ['log']],\n\n"
                . "    'update' => ['enabled' => true, 'mode' => 'notify', 'repo' => 'paulmann/BAS-IP'],\n"
                . "];\n";

            Fs::put($localFile, $contents, 0600);
            $out->success('Записан ' . Fs::relative($localFile, $config->root()) . ' (chmod 600)');

            if ($devicePass !== '') {
                $config->override(['app' => ['key' => $key]]);
                $config->secrets()->set('DEVICE_PASSWORD', $devicePass);
                $out->success('Пароль панели сохранён в зашифрованное хранилище секретов');
            }

            // Перечитываем конфиг уже с новыми значениями.
            $config->override([
                'app' => ['key' => $key, 'name' => $appName, 'url' => $url, 'timezone' => $timezone],
                'db' => ['default' => $driver, 'connections' => [$driver => $connection]],
            ]);

            // ── миграции ───────────────────────────────────────────────────
            /** @var Migrator $migrator */
            $migrator = $c->get(Migrator::class);
            $applied = $migrator->migrate();
            $out->success(sprintf('Миграции: применено %d из %d', count($applied), count($migrator->files())));

            // ── настройки и устройство ─────────────────────────────────────
            /** @var SettingsService $settings */
            $settings = $c->get(SettingsService::class);
            $settings->set('system.installed_at', date('Y-m-d H:i:s'));
            $settings->set('system.installed_version', App::VERSION);
            $settings->set('ui.organisation_name', $appName);

            if ($deviceUrl !== '') {
                /** @var \BasIp\Device\DeviceManager $devices */
                $devices = $c->get(\BasIp\Device\DeviceManager::class);
                $devices->save([
                    'name' => $appName . ' (основной)', 'slug' => 'main', 'driver' => 'basip',
                    'base_url' => $deviceUrl, 'login' => $deviceLogin, 'password' => '${secret:DEVICE_PASSWORD}',
                    'channel' => 1, 'is_active' => true, 'is_default' => true,
                ]);
                $out->success('Устройство добавлено: ' . $deviceUrl);
            }

            // ── администратор ──────────────────────────────────────────────
            /** @var AuthService $auth */
            $auth = $c->get(AuthService::class);
            if ($auth->findByLogin($adminLogin) === null) {
                $errors = $auth->validatePassword($adminPass, $adminLogin);
                if ($errors !== []) {
                    $out->error('Пароль администратора не проходит проверку: ' . implode(' ', $errors));

                    return 1;
                }
                $account = $auth->createAccount([
                    'login' => $adminLogin, 'email' => $adminEmail ?: null,
                    'full_name' => $adminName, 'role' => 'admin',
                    'password' => $adminPass, 'is_active' => true, 'must_change_password' => false,
                ]);
                if (!$account['ok']) {
                    $out->error('Не удалось создать администратора: ' . ($account['error'] ?? '?'));

                    return 1;
                }
                $out->success('Администратор создан: ' . $adminLogin);
            } else {
                $out->info('Администратор «' . $adminLogin . '» уже существует — пропущено');
            }

            if ($in->bool('demo')) {
                $seed = $config->getPath('database') . '/seeds/demo.php';
                if (is_file($seed)) {
                    /** @var callable $seeder */
                    $seeder = require $seed;
                    $count = (int) $seeder($c->get(\BasIp\Db\Database::class), $c->get(\BasIp\Core\Clock::class));
                    $out->success('Демо-данные: ' . $count . ' жителей');
                }
            }

            $out->section('Готово');
            $out->bullet('Веб-интерфейс: <cyan>' . ($url !== '' ? $url : 'http://ваш-сервер') . '</cyan>');
            $out->bullet('Вход: <cyan>' . $adminLogin . '</cyan>');
            $out->bullet('Документация: <cyan>README.md</cyan> и <cyan>wiki/</cyan>');
            $out->bullet('Проверка: <cyan>bin/bas-ip doctor</cyan>');
            $out->line('');

            return 0;
        },
    ),

    /* ══════════════════ ключи и секреты ══════════════════ */

    new Command(
        name: 'key:generate',
        description: 'Сгенерировать app.key (шифрование секретов, подписи, CSRF)',
        usage: 'bin/bas-ip key:generate [--show] [--write]',
        options: ['show' => 'Показать ключ, не записывая', 'write' => 'Дописать в .bas-ip.config.local.php'],
        arguments: [], group: 'Установка', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $key = Crypto::randomBase64(32);

            if ($in->bool('show')) {
                $out->line($key);

                return 0;
            }

            $file = $config->localFilePath();
            if ($in->bool('write') && is_file($file)) {
                $contents = (string) file_get_contents($file);
                if (preg_match("/'key'\\s*=>\\s*('[^']*'|null)/", $contents)) {
                    $contents = (string) preg_replace("/'key'\\s*=>\\s*('[^']*'|null)/", "'key' => " . var_export($key, true), $contents, 1);
                    Fs::put($file, $contents, 0600);
                    $out->success('app.key записан в ' . basename($file));

                    return 0;
                }
                $out->warn("В файле нет строки 'key' => … — добавьте вручную:");
            }

            $out->line("  'app' => [");
            $out->line("      'key' => " . var_export($key, true) . ",");
            $out->line('  ],');
            $out->info('Поместите в .bas-ip.config.local.php и выполните bin/bas-ip secret:reencrypt');

            return 0;
        },
    ),

    new Command(
        name: 'secret:set',
        description: 'Сохранить секрет в зашифрованное хранилище',
        usage: 'bin/bas-ip secret:set NAME [value] ',
        options: [], arguments: ['name' => 'Имя секрета (A-Z, 0-9, _)'],
        group: 'Установка', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $name = (string) $in->argument(0, '');
            if ($name === '') {
                $out->error('Укажите имя секрета: bin/bas-ip secret:set DEVICE_PASSWORD');

                return 1;
            }
            $value = (string) ($in->argument(1) ?? $out->askHidden('Значение'));
            if ($value === '') {
                $out->error('Пустое значение не сохранено.');

                return 1;
            }
            $config->secrets()->set($name, $value);
            $out->success(sprintf('Секрет %s сохранён%s', $name, $config->secrets()->encrypted() ? ' (зашифрован)' : ' (БЕЗ шифрования — задайте app.key!)'));
            $out->info('Использование в конфиге: <cyan>${secret:' . strtoupper($name) . '}</cyan>');

            return 0;
        },
    ),

    new Command(
        name: 'secret:list',
        description: 'Список сохранённых секретов (без значений)',
        usage: 'bin/bas-ip secret:list',
        options: [], arguments: [], group: 'Установка', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $names = $config->secrets()->names();
            $out->title('Секреты');
            if ($names === []) {
                $out->info('Секретов нет. Добавьте: bin/bas-ip secret:set NAME');

                return 0;
            }
            $rows = [];
            foreach ($names as $name) {
                $value = (string) $config->secrets()->get($name, '');
                $rows[] = [$name, $value === '' ? '(пусто)' : str_repeat('•', min(12, max(4, mb_strlen($value)))), strlen($value) . ' симв.'];
            }
            $out->table(['Имя', 'Значение', 'Длина'], $rows);
            $out->line('');
            $out->line('  Хранилище: <cyan>' . Fs::relative($config->secrets()->file(), $config->root()) . '</cyan> · шифрование: ' . ($config->secrets()->encrypted() ? '<green>включено</green>' : '<red>выключено</red>'));

            return 0;
        },
    ),

    new Command(
        name: 'secret:forget',
        description: 'Удалить секрет',
        usage: 'bin/bas-ip secret:forget NAME',
        options: [], arguments: ['name' => 'Имя секрета'],
        group: 'Установка', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $name = (string) $in->argument(0, '');
            if ($name === '' || !$config->secrets()->forget($name)) {
                $out->error('Секрет не найден: ' . $name);

                return 1;
            }
            $out->success('Секрет удалён: ' . $name);

            return 0;
        },
    ),

    new Command(
        name: 'secret:reencrypt',
        description: 'Перешифровать секреты новым app.key',
        usage: 'bin/bas-ip secret:reencrypt --old-key=…',
        options: ['old-key' => 'Предыдущий app.key (base64 или raw)'],
        arguments: [], group: 'Установка', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $old = $in->str('old-key', '');
            if ($old === '') {
                $old = (string) $out->askHidden('Предыдущий app.key');
            }
            if ($old === '') {
                $out->error('Не указан предыдущий ключ.');

                return 1;
            }
            $oldKey = str_starts_with($old, 'base64:') ? (string) base64_decode(substr($old, 7), true) : $old;
            $count = $config->secrets()->reencrypt($oldKey !== '' ? $oldKey : null);
            $out->success('Перешифровано секретов: ' . $count);

            return 0;
        },
    ),

    /* ══════════════════ миграции ══════════════════ */

    new Command(
        name: 'migrate',
        description: 'Применить ожидающие миграции',
        usage: 'bin/bas-ip migrate [--step=N] [--force] [--seed]',
        options: ['step' => 'Применить только N миграций', 'force' => 'Продолжать при ошибках', 'seed' => 'Заполнить демо-данными после миграций'],
        arguments: [], group: 'База данных',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Migrator $migrator */
            $migrator = $c->get(Migrator::class);
            $step = $in->has('step') ? $in->int('step') : null;
            $applied = $migrator->migrate($step, $in->bool('force'));

            if ($applied === []) {
                $out->info('Новых миграций нет — схема актуальна.');
            } else {
                $out->success('Применено миграций: ' . count($applied));
                foreach ($applied as $name) {
                    $out->bullet($name);
                }
            }

            foreach ($migrator->checksumWarnings() as $warning) {
                $out->warn('Файл миграции изменён после применения: ' . $warning);
            }

            if ($in->bool('seed')) {
                $seed = $c->get(Config::class)->getPath('database') . '/seeds/demo.php';
                if (is_file($seed)) {
                    /** @var callable $seeder */
                    $seeder = require $seed;
                    $out->success('Демо-данные: ' . $seeder($c->get(\BasIp\Db\Database::class), $c->get(\BasIp\Core\Clock::class)) . ' жителей');
                }
            }

            return 0;
        },
    ),

    new Command(
        name: 'migrate:status',
        description: 'Показать состояние миграций',
        usage: 'bin/bas-ip migrate:status',
        options: [], arguments: [], group: 'База данных',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Migrator $migrator */
            $migrator = $c->get(Migrator::class);
            $rows = [];
            foreach ($migrator->status() as $name => $info) {
                $rows[] = [
                    $name,
                    $info['applied'] ? '<green>да</green>' : '<yellow>нет</yellow>',
                    (string) ($info['batch'] ?? ''),
                    $info['checksum_ok'] === false ? '<red>изменён</red>' : ($info['checksum_ok'] === null ? '—' : '<green>ok</green>'),
                    (string) ($info['executed_at'] ?? ''),
                    \BasIp\Support\Str::limit((string) ($info['description'] ?? ''), 40),
                ];
            }
            $out->title('Миграции');
            $out->table(['Файл', 'Применена', 'Пакет', 'Контрольная сумма', 'Дата', 'Описание'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'migrate:rollback',
        description: 'Откатить последний пакет миграций',
        usage: 'bin/bas-ip migrate:rollback [--step=1]',
        options: ['step' => 'Сколько пакетов откатить (по умолчанию 1)'],
        arguments: [], group: 'База данных',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Migrator $migrator */
            $migrator = $c->get(Migrator::class);
            $step = $in->int('step', 1);
            if (!$in->bool('force') && !$out->confirm('Откатить ' . $step . ' пакет(ов) миграций? Данные этих таблиц будут удалены.', false)) {
                $out->info('Отменено.');

                return 0;
            }
            $rolled = $migrator->rollback($step);
            $out->success('Откачено миграций: ' . count($rolled));
            foreach ($rolled as $name) {
                $out->bullet($name);
            }

            return 0;
        },
    ),

    new Command(
        name: 'migrate:fresh',
        description: 'Снести схему и накатить заново (ОПАСНО)',
        usage: 'bin/bas-ip migrate:fresh [--seed] [--yes]',
        options: ['seed' => 'Заполнить демо-данными', 'yes' => 'Не спрашивать подтверждения'],
        arguments: [], group: 'База данных',
        handler: static function (Input $in, Output $out, Container $c): int {
            if (!$in->bool('yes') && !$out->confirm('<red>ВСЕ ДАННЫЕ БУДУТ УДАЛЕНЫ.</red> Продолжить?', false)) {
                $out->info('Отменено.');

                return 0;
            }
            /** @var Migrator $migrator */
            $migrator = $c->get(Migrator::class);
            $applied = $migrator->fresh();
            $out->success('Схема пересоздана, миграций: ' . count($applied));

            return 0;
        },
    ),

    new Command(
        name: 'db:seed',
        description: 'Загрузить демо-данные (жители, машины, начисления, оплаты)',
        usage: 'bin/bas-ip db:seed [--residents=24]',
        options: ['residents' => 'Сколько жителей создать'],
        arguments: [], group: 'База данных',
        handler: static function (Input $in, Output $out, Container $c): int {
            $file = $c->get(Config::class)->getPath('database') . '/seeds/demo.php';
            if (!is_file($file)) {
                $out->error('Файл database/seeds/demo.php не найден.');

                return 1;
            }
            /** @var callable $seeder */
            $seeder = require $file;
            $count = (int) $seeder($c->get(\BasIp\Db\Database::class), $c->get(\BasIp\Core\Clock::class), $in->int('residents', 24));
            $out->success('Создано жителей: ' . $count);

            return 0;
        },
    ),

    /* ══════════════════ пользователи ══════════════════ */

    new Command(
        name: 'user:create',
        description: 'Создать учётную запись',
        usage: 'bin/bas-ip user:create --login=admin --password=… [--role=admin] [--email=…] [--name=…]',
        options: [
            'login' => 'Логин', 'password' => 'Пароль (иначе сгенерируется)',
            'role' => 'admin | operator | resident', 'email' => 'E-mail', 'name' => 'ФИО',
            'resident' => 'ID жителя (для роли resident)',
        ],
        arguments: [], group: 'Пользователи',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var AuthService $auth */
            $auth = $c->get(AuthService::class);
            $login = $in->str('login', '') ?: $out->ask('Логин', 'admin');
            $role = $in->str('role', 'admin');
            if (!Permissions::isValidRole($role)) {
                $out->error('Недопустимая роль: ' . $role . ' (admin | operator | resident)');

                return 1;
            }
            $password = $in->str('password', '') ?: $auth->generatePassword(16);
            $generated = $in->str('password', '') === '';

            $result = $auth->createAccount([
                'login' => $login, 'password' => $password, 'role' => $role,
                'email' => $in->str('email', '') ?: null,
                'full_name' => $in->str('name', '') ?: $login,
                'resident_id' => $in->has('resident') ? $in->int('resident') : null,
                'must_change_password' => $generated,
            ]);

            if (!$result['ok']) {
                $out->error((string) ($result['error'] ?? 'Ошибка'));

                return 1;
            }
            $out->success(sprintf('Создана учётная запись %s (роль: %s, id: %d)', $login, $role, (int) $result['id']));
            if ($generated) {
                $out->warn('Пароль: <bold>' . $password . '</bold> (потребуется смена при первом входе)');
            }

            return 0;
        },
    ),

    new Command(
        name: 'user:list',
        description: 'Список учётных записей',
        usage: 'bin/bas-ip user:list [--role=admin]',
        options: ['role' => 'Фильтр по роли'],
        arguments: [], group: 'Пользователи',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var UserService $users */
            $users = $c->get(UserService::class);
            $paginator = $users->search($in->has('role') ? ['role' => $in->str('role')] : [], 200, 1);
            $rows = [];
            foreach ($paginator->items() as $row) {
                $rows[] = [
                    $row['id'], $row['login'], $row['full_name'], $row['role'],
                    $row['is_active'] ? '<green>да</green>' : '<red>нет</red>',
                    $row['totp_enabled'] ? '<green>да</green>' : '—',
                    (string) ($row['last_login_at'] ?? '—'),
                    (string) ($row['resident_id'] ?? ''),
                ];
            }
            $out->title('Учётные записи (' . $paginator->total() . ')');
            $out->table(['ID', 'Логин', 'ФИО', 'Роль', 'Активен', '2FA', 'Последний вход', 'Житель'], $rows);

            return 0;
        },
    ),

    new Command(
        name: 'user:password',
        description: 'Сменить пароль учётной записи',
        usage: 'bin/bas-ip user:password LOGIN [--password=…]',
        options: ['password' => 'Новый пароль'],
        arguments: ['login' => 'Логин или e-mail'],
        group: 'Пользователи',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var AuthService $auth */
            $auth = $c->get(AuthService::class);
            $login = (string) $in->argument(0, '');
            if ($login === '') {
                $out->error('Укажите логин.');

                return 1;
            }
            $user = $auth->findByLogin($login);
            if ($user === null) {
                $out->error('Учётная запись не найдена: ' . $login);

                return 1;
            }
            $password = $in->str('password', '') ?: $out->askHidden('Новый пароль');
            $errors = $auth->validatePassword($password, $user->login);
            if ($errors !== []) {
                $out->error(implode(' ', $errors));

                return 1;
            }
            $auth->setPassword($user->id, $password);
            $auth->unlock($user->id);
            $out->success('Пароль изменён для ' . $user->login . '. Все сессии завершены.');

            return 0;
        },
    ),

    new Command(
        name: 'user:unlock',
        description: 'Снять блокировку после неудачных попыток входа',
        usage: 'bin/bas-ip user:unlock LOGIN',
        options: [], arguments: ['login' => 'Логин'],
        group: 'Пользователи',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var AuthService $auth */
            $auth = $c->get(AuthService::class);
            $user = $auth->findByLogin((string) $in->argument(0, ''));
            if ($user === null) {
                $out->error('Не найдено.');

                return 1;
            }
            $auth->unlock($user->id);
            $out->success('Блокировка снята: ' . $user->login);

            return 0;
        },
    ),

    new Command(
        name: 'user:2fa-reset',
        description: 'Сбросить 2FA (например, при утере телефона)',
        usage: 'bin/bas-ip user:2fa-reset LOGIN [--yes]',
        options: ['yes' => 'Без подтверждения'],
        arguments: ['login' => 'Логин'], group: 'Пользователи',
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var AuthService $auth */
            $auth = $c->get(AuthService::class);
            $user = $auth->findByLogin((string) $in->argument(0, ''));
            if ($user === null) {
                $out->error('Не найдено.');

                return 1;
            }
            if (!$in->bool('yes') && !$out->confirm('Сбросить 2FA для ' . $user->login . '?', false)) {
                return 0;
            }
            $c->get(\BasIp\Db\Database::class)->table('users')->where('id', '=', $user->id)->update([
                'totp_enabled' => 0, 'totp_secret' => null, 'recovery_codes' => null,
            ]);
            $out->success('2FA сброшена для ' . $user->login);

            return 0;
        },
    ),

    /* ══════════════════ конфигурация и диагностика ══════════════════ */

    new Command(
        name: 'config:show',
        description: 'Показать итоговую конфигурацию (секреты скрыты)',
        usage: 'bin/bas-ip config:show [ключ] [--json]',
        options: ['json' => 'Вывод в JSON'],
        arguments: ['key' => 'Точечный ключ, например db.default'],
        group: 'Диагностика', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var Config $config */
            $config = $c->get(Config::class);
            $key = (string) $in->argument(0, '');
            $view = $config->publicView();

            if ($key !== '') {
                $value = \BasIp\Support\Arr::get($view, $key, '(не задано)');
                if ($in->bool('json')) {
                    $out->line((string) json_encode([$key => $value], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                    return 0;
                }
                $out->line($key . ' = ' . (is_scalar($value) ? var_export($value, true) : (string) json_encode($value, JSON_UNESCAPED_UNICODE)));

                return 0;
            }

            if ($in->bool('json')) {
                $out->line((string) json_encode($view, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

                return 0;
            }

            $out->title('Конфигурация');
            $out->line('  <dim>Источники (по порядку наложения):</dim>');
            foreach ($config->sources() as $source) {
                $out->bullet($source);
            }
            $out->line('');
            foreach (\BasIp\Support\Arr::flatten($view) as $flatKey => $value) {
                $text = is_scalar($value) || $value === null
                    ? var_export($value, true)
                    : (string) json_encode($value, JSON_UNESCAPED_UNICODE);
                if (mb_strlen($text) > 90) {
                    $text = mb_substr($text, 0, 87) . '…';
                }
                $out->line(sprintf('  <cyan>%-46s</cyan> %s', $flatKey, $text));
            }

            return 0;
        },
    ),

    new Command(
        name: 'doctor',
        description: 'Самодиагностика: PHP, БД, права, безопасность, устройство',
        usage: 'bin/bas-ip doctor [--json] [--fix]',
        options: ['json' => 'Вывод в JSON', 'fix' => 'Исправить то, что можно автоматически'],
        arguments: [], group: 'Диагностика', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var DataService $data */
            $data = $c->get(DataService::class);
            $issues = $data->doctor();

            if ($in->bool('json')) {
                $out->line((string) json_encode(['ok' => true, 'issues' => $issues], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

                return 0;
            }

            $out->title('Диагностика');
            $icons = ['ok' => '<green>✔</green>', 'info' => '<cyan>ℹ</cyan>', 'warn' => '<yellow>⚠</yellow>', 'error' => '<red>✖</red>'];
            $counts = ['error' => 0, 'warn' => 0, 'info' => 0, 'ok' => 0];
            foreach ($issues as $issue) {
                $level = (string) $issue['level'];
                $counts[$level] = ($counts[$level] ?? 0) + 1;
                $out->line(sprintf('  %s <bold>[%s]</bold> %s', $icons[$level] ?? '•', $issue['area'], $issue['message']));
                if (isset($issue['hint'])) {
                    $out->line('       <dim>→ ' . $issue['hint'] . '</dim>');
                }
            }

            $out->line('');
            $out->line(sprintf('  Итого: <red>ошибок %d</red>, <yellow>предупреждений %d</yellow>, <cyan>замечаний %d</cyan>', $counts['error'], $counts['warn'], $counts['info']));

            if ($in->bool('fix')) {
                $fixed = 0;
                foreach (['logs', 'cache', 'sessions', 'exports', 'backups', 'updates', 'tokens'] as $dir) {
                    $path = $c->get(Config::class)->getPath($dir);
                    if (!is_dir($path)) {
                        @mkdir($path, 0770, true);
                        $fixed++;
                        continue;
                    }
                    @chmod($path, 0770);
                }
                $out->success('Автоисправлений: ' . $fixed . ' (созданы/поправлены каталоги storage)');
            }

            return $counts['error'] > 0 ? 1 : 0;
        },
    ),

    new Command(
        name: 'version',
        description: 'Версия приложения и окружения',
        usage: 'bin/bas-ip version',
        options: [], arguments: [], group: 'Диагностика', needsDatabase: false,
        handler: static function (Input $in, Output $out, Container $c): int {
            /** @var App $app */
            $app = $c->get(App::class);
            $out->definitions($app->environment());

            return 0;
        },
    ),
];
