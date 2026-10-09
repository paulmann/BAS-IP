<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Support\Paginator;
use BasIp\Support\Str;

/**
 * Broadcast announcements shown in the resident cabinet and on the dashboard
 * («Завтра с 10:00 профилактика шлагбаума»).
 */
final class AnnouncementService
{
    public const LEVELS = ['info', 'success', 'warning', 'urgent'];
    public const AUDIENCES = ['all', 'active', 'debtors', 'admins'];

    public function __construct(
        private readonly Database $db,
        private readonly Clock $clock,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function visible(?User $user = null, bool $hasDebt = false, int $limit = 10): array
    {
        $now = $this->clock->format();
        $q = $this->db->table('announcements')
            ->where('is_published', '=', 1)
            ->where(static function ($qq) use ($now): void {
                $qq->whereNull('publish_at')->orWhere('publish_at', '<=', $now);
            })
            ->where(static function ($qq) use ($now): void {
                $qq->whereNull('expires_at')->orWhere('expires_at', '>=', $now);
            });

        if ($user === null || $user->isResident()) {
            $q = $q->whereIn('audience', ['all', 'active']);
            if ($hasDebt) {
                $q = $q->orWhere('audience', '=', 'debtors');
            }
        }

        return $q->orderBy('pin', 'desc')->orderBy('id', 'desc')->limit($limit)->get();
    }

    /** @param array<string, mixed> $filters */
    public function search(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        $q = $this->db->table('announcements');
        if (!empty($filters['level'])) {
            $q = $q->where('level', '=', (string) $filters['level']);
        }
        if (isset($filters['published']) && $filters['published'] !== '') {
            $q = $q->where('is_published', '=', Str::toBoolSafe($filters['published']) ? 1 : 0);
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['title', 'body'], (string) $filters['q']);
        }

        return $q->orderBy('id', 'desc')->paginate($perPage, $page);
    }

    public function find(int $id): ?array
    {
        return $this->db->table('announcements')->where('id', '=', $id)->first();
    }

    /** @param array<string, mixed> $input */
    public function save(array $input, ?int $id = null, ?User $actor = null, ?Request $request = null): int
    {
        $now = $this->clock->format();
        $row = [
            'title'   => mb_substr(Str::squash((string) ($input['title'] ?? '')), 0, 190),
            'body'    => trim((string) ($input['body'] ?? '')) ?: null,
            'level'   => in_array($input['level'] ?? 'info', self::LEVELS, true) ? $input['level'] : 'info',
            'audience' => in_array($input['audience'] ?? 'active', self::AUDIENCES, true) ? $input['audience'] : 'active',
            'is_published' => Str::toBoolSafe($input['is_published'] ?? true) ? 1 : 0,
            'pin'     => Str::toBoolSafe($input['pin'] ?? false) ? 1 : 0,
            'publish_at' => $this->datetime($input['publish_at'] ?? null),
            'expires_at' => $this->datetime($input['expires_at'] ?? null),
            'updated_at' => $now,
        ];

        if ($id !== null && $id > 0) {
            $this->db->table('announcements')->where('id', '=', $id)->update($row);
            $this->audit->info('announcement.update', $actor, 'announcement', (string) $id, $row['title'], $request);

            return $id;
        }

        $row['created_at'] = $now;
        $row['created_by'] = $actor?->id;
        $newId = $this->db->table('announcements')->insertGetId($row);
        $this->audit->info('announcement.create', $actor, 'announcement', (string) $newId, $row['title'], $request);

        return $newId;
    }

    public function delete(int $id, ?User $actor = null, ?Request $request = null): bool
    {
        $ok = $this->db->table('announcements')->where('id', '=', $id)->delete() > 0;
        if ($ok) {
            $this->audit->info('announcement.delete', $actor, 'announcement', (string) $id, null, $request);
        }

        return $ok;
    }

    public function markViewed(int $id): void
    {
        $this->db->table('announcements')->where('id', '=', $id)->increment('views');
    }

    private function datetime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);

        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
