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
 * Telegram Bot API delivery.
 *
 * Recipient may be a `@username` (resolved via getUpdates cache), a numeric
 * chat id, or the special value `admins` (broadcast to `admin_chat_id`).
 */
final class TelegramChannel extends AbstractChannel
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

    public function name(): string  { return 'telegram'; }
    public function label(): string { return 'Telegram'; }
    public function icon(): string  { return 'send'; }

    public function enabled(): bool
    {
        return (bool) $this->option('enabled', false) && (string) ($this->secret('bot_token') ?? '') !== '';
    }

    public function supports(string $contactType): bool
    {
        return $contactType === 'telegram';
    }

    public function send(string $to, Message $message): array
    {
        $token = $this->secret('bot_token');
        if ($token === null || $token === '') {
            return ['ok' => false, 'error' => 'Не указан токен Telegram-бота.'];
        }

        $chatId = $this->resolveChatId($to, $token);
        if ($chatId === null) {
            return ['ok' => false, 'error' => 'Не удалось определить chat_id для «' . $to . '». Пользователь должен сначала написать боту /start.'];
        }

        $text = $message->getSubject() !== ''
            ? '<b>' . $this->escape($message->getSubject()) . "</b>\n\n" . $this->format($message->getBody())
            : $this->format($message->getBody());

        try {
            $response = $this->http->postJson(
                rtrim((string) $this->option('api', 'https://api.telegram.org'), '/') . '/bot' . $token . '/sendMessage',
                [
                    'chat_id' => $chatId,
                    'text' => mb_substr($text, 0, 4000),
                    'parse_mode' => (string) $this->option('parse_mode', 'HTML'),
                    'disable_web_page_preview' => (bool) $this->option('disable_preview', true),
                ],
                ['timeout' => (int) $this->option('timeout', 10), 'verify_tls' => true, 'retries' => 1]
            );

            $data = $response->json();
            if ($response->ok() && ($data['ok'] ?? false) === true) {
                return ['ok' => true, 'provider_id' => 'tg:' . (string) ($data['result']['message_id'] ?? '')];
            }

            $error = (string) ($data['description'] ?? $response->error ?? ('HTTP ' . $response->status));
            $this->log('warning', 'Telegram: сообщение не отправлено', ['to' => $to, 'error' => $error]);

            return ['ok' => false, 'error' => $error];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Telegram API: ' . $e->getMessage()];
        }
    }

    /**
     * `@username` → chat_id, resolved from the bot's recent updates and cached
     * in settings so we do not hammer getUpdates on every message.
     */
    public function resolveChatId(string $to, string $token): ?string
    {
        $to = trim($to);
        if ($to === '' ) {
            return null;
        }
        if (preg_match('/^-?\d{5,20}$/', $to)) {
            return $to;
        }
        if (strtolower($to) === 'admins') {
            $chat = $this->option('admin_chat_id', $this->settings->string('notify.telegram_chat_id', ''));

            return $chat !== '' ? (string) $chat : null;
        }
        if (!str_starts_with($to, '@')) {
            return null;
        }

        $cacheKey = 'telegram.chat.' . strtolower($to);
        $cached = $this->settings->get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http->get(
                rtrim((string) $this->option('api', 'https://api.telegram.org'), '/') . '/bot' . $token . '/getUpdates',
                ['limit' => 100, 'allowed_updates' => ['message']],
                ['timeout' => 10, 'retries' => 0]
            );
            $data = $response->json();
            foreach ((array) ($data['result'] ?? []) as $update) {
                $chat = $update['message']['chat'] ?? $update['channel_post']['chat'] ?? null;
                if (!is_array($chat)) {
                    continue;
                }
                $username = $chat['username'] ?? null;
                if (is_string($username) && '@' . strtolower($username) === strtolower($to)) {
                    $id = (string) $chat['id'];
                    $this->settings->set($cacheKey, $id);

                    return $id;
                }
            }
        } catch (Throwable $e) {
            $this->log('warning', 'Telegram getUpdates failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    public function test(string $to): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'message' => 'Канал Telegram отключён или не указан токен бота.'];
        }
        $token = (string) $this->secret('bot_token');
        try {
            $me = $this->http->get(rtrim((string) $this->option('api', 'https://api.telegram.org'), '/') . '/bot' . $token . '/getMe', [], ['timeout' => 10]);
            $data = $me->json();
            if (!$me->ok() || ($data['ok'] ?? false) !== true) {
                return ['ok' => false, 'message' => 'Токен не принят Telegram: ' . (string) ($data['description'] ?? ('HTTP ' . $me->status))];
            }
            $bot = $data['result']['username'] ?? '?';
            $result = parent::test($to);
            $result['message'] .= ' (бот: @' . $bot . ')';

            return $result;
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => 'Telegram API недоступен: ' . $e->getMessage()];
        }
    }

    public function configFields(): array
    {
        return [
            ['key' => 'enabled', 'label' => 'Канал включён', 'type' => 'bool'],
            ['key' => 'bot_token', 'label' => 'Токен бота', 'type' => 'secret', 'hint' => 'Получите у @BotFather'],
            ['key' => 'admin_chat_id', 'label' => 'Chat ID администратора', 'type' => 'text', 'hint' => 'Куда слать служебные уведомления'],
            ['key' => 'parse_mode', 'label' => 'Разметка', 'type' => 'select', 'options' => ['HTML' => 'HTML', 'MarkdownV2' => 'Markdown V2', '' => 'Без разметки']],
        ];
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape HTML specials but keep our own line breaks and links. */
    private function format(string $body): string
    {
        $escaped = $this->escape($body);
        $escaped = (string) preg_replace('~(https?://[^\s<]+)~i', '<a href="$1">$1</a>', $escaped);

        return $escaped;
    }
}
