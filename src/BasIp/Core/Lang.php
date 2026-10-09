<?php

declare(strict_types=1);

namespace BasIp\Core;

use BasIp\Support\Str;

/**
 * Very small i18n layer.
 *
 * Russian is the source language of the UI, so the *message keys themselves*
 * are Russian. When the locale is anything else, keys are translated through
 * `resources/lang/<locale>.php`. That keeps the templates readable while still
 * shipping a full English translation.
 *
 * Placeholders use `{name}` syntax.
 */
final class Lang
{
    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    /** @var array<string, string> */
    private array $missing = [];

    public function __construct(
        private readonly Config $config,
        private string $locale,
        private readonly string $fallback = 'en',
    ) {
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
        $this->catalogues = [];
    }

    /** @param array<string, scalar|null> $replace */
    public function get(string $key, array $replace = []): string
    {
        $line = $this->translate($key);
        foreach ($replace as $name => $value) {
            $line = str_replace('{' . $name . '}', Str::stringify($value), $line);
        }

        return $line;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->catalogue());
    }

    /** @return array<int, string> */
    public function missingKeys(): array
    {
        $keys = array_keys($this->missing);
        sort($keys);

        return $keys;
    }

    private function translate(string $key): string
    {
        if ($this->locale === 'ru') {
            return $key;
        }
        $catalogue = $this->catalogue();
        if (isset($catalogue[$key])) {
            return $catalogue[$key];
        }
        if ($this->locale !== $this->fallback) {
            $fallback = $this->load($this->fallback);
            if (isset($fallback[$key])) {
                return $fallback[$key];
            }
        }
        $this->missing[$key] = $key;

        return $key;
    }

    /** @return array<string, string> */
    private function catalogue(): array
    {
        return $this->catalogues[$this->locale] ??= $this->load($this->locale);
    }

    /** @return array<string, string> */
    private function load(string $locale): array
    {
        if (isset($this->catalogues[$locale])) {
            return $this->catalogues[$locale];
        }
        $file = $this->config->getPath('resources') . '/lang/' . preg_replace('/[^a-z\-_]/i', '', $locale) . '.php';
        if (!is_file($file)) {
            return $this->catalogues[$locale] = [];
        }
        /** @var array<string, string>|null $data */
        $data = require $file;

        return $this->catalogues[$locale] = is_array($data) ? $data : [];
    }
}
