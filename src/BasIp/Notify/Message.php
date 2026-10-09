<?php

declare(strict_types=1);

namespace BasIp\Notify;

use BasIp\Domain\Model\Resident;

/**
 * A single outgoing notification.
 *
 * Fluent and immutable-ish: `Message::forResident($r)->subject(...)->body(...)`.
 * If no explicit recipient is set the channel resolves one from the resident's
 * contacts (see {@see Resident::notifyTargets()}).
 */
final class Message
{
    private ?string $channel = null;
    private ?string $to = null;
    private string $subject = '';
    private string $body = '';
    private string $template = 'custom';
    /** @var array<string, mixed> */
    private array $context = [];
    private ?int $residentId = null;
    private ?string $residentName = null;
    private ?int $chargeId = null;
    private ?int $invoiceId = null;
    private ?int $batchId = null;
    /** @var array<int, string>|null */
    private ?array $channels = null;
    private int $priority = 5;
    private ?int $scheduledAt = null;
    /** @var array<string, mixed> */
    private array $options = [];

    public static function make(): self
    {
        return new self();
    }

    public static function forResident(Resident $resident): self
    {
        $message = new self();
        $message->residentId   = $resident->id;
        $message->residentName = $resident->displayName();
        $message->context = [
            'name'      => $resident->shortName(),
            'full_name' => $resident->displayName(),
            'apartment' => $resident->apartment,
        ];

        return $message;
    }

    public static function toAdmins(string $subject, string $body): self
    {
        $message = new self();

        return $message->subject($subject)->body($body)->to('admins')->priority(8);
    }

    public function channel(string $channel): self          { $clone = clone $this; $clone->channel = $channel; return $clone; }
    public function to(string $to): self                    { $clone = clone $this; $clone->to = $to; return $clone; }
    public function subject(string $subject): self          { $clone = clone $this; $clone->subject = $subject; return $clone; }
    public function body(string $body): self                { $clone = clone $this; $clone->body = $body; return $clone; }
    public function template(string $template): self        { $clone = clone $this; $clone->template = $template; return $clone; }
    public function charge(?int $id): self                  { $clone = clone $this; $clone->chargeId = $id; return $clone; }
    public function invoice(?int $id): self                 { $clone = clone $this; $clone->invoiceId = $id; return $clone; }
    public function batch(?int $id): self                   { $clone = clone $this; $clone->batchId = $id; return $clone; }
    public function priority(int $priority): self           { $clone = clone $this; $clone->priority = max(1, min(10, $priority)); return $clone; }
    public function scheduleAt(?int $timestamp): self       { $clone = clone $this; $clone->scheduledAt = $timestamp; return $clone; }

    /** @param array<string, mixed> $context */
    public function context(array $context): self
    {
        $clone = clone $this;
        $clone->context = array_merge($this->context, $context);

        return $clone;
    }

    /** @param array<int, string>|null $channels */
    public function channels(?array $channels): self
    {
        $clone = clone $this;
        $clone->channels = $channels;

        return $clone;
    }

    /** @param array<string, mixed> $options */
    public function options(array $options): self
    {
        $clone = clone $this;
        $clone->options = array_merge($this->options, $options);

        return $clone;
    }

    public function getChannel(): ?string          { return $this->channel; }
    public function getTo(): ?string               { return $this->to; }
    public function getSubject(): string           { return $this->subject; }
    public function getBody(): string              { return $this->body; }
    public function getTemplate(): string          { return $this->template; }
    /** @return array<string, mixed> */
    public function getContext(): array            { return $this->context; }
    public function getResidentId(): ?int          { return $this->residentId; }
    public function getResidentName(): ?string     { return $this->residentName; }
    public function getChargeId(): ?int            { return $this->chargeId; }
    public function getInvoiceId(): ?int           { return $this->invoiceId; }
    public function getBatchId(): ?int             { return $this->batchId; }
    public function getPriority(): int             { return $this->priority; }
    public function getScheduledAt(): ?int         { return $this->scheduledAt; }
    /** @return array<int, string>|null */
    public function getChannels(): ?array          { return $this->channels; }
    /** @return array<string, mixed> */
    public function getOptions(): array            { return $this->options; }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'channel'      => $this->channel,
            'to'           => $this->to,
            'subject'      => $this->subject,
            'body'         => $this->body,
            'template'     => $this->template,
            'context'      => $this->context,
            'resident_id'  => $this->residentId,
            'charge_id'    => $this->chargeId,
            'invoice_id'   => $this->invoiceId,
            'batch_id'     => $this->batchId,
            'priority'     => $this->priority,
            'scheduled_at' => $this->scheduledAt,
        ];
    }
}
