<?php

declare(strict_types=1);

namespace BasIp\Notify\Channel;

use BasIp\Notify\Message;

/**
 * Writes the message to `storage/logs/notify.log` instead of sending it.
 *
 * Always available and the default channel — so a fresh installation can be
 * tried end-to-end without an SMTP server or a Telegram bot.
 */
final class LogChannel extends AbstractChannel
{
    public function name(): string  { return 'log'; }
    public function label(): string { return 'Журнал (отладка)'; }
    public function icon(): string  { return 'file-text'; }

    public function enabled(): bool
    {
        return true;
    }

    public function supports(string $contactType): bool
    {
        return true;
    }

    public function send(string $to, Message $message): array
    {
        $this->log((string) $this->option('level', 'info'), 'Уведомление (лог)', [
            'to'       => $to,
            'subject'  => $message->getSubject(),
            'body'     => $message->getBody(),
            'template' => $message->getTemplate(),
            'resident' => $message->getResidentId(),
        ]);

        return ['ok' => true, 'provider_id' => 'log:' . substr(hash('sha256', $to . $message->getBody()), 0, 12)];
    }

    public function test(string $to): array
    {
        return ['ok' => true, 'message' => 'Записано в storage/logs/notify.log'];
    }
}
