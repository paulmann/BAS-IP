<?php

declare(strict_types=1);

namespace BasIp\Console;

use BasIp\Core\App;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Container;
use BasIp\Core\Logger;
use BasIp\Db\Database;
use Throwable;

/**
 * CLI kernel — the `bin/bas-ip` entry point.
 *
 * Bootstraps the same container as the web app, resolves the command, wires the
 * database lazily (so `--help` works without a DB) and turns uncaught
 * exceptions into a readable error plus a non-zero exit code.
 */
final class Kernel
{
    /** @var array<string, Command> */
    private array $commands = [];

    private Output $output;
    private Container $container;
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->container = $app->container();
        $this->output = new Output();
        $this->register();
    }

    /** @param array<int, string> $argv */
    public function run(array $argv): int
    {
        $input = new Input($argv);

        if ($input->bool('no-ansi')) {
            $this->output = new Output(STDOUT, STDERR);
            putenv('NO_COLOR=1');
        }
        $this->output->setVerbose($input->bool('verbose') || $input->bool('v'));
        $this->output->setQuiet($input->bool('quiet') || $input->bool('q'));

        $name = $input->command();

        if ($name === '' || $name === 'help' || $input->bool('help') || $input->bool('h')) {
            if ($name !== '' && $name !== 'help' && isset($this->commands[$name])) {
                $this->showCommandHelp($this->commands[$name]);

                return 0;
            }

            return $this->showHelp($name === '' ? null : $name);
        }

        if ($name === 'list') {
            return $this->showHelp(null);
        }

        if ($name === 'version' || $input->bool('version') || $input->bool('V')) {
            $this->output->line(sprintf('<bold>BAS-IP Gate Manager</bold> <cyan>%s</cyan> <dim>(%s)</dim>', App::VERSION, App::CODENAME));
            $this->output->line(sprintf('<dim>PHP %s · %s · %s</dim>', PHP_VERSION, PHP_OS_FAMILY, date('c')));

            return 0;
        }

        if (!isset($this->commands[$name])) {
            $this->output->error('Неизвестная команда: ' . $name);
            $suggestion = $this->suggest($name);
            if ($suggestion !== null) {
                $this->output->line('  Возможно, вы имели в виду: <cyan>' . $suggestion . '</cyan>');
            }
            $this->output->line('  Выполните <cyan>bin/bas-ip list</cyan>, чтобы увидеть все команды.');

            return 1;
        }

        $command = $this->commands[$name];

        // Reject unknown options loudly: a silent typo (`--sum` instead of
        // `--total`) must not turn into «command succeeded with defaults».
        $allowed = array_merge(
            array_map('strval', array_keys($command->options)),
            ['help', 'h', 'verbose', 'v', 'quiet', 'q', 'no-ansi', 'version', 'V'],
        );
        foreach ($input->optionNames() as $given) {
            if (in_array($given, $allowed, true)) {
                continue;
            }
            $this->output->error(sprintf('Неизвестная опция команды %s: --%s', $name, $given));
            $closest = $this->suggest($given, $allowed);
            if ($closest !== null) {
                $this->output->line('  Возможно, вы имели в виду: <cyan>--' . $closest . '</cyan>');
            }
            $this->output->line('  Справка: <cyan>bin/bas-ip ' . $name . ' --help</cyan>');

            return 1;
        }

        $startedAt = microtime(true);

        try {
            // Fail fast with a useful message instead of a PDO stack trace.
            if ($command->needsDatabase && !$this->ensureDatabase()) {
                return 1;
            }

            $code = (int) ($command->handler)($input, $this->output, $this->container);
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());
            if ($this->output->isVerbose()) {
                $this->output->line('<dim>' . $e->getFile() . ':' . $e->getLine() . '</dim>');
                $this->output->line('<dim>' . $e->getTraceAsString() . '</dim>');
            }
            try {
                /** @var Logger $logger */
                $this->container->get(Logger::class)->channel('error')->exception($e, ['command' => $name]);
            } catch (Throwable) {
            }

            return 1;
        }

        $ms = (microtime(true) - $startedAt) * 1000;
        $this->output->debug(sprintf('Готово за %.0f мс', $ms));

        return $code === 0 ? 0 : $code;
    }

    private function ensureDatabase(): bool
    {
        try {
            /** @var Database $db */
            $db = $this->container->get(Database::class);
            if ($db->ping()) {
                return true;
            }
            $this->output->error('Нет соединения с базой данных.');
        } catch (Throwable $e) {
            $this->output->error('База данных недоступна: ' . $e->getMessage());
        }

        $config = $this->container->get(Config::class);
        $this->output->line('  Драйвер: <cyan>' . $config->string('db.default') . '</cyan>');
        $this->output->line('  Проверьте <cyan>.bas-ip.config.local.php</cyan> или выполните <cyan>bin/bas-ip install</cyan>.');

        return false;
    }

    private function register(): void
    {
        $dir = $this->container->get(Config::class)->getPath('config');
        $files = [
            __DIR__ . '/commands/core.php',
            __DIR__ . '/commands/residents.php',
            __DIR__ . '/commands/billing.php',
            __DIR__ . '/commands/gate.php',
            __DIR__ . '/commands/system.php',
            $dir . '/commands.php',        // optional site-specific commands
        ];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            /** @var array<int, Command>|null $commands */
            $commands = require $file;
            if (!is_array($commands)) {
                continue;
            }
            foreach ($commands as $command) {
                if ($command instanceof Command) {
                    $this->commands[$command->name] = $command;
                }
            }
        }

        ksort($this->commands);
    }

    /** @return array<string, Command> */
    public function commands(): array
    {
        return $this->commands;
    }

    private function showHelp(?string $filter): int
    {
        $this->output->line('');
        $this->output->line('  <bold><cyan>BAS-IP Gate Manager</cyan></bold> <dim>v' . App::VERSION . '</dim> — консоль управления');
        $this->output->line('  <dim>Использование:</dim> <bold>bin/bas-ip</bold> <green>команда</green> [аргументы] [--опции]');
        $this->output->line('');

        $groups = [];
        foreach ($this->commands as $command) {
            if ($command->hidden) {
                continue;
            }
            if ($filter !== null && !str_contains($command->name, $filter) && !str_contains($command->description, $filter)) {
                continue;
            }
            $groups[$command->group][] = $command;
        }

        foreach ($groups as $group => $commands) {
            $this->output->line(' <bold>' . $group . '</bold>');
            foreach ($commands as $command) {
                $this->output->line(sprintf('  <green>%-28s</green> <dim>%s</dim>', $command->name, $command->description));
            }
            $this->output->line('');
        }

        $this->output->line(' <bold>Глобальные опции</bold>');
        $this->output->line('  <green>--help, -h</green>                 Справка по команде');
        $this->output->line('  <green>--verbose, -v</green>              Подробный вывод и стектрейсы');
        $this->output->line('  <green>--quiet, -q</green>                Минимальный вывод (для cron)');
        $this->output->line('  <green>--no-ansi</green>                  Без цветов');
        $this->output->line('');
        $this->output->line(' <dim>Быстрый старт:</dim>');
        $this->output->line('   bin/bas-ip install --no-interaction     # установка');
        $this->output->line('   bin/bas-ip doctor                       # самодиагностика');
        $this->output->line('   bin/bas-ip schedule:run                 # добавить в cron каждую минуту');
        $this->output->line('');

        return 0;
    }

    private function showCommandHelp(Command $command): int
    {
        $this->output->line('');
        $this->output->line('  <bold><green>' . $command->name . '</green></bold> — ' . $command->description);
        $this->output->line('  <dim>Использование:</dim> ' . $command->usage);

        if ($command->arguments !== []) {
            $this->output->line('');
            $this->output->line('  <bold>Аргументы</bold>');
            foreach ($command->arguments as $name => $description) {
                $this->output->line(sprintf('    <cyan>%-20s</cyan> %s', $name, $description));
            }
        }
        if ($command->options !== []) {
            $this->output->line('');
            $this->output->line('  <bold>Опции</bold>');
            foreach ($command->options as $name => $description) {
                $this->output->line(sprintf('    <cyan>--%-18s</cyan> %s', $name, $description));
            }
        }
        $this->output->line('');

        return 0;
    }

    /** Closest match by edit distance — for commands and option names alike.
     *  @param array<int, string>|null $candidates defaults to registered commands */
    private function suggest(string $name, ?array $candidates = null): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($candidates ?? array_keys($this->commands) as $candidate) {
            $distance = levenshtein($name, (string) $candidate);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = (string) $candidate;
            }
        }

        return $bestDistance <= 4 ? $best : null;
    }

    public function output(): Output
    {
        return $this->output;
    }
}
