<?php

declare(strict_types=1);

namespace BasIp\Notify;

use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Logger;
use BasIp\Db\Database;
use Throwable;

/**
 * Outbox worker — `bin/bas-ip queue:work` (daemon) or `queue:run` (cron, once).
 *
 * Picks the oldest due rows, marks them `processing` (with a lease so two
 * workers cannot grab the same row), delivers through the right channel and
 * applies exponential back-off on failure.
 */
final class QueueWorker
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
        private readonly NotificationService $notify,
    ) {
    }

    /**
     * Process up to `$limit` messages, stopping after `$maxSeconds`.
     *
     * @return array{processed: int, sent: int, failed: int, skipped: int}
     */
    public function run(int $limit = 100, int $maxSeconds = 50): array
    {
        $started = microtime(true);
        $stats = ['processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $throttleMs = max(0, $this->config->int('notify.queue.throttle_ms', 150));

        while ($stats['processed'] < $limit && (microtime(true) - $started) < $maxSeconds) {
            $row = $this->claim();
            if ($row === null) {
                break;
            }

            $stats['processed']++;
            $result = $this->deliver($row);

            if ($result['ok']) {
                $stats['sent']++;
                $this->markSent((int) $row['id'], $result['provider_id'] ?? null);
            } else {
                $attempts = (int) $row['attempts'] + 1;
                if ($attempts >= (int) $row['max_attempts']) {
                    $stats['failed']++;
                    $this->markFailed((int) $row['id'], (string) ($result['error'] ?? 'unknown'), $attempts, true);
                } else {
                    $this->markFailed((int) $row['id'], (string) ($result['error'] ?? 'unknown'), $attempts, false);
                }
            }

            if ((int) ($row['batch_id'] ?? 0) > 0) {
                $this->notify->refreshBatch((int) $row['batch_id']);
            }

            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
        }

        if ($stats['processed'] > 0) {
            $this->logger->channel('notify')->info('Очередь обработана', $stats);
        }

        return $stats;
    }

    /** Run forever (systemd service). Stops on SIGTERM/SIGINT. */
    public function loop(int $sleepSeconds = 5, ?callable $shouldStop = null): void
    {
        $stop = false;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $handler = static function () use (&$stop): void { $stop = true; };
            pcntl_signal(SIGTERM, $handler);
            pcntl_signal(SIGINT, $handler);
        }

        $batchSize = max(1, $this->config->int('notify.queue.batch_size', 50));
        $this->logger->channel('notify')->info('Queue worker started', ['pid' => getmypid(), 'batch' => $batchSize]);

        while (!$stop && ($shouldStop === null || !$shouldStop())) {
            $stats = $this->run($batchSize, 50);
            if ($stats['processed'] === 0) {
                $this->releaseStale();
                for ($i = 0; $i < $sleepSeconds && !$stop; $i++) {
                    sleep(1);
                }
            }
        }

        $this->logger->channel('notify')->info('Queue worker stopped');
    }

    /**
     * Grab the next due row and lease it.
     *
     * The row is flipped to `processing` inside a transaction so two workers
     * (cron + systemd) cannot deliver the same message twice.
     *
     * @return array<string, mixed>|null
     */
    private function claim(): ?array
    {
        $now = $this->clock->format();

        return $this->db->transaction(function () use ($now): ?array {
            $row = $this->db->table('outbox')
                ->where('status', '=', 'pending')
                ->where(static function ($q) use ($now): void {
                    $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', $now);
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return null;
            }
            $this->db->table('outbox')->where('id', '=', (int) $row['id'])->update(['status' => 'processing']);

            return $row;
        });
    }

    /** @param array<string, mixed> $row @return array{ok: bool, provider_id?: ?string, error?: ?string} */
    private function deliver(array $row): array
    {
        $channel = $this->notify->channel((string) $row['channel']);
        if ($channel === null) {
            return ['ok' => false, 'error' => 'Канал «' . $row['channel'] . '» не зарегистрирован.'];
        }
        if (!$channel->enabled()) {
            return ['ok' => false, 'error' => 'Канал «' . $channel->label() . '» отключён в настройках.'];
        }

        $context = (array) \BasIp\Domain\Model\Entity::decodeJson($row['context'] ?? null, []);
        $message = Message::make()
            ->subject((string) ($row['subject'] ?? ''))
            ->body((string) ($row['body'] ?? ''))
            ->template((string) ($row['template'] ?? 'custom'))
            ->context($context);

        try {
            return $channel->send((string) $row['recipient'], $message);
        } catch (Throwable $e) {
            $this->logger->channel('notify')->error('Delivery exception', [
                'channel' => $row['channel'], 'to' => $row['recipient'], 'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    private function markSent(int $id, ?string $providerId): void
    {
        $this->db->table('outbox')->where('id', '=', $id)->update([
            'status' => 'sent', 'sent_at' => $this->clock->format(),
            'provider_id' => $providerId, 'last_error' => null,
        ]);
    }

    private function markFailed(int $id, string $error, int $attempts, bool $final): void
    {
        $backoff = (array) $this->config->get('notify.queue.retry_backoff', [60, 300, 900, 3600, 10800]);
        $delay = (int) ($backoff[min($attempts - 1, count($backoff) - 1)] ?? 300);

        $this->db->table('outbox')->where('id', '=', $id)->update([
            'status'       => $final ? 'failed' : 'pending',
            'attempts'     => $attempts,
            'last_error'   => mb_substr($error, 0, 2000),
            'scheduled_at' => $final ? null : $this->clock->format('Y-m-d H:i:s', $this->clock->now() + $delay),
        ]);

        $this->logger->channel('notify')->warning($final ? 'Delivery permanently failed' : 'Delivery failed, will retry', [
            'id' => $id, 'attempts' => $attempts, 'error' => mb_substr($error, 0, 200), 'retry_in' => $final ? null : $delay,
        ]);
    }

    /** Rows stuck in `processing` (worker killed) go back to pending. */
    public function releaseStale(int $olderThanSeconds = 600): int
    {
        $cutoff = $this->clock->format('Y-m-d H:i:s', $this->clock->now() - $olderThanSeconds);

        return $this->db->table('outbox')
            ->where('status', '=', 'processing')
            ->where('updated_at', '<', $cutoff)
            ->update(['status' => 'pending']);
    }

    public function pendingCount(): int
    {
        return $this->db->table('outbox')->where('status', '=', 'pending')->count();
    }
}
