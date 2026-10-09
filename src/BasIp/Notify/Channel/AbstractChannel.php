<?php

declare(strict_types=1);

namespace BasIp\Notify\Channel;

use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Logger;
use BasIp\Notify\Channel;
use BasIp\Notify\Message;
use BasIp\Services\SettingsService;

/**
 * Shared plumbing for channels: config + settings lookup, secret decryption,
 * logging and a default no-op self-test.
 */
abstract class AbstractChannel implements Channel
{
    public function __construct(
        protected readonly Config $config,
        protected readonly SettingsService $settings,
        protected readonly Logger $logger,
        protected readonly Crypto $crypto,
    ) {
    }

    public function icon(): string
    {
        return 'send';
    }

    /** Channel options from config, merged with runtime settings. */
    protected function options(): array
    {
        return (array) $this->config->get('notify.channels.' . $this->name(), []);
    }

    /** Read a channel option, preferring the runtime setting when present. */
    protected function option(string $key, mixed $default = null): mixed
    {
        $settingKey = 'notify.' . $this->name() . '.' . $key;
        if ($this->settings->has($settingKey)) {
            $value = $this->settings->get($settingKey);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $this->options()[$key] ?? $this->config->get('notify.channels.' . $this->name() . '.' . $key, $default);
    }

    /** Decrypt `${secret:…}`-style values transparently. */
    protected function secret(string $key, ?string $default = null): ?string
    {
        $value = $this->option($key, $default);
        if (!is_string($value) || $value === '') {
            return $default;
        }
        if ($this->crypto->isEncrypted($value)) {
            return $this->crypto->decrypt($value, 'channel:' . $this->name()) ?? $default;
        }

        return $value;
    }

    public function test(string $to): array
    {
        $result = $this->send($to, Message::make()
            ->subject('Тестовое сообщение BAS-IP')
            ->body('Это тестовое сообщение канала «' . $this->label() . '». Если вы его видите — канал настроен верно.'));

        return [
            'ok'      => (bool) $result['ok'],
            'message' => $result['ok'] ? 'Тестовое сообщение отправлено: ' . $to : (string) ($result['error'] ?? 'Неизвестная ошибка'),
        ];
    }

    public function configFields(): array
    {
        return [];
    }

    protected function log(string $level, string $message, array $context = []): void
    {
        $this->logger->channel('notify')->log($level, $message, $context + ['channel_name' => $this->name()]);
    }
}
