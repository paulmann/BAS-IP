<?php

declare(strict_types=1);

namespace BasIp\Notify;

/**
 * A delivery driver.
 *
 * Implementations: log, mail (SMTP/mail()), telegram, sms (generic HTTP
 * gateway), webhook, whatsapp. Adding a channel means adding one class and one
 * entry in `config.notify.channels`.
 */
interface Channel
{
    /** Stable identifier used in config and in the admin UI. */
    public function name(): string;

    /** Human label + icon for the settings screen. */
    public function label(): string;

    public function icon(): string;

    /** Whether the channel is configured and switched on. */
    public function enabled(): bool;

    /**
     * Whether this channel can deliver to the given contact type.
     * `phone` → sms, `email` → mail, `telegram` → telegram …
     */
    public function supports(string $contactType): bool;

    /**
     * Deliver one message to one recipient.
     *
     * @return array{ok: bool, provider_id?: ?string, error?: ?string, raw?: mixed}
     */
    public function send(string $to, Message $message): array;

    /**
     * Self-test used by the «Проверить подключение» button in the admin UI.
     *
     * @return array{ok: bool, message: string}
     */
    public function test(string $to): array;

    /** Extra fields rendered on the settings page. @return array<int, array<string, mixed>> */
    public function configFields(): array;
}
