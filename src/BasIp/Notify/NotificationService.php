<?php

declare(strict_types=1);

namespace BasIp\Notify;

use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Logger;
use BasIp\Db\Database;
use BasIp\Domain\Model\Resident;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Services\AuditService;
use BasIp\Services\SettingsService;
use BasIp\Support\Paginator;
use Throwable;

/**
 * Notification dispatcher.
 *
 * Responsibilities:
 *   • resolve *which* channels and *which* addresses a message should go to,
 *     based on the resident's contacts and their per-contact `notify` flag;
 *   • enqueue into the `outbox` table (the queue) or deliver synchronously;
 *   • keep batch statistics for the «Рассылки» admin screen.
 *
 * Delivery itself is done by {@see QueueWorker} so that a 300-recipient
 * mailing never blocks a web request.
 */
final class NotificationService
{
    /** @var array<string, Channel> */
    private array $channels = [];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly SettingsService $settings,
        private readonly ResidentRepository $residents,
        private readonly Crypto $crypto,
        private readonly ?TemplateRenderer $templates = null,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * Render a named template (config `notify.templates.*`) with a context.
     *
     * @param array<string, mixed> $context
     * @return array{subject: string, body: string}
     */
    public function renderTemplate(string $name, array $context = []): array
    {
        if ($this->templates === null) {
            return ['subject' => $name, 'body' => ''];
        }

        return $this->templates->render($name, $context);
    }

    /** @param array<int, Channel> $channels */
    public function registerChannels(array $channels): void
    {
        foreach ($channels as $channel) {
            $this->channels[$channel->name()] = $channel;
        }
    }

    public function channel(string $name): ?Channel
    {
        return $this->channels[$name] ?? null;
    }

    /** @return array<string, Channel> */
    public function channels(): array
    {
        return $this->channels;
    }

    /** @return array<int, array<string, mixed>> */
    public function channelStatus(): array
    {
        $out = [];
        foreach ($this->channels as $channel) {
            $out[] = [
                'name'    => $channel->name(),
                'label'   => $channel->label(),
                'icon'    => $channel->icon(),
                'enabled' => $channel->enabled(),
                'fields'  => $channel->configFields(),
            ];
        }

        return $out;
    }

    /** Channels enabled by configuration/settings, in configured order. @return array<int, string> */
    public function defaultChannels(): array
    {
        $configured = $this->settings->json('notify.channels_default', []);
        if (!is_array($configured) || $configured === []) {
            $configured = $this->config->list('notify.default') ?: ['log'];
        }

        return array_values(array_filter(array_map('strval', $configured), fn (string $c): bool => isset($this->channels[$c])));
    }

    /* ─────────────────────────── resolution ─────────────────────────── */

    /**
     * Turn one logical message into concrete (channel, recipient) pairs.
     *
     * @return array<int, array{channel: string, to: string, label: ?string}>
     */
    public function resolve(Message $message, ?Resident $resident = null): array
    {
        $wanted = $message->getChannels() ?? $this->defaultChannels();
        if ($message->getChannel() !== null) {
            $wanted = [$message->getChannel()];
        }

        // Explicit recipient ("admins", a raw e-mail/phone) short-circuits contact lookup.
        $explicit = $message->getTo();
        if ($explicit !== null && $explicit !== '') {
            $out = [];
            foreach ($wanted as $name) {
                if (!isset($this->channels[$name]) || !$this->channels[$name]->enabled()) {
                    continue;
                }
                $out[] = ['channel' => $name, 'to' => $explicit, 'label' => 'явный получатель'];
            }
            if ($out === []) {
                $out[] = ['channel' => 'log', 'to' => $explicit, 'label' => 'явный получатель'];
            }

            return $out;
        }

        if ($resident === null && $message->getResidentId() !== null) {
            $resident = $this->residents->findById($message->getResidentId());
        }

        $out = [];
        if ($resident !== null) {
            if (!$resident->notifyEnabled) {
                return [];
            }
            $contacts = $resident->contacts !== []
                ? $resident->contacts
                : array_map(static fn (array $c): array => $c, $this->residents->contactsFor($resident->id));

            foreach ($wanted as $name) {
                $channel = $this->channels[$name] ?? null;
                if ($channel === null || !$channel->enabled()) {
                    continue;
                }
                $matched = false;
                foreach ($contacts as $contact) {
                    $type = (string) ($contact['type'] ?? '');
                    $value = trim((string) ($contact['value'] ?? ''));
                    if ($value === '' || !$channel->supports($type)) {
                        continue;
                    }
                    if (isset($contact['notify']) && !\BasIp\Domain\Model\Entity::toBool($contact['notify'])) {
                        continue;
                    }
                    $out[] = ['channel' => $name, 'to' => $value, 'label' => isset($contact['label']) ? (string) $contact['label'] : null];
                    $matched = true;
                    if ($this->config->bool('notify.one_per_channel', true)) {
                        break;      // do not spam every phone number a person has
                    }
                }
                if (!$matched) {
                    // Fall back to the denormalised primary columns.
                    $fallback = match ($name) {
                        'mail'     => $resident->email,
                        'sms'      => $resident->phone,
                        default    => null,
                    };
                    if ($fallback !== null && $fallback !== '') {
                        $out[] = ['channel' => $name, 'to' => $fallback, 'label' => 'основной'];
                    }
                }
            }
        }

        if ($out === []) {
            // Always leave a trace so the operator can see that nobody was reachable.
            $out[] = ['channel' => 'log', 'to' => 'no-recipient:' . ($resident?->id ?? 0), 'label' => 'нет каналов доставки'];
        }

        return $out;
    }

    /* ─────────────────────────── queueing ─────────────────────────── */

    /** Queue a single message. Returns the number of outbox rows created. */
    public function queue(Message $message, ?Resident $resident = null): int
    {
        $batch = $this->queueBatch([$message], [], $resident);

        return (int) $batch['queued'];
    }

    /**
     * Queue many messages as one batch (a "рассылка").
     *
     * @param array<int, Message> $messages
     * @param array<string, mixed> $meta name, template, charge_id, created_by, subject, message, pay_link, channels, audience
     * @return array{id: int, queued: int, skipped: int, recipients: int}
     */
    public function queueBatch(array $messages, array $meta = [], ?Resident $resident = null): array
    {
        $now = $this->clock->format();
        $channels = $meta['channels'] ?? $this->defaultChannels();

        $batchId = $this->db->table('notification_batches')->insertGetId([
            'uuid'       => Crypto::uuidv4(),
            'name'       => mb_substr((string) ($meta['name'] ?? 'Рассылка'), 0, 190),
            'template'   => mb_substr((string) ($meta['template'] ?? 'custom'), 0, 64),
            'subject'    => isset($meta['subject']) ? mb_substr((string) $meta['subject'], 0, 255) : null,
            'message'    => isset($meta['message']) ? (string) $meta['message'] : null,
            'pay_link'   => isset($meta['pay_link']) ? mb_substr((string) $meta['pay_link'], 0, 512) : null,
            'charge_id'  => $meta['charge_id'] ?? null,
            'channels'   => (string) json_encode(array_values((array) $channels), JSON_UNESCAPED_UNICODE),
            'audience'   => isset($meta['audience']) ? (string) json_encode($meta['audience'], JSON_UNESCAPED_UNICODE) : null,
            'recipients' => count($messages),
            'status'     => $messages === [] ? 'done' : 'queued',
            'created_by' => $meta['created_by'] ?? null,
            'created_at' => $now,
        ]);

        if ($messages === []) {
            return ['id' => $batchId, 'queued' => 0, 'skipped' => 0, 'recipients' => 0];
        }

        $rows = [];
        $queued = 0;
        $skipped = 0;
        $maxAttempts = $this->config->int('notify.queue.max_attempts', 5);
        $scheduledAt = isset($meta['scheduled_at']) && $meta['scheduled_at'] !== ''
            ? date('Y-m-d H:i:s', (int) strtotime((string) $meta['scheduled_at']))
            : $now;

        foreach ($messages as $message) {
            $targets = $this->resolve($message->batch($batchId), $resident);
            if ($targets === []) {
                $skipped++;
                continue;
            }
            foreach ($targets as $target) {
                $rows[] = [
                    'uuid'         => Crypto::uuidv4(),
                    'batch_id'     => $batchId,
                    'channel'      => $target['channel'],
                    'resident_id'  => $message->getResidentId(),
                    'recipient'    => mb_substr($target['to'], 0, 255),
                    'subject'      => mb_substr($message->getSubject(), 0, 255) ?: null,
                    'body'         => $message->getBody(),
                    'template'     => mb_substr($message->getTemplate(), 0, 64),
                    'context'      => $message->getContext() === [] ? null : (string) json_encode($message->getContext(), JSON_UNESCAPED_UNICODE),
                    'status'       => 'pending',
                    'max_attempts' => $maxAttempts,
                    'scheduled_at' => $scheduledAt,
                    'created_at'   => $now,
                ];
                $queued++;
            }
        }

        if ($rows !== []) {
            $this->db->table('outbox')->insertBatch($rows, 200);
        }
        $this->db->table('notification_batches')->where('id', '=', $batchId)->update([
            'sent' => 0, 'failed' => 0, 'skipped' => $skipped,
        ]);

        // Synchronous mode (queue disabled) — used by tests and tiny installs.
        if (!$this->config->bool('notify.queue.enabled', true)) {
            $worker = new QueueWorker($this->db, $this->config, $this->clock, $this->logger, $this);
            $worker->run(count($rows) + 1, 60);
        }

        return ['id' => $batchId, 'queued' => $queued, 'skipped' => $skipped, 'recipients' => count($messages)];
    }

    /** Deliver immediately, bypassing the queue. */
    public function sendNow(Message $message, ?Resident $resident = null): array
    {
        $targets = $this->resolve($message, $resident);
        $results = [];
        foreach ($targets as $target) {
            $channel = $this->channels[$target['channel']] ?? null;
            if ($channel === null) {
                $results[] = ['channel' => $target['channel'], 'ok' => false, 'error' => 'Канал не зарегистрирован'];
                continue;
            }
            try {
                $result = $channel->send($target['to'], $message);
            } catch (Throwable $e) {
                $result = ['ok' => false, 'error' => $e->getMessage()];
            }
            $results[] = ['channel' => $target['channel'], 'to' => $target['to']] + $result;
        }

        return $results;
    }

    /* ─────────────────────────── admin views ─────────────────────────── */

    /** @param array<string, mixed> $filters */
    public function batches(int $perPage = 25, int $page = 1, array $filters = []): Paginator
    {
        $q = $this->db->table('notification_batches');
        if (!empty($filters['status'])) {
            $q = $q->where('status', '=', (string) $filters['status']);
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['name', 'subject', 'template'], (string) $filters['q']);
        }

        return $q->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /** @return array<string, mixed>|null */
    public function batch(int $id): ?array
    {
        $row = $this->db->table('notification_batches')->where('id', '=', $id)->first();
        if ($row === null) {
            return null;
        }
        $stats = $this->db->table('outbox')
            ->select('status', 'COUNT(*) AS cnt')
            ->where('batch_id', '=', $id)
            ->groupBy('status')
            ->get();
        $byStatus = [];
        foreach ($stats as $stat) {
            $byStatus[(string) $stat['status']] = (int) $stat['cnt'];
        }
        $row['stats'] = $byStatus;
        $row['channels'] = (array) \BasIp\Domain\Model\Entity::decodeJson($row['channels'] ?? null, []);

        return $row;
    }

    /** @param array<string, mixed> $filters */
    public function outbox(array $filters = [], int $perPage = 50, int $page = 1): Paginator
    {
        $q = $this->db->table('outbox o')
            ->select('o.*', 'r.full_name AS resident_name', 'r.apartment')
            ->leftJoin('residents r', 'r.id', '=', 'o.resident_id');

        if (!empty($filters['batch_id'])) {
            $q = $q->where('o.batch_id', '=', (int) $filters['batch_id']);
        }
        if (!empty($filters['status'])) {
            $q = $q->where('o.status', '=', (string) $filters['status']);
        }
        if (!empty($filters['channel'])) {
            $q = $q->where('o.channel', '=', (string) $filters['channel']);
        }
        if (!empty($filters['resident_id'])) {
            $q = $q->where('o.resident_id', '=', (int) $filters['resident_id']);
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['o.recipient', 'o.subject', 'o.body', 'r.full_name', 'r.apartment'], (string) $filters['q']);
        }

        return $q->orderBy('o.id', 'desc')->paginate($perPage, $page);
    }

    /** @return array<string, int> */
    public function queueStats(): array
    {
        $rows = $this->db->table('outbox')->select('status', 'COUNT(*) AS cnt')->groupBy('status')->get();
        $out = ['pending' => 0, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $out[(string) $row['status']] = (int) $row['cnt'];
        }
        $out['total'] = array_sum($out);

        return $out;
    }

    public function retryFailed(int $batchId = 0): int
    {
        $q = $this->db->table('outbox')->where('status', '=', 'failed');
        if ($batchId > 0) {
            $q = $q->where('batch_id', '=', $batchId);
        }

        return $q->update([
            'status' => 'pending', 'attempts' => 0, 'last_error' => null,
            'scheduled_at' => $this->clock->format(),
        ]);
    }

    public function cancelBatch(int $batchId): int
    {
        $count = $this->db->table('outbox')->where('batch_id', '=', $batchId)->where('status', '=', 'pending')
            ->update(['status' => 'cancelled']);
        $this->db->table('notification_batches')->where('id', '=', $batchId)->update(['status' => 'cancelled']);

        return $count;
    }

    public function purgeSent(int $keepDays = 30): int
    {
        $cutoff = $this->clock->format('Y-m-d H:i:s', $this->clock->now() - $keepDays * 86400);

        return $this->db->table('outbox')->whereIn('status', ['sent', 'skipped', 'cancelled'])
            ->where('created_at', '<', $cutoff)->delete();
    }

    public function crypto(): Crypto
    {
        return $this->crypto;
    }

    public function logger(): Logger
    {
        return $this->logger;
    }

    public function audit(): ?AuditService
    {
        return $this->audit;
    }

    /** Refresh batch counters — called by the worker after each delivery. */
    public function refreshBatch(int $batchId): void
    {
        $rows = $this->db->table('outbox')->select('status', 'COUNT(*) AS cnt')->where('batch_id', '=', $batchId)->groupBy('status')->get();
        $counts = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'pending' => 0, 'processing' => 0, 'cancelled' => 0];
        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['cnt'];
        }
        $status = match (true) {
            $counts['pending'] + $counts['processing'] > 0 => 'running',
            $counts['failed'] > 0 && $counts['sent'] > 0   => 'partial',
            $counts['failed'] > 0 && $counts['sent'] === 0 => 'failed',
            default                                        => 'done',
        };
        $this->db->table('notification_batches')->where('id', '=', $batchId)->update([
            'sent' => $counts['sent'], 'failed' => $counts['failed'], 'skipped' => $counts['skipped'] + $counts['cancelled'],
            'status' => $status,
            'started_at' => $this->db->table('notification_batches')->where('id', '=', $batchId)->value('started_at') ?? $this->clock->format(),
            'finished_at' => in_array($status, ['done', 'partial', 'failed'], true) ? $this->clock->format() : null,
        ]);
    }
}
