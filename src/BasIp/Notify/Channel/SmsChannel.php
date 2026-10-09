<?php

declare(strict_types=1);

namespace BasIp\Notify\Channel;

use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\HttpClient;
use BasIp\Core\Logger;
use BasIp\Notify\Message;
use BasIp\Services\SettingsService;
use Throwable;

/**
 * Generic HTTP SMS gateway.
 *
 * Works with any provider that exposes a REST endpoint (SMS.ru, SMS Aero,
 * MainIp, Mobizon, Twilio-compatible relays, a local GSM modem HTTP bridge…).
 * Configure the URL template once:
 *
 *   endpoint: https://api.provider.com/send
 *   template: GET  ?phone={to}&text={text}&api_id={token}
 *   success_when: body contains "OK"   (or json path)
 *
 * Everything is parameterised — no provider SDK is compiled in.
 */
final class SmsChannel extends AbstractChannel
{
    public function __construct(
        Config $config,
        SettingsService $settings,
        Logger $logger,
        Crypto $crypto,
        private readonly HttpClient $http,
    ) {
        parent::__construct($config, $settings, $logger, $crypto);
    }

    public function name(): string  { return 'sms'; }
    public function label(): string { return 'SMS (HTTP-шлюз)'; }
    public function icon(): string  { return 'smartphone'; }

    public function enabled(): bool
    {
        return (bool) $this->option('enabled', false)
            && (string) ($this->option('endpoint') ?? '') !== '';
    }

    public function supports(string $contactType): bool
    {
        return $contactType === 'phone';
    }

    public function send(string $to, Message $message): array
    {
        $phone = $this->normalizePhone($to);
        if ($phone === null) {
            return ['ok' => false, 'error' => 'Некорректный номер телефона: ' . $to];
        }

        $endpoint = (string) $this->option('endpoint');
        $method   = strtoupper((string) $this->option('template', 'GET'));
        $body     = $this->truncate($message->getBody(), (int) $this->option('max_length', 700));
        $sender   = $this->option('sender', $this->option('from'));

        $replacements = [
            '{to}'     => $phone,
            '{phone}'  => $phone,
            '{text}'   => $body,
            '{body}'   => $body,
            '{subject}'=> $message->getSubject(),
            '{token}'  => (string) ($this->secret('password') ?? $this->secret('api_key') ?? ''),
            '{login}'  => (string) ($this->option('login') ?? ''),
            '{sender}' => (string) ($sender ?? ''),
            '{from}'   => (string) ($sender ?? ''),
        ];

        try {
            $payload = array_map(
                static fn ($v) => strtr((string) $v, $replacements),
                (array) $this->option('params', [])
            );
            $url = strtr($endpoint, $replacements);

            $response = $method === 'POST'
                ? $this->http->post($url, $payload !== [] ? $payload : $replacements, $this->httpOptions())
                : $this->http->get($url, $payload, $this->httpOptions());

            $data = $response->json();
            $ok = $response->ok();

            // Optional provider-specific success detection.
            $successWhen = $this->option('success_when');
            if (is_string($successWhen) && $successWhen !== '') {
                $ok = $ok && str_contains($response->body, $successWhen);
            }
            $successPath = $this->option('success_path');
            if (is_string($successPath) && $successPath !== '' && is_array($data)) {
                $ok = $ok && (bool) \BasIp\Support\Arr::get($data, $successPath, false);
            }

            if (!$ok) {
                $error = is_array($data)
                    ? (string) (\BasIp\Support\Arr::get($data, 'error') ?? \BasIp\Support\Arr::get($data, 'description') ?? json_encode($data, JSON_UNESCAPED_UNICODE))
                    : substr($response->body, 0, 300);

                return ['ok' => false, 'error' => 'Шлюз SMS: ' . ($error !== '' ? $error : ('HTTP ' . $response->status)), 'raw' => $data];
            }

            $providerId = is_array($data)
                ? (string) (\BasIp\Support\Arr::get($data, 'id') ?? \BasIp\Support\Arr::get($data, 'sms_id') ?? \BasIp\Support\Arr::get($data, 'message_id') ?? '')
                : '';

            return ['ok' => true, 'provider_id' => $providerId !== '' ? 'sms:' . $providerId : null, 'raw' => $data];
        } catch (Throwable $e) {
            $this->log('error', 'SMS gateway error', ['to' => $phone, 'error' => $e->getMessage()]);

            return ['ok' => false, 'error' => 'SMS-шлюз недоступен: ' . $e->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function httpOptions(): array
    {
        return [
            'timeout' => (int) $this->option('timeout', 15),
            'verify_tls' => (bool) $this->option('verify_tls', true),
            'headers' => (array) $this->option('headers', []),
            'retries' => (int) $this->option('retries', 1),
        ];
    }

    private function normalizePhone(string $to): ?string
    {
        $digits = preg_replace('/\D/', '', $to) ?? '';
        if ($digits === '' || strlen($digits) < 7) {
            return null;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '8')) {
            $digits = '7' . substr($digits, 1);
        }
        $format = (string) $this->option('phone_format', 'e164');

        return match ($format) {
            'national' => ltrim($digits, '+'),
            'plus'     => '+' . ltrim($digits, '+'),
            default    => ltrim($digits, '+'),
        };
    }

    private function truncate(string $text, int $max): int|string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max - 1, 'UTF-8') . '…';
    }

    public function configFields(): array
    {
        return [
            ['key' => 'enabled', 'label' => 'Канал включён', 'type' => 'bool'],
            ['key' => 'endpoint', 'label' => 'URL шлюза', 'type' => 'text', 'placeholder' => 'https://sms.ru/sms/send'],
            ['key' => 'template', 'label' => 'HTTP-метод', 'type' => 'select', 'options' => ['GET' => 'GET', 'POST' => 'POST']],
            ['key' => 'login', 'label' => 'Логин / api_id', 'type' => 'text'],
            ['key' => 'password', 'label' => 'Пароль / токен', 'type' => 'secret'],
            ['key' => 'sender', 'label' => 'Имя отправителя', 'type' => 'text'],
            ['key' => 'success_when', 'label' => 'Признак успеха в ответе', 'type' => 'text', 'hint' => 'Подстрока, например "OK" или "100"'],
            ['key' => 'max_length', 'label' => 'Макс. длина текста', 'type' => 'number'],
        ];
    }
}
