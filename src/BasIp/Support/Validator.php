<?php

declare(strict_types=1);

namespace BasIp\Support;

/**
 * Validation with Russian messages and dot-notation field access.
 *
 *   $v = Validator::make($request->all(), [
 *       'apartment'  => 'required|max:8',
 *       'email'      => 'nullable|email',
 *       'total'      => 'required|numeric|min:0.01',
 *       'phones.*.value' => 'required|phone',
 *   ], ['apartment' => 'Квартира']);
 *   if ($v->fails()) { … $v->errors() … }
 */
final class Validator
{
    /** @var array<string, array<int, string>> */
    private array $errors = [];

    /** @var array<string, mixed> */
    private array $validated = [];

    private bool $ran = false;

    /** @var array<string, string> */
    private array $labels;

    /**
     * @param array<string, mixed> $data
     * @param array<string, string> $rules
     * @param array<string, string> $labels
     * @param array<string, string> $messages custom per-rule messages: "field.rule" => "text"
     */
    public function __construct(
        private readonly array $data,
        private readonly array $rules,
        array $labels = [],
        private readonly array $messages = [],
    ) {
        $this->labels = $labels;
    }

    /** @param array<string, mixed> $data @param array<string, string> $rules */
    public static function make(array $data, array $rules, array $labels = [], array $messages = []): self
    {
        return new self($data, $rules, $labels, $messages);
    }

    public function validate(): bool
    {
        $this->ran = true;
        $this->errors = [];
        $this->validated = [];

        foreach ($this->rules as $field => $ruleString) {
            $rules = is_array($ruleString) ? $ruleString : explode('|', (string) $ruleString);
            $value = Arr::get($this->data, (string) $field);
            $label = $this->label((string) $field);

            $required = in_array('required', $rules, true);
            $nullable = in_array('nullable', $rules, true) || in_array('sometimes', $rules, true);

            // Wildcard rules (phones.*.value) expand against the data.
            if (str_contains((string) $field, '*')) {
                $this->validateWildcard((string) $field, $rules);
                continue;
            }

            $empty = $value === null || $value === '' || $value === [];
            if ($required && $empty) {
                $this->addError((string) $field, $this->message((string) $field, 'required', ':attribute обязательно для заполнения.', ['attribute' => $label]));
                continue;
            }
            if ($empty) {
                if (!$nullable && Arr::has($this->data, (string) $field)) {
                    $this->validated[(string) $field] = $value;
                } elseif (!$nullable) {
                    $this->validated[(string) $field] = null;
                } else {
                    $this->validated[(string) $field] = null;
                }
                continue;
            }

            $numeric = $this->isNumericField($rules);
            foreach ($rules as $rule) {
                $rule = trim((string) $rule);
                if ($rule === '' || in_array($rule, ['required', 'nullable', 'sometimes'], true)) {
                    continue;
                }
                [$name, $param] = $this->parseRule($rule);
                $error = $this->check($name, $param, $value, $label, (string) $field, $numeric);
                if ($error !== null) {
                    $this->addError((string) $field, $error);
                    continue 2;
                }
                $value = $this->normalize($name, $value);
            }

            $this->validated[(string) $field] = $value;
        }

        return $this->errors === [];
    }

    /** @return array{0: string, 1: ?string} */
    private function parseRule(string $rule): array
    {
        if (!str_contains($rule, ':')) {
            return [strtolower($rule), null];
        }
        [$name, $param] = explode(':', $rule, 2);

        return [strtolower($name), $param];
    }

    private function check(string $rule, ?string $param, mixed $value, string $label, string $field, bool $numeric = false): ?string
    {
        $custom = $this->messages[$field . '.' . $rule] ?? null;

        return match ($rule) {
            'max' => $this->length($value, $numeric) > (float) $param
                ? ($custom ?? sprintf('%s: не более %s символов.', $label, $param))
                : null,
            'min' => $this->length($value, $numeric) < (float) $param
                ? ($custom ?? sprintf('%s: не менее %s символов.', $label, $param))
                : null,
            'size' => $this->length($value, $numeric) !== (float) $param
                ? ($custom ?? sprintf('%s: ровно %s символов.', $label, $param))
                : null,
            'between' => $this->outOfRange($value, $param)
                ? ($custom ?? sprintf('%s: значение вне допустимого диапазона (%s).', $label, str_replace(',', '–', (string) $param)))
                : null,
            'numeric' => !is_numeric($value)
                ? ($custom ?? sprintf('%s должно быть числом.', $label))
                : null,
            'integer', 'int' => !preg_match('/^-?\d+$/', trim((string) $value))
                ? ($custom ?? sprintf('%s должно быть целым числом.', $label))
                : null,
            'email' => !filter_var((string) $value, FILTER_VALIDATE_EMAIL)
                ? ($custom ?? sprintf('%s: некорректный e-mail.', $label))
                : null,
            'url' => !filter_var((string) $value, FILTER_VALIDATE_URL)
                ? ($custom ?? sprintf('%s: некорректная ссылка.', $label))
                : null,
            'phone' => !Phone::isValid((string) $value)
                ? ($custom ?? sprintf('%s: некорректный номер телефона.', $label))
                : null,
            'plate' => !Plate::isValid((string) $value)
                ? ($custom ?? sprintf('%s: некорректный номер автомобиля.', $label))
                : null,
            'date' => strtotime((string) $value) === false
                ? ($custom ?? sprintf('%s: некорректная дата.', $label))
                : null,
            'boolean' => !in_array((string) $value, ['0', '1', 'true', 'false', 'on', 'off', ''], true)
                ? ($custom ?? sprintf('%s должно быть «да» или «нет».', $label))
                : null,
            'in' => !in_array((string) $value, array_map('trim', explode(',', (string) $param)), true)
                ? ($custom ?? sprintf('%s: недопустимое значение.', $label))
                : null,
            'not_in' => in_array((string) $value, array_map('trim', explode(',', (string) $param)), true)
                ? ($custom ?? sprintf('%s: недопустимое значение.', $label))
                : null,
            'regex' => !preg_match((string) $param, (string) $value)
                ? ($custom ?? sprintf('%s: неверный формат.', $label))
                : null,
            'alpha_dash' => !preg_match('/^[\p{L}\p{N}._\- ]+$/u', (string) $value)
                ? ($custom ?? sprintf('%s: допустимы буквы, цифры, точка, дефис и подчёркивание.', $label))
                : null,
            'confirmed' => (string) $value !== (string) (Arr::get($this->data, $field . '_confirmation') ?? '')
                ? ($custom ?? sprintf('%s: значения не совпадают.', $label))
                : null,
            'array' => !is_array($value)
                ? ($custom ?? sprintf('%s должно быть списком.', $label))
                : null,
            'time' => !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value)
                ? ($custom ?? sprintf('%s: время в формате ЧЧ:ММ.', $label))
                : null,
            default => null,
        };
    }

    /** @param array<int, string> $rules */
    private function validateWildcard(string $field, array $rules): void
    {
        $paths = $this->expandWildcard($field);
        foreach ($paths as $path) {
            $value = Arr::get($this->data, $path);
            $label = $this->label($field) . ' [' . $this->indexPath($path, $field) . ']';
            $required = in_array('required', $rules, true);
            if ($required && ($value === null || $value === '')) {
                $this->addError($path, sprintf('%s обязательно для заполнения.', $label));
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $numeric = $this->isNumericField($rules);
            foreach ($rules as $rule) {
                $rule = trim((string) $rule);
                if ($rule === '' || in_array($rule, ['required', 'nullable', 'sometimes'], true)) {
                    continue;
                }
                [$name, $param] = $this->parseRule($rule);
                $error = $this->check($name, $param, $value, $label, $field, $numeric);
                if ($error !== null) {
                    $this->addError($path, $error);
                    break;
                }
            }
        }
    }

    /** @return array<int, string> */
    private function expandWildcard(string $field): array
    {
        $segments = explode('.', $field);
        $paths = [''];
        foreach ($segments as $segment) {
            $next = [];
            foreach ($paths as $path) {
                if ($segment === '*') {
                    $node = $path === '' ? $this->data : Arr::get($this->data, $path);
                    if (is_array($node)) {
                        foreach (array_keys($node) as $key) {
                            $next[] = $path === '' ? (string) $key : $path . '.' . $key;
                        }
                    }
                    continue;
                }
                $next[] = $path === '' ? $segment : $path . '.' . $segment;
            }
            $paths = $next;
        }

        return array_values(array_filter($paths));
    }

    private function indexPath(string $path, string $field): string
    {
        $pathParts = explode('.', $path);
        $fieldParts = explode('.', $field);
        $out = [];
        foreach ($fieldParts as $i => $part) {
            if ($part === '*' && isset($pathParts[$i])) {
                $out[] = '#' . ((int) $pathParts[$i] + 1);
            }
        }

        return implode(' ', $out) ?: '#1';
    }

    /**
     * Size of a value for the `min`/`max`/`size`/`between` rules.
     *
     * Mirrors the usual framework semantics: when the field is declared numeric
     * the *number* is compared, otherwise the string length. Without this a
     * phone number like `89031234567` would fail `max:32`.
     */
    private function length(mixed $value, bool $numeric = false): float
    {
        if (is_array($value)) {
            return (float) count($value);
        }
        if ($numeric && is_numeric($value)) {
            return (float) $value;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        return (float) mb_strlen((string) $value, 'UTF-8');
    }

    /** @param array<int, string> $rules */
    private function isNumericField(array $rules): bool
    {
        foreach ($rules as $rule) {
            $name = strtolower((string) (explode(':', (string) $rule, 2)[0] ?? ''));
            if (in_array($name, ['numeric', 'integer', 'int'], true)) {
                return true;
            }
        }

        return false;
    }

    private function outOfRange(mixed $value, ?string $param): bool
    {
        if (!is_numeric($value) || $param === null) {
            return true;
        }
        [$min, $max] = array_pad(explode(',', $param, 2), 2, null);

        return (float) $value < (float) $min || (float) $value > (float) $max;
    }

    private function normalize(string $rule, mixed $value): mixed
    {
        return match ($rule) {
            'phone'   => is_string($value) ? Phone::normalize($value) : $value,
            'plate'   => is_string($value) ? Plate::normalize($value) : $value,
            'email'   => is_string($value) ? mb_strtolower(trim($value), 'UTF-8') : $value,
            'integer', 'int' => (int) $value,
            'numeric' => (float) $value,
            'boolean' => self::truthy($value) ? 1 : 0,
            default   => $value,
        };
    }

    private static function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value !== 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'y', 't'], true);
    }

    /**
     * Validation runs on first inspection, so callers can use `passes()`,
     * `fails()`, `errors()` and `validated()` in any order without remembering
     * to call `validate()` first.
     */
    private function ensureValidated(): void
    {
        if (!$this->ran) {
            $this->validate();
        }
    }

    public function fails(): bool
    {
        $this->ensureValidated();

        return $this->errors !== [];
    }

    public function passes(): bool
    {
        $this->ensureValidated();

        return $this->errors === [];
    }

    /** @return array<string, array<int, string>> */
    public function errors(): array
    {
        $this->ensureValidated();

        return $this->errors;
    }

    public function firstError(): ?string
    {
        $this->ensureValidated();
        foreach ($this->errors as $messages) {
            return $messages[0] ?? null;
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function validated(): array
    {
        $this->ensureValidated();

        return $this->validated;
    }

    public function value(string $field, mixed $default = null): mixed
    {
        return $this->validated[$field] ?? Arr::get($this->data, $field, $default);
    }

    private function addError(string $field, string $message): void
    {
        $this->errors[$field][] = $message;
    }

    private function label(string $field): string
    {
        if (isset($this->labels[$field])) {
            return $this->labels[$field];
        }
        $base = (string) (explode('.', $field)[0] ?? $field);

        return $this->labels[$base] ?? $base;
    }

    /** @param array<string, string> $replace */
    private function message(string $field, string $rule, string $default, array $replace): string
    {
        $custom = $this->messages[$field . '.' . $rule] ?? null;

        return $custom !== null ? strtr($custom, $replace) : strtr($default, $replace);
    }
}
