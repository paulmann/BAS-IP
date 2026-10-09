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
 * WhatsApp Cloud API (Meta) — text messages.
 *
 * Works with the official Cloud API and with most compatible relays
 * (chat-api, wazzup, green-api style endpoints accept the same payload shape
 * when `endpoint` is overridden).
 */
final class WhatsAppChannel extends AbstractChannel
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

    public function name(): string  { return 'whatsapp'; }
    public function label(): string { return 'WhatsApp'; }
    public function icon(): string  { return 'message-circle'; }

    public function enabled(): bool
    {
        return (bool) $this->option('enabled', false)
            && (string) ($this->secret('token') ?? '') !== '';
    }

    public function supports(string $contactType): bool
    {
        return $contactType === 'whatsapp' || $contactType === 'phone';
    }

    public function send(string $to, Message $message): array
    {
        $phone = preg_replace('/\D/', '', $to) ?? '';
        if (strlen($phone) < 7) {
            return ['ok' => false, 'error' => 'Некорректный номер WhatsApp: ' . $to];
        }
        $token = $this->secret('token');
        $phoneId = (string) $this->option('phone_id', '');
        $endpoint = (string) $this->option('endpoint', 'https://graph.facebook.com/v19.0');
        $url = rtrim($endpoint, '/') . ($phoneId !== '' ? '/' . $phoneId . '/messages' : '');

        $text = $message->getSubject() !== ''
            ? '*' . $message->getSubject() . "*\n\n" . $message->getBody()
            : $message->getBody();

        try {
            $response = $this->http->postJson($url, [
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => $phone,
                'type'              => 'text',
                'text'              => ['preview_url' => true, 'body' => mb_substr($text, 0, 4000)],
            ], [
                'timeout' => (int) $this->option('timeout', 15),
                'headers' => ['Authorization' => 'Bearer ' . $token],
            ]);

            $data = $response->json();
            if ($response->ok() && isset($data['messages'][0]['id'])) {
                return ['ok' => true, 'provider_id' => 'wa:' . (string) $data['messages'][0]['id']];
            }

            return ['ok' => false, 'error' => 'WhatsApp API: ' . substr($response->body, 0, 250), 'raw' => $data];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'WhatsApp API: ' . $e->getMessage()];
        }
    }

    public function configFields(): array
    {
        return [
            ['key' => 'enabled', 'label' => 'Канал включён', 'type' => 'bool'],
            ['key' => 'endpoint', 'label' => 'API endpoint', 'type' => 'text'],
            ['key' => 'phone_id', 'label' => 'Phone Number ID', 'type' => 'text'],
            ['key' => 'token', 'label' => 'Токен', 'type' => 'secret'],
        ];
    }
}
