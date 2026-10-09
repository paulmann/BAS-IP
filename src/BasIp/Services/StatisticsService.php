<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Core\Cache;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Db\Database;
use BasIp\Domain\Repository\ResidentRepository;
use Throwable;

/**
 * Aggregations for the dashboard and the reports page.
 *
 * Everything is cached for a short window because dashboards are polled by
 * several browsers at once and the underlying GROUP BYs get expensive on a
 * building with a few years of gate history.
 */
final class StatisticsService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly Cache $cache,
        private readonly ResidentRepository $residents,
    ) {
    }

    /** @return array<string, mixed> */
    public function overview(int $ttl = 60): array
    {
        return (array) $this->cache->remember('stats.overview', $ttl, function (): array {
            $today = $this->clock->format('Y-m-d 00:00:00');
            $month = $this->clock->format('Y-m-01 00:00:00');

            $residents = [
                'total'      => $this->residents->countAll(),
                'active'     => $this->residents->countActive(),
                'inactive'   => $this->residents->countInactive(),
                'billable'   => $this->residents->countBillable(),
                'no_access'  => $this->residents->countWithoutAccess(),
                'deleted'    => $this->residents->countDeleted(),
                'cars'       => $this->db->table('cars')->count(),
                'active_cars' => $this->db->table('cars')->where('is_active', '=', 1)->count(),
            ];

            $gate = $this->safe(fn (): array => [
                'today'   => (int) $this->db->table('gate_events')->where('occurred_at', '>=', $today)->count(),
                'month'   => (int) $this->db->table('gate_events')->where('occurred_at', '>=', $month)->count(),
                'total'   => (int) $this->db->table('gate_events')->count(),
                'denied'  => (int) $this->db->table('gate_events')->where('result', '=', 'denied')->where('occurred_at', '>=', $month)->count(),
                'guest'   => (int) $this->db->table('gate_events')->where('access_type', '=', 'guest')->where('occurred_at', '>=', $month)->count(),
                'last'    => $this->db->table('gate_events')->orderBy('occurred_at', 'desc')->value('occurred_at'),
            ]);

            $billing = $this->safe(fn (): array => [
                'debt'        => $this->residents->totalDebt(),
                'overdue'     => $this->residents->totalOverdue(),
                'collected_month' => $this->residents->totalCollected($this->clock->format('Y-m-01'), $this->clock->format('Y-m-d')),
                'collected_total' => $this->residents->totalCollected(),
                'open_invoices' => (int) $this->db->table('invoices')->whereIn('status', ['pending', 'partial', 'overdue'])->count(),
                'paid_invoices' => (int) $this->db->table('invoices')->where('status', '=', 'paid')->count(),
                'debtors'     => count($this->safeArray(fn () => $this->debtorsBrief())),
            ]);

            $guest = $this->safe(fn (): array => [
                'active'  => (int) $this->db->table('guest_passes')->where('status', '=', 'active')->where('valid_until', '>', $this->clock->format())->count(),
                'today'   => (int) $this->db->table('guest_passes')->where('created_at', '>=', $today)->count(),
                'month'   => (int) $this->db->table('guest_passes')->where('created_at', '>=', $month)->count(),
                'revoked' => (int) $this->db->table('guest_passes')->where('status', '=', 'revoked')->where('created_at', '>=', $month)->count(),
            ]);

            $notify = $this->safe(fn (): array => [
                'pending' => (int) $this->db->table('outbox')->where('status', '=', 'pending')->count(),
                'failed'  => (int) $this->db->table('outbox')->where('status', '=', 'failed')->count(),
                'sent_month' => (int) $this->db->table('outbox')->where('status', '=', 'sent')->where('sent_at', '>=', $month)->count(),
            ]);

            return [
                'residents' => $residents,
                'gate'      => $gate,
                'billing'   => $billing,
                'guest'     => $guest,
                'notify'    => $notify,
                'generated_at' => $this->clock->format(),
            ];
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function gateByDay(int $days = 30): array
    {
        return $this->cache->remember('stats.gate.day.' . $days, 120, function () use ($days): array {
            $dayExpr = $this->db->isPgsql() ? "TO_CHAR(occurred_at, 'YYYY-MM-DD')" : "DATE(occurred_at)";
            $rows = $this->db->table('gate_events')
                ->select("$dayExpr AS day", 'COUNT(*) AS cnt', "COALESCE(SUM(CASE WHEN result = 'success' THEN 1 ELSE 0 END), 0) AS ok")
                ->where('occurred_at', '>=', $this->clock->format('Y-m-d 00:00:00', $this->clock->now() - $days * 86400))
                ->groupBy('day')
                ->orderBy('day')
                ->get();

            $byDay = [];
            foreach ($rows as $row) {
                $byDay[(string) $row['day']] = ['count' => (int) $row['cnt'], 'ok' => (int) $row['ok']];
            }

            $out = [];
            for ($i = $days - 1; $i >= 0; $i--) {
                $date = $this->clock->format('Y-m-d', $this->clock->now() - $i * 86400);
                $out[] = [
                    'date'  => $date,
                    'label' => date('d.m', (int) strtotime($date)),
                    'count' => $byDay[$date]['count'] ?? 0,
                    'ok'    => $byDay[$date]['ok'] ?? 0,
                ];
            }

            return $out;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function gateByHour(int $days = 14): array
    {
        return $this->cache->remember('stats.gate.hour.' . $days, 300, function () use ($days): array {
            $hourExpr = match (true) {
                $this->db->isPgsql()  => "TO_CHAR(occurred_at, 'HH24')",
                $this->db->isSqlite() => "strftime('%H', occurred_at)",
                default               => "DATE_FORMAT(occurred_at, '%H')",
            };
            $rows = $this->db->table('gate_events')
                ->select("$hourExpr AS hour", 'COUNT(*) AS cnt')
                ->where('occurred_at', '>=', $this->clock->format('Y-m-d 00:00:00', $this->clock->now() - $days * 86400))
                ->groupBy('hour')
                ->orderBy('hour')
                ->get();

            $byHour = [];
            foreach ($rows as $row) {
                $byHour[(int) $row['hour']] = (int) $row['cnt'];
            }
            $out = [];
            for ($h = 0; $h < 24; $h++) {
                $out[] = ['hour' => $h, 'label' => sprintf('%02d:00', $h), 'count' => $byHour[$h] ?? 0];
            }

            return $out;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function paymentsByMonth(int $months = 12): array
    {
        return $this->cache->remember('stats.payments.month.' . $months, 300, function () use ($months): array {
            $monthExpr = match (true) {
                $this->db->isPgsql()  => "TO_CHAR(paid_at, 'YYYY-MM')",
                $this->db->isSqlite() => "strftime('%Y-%m', paid_at)",
                default               => "DATE_FORMAT(paid_at, '%Y-%m')",
            };
            $from = $this->clock->format('Y-m-01', strtotime('-' . ($months - 1) . ' months'));
            $rows = $this->db->table('payments')
                ->select("$monthExpr AS month", 'COALESCE(SUM(amount), 0) AS total', 'COUNT(*) AS cnt')
                ->where('paid_at', '>=', $from)
                ->groupBy('month')
                ->orderBy('month')
                ->get();

            $byMonth = [];
            foreach ($rows as $row) {
                $byMonth[(string) $row['month']] = ['total' => round((float) $row['total'], 2), 'count' => (int) $row['cnt']];
            }

            $out = [];
            for ($i = $months - 1; $i >= 0; $i--) {
                $ts = strtotime('-' . $i . ' months');
                $key = date('Y-m', (int) $ts);
                $out[] = [
                    'month' => $key,
                    'label' => $this->monthLabel((int) $ts),
                    'total' => $byMonth[$key]['total'] ?? 0.0,
                    'count' => $byMonth[$key]['count'] ?? 0,
                ];
            }

            return $out;
        });
    }

    /** @return array<int, array<string, mixed>> */
    public function collectionRate(int $months = 12): array
    {
        $payments = $this->paymentsByMonth($months);
        $from = $this->clock->format('Y-m-01', strtotime('-' . ($months - 1) . ' months'));

        $monthExpr = match (true) {
            $this->db->isPgsql()  => "TO_CHAR(issued_date, 'YYYY-MM')",
            $this->db->isSqlite() => "strftime('%Y-%m', issued_date)",
            default               => "DATE_FORMAT(issued_date, '%Y-%m')",
        };

        $rows = $this->db->table('invoices')
            ->select("$monthExpr AS month", "COALESCE(SUM(CASE WHEN status <> 'void' THEN amount ELSE 0 END), 0) AS billed", "COALESCE(SUM(CASE WHEN status <> 'void' THEN paid_amount ELSE 0 END), 0) AS paid")
            ->where('issued_date', '>=', $from)
            ->groupBy('month')
            ->get();

        $byMonth = [];
        foreach ($rows as $row) {
            $billed = (float) $row['billed'];
            $paid   = (float) $row['paid'];
            $byMonth[(string) $row['month']] = [
                'billed' => round($billed, 2),
                'paid'   => round($paid, 2),
                'rate'   => $billed > 0 ? round(min(100, ($paid / $billed) * 100), 1) : 0.0,
            ];
        }

        return array_map(static function (array $p) use ($byMonth): array {
            $data = $byMonth[$p['month']] ?? ['billed' => 0.0, 'paid' => 0.0, 'rate' => 0.0];

            return $p + $data;
        }, $payments);
    }

    /** @return array<int, array<string, mixed>> */
    public function debtorsBrief(int $limit = 10): array
    {
        $today = $this->clock->format('Y-m-d');

        return $this->db->table('invoices i')
            ->select('i.resident_id', 'r.full_name', 'r.apartment', 'r.is_active', 'COALESCE(SUM(i.balance), 0) AS debt', 'MIN(i.due_date) AS oldest')
            ->innerJoin('residents r', 'r.id', '=', 'i.resident_id')
            ->whereIn('i.status', ['pending', 'partial', 'overdue'])
            ->where('r.is_active', '=', 1)
            ->whereNull('r.deleted_at')
            ->groupBy('i.resident_id', 'r.full_name', 'r.apartment', 'r.is_active')
            ->having('COALESCE(SUM(i.balance), 0) > 0.005')
            ->orderByRaw('COALESCE(SUM(i.balance), 0) DESC')
            ->limit($limit)
            ->get();
    }

    /** @return array<int, array<string, mixed>> */
    public function topResidentsByOpens(int $days = 30, int $limit = 10): array
    {
        return $this->db->table('gate_events e')
            ->select('e.resident_id', 'r.full_name', 'r.apartment', 'COUNT(*) AS cnt')
            ->innerJoin('residents r', 'r.id', '=', 'e.resident_id')
            ->where('e.occurred_at', '>=', $this->clock->format('Y-m-d 00:00:00', $this->clock->now() - $days * 86400))
            ->where('e.result', '=', 'success')
            ->groupBy('e.resident_id', 'r.full_name', 'r.apartment')
            ->orderBy('cnt', 'desc')
            ->limit($limit)
            ->get();
    }

    public function flush(): void
    {
        $this->cache->flush('stats');
    }

    private function monthLabel(int $ts): string
    {
        $months = [1 => 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

        return ($months[(int) date('n', $ts)] ?? date('M', $ts)) . ' ' . date('y', $ts);
    }

    /** @template T @param callable(): T $fn @return T|array<string, mixed> */
    private function safe(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<int, mixed> */
    private function safeArray(callable $fn): array
    {
        try {
            $result = $fn();

            return is_array($result) ? $result : [];
        } catch (Throwable) {
            return [];
        }
    }
}
