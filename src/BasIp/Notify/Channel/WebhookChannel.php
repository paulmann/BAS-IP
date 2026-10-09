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
 * Outbound webhook — hand the notification to any external system
 * (Home Assistant, n8n, Mattermost, Slack, a custom CRM, …).
 *
 * Payload is signed with `X-BAS-IP-Signature: sha256=…` when a secret is set.
 */
final class WebhookChannel extends AbstractChannel
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

    public function name(): string  { return 'webhook'; }
    public function label(): string { return 'Webhook (HTTP POST)'; }
    public function icon(): string  { return 'webhook'; }

    public function enabled(): bool
    {
        return (bool) $this->option('enabled', false) && (string) ($this->option('url') ?? '') !== '';
    }

    public function supports(string $contactType): bool
    {
        return true;      // the payload carries everything; the receiver decides
    }

    public function send(string $to, Message $message): array
    {
        $url = (string) $this->option('url');
        if ($url === '') {
            return ['ok' => false, 'error' => 'Не указан URL вебхука.'];
        }

        $payload = [
            'event'      => 'notification.' . $message->getTemplate(),
            'channel'    => 'webhook',
            'to'         => $to,
            'subject'    => $message->getSubject(),
            'body'       => $message->getBody(),
            'template'   => $message->getTemplate(),
            'context'    => $message->getContext(),
            'resident_id' => $message->getResidentId(),
            'charge_id'  => $message->getChargeId(),
            'invoice_id' => $message->getInvoiceId(),
            'timestamp'  => date(DATE_ATOM),
        ];

        $headers = (array) $this->option('headers', []);
        $secret = $this->secret('secret');
        if ($secret !== null && $secret !== '') {
            $headers['X-BAS-IP-Signature'] = 'sha256=' . hash_hmac('sha256', (string) json_encode($payload), $secret);
        }

        try {
            $response = $this->http->postJson($url, $payload, [
                'timeout' => (int) $this->option('timeout', 10),
                'headers' => $headers,
                'verify_tls' => (bool) $this->option('verify_tls', true),
                'retries' => (int) $this->option('retries', 2),
            ]);

            if (!$response->ok()) {
                return ['ok' => false, 'error' => 'Webhook вернул HTTP ' . $response->status . ': ' . substr($response->body, 0, 200)];
            }

            return ['ok' => true, 'provider_id' => 'webhook:' . $response->status, 'raw' => $response->json()];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Webhook: ' . $e->getMessage()];
        }
    }

    public function configFields(): array
    {
        return [
            ['key' => 'enabled', 'label' => 'Канал включён', 'type' => 'bool'],
            ['key' => 'url', 'label' => 'URL', 'type' => 'text', 'placeholder' => 'https://hooks.example.com/bas-ip'],
            ['key' => 'secret', 'label' => 'Секрет подписи', 'type' => 'secret'],
            ['key' => 'timeout', 'label' => 'Таймаут, с', 'type' => 'number'],
        ];
    }
}
