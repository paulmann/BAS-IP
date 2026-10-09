<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Logger;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Support\Arr;
use BasIp\Support\Paginator;

/**
 * Write-only audit trail: every meaningful action is recorded with actor,
 * subject, IP, request id and a redacted before/after diff.
 *
 * Records are also mirrored to `storage/logs/audit.log` so they survive even if
 * the database itself is the thing being tampered with.
 */
final class AuditService
{
    /** @var array<int, string> */
    private const REDACT = ['password', 'password_hash', 'totp_secret', 'recovery_codes', 'token', 'secret', 'password_enc', 'remember_token', 'pin'];

    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Logger $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $context keys: subject_type, subject_id, subject_label,
     *                                      before, after, meta, is_security, occurred_at
     */
    public function record(string $action, ?User $actor, array $context = [], ?Request $request = null): int
    {
        if (!$this->config->bool('security.audit.enabled', true)) {
            return 0;
        }

        $before = isset($context['before']) ? $this->diffable($context['before']) : null;
        $after  = isset($context['after']) ? $this->diffable($context['after']) : null;

        $changes = null;
        if ($before !== null || $after !== null) {
            $changes = (string) json_encode([
                'before' => $before,
                'after'  => $after,
                'diff'   => $this->diff(is_array($before) ? $before : [], is_array($after) ? $after : []),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $meta = null;
        if (isset($context['meta'])) {
            $meta = (string) json_encode($this->diffable($context['meta']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $row = [
            'occurred_at'   => (string) ($context['occurred_at'] ?? $this->clock->format()),
            'user_id'       => $actor?->id ?? (isset($context['user_id']) ? (int) $context['user_id'] : null),
            'actor_name'    => $actor?->displayName() ?? (string) ($context['actor_name'] ?? 'system'),
            'actor_role'    => $actor?->role ?? (string) ($context['actor_role'] ?? 'system'),
            'action'        => mb_substr($action, 0, 48),
            'subject_type'  => isset($context['subject_type']) ? mb_substr((string) $context['subject_type'], 0, 48) : null,
            'subject_id'    => isset($context['subject_id']) ? mb_substr((string) $context['subject_id'], 0, 64) : null,
            'subject_label' => isset($context['subject_label']) ? mb_substr((string) $context['subject_label'], 0, 190) : null,
            'ip'            => $request?->ip($this->config) ?? (isset($context['ip']) ? (string) $context['ip'] : null),
            'user_agent'    => $request !== null ? $request->userAgent() : (string) ($context['user_agent'] ?? 'cli'),
            'request_id'    => $request?->requestId() ?? (isset($context['request_id']) ? (string) $context['request_id'] : null),
            'changes'       => $changes,
            'meta'          => $meta,
            'is_security'   => !empty($context['is_security']) ? 1 : 0,
        ];

        try {
            $id = $this->db->table('audit_log')->insertGetId($row);
        } catch (\Throwable) {
            $id = 0;
        }

        $this->logger->channel('audit')->info($action, array_merge([
            'actor'   => $row['actor_name'],
            'subject' => $row['subject_type'] . ':' . $row['subject_id'],
            'ip'      => $row['ip'],
            'security' => (bool) $row['is_security'],
        ], is_array($context['meta'] ?? null) ? ['meta' => $context['meta']] : []));

        return $id;
    }

    /** Convenience wrappers used all over the controllers. */
    public function info(string $action, ?User $actor, ?string $subjectType = null, ?string $subjectId = null, ?string $label = null, ?Request $request = null, array $meta = []): int
    {
        return $this->record($action, $actor, array_merge([
            'subject_type' => $subjectType, 'subject_id' => $subjectId, 'subject_label' => $label, 'meta' => $meta,
        ], $request !== null ? [] : []), $request);
    }

    public function security(string $action, ?User $actor, array $context = [], ?Request $request = null): int
    {
        return $this->record($action, $actor, $context + ['is_security' => true], $request);
    }

    public function changed(string $action, ?User $actor, string $subjectType, int|string $subjectId, ?string $label, array $before, array $after, ?Request $request = null, array $meta = []): int
    {
        return $this->record($action, $actor, [
            'subject_type' => $subjectType, 'subject_id' => (string) $subjectId, 'subject_label' => $label,
            'before' => $before, 'after' => $after, 'meta' => $meta,
        ], $request);
    }

    /**
     * @param array<string, mixed> $filters action, subject_type, subject_id, user_id, from, to, q, security
     */
    public function query(array $filters = [], int $perPage = 50, int $page = 1): Paginator
    {
        $q = $this->db->table('audit_log');

        if (!empty($filters['action'])) {
            $q = $q->where('action', '=', (string) $filters['action']);
        }
        if (!empty($filters['subject_type'])) {
            $q = $q->where('subject_type', '=', (string) $filters['subject_type']);
        }
        if (!empty($filters['subject_id'])) {
            $q = $q->where('subject_id', '=', (string) $filters['subject_id']);
        }
        if (!empty($filters['user_id'])) {
            $q = $q->where('user_id', '=', (int) $filters['user_id']);
        }
        if (!empty($filters['from'])) {
            $q = $q->where('occurred_at', '>=', $this->normalizeDate((string) $filters['from'], ' 00:00:00'));
        }
        if (!empty($filters['to'])) {
            $q = $q->where('occurred_at', '<=', $this->normalizeDate((string) $filters['to'], ' 23:59:59'));
        }
        if (!empty($filters['security'])) {
            $q = $q->where('is_security', '=', 1);
        }
        if (!empty($filters['q'])) {
            $term = (string) $filters['q'];
            $q = $q->whereSearch(['action', 'actor_name', 'subject_label', 'subject_type', 'ip'], $term);
        }

        return $q->orderBy('occurred_at', 'desc')->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    /** @return array<int, array<string, mixed>> */
    public function forSubject(string $type, int|string $id, int $limit = 100): array
    {
        return $this->db->table('audit_log')
            ->where('subject_type', '=', $type)
            ->where('subject_id', '=', (string) $id)
            ->orderBy('occurred_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /** @return array<int, string> */
    public function distinctActions(): array
    {
        $rows = $this->db->table('audit_log')->select('action')->distinct()->orderBy('action')->get();

        return array_values(array_filter(array_column($rows, 'action')));
    }

    public function purge(int $keepDays): int
    {
        $keepDays = max(1, $keepDays);
        $cutoff = $this->clock->format('Y-m-d H:i:s', $this->clock->now() - $keepDays * 86400);

        return $this->db->table('audit_log')->where('occurred_at', '<', $cutoff)->delete();
    }

    /** @param mixed $value @return array<string, mixed>|null */
    private function diffable($value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            return ['value' => $value];
        }
        if (!$this->config->bool('security.audit.log_payload', false)) {
            // Keep only the keys that changed, without full payloads.
            return Arr::redact($value, self::REDACT);
        }

        return Arr::redact($value, self::REDACT);
    }

    /**
     * Minimal field-level diff for the UI.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $before, array $after): array
    {
        $diff = [];
        foreach ($after as $key => $value) {
            if (in_array($key, self::REDACT, true)) {
                continue;
            }
            $old = $before[$key] ?? null;
            if ($this->looseEquals($old, $value)) {
                continue;
            }
            $diff[(string) $key] = ['from' => $this->scalar($old), 'to' => $this->scalar($value)];
        }
        foreach ($before as $key => $value) {
            if (!array_key_exists($key, $after) && !in_array($key, self::REDACT, true) && $value !== null && $value !== '') {
                $diff[(string) $key] = ['from' => $this->scalar($value), 'to' => null];
            }
        }

        return $diff;
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            return json_encode($a) === json_encode($b);
        }
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_numeric($a) && is_numeric($b)) {
            return abs((float) $a - (float) $b) < 0.000001;
        }

        return (string) ($a ?? '') === (string) ($b ?? '');
    }

    private function scalar(mixed $value): mixed
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }
        if (is_bool($value)) {
            return $value ? 'да' : 'нет';
        }

        return $value;
    }

    private function normalizeDate(string $value, string $suffix): string
    {
        $ts = strtotime($value);

        return $ts === false ? $value : date('Y-m-d', $ts) . $suffix;
    }
}
