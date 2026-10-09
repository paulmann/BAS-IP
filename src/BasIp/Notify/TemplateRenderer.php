<?php

declare(strict_types=1);

namespace BasIp\Notify;

use BasIp\Core\Config;
use BasIp\Support\Arr;
use BasIp\Support\Money;
use BasIp\Support\Str;

/**
 * Renders notification templates.
 *
 * A template is `{subject, body}` with `{placeholder}` tokens. Templates come
 * from `config.notify.templates.*`, can be overridden per-installation via the
 * settings table, and support three modifiers:
 *
 *   {amount|money}          — format as currency
 *   {name|upper}            — uppercase
 *   {due_date|date:d.m.Y}   — reformat a date
 *   {items|count}           — count an array
 *   {text|limit:80}         — truncate
 *
 * Unknown placeholders are left untouched so a typo is visible rather than
 * silently sending an empty message.
 */
final class TemplateRenderer
{
    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $template `{subject, body}`; falls back to config
     * @return array{subject: string, body: string}
     */
    public function render(string $name, array $context = [], array $template = []): array
    {
        $template = $template !== [] ? $template : (array) $this->config->get('notify.templates.' . $name, []);
        $subject = (string) ($template['subject'] ?? $name);
        $body    = (string) ($template['body'] ?? '');

        return [
            'subject' => $this->interpolate($subject, $context),
            'body'    => $this->interpolate($body, $context),
        ];
    }

    /**
     * Replace `{token}` and `{token|modifier:arg}` occurrences.
     *
     * After substitution, multi-line texts drop rows that degenerated into a
     * bare label with an empty value («Оплатить: {pay_link}» without a link
     * configured) — a dangling label looks like a bug in the finished letter.
     *
     * @param array<string, mixed> $context
     */
    public function interpolate(string $text, array $context): string
    {
        if ($text === '' || !str_contains($text, '{')) {
            return $text;
        }

        $rendered = (string) preg_replace_callback('/\{\s*([a-zA-Z0-9_.]+)\s*(?:\|\s*([a-zA-Z0-9_]+)\s*(?::\s*([^}]*?))?\s*)?\}/', static function (array $m) use ($context): string {
            $key      = $m[1];
            $modifier = isset($m[2]) && $m[2] !== '' ? strtolower($m[2]) : null;
            $arg      = isset($m[3]) ? trim($m[3]) : null;

            if (!Arr::has($context, $key) && !array_key_exists($key, $context)) {
                return $m[0];                       // leave unknown placeholders visible
            }
            $value = Arr::get($context, $key);

            return self::applyModifier($value, $modifier, $arg);
        }, $text);

        return self::dropEmptyLabelLines($rendered);
    }

    /** Remove «Label:» lines whose placeholder interpolated to nothing. */
    private static function dropEmptyLabelLines(string $text): string
    {
        if (!str_contains($text, "\n")) {
            return $text;                           // subjects stay untouched
        }

        $lines  = explode("\n", $text);
        $kept   = [];
        $dropped = 0;
        foreach ($lines as $line) {
            if (preg_match('/^\s*[^\s:][^:\n]{0,39}:\s*$/u', $line) === 1) {
                $dropped++;
                continue;
            }
            $kept[] = $line;
        }
        if ($dropped === 0 || $kept === []) {
            return $text;
        }

        // Collapse the blank gap the dropped line leaves behind («…\n\n\nКвартира…» → «…\n\nКвартира…»).
        $joined = implode("\n", $kept);

        return (string) preg_replace('/\n{3,}/', "\n\n", $joined);
    }

    private static function applyModifier(mixed $value, ?string $modifier, ?string $arg): string
    {
        return match ($modifier) {
            'money'    => Money::format(is_numeric($value) ? (float) $value : 0.0, $arg ?? '₽'),
            'upper'    => Str::upper(Str::stringify($value)),
            'lower'    => Str::lower(Str::stringify($value)),
            'title'    => Str::title(Str::stringify($value)),
            'date'     => self::formatDate($value, $arg ?? 'd.m.Y'),
            'datetime' => self::formatDate($value, $arg ?? 'd.m.Y H:i'),
            'limit'    => Str::limit(Str::stringify($value), (int) ($arg ?? 100)),
            'count'    => (string) (is_array($value) ? count($value) : 0),
            'number'   => is_numeric($value) ? number_format((float) $value, (int) ($arg ?? 2), ',', ' ') : Str::stringify($value),
            'plural'   => self::plural($value, $arg),
            'default'  => Str::stringify($value) !== '' ? Str::stringify($value) : (string) $arg,
            'mask'     => Str::mask(Str::stringify($value)),
            null, ''   => Str::stringify($value),
            default    => Str::stringify($value),
        };
    }

    private static function formatDate(mixed $value, string $format): string
    {
        if ($value === null || $value === '') {
            return '—';
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $ts === false ? Str::stringify($value) : date($format, $ts);
    }

    /** `{count|plural:житель,жителя,жителей}` */
    private static function plural(mixed $value, ?string $arg): string
    {
        $count = (int) $value;
        $forms = array_map('trim', explode(',', (string) $arg));
        if (count($forms) < 3) {
            return (string) $count;
        }

        return $count . ' ' . Str::plural($count, $forms[0], $forms[1], $forms[2]);
    }

    /** @return array<string, string> */
    public function placeholders(): array
    {
        return [
            '{name}'          => 'Краткое имя жителя (Иванов И.И.)',
            '{full_name}'     => 'Полное ФИО',
            '{apartment}'     => 'Номер квартиры',
            '{building}'      => 'Корпус',
            '{address}'       => 'Полный адрес',
            '{amount}'        => 'Сумма с символом валюты',
            '{currency}'      => 'Код валюты',
            '{period}'        => 'Расчётный период',
            '{due_date}'      => 'Срок оплаты',
            '{invoice_no}'    => 'Номер счёта',
            '{charge_no}'     => 'Номер начисления',
            '{balance}'       => 'Баланс жителя',
            '{debt}'          => 'Сумма долга',
            '{pay_link}'      => 'Ссылка на оплату',
            '{organisation}'  => 'Название организации/ЖК',
            '{support_phone}' => 'Телефон поддержки',
            '{support_email}' => 'E-mail поддержки',
            '{today}'         => 'Текущая дата',
            '{code}'          => 'Код гостевого доступа',
            '{pin}'           => 'PIN гостевого доступа',
            '{link}'          => 'Ссылка гостевого доступа',
            '{expires_at}'    => 'Действует до',
            '{gate}'          => 'Название шлагбаума',
            '{cabinet_url}'   => 'Ссылка на личный кабинет',
            '{login}'         => 'Логин',
            '{reason}'        => 'Причина перерасчёта',
        ];
    }

    /** @return array<int, string> */
    public function templateNames(): array
    {
        $names = array_keys((array) $this->config->get('notify.templates', []));
        sort($names);

        return $names;
    }
}
