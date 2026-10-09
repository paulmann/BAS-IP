<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Domain\Model\Charge;
use BasIp\Domain\Model\Invoice;
use BasIp\Domain\Model\Payment;
use BasIp\Domain\Model\Resident;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Notify\Message;
use BasIp\Notify\NotificationService;
use BasIp\Notify\TemplateRenderer;
use BasIp\Support\Money;
use BasIp\Support\Paginator;
use BasIp\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The money engine.
 *
 *  • A **charge** stores the total maintenance cost for a period and a snapshot
 *    of how many *active* residents it was split across.
 *  • **Issuing** creates one invoice per billable resident.
 *  • **Recalculating** never rewrites an issued invoice — it creates
 *    *adjustment* invoices (positive = additional bill, negative = credit note)
 *    and picks up residents who joined the building in the meantime.
 *  • **Payments** are allocated FIFO to the oldest unpaid invoice unless the
 *    operator names one explicitly; any surplus stays on the resident balance.
 *
 * Every step lands in `billing_history` and the audit log.
 */
final class BillingService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly ResidentRepository $residents,
        private readonly AuditService $audit,
        private readonly SettingsService $settings,
        private readonly NotificationService $notify,
        private readonly TemplateRenderer $templates,
    ) {
    }

    /* ═══════════════════════════ preview / split ═══════════════════════════ */

    /**
     * Live preview for the charge wizard: total ÷ number of active residents,
     * with the exact per-resident split (remainders distributed in minor units
     * so the parts always add up to the total).
     *
     * @return array{total: float, participants: int, per_unit: float, allocation: string,
     *               rows: array<int, array<string, mixed>>, sum: float,
     *               rounding_delta: float, weight_total: float, period_label: string|null}
     */
    public function preview(float $total, string $allocation = 'equal', ?string $periodLabel = null): array
    {
        $allocation = in_array($allocation, Charge::ALLOCATIONS, true) ? $allocation : 'equal';
        $entries = $this->residents->billableForCharge($allocation);

        $weights = array_map(static fn (array $e): float => (float) $e['weight'], $entries);
        $weightTotal = array_sum($weights);
        if ($weightTotal <= 0.0) {
            $weights = array_fill(0, count($entries), 1.0);
            $weightTotal = (float) count($entries);
        }

        $totalMinor = Money::parse($total);
        $shares = $allocation === 'equal'
            ? Money::split($totalMinor, count($entries))
            : Money::splitWeighted($totalMinor, $weights);

        $rows = [];
        $sum  = 0.0;
        foreach ($entries as $i => $entry) {
            /** @var Resident $resident */
            $resident = $entry['resident'];
            $amount = Money::toFloat((int) ($shares[$i] ?? 0));
            $sum += $amount;
            $rows[] = [
                'id'        => $resident->id,
                'name'      => $resident->displayName(),
                'apartment' => $resident->apartment,
                'building'  => $resident->building,
                'weight'    => (float) $entry['weight'],
                'share_pct' => $weightTotal > 0 ? round(((float) $entry['weight'] / $weightTotal) * 100, 2) : 0.0,
                'amount'    => $amount,
                'phone'     => $resident->phone,
                'email'     => $resident->email,
                'balance'   => $resident->balance,
            ];
        }

        return [
            'total'          => round($total, 2),
            'participants'   => count($entries),
            'per_unit'       => count($entries) > 0
                ? Money::round($total / count($entries), $this->config->string('billing.rounding', 'half_up'))
                : 0.0,
            'allocation'     => $allocation,
            'rows'           => $rows,
            'sum'            => round($sum, 2),
            'rounding_delta' => round($total - $sum, 2),
            'weight_total'   => $weightTotal,
            'period_label'   => $periodLabel,
        ];
    }

    /** How many residents take part in the next split. */
    public function activeParticipants(): int
    {
        return $this->residents->countBillable();
    }

    /* ═══════════════════════════ charges ═══════════════════════════ */

    /**
     * Create a charge and (unless `issue` is false) generate its invoices.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, number?: string, invoices?: int, per_unit?: float, error?: string}
     */
    public function createCharge(array $input, ?User $actor = null, ?Request $request = null): array
    {
        $total = Money::round((float) ($input['total_amount'] ?? 0), $this->config->string('billing.rounding', 'half_up'));
        if ($total <= 0) {
            return ['ok' => false, 'error' => 'Общая сумма должна быть больше нуля.'];
        }
        $allocation = (string) ($input['allocation'] ?? $this->settings->string('billing.last_allocation', 'equal'));
        if (!in_array($allocation, Charge::ALLOCATIONS, true)) {
            $allocation = 'equal';
        }

        $preview = $this->preview($total, $allocation);
        if ($preview['participants'] === 0) {
            return ['ok' => false, 'error' => 'Нет ни одного активного жителя, участвующего в расчёте. Добавьте жителей или включите существующих.'];
        }

        $periodStart = $this->dateOrNull($input['period_start'] ?? null);
        $periodEnd   = $this->dateOrNull($input['period_end'] ?? null);
        if ($periodStart === null) {
            [$periodStart, $periodEnd] = $this->guessPeriod((string) ($input['period_label'] ?? ''));
        }
        $periodLabel = Str::squash((string) ($input['period_label'] ?? '')) ?: $this->periodLabel($periodStart, $periodEnd);
        $dueDays = max(0, (int) ($input['due_days'] ?? $this->config->int('billing.due_days', 14)));
        $dueDate = $this->dateOrNull($input['due_date'] ?? null)
            ?? $this->clock->format('Y-m-d', $this->clock->now() + $dueDays * 86400);
        $now    = $this->clock->format();
        $number = (string) ($input['number'] ?? $this->nextChargeNumber($periodStart));
        $issue  = (bool) ($input['issue'] ?? true);

        try {
            $chargeId = $this->db->transaction(function () use ($input, $total, $allocation, $preview, $periodStart, $periodEnd, $periodLabel, $dueDays, $dueDate, $now, $number, $actor, $issue): int {
                $id = $this->db->table('charges')->insertGetId([
                    'uuid'            => Crypto::uuidv4(),
                    'number'          => $number,
                    'title'           => mb_substr(Str::squash((string) ($input['title'] ?? 'Обслуживание шлагбаума')) . ', ' . $periodLabel, 0, 190),
                    'description'     => isset($input['description']) ? trim((string) $input['description']) : null,
                    'period_start'    => $periodStart,
                    'period_end'      => $periodEnd,
                    'period_label'    => mb_substr($periodLabel, 0, 64),
                    'total_amount'    => $total,
                    'active_count'    => $preview['participants'],
                    'per_unit_amount' => $preview['per_unit'],
                    'allocation'      => $allocation,
                    'due_days'        => $dueDays,
                    'due_date'        => $dueDate,
                    'message'         => isset($input['message']) ? trim((string) $input['message']) : null,
                    'pay_link'        => $this->normalizePayLink($input['pay_link'] ?? null),
                    'status'          => Charge::STATUS_DRAFT,
                    'recalc_of'       => $input['recalc_of'] ?? null,
                    'recalc_reason'   => isset($input['recalc_reason']) ? mb_substr((string) $input['recalc_reason'], 0, 255) : null,
                    'notes'           => isset($input['notes']) ? trim((string) $input['notes']) : null,
                    'created_by'      => $actor?->id,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ]);

                if ($issue) {
                    $this->generateInvoices($id, $preview, $dueDate, $periodStart, $periodEnd, $periodLabel, $actor);
                    $this->db->table('charges')->where('id', '=', $id)->update([
                        'status' => Charge::STATUS_ISSUED, 'issued_at' => $now,
                    ]);
                }

                $this->settings->set('billing.last_total', $total);
                $this->settings->set('billing.last_period', $periodLabel);
                $this->settings->set('billing.last_allocation', $allocation);

                return $id;
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Не удалось создать начисление: ' . $e->getMessage()];
        }

        $this->audit->record('billing.charge.create', $actor, [
            'subject_type' => 'charge', 'subject_id' => (string) $chargeId, 'subject_label' => $number,
            'meta' => ['total' => $total, 'participants' => $preview['participants'], 'per_unit' => $preview['per_unit'], 'allocation' => $allocation, 'period' => $periodLabel],
        ], $request);

        return [
            'ok' => true, 'id' => $chargeId, 'number' => $number,
            'invoices' => $issue ? $preview['participants'] : 0,
            'per_unit' => $preview['per_unit'],
        ];
    }

    /** @param array<string, mixed> $preview */
    private function generateInvoices(int $chargeId, array $preview, string $dueDate, ?string $periodStart, ?string $periodEnd, string $periodLabel, ?User $actor): int
    {
        $now     = $this->clock->format();
        $payLink = $this->chargePayLink($chargeId);
        $rows    = [];
        $sum     = 0.0;

        foreach ($preview['rows'] as $row) {
            $amount = round((float) $row['amount'], 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $sum += $amount;
            $rows[] = [
                'uuid'          => Crypto::uuidv4(),
                'number'        => $this->nextInvoiceNumber($periodLabel, 'regular'),
                'charge_id'     => $chargeId,
                'resident_id'   => (int) $row['id'],
                'kind'          => 'regular',
                'amount'        => $amount,
                'paid_amount'   => 0.0,
                'balance'       => $amount,
                'period_start'  => $periodStart,
                'period_end'    => $periodEnd,
                'period_label'  => mb_substr($periodLabel, 0, 64),
                'due_date'      => $dueDate,
                'issued_date'   => $this->clock->format('Y-m-d'),
                'status'        => Invoice::STATUS_PENDING,
                'pay_link'      => $this->expandPayLink($payLink, $row, $amount, $periodLabel),
                'issued_at'     => $now,
                'created_by'    => $actor?->id,
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if ($rows !== []) {
            $this->db->table('invoices')->insertBatch($rows, 100);
        }

        $this->db->table('charges')->where('id', '=', $chargeId)->update([
            'invoices_total'  => count($rows),
            'invoices_amount' => round($sum, 2),
            'updated_at'      => $now,
        ]);

        foreach ($rows as $row) {
            $this->history((int) $row['resident_id'], 'invoice', 0, 'issued', (float) $row['amount'], $actor, [
                'charge_id' => $chargeId, 'number' => $row['number'], 'period' => $periodLabel,
            ]);
        }

        return count($rows);
    }

    /* ═══════════════════════════ recalculation ═══════════════════════════ */

    /**
     * Recalculate an issued charge.
     *
     * Two things can change — the total sum and the number of participants.
     * Already-issued invoices are never rewritten:
     *   • each existing invoice gets an **adjustment** for the delta
     *     (positive → additional bill, negative → credit note);
     *   • residents who became active after issuing get a regular invoice;
     *   • residents who are no longer active get a full credit note.
     *
     * @param array<string, mixed> $input total_amount?, allocation?, include_new?,
     *                                    exclude_inactive?, reason?, due_date?
     * @return array<string, mixed>
     */
    public function recalculate(int $chargeId, array $input, ?User $actor = null, ?Request $request = null, bool $dryRun = false): array
    {
        $charge = $this->charge($chargeId);
        if ($charge === null) {
            return ['ok' => false, 'error' => 'Начисление не найдено.'];
        }
        if ($charge->isCancelled()) {
            return ['ok' => false, 'error' => 'Начисление отменено — перерасчёт невозможен.'];
        }

        $newTotal = isset($input['total_amount']) && $input['total_amount'] !== '' && $input['total_amount'] !== null
            ? Money::round((float) $input['total_amount'], $this->config->string('billing.rounding', 'half_up'))
            : $charge->totalAmount;
        if ($newTotal < 0) {
            return ['ok' => false, 'error' => 'Сумма не может быть отрицательной.'];
        }
        $allocation = (string) ($input['allocation'] ?? $charge->allocation);
        if (!in_array($allocation, Charge::ALLOCATIONS, true)) {
            $allocation = $charge->allocation;
        }

        $includeNew      = (bool) ($input['include_new'] ?? true);
        $excludeInactive = (bool) ($input['exclude_inactive'] ?? true);

        $preview   = $this->preview($newTotal, $allocation);
        $newShares = [];
        foreach ($preview['rows'] as $row) {
            $newShares[(int) $row['id']] = (float) $row['amount'];
        }

        // What has already been billed inside this charge (invoice + adjustments).
        $billed = [];
        foreach ($this->db->table('invoices')->where('charge_id', '=', $chargeId)->get() as $invoice) {
            if (($invoice['status'] ?? '') === Invoice::STATUS_VOID) {
                continue;
            }
            $residentId = (int) $invoice['resident_id'];
            $billed[$residentId] = round(($billed[$residentId] ?? 0.0) + (float) $invoice['amount'], 2);
        }

        $minDelta    = $this->config->float('billing.min_invoice', 0.01);
        $adjustments = [];
        $newOnes     = [];
        $credits     = [];

        foreach ($billed as $residentId => $alreadyBilled) {
            $target = $newShares[$residentId] ?? null;
            if ($target === null) {
                if ($excludeInactive && abs($alreadyBilled) >= $minDelta) {
                    $credits[$residentId] = -round($alreadyBilled, 2);
                }
                continue;
            }
            $delta = round($target - $alreadyBilled, 2);
            if (abs($delta) >= $minDelta) {
                $adjustments[$residentId] = $delta;
            }
        }

        if ($includeNew) {
            foreach ($newShares as $residentId => $amount) {
                if (!isset($billed[$residentId]) && abs($amount) >= $minDelta) {
                    $newOnes[$residentId] = round($amount, 2);
                }
            }
        }

        $reason = Str::squash((string) ($input['reason'] ?? ''))
            ?: $this->describeRecalcReason($charge, $newTotal, $allocation, count($newShares));

        $summary = [
            'ok'               => true,
            'dry_run'          => $dryRun,
            'charge_id'        => $chargeId,
            'old_total'        => $charge->totalAmount,
            'total'            => $newTotal,
            'old_per_unit'     => $charge->perUnitAmount,
            'per_unit'         => $preview['per_unit'],
            'old_participants' => $charge->activeCount,
            'participants'     => $preview['participants'],
            'adjustments'      => count($adjustments),
            'new_invoices'     => count($newOnes),
            'credits'          => count($credits),
            'adjustment_sum'   => round(array_sum($adjustments), 2),
            'new_sum'          => round(array_sum($newOnes), 2),
            'credit_sum'       => round(array_sum($credits), 2),
            'delta'            => round($newTotal - $charge->totalAmount, 2),
            'reason'           => $reason,
            'details'          => $this->recalcDetails($adjustments, $newOnes, $credits),
        ];

        if ($dryRun) {
            $summary['preview'] = $preview;

            return $summary;
        }
        if ($adjustments === [] && $newOnes === [] && $credits === []) {
            $summary['unchanged'] = true;

            return $summary;
        }

        try {
            $this->db->transaction(function () use ($charge, $chargeId, $newTotal, $allocation, $preview, $adjustments, $newOnes, $credits, $reason, $actor, $input): void {
                $now = $this->clock->format();
                $dueDate = $this->dateOrNull($input['due_date'] ?? null)
                    ?? $charge->dueDate
                    ?? $this->clock->format('Y-m-d', $this->clock->now() + max(1, $charge->dueDays) * 86400);
                $payLink = $this->chargePayLink($chargeId);

                foreach ([['adjustment', $adjustments], ['credit', $credits], ['regular', $newOnes]] as [$kind, $map]) {
                    foreach ($map as $residentId => $amount) {
                        $residentId = (int) $residentId;
                        $parent   = $kind === 'regular' ? null : $this->primaryInvoiceFor($chargeId, $residentId);
                        $resident = $this->residents->findById($residentId);
                        $number   = $this->nextInvoiceNumber($charge->periodLabel, $kind);

                        $invoiceId = $this->db->table('invoices')->insertGetId([
                            'uuid'              => Crypto::uuidv4(),
                            'number'            => $number,
                            'charge_id'         => $chargeId,
                            'resident_id'       => $residentId,
                            'parent_invoice_id' => $parent,
                            'kind'              => $kind,
                            'amount'            => round((float) $amount, 2),
                            'paid_amount'       => 0.0,
                            'balance'           => round((float) $amount, 2),
                            'period_start'      => $charge->periodStart,
                            'period_end'        => $charge->periodEnd,
                            'period_label'      => $charge->periodLabel,
                            'due_date'          => $dueDate,
                            'issued_date'       => $this->clock->format('Y-m-d'),
                            'status'            => Invoice::STATUS_PENDING,
                            'pay_link'          => $this->expandPayLink($payLink, [
                                'id' => $residentId,
                                'apartment' => $resident?->apartment ?? '',
                                'name' => $resident?->displayName() ?? '',
                            ], (float) $amount, $charge->periodLabel),
                            'note'            => ($kind === 'credit' ? 'Сторно: ' : 'Перерасчёт: ') . $reason,
                            'recalc_reason' => mb_substr($reason, 0, 255),
                            'issued_at'       => $now,
                            'created_by'      => $actor?->id,
                            'created_at'      => $now,
                            'updated_at'      => $now,
                        ]);

                        $this->history($residentId, 'invoice', $invoiceId, $kind === 'credit' ? 'credited' : 'adjusted', (float) $amount, $actor, [
                            'charge_id' => $chargeId, 'number' => $number, 'reason' => $reason,
                        ]);
                    }
                }

                $stats = $this->chargeStats($chargeId);
                $this->db->table('charges')->where('id', '=', $chargeId)->update([
                    'total_amount'    => $newTotal,
                    'per_unit_amount' => $preview['per_unit'],
                    'active_count'    => $preview['participants'],
                    'allocation'      => $allocation,
                    'due_date'        => $dueDate,
                    'recalc_reason'   => mb_substr($reason, 0, 255),
                    'invoices_total'  => $stats['count'],
                    'invoices_amount' => $stats['amount'],
                    'paid_amount'     => $stats['paid'],
                    'paid_count'      => $stats['paid_count'],
                    'updated_at'      => $now,
                ]);
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Перерасчёт не выполнен: ' . $e->getMessage()];
        }

        foreach (array_keys($adjustments + $credits + $newOnes) as $residentId) {
            $this->recalculateBalance((int) $residentId);
        }

        $this->audit->record('billing.charge.recalc', $actor, [
            'subject_type' => 'charge', 'subject_id' => (string) $chargeId, 'subject_label' => $charge->number,
            'before' => ['total' => $charge->totalAmount, 'participants' => $charge->activeCount, 'per_unit' => $charge->perUnitAmount],
            'after'  => ['total' => $newTotal, 'participants' => $preview['participants'], 'per_unit' => $preview['per_unit']],
            'meta'   => ['reason' => $reason, 'adjustments' => count($adjustments), 'new' => count($newOnes), 'credits' => count($credits)],
        ], $request);

        return $summary;
    }

    /**
     * @param array<int, float> $adjustments
     * @param array<int, float> $newOnes
     * @param array<int, float> $credits
     * @return array<int, array<string, mixed>>
     */
    private function recalcDetails(array $adjustments, array $newOnes, array $credits): array
    {
        $ids = array_values(array_unique(array_map('intval', array_merge(
            array_keys($adjustments), array_keys($newOnes), array_keys($credits)
        ))));
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->table('residents')->select('id', 'full_name', 'apartment', 'is_active')->whereIn('id', $ids)->get();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }

        $out = [];
        foreach ($ids as $id) {
            $row = $byId[$id] ?? null;
            $out[] = [
                'id'        => $id,
                'name'      => (string) ($row['full_name'] ?? '#' . $id),
                'apartment' => (string) ($row['apartment'] ?? ''),
                'active'    => (bool) ($row['is_active'] ?? false),
                'type'      => isset($credits[$id]) ? 'credit' : (isset($newOnes[$id]) ? 'new' : 'adjustment'),
                'amount'    => round((float) ($credits[$id] ?? $newOnes[$id] ?? $adjustments[$id] ?? 0), 2),
            ];
        }

        return $out;
    }

    private function describeRecalcReason(Charge $charge, float $newTotal, string $allocation, int $currentCount): string
    {
        $parts = [];
        if (abs($newTotal - $charge->totalAmount) > 0.004) {
            $parts[] = sprintf('сумма %s → %s', Money::format($charge->totalAmount), Money::format($newTotal));
        }
        if ($currentCount !== $charge->activeCount) {
            $parts[] = sprintf('участников %d → %d', $charge->activeCount, $currentCount);
        }
        if ($allocation !== $charge->allocation) {
            $parts[] = 'изменён способ распределения';
        }

        return $parts === [] ? 'Перерасчёт' : 'Перерасчёт: ' . implode(', ', $parts);
    }

    private function primaryInvoiceFor(int $chargeId, int $residentId): ?int
    {
        $id = $this->db->table('invoices')
            ->where('charge_id', '=', $chargeId)
            ->where('resident_id', '=', $residentId)
            ->where('kind', '=', 'regular')
            ->orderBy('id')
            ->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return array{count: int, amount: float, paid: float, paid_count: int} */
    public function chargeStats(int $chargeId): array
    {
        $row = $this->db->table('invoices')
            ->select(
                'COUNT(*) AS cnt',
                "COALESCE(SUM(CASE WHEN status <> 'void' THEN amount ELSE 0 END), 0) AS amount",
                "COALESCE(SUM(CASE WHEN status <> 'void' THEN paid_amount ELSE 0 END), 0) AS paid",
                "COALESCE(SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END), 0) AS paid_count"
            )
            ->where('charge_id', '=', $chargeId)
            ->first();

        return [
            'count'      => (int) ($row['cnt'] ?? 0),
            'amount'     => round((float) ($row['amount'] ?? 0), 2),
            'paid'       => round((float) ($row['paid'] ?? 0), 2),
            'paid_count' => (int) ($row['paid_count'] ?? 0),
        ];
    }

    /* ═══════════════════════════ charge lifecycle ═══════════════════════════ */

    public function charge(int $id): ?Charge
    {
        $row = $this->db->table('charges')->where('id', '=', $id)->first();

        return $row === null ? null : Charge::fromRow($row);
    }

    /** @param array<string, mixed> $filters */
    public function charges(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        $q = $this->db->table('charges');
        if (!empty($filters['status'])) {
            $q = $q->where('status', '=', (string) $filters['status']);
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['number', 'title', 'period_label'], (string) $filters['q']);
        }
        if (!empty($filters['from'])) {
            $q = $q->where('period_start', '>=', (string) $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q = $q->where('period_end', '<=', (string) $filters['to']);
        }
        if (!empty($filters['only_recalc'])) {
            $q = $q->whereNotNull('recalc_of');
        }

        $paginator = $q->orderBy('id', 'desc')->paginate($perPage, $page);

        return new Paginator(
            array_map(static fn (array $row): Charge => Charge::fromRow($row), $paginator->items()),
            $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->pageName()
        );
    }

    /** @return array<int, Charge> */
    public function allCharges(int $limit = 200): array
    {
        return array_map(
            static fn (array $r): Charge => Charge::fromRow($r),
            $this->db->table('charges')->orderBy('id', 'desc')->limit($limit)->get()
        );
    }

    public function closeCharge(int $chargeId, ?User $actor = null, ?Request $request = null): bool
    {
        $charge = $this->charge($chargeId);
        if ($charge === null || $charge->isClosed()) {
            return false;
        }
        $stats = $this->chargeStats($chargeId);
        $this->db->table('charges')->where('id', '=', $chargeId)->update([
            'status' => Charge::STATUS_CLOSED, 'closed_at' => $this->clock->format(),
            'invoices_total' => $stats['count'], 'invoices_amount' => $stats['amount'],
            'paid_amount' => $stats['paid'], 'paid_count' => $stats['paid_count'],
            'updated_at' => $this->clock->format(),
        ]);
        $this->audit->info('billing.charge.close', $actor, 'charge', (string) $chargeId, $charge->number, $request);

        return true;
    }

    public function cancelCharge(int $chargeId, string $reason = '', ?User $actor = null, ?Request $request = null): bool
    {
        $charge = $this->charge($chargeId);
        if ($charge === null) {
            return false;
        }

        $this->db->transaction(function () use ($chargeId, $reason, $charge): void {
            $now = $this->clock->format();
            $this->db->table('invoices')->where('charge_id', '=', $chargeId)->update([
                'status' => Invoice::STATUS_VOID, 'balance' => 0.0,
                'voided_at' => $now, 'note' => trim('Аннулировано: ' . $reason), 'updated_at' => $now,
            ]);
            $this->db->table('charges')->where('id', '=', $chargeId)->update([
                'status' => Charge::STATUS_CANCELLED, 'invoices_amount' => 0.0,
                'notes' => trim((string) ($charge->notes ?? '') . "\nОтменено: " . $reason),
                'updated_at' => $now,
            ]);
            foreach ($this->db->table('invoices')->where('charge_id', '=', $chargeId)->pluck('resident_id') as $residentId) {
                $this->recalculateBalance((int) $residentId);
            }
        });

        $this->audit->info('billing.charge.cancel', $actor, 'charge', (string) $chargeId, $charge->number, $request, ['reason' => $reason]);

        return true;
    }

    /** @param array<string, mixed> $input */
    public function updateCharge(int $chargeId, array $input, ?User $actor = null, ?Request $request = null): bool
    {
        $charge = $this->charge($chargeId);
        if ($charge === null) {
            return false;
        }
        $now    = $this->clock->format();
        $update = ['updated_at' => $now];

        foreach (['title', 'description', 'message', 'notes', 'recalc_reason'] as $field) {
            if (array_key_exists($field, $input)) {
                $update[$field] = $input[$field] === ''
                    ? null
                    : mb_substr(trim((string) $input[$field]), 0, $field === 'title' ? 190 : 20000);
            }
        }
        if (array_key_exists('pay_link', $input)) {
            $update['pay_link'] = $this->normalizePayLink($input['pay_link']);
        }
        if (array_key_exists('due_days', $input)) {
            $update['due_days'] = max(0, (int) $input['due_days']);
        }
        if (array_key_exists('due_date', $input) && ($date = $this->dateOrNull($input['due_date'])) !== null) {
            $update['due_date'] = $date;
            $this->db->table('invoices')->where('charge_id', '=', $chargeId)
                ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE])
                ->update(['due_date' => $date, 'updated_at' => $now]);
        }

        $this->db->table('charges')->where('id', '=', $chargeId)->update($update);
        $this->audit->changed('billing.charge.update', $actor, 'charge', $chargeId, $charge->number, $charge->toArray(), $update, $request);

        return true;
    }

    /* ═══════════════════════════ invoices ═══════════════════════════ */

    public function invoice(int $id): ?Invoice
    {
        $row = $this->db->table('invoices')->where('id', '=', $id)->first();

        return $row === null ? null : Invoice::fromRow($row);
    }

    /** @param array<string, mixed> $filters @return array<int, Invoice> */
    public function invoicesForCharge(int $chargeId, array $filters = []): array
    {
        $q = $this->db->table('invoices i')
            ->select('i.*', 'r.full_name AS resident_name', 'r.apartment', 'r.is_active', 'c.number AS charge_number')
            ->leftJoin('residents r', 'r.id', '=', 'i.resident_id')
            ->leftJoin('charges c', 'c.id', '=', 'i.charge_id')
            ->where('i.charge_id', '=', $chargeId);

        if (!empty($filters['status'])) {
            $q = (string) $filters['status'] === 'unpaid'
                ? $q->whereIn('i.status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE])
                : $q->where('i.status', '=', (string) $filters['status']);
        }
        if (!empty($filters['only_active'])) {
            $q = $q->where('r.is_active', '=', 1);
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['r.full_name', 'r.apartment', 'i.number'], (string) $filters['q']);
        }

        $dir = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $orderColumn = match ((string) ($filters['sort'] ?? 'apartment')) {
            'name'   => 'r.full_name',
            'amount' => 'i.amount',
            'status' => 'i.status',
            'due'    => 'i.due_date',
            default  => 'r.apartment_no',
        };

        return array_map(
            static fn (array $row): Invoice => Invoice::fromRow($row),
            $q->orderBy($orderColumn, $dir)->orderBy('i.id')->get()
        );
    }

    /** @param array<string, mixed> $filters */
    public function invoices(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        $q = $this->db->table('invoices i')
            ->select('i.*', 'r.full_name AS resident_name', 'r.apartment', 'r.is_active', 'c.number AS charge_number')
            ->leftJoin('residents r', 'r.id', '=', 'i.resident_id')
            ->leftJoin('charges c', 'c.id', '=', 'i.charge_id');

        if (!empty($filters['resident_id'])) {
            $q = $q->where('i.resident_id', '=', (int) $filters['resident_id']);
        }
        if (!empty($filters['charge_id'])) {
            $q = $q->where('i.charge_id', '=', (int) $filters['charge_id']);
        }
        if (!empty($filters['status'])) {
            $q = (string) $filters['status'] === 'unpaid'
                ? $q->whereIn('i.status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE])
                : $q->where('i.status', '=', (string) $filters['status']);
        }
        if (!empty($filters['kind'])) {
            $q = $q->where('i.kind', '=', (string) $filters['kind']);
        }
        if (!empty($filters['only_active'])) {
            $q = $q->where('r.is_active', '=', 1)->whereNull('r.deleted_at');
        }
        if (!empty($filters['overdue'])) {
            $q = $q->where('i.due_date', '<', $this->clock->format('Y-m-d'))
                ->whereIn('i.status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE]);
        }
        if (!empty($filters['from'])) {
            $q = $q->where('i.issued_date', '>=', (string) $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q = $q->where('i.issued_date', '<=', (string) $filters['to']);
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['i.number', 'r.full_name', 'r.apartment', 'i.period_label', 'c.number'], (string) $filters['q']);
        }

        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $orderColumn = match ((string) ($filters['sort'] ?? 'due')) {
            'amount'    => 'i.amount',
            'name'      => 'r.full_name',
            'apartment' => 'r.apartment_no',
            'status'    => 'i.status',
            'issued'    => 'i.issued_date',
            default     => 'i.due_date',
        };

        $paginator = $q->orderBy($orderColumn, $dir)->orderBy('i.id', 'desc')->paginate($perPage, $page);

        return new Paginator(
            array_map(static fn (array $r): Invoice => Invoice::fromRow($r), $paginator->items()),
            $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->pageName()
        );
    }

    /** @return array{count: int, amount: float, paid: float, outstanding: float, overdue: float, overdue_count: int} */
    public function invoiceTotals(bool $onlyActive = true): array
    {
        $base = 'FROM ' . $this->db->quoteIdentifier($this->db->prefixTable('invoices')) . ' i';
        if ($onlyActive) {
            $base .= ' INNER JOIN ' . $this->db->quoteIdentifier($this->db->prefixTable('residents'))
                . ' r ON r.id = i.resident_id AND r.is_active = 1 AND r.deleted_at IS NULL';
        }
        $today = $this->clock->format('Y-m-d');

        $row = $this->db->selectOne(
            "SELECT COALESCE(SUM(CASE WHEN i.status <> 'void' THEN 1 ELSE 0 END), 0) AS cnt,
                    COALESCE(SUM(CASE WHEN i.status <> 'void' THEN i.amount ELSE 0 END), 0) AS amount,
                    COALESCE(SUM(CASE WHEN i.status <> 'void' THEN i.paid_amount ELSE 0 END), 0) AS paid,
                    COALESCE(SUM(CASE WHEN i.status IN ('pending','partial','overdue') THEN i.balance ELSE 0 END), 0) AS outstanding,
                    COALESCE(SUM(CASE WHEN i.status IN ('pending','partial','overdue') AND i.due_date < ? THEN i.balance ELSE 0 END), 0) AS overdue,
                    COALESCE(SUM(CASE WHEN i.status IN ('pending','partial','overdue') AND i.due_date < ? THEN 1 ELSE 0 END), 0) AS overdue_cnt
             $base",
            [$today, $today]
        );

        return [
            'count'         => (int) ($row['cnt'] ?? 0),
            'amount'        => round((float) ($row['amount'] ?? 0), 2),
            'paid'          => round((float) ($row['paid'] ?? 0), 2),
            'outstanding'   => round((float) ($row['outstanding'] ?? 0), 2),
            'overdue'       => round((float) ($row['overdue'] ?? 0), 2),
            'overdue_count' => (int) ($row['overdue_cnt'] ?? 0),
        ];
    }

    public function voidInvoice(int $invoiceId, string $reason = '', ?User $actor = null, ?Request $request = null): bool
    {
        $invoice = $this->invoice($invoiceId);
        if ($invoice === null || $invoice->isVoid()) {
            return false;
        }
        if ($invoice->paidAmount > 0.004) {
            throw new RuntimeException('Нельзя аннулировать частично оплаченный счёт — сначала удалите оплаты.');
        }

        $this->db->transaction(function () use ($invoiceId, $reason, $actor, $invoice): void {
            $this->db->table('invoices')->where('id', '=', $invoiceId)->update([
                'status' => Invoice::STATUS_VOID, 'balance' => 0.0,
                'voided_at' => $this->clock->format(),
                'note' => trim(($invoice->note ?? '') . ' Аннулировано: ' . $reason),
                'updated_at' => $this->clock->format(),
            ]);
            $this->recalculateBalance($invoice->residentId);
            if ($invoice->chargeId !== null) {
                $stats = $this->chargeStats($invoice->chargeId);
                $this->db->table('charges')->where('id', '=', $invoice->chargeId)->update([
                    'invoices_total' => $stats['count'], 'invoices_amount' => $stats['amount'],
                    'paid_amount' => $stats['paid'], 'paid_count' => $stats['paid_count'],
                    'updated_at' => $this->clock->format(),
                ]);
            }
            $this->history($invoice->residentId, 'invoice', $invoiceId, 'voided', -$invoice->amount, $actor, ['reason' => $reason]);
        });

        $this->audit->info('billing.invoice.void', $actor, 'invoice', (string) $invoiceId, $invoice->number, $request, ['reason' => $reason]);

        return true;
    }

    /** @param array<string, mixed> $input */
    public function createManualInvoice(array $input, ?User $actor = null, ?Request $request = null): array
    {
        $residentId = (int) ($input['resident_id'] ?? 0);
        $amount = Money::round((float) ($input['amount'] ?? 0));
        if ($residentId <= 0 || $this->residents->findById($residentId) === null) {
            return ['ok' => false, 'error' => 'Житель не найден.'];
        }
        if (abs($amount) < 0.01) {
            return ['ok' => false, 'error' => 'Сумма не должна быть равна нулю.'];
        }

        $now = $this->clock->format();
        $kind = $amount < 0 ? 'credit' : 'manual';
        $dueDate = $this->dateOrNull($input['due_date'] ?? null)
            ?? $this->clock->format('Y-m-d', $this->clock->now() + $this->config->int('billing.due_days', 14) * 86400);
        $periodLabel = mb_substr((string) ($input['period_label'] ?? ''), 0, 64);

        $id = $this->db->table('invoices')->insertGetId([
            'uuid' => Crypto::uuidv4(),
            'number' => $this->nextInvoiceNumber($periodLabel, $kind),
            'charge_id' => null,
            'resident_id' => $residentId,
            'kind' => $kind,
            'amount' => $amount,
            'paid_amount' => 0.0,
            'balance' => $amount,
            'period_label' => $periodLabel,
            'due_date' => $dueDate,
            'issued_date' => $this->clock->format('Y-m-d'),
            'status' => Invoice::STATUS_PENDING,
            'pay_link' => $this->normalizePayLink($input['pay_link'] ?? null),
            'note' => isset($input['note']) ? mb_substr(trim((string) $input['note']), 0, 2000) : null,
            'issued_at' => $now,
            'created_by' => $actor?->id,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->recalculateBalance($residentId);
        $this->history($residentId, 'invoice', $id, 'issued', $amount, $actor, ['manual' => true]);
        $this->audit->info('billing.invoice.manual', $actor, 'invoice', (string) $id, null, $request, [
            'resident_id' => $residentId, 'amount' => $amount,
        ]);

        return ['ok' => true, 'id' => $id];
    }

    /* ═══════════════════════════ payments ═══════════════════════════ */

    /**
     * Record a payment and allocate it.
     *
     * Allocation order: the invoice named in `invoice_id`, otherwise the oldest
     * unpaid invoice (FIFO); any surplus stays on the resident balance.
     *
     * @param array<string, mixed> $input
     * @return array{ok: bool, id?: int, allocated?: array<int, array<string, mixed>>, overpay?: float, balance?: float, error?: string}
     */
    public function recordPayment(array $input, ?User $actor = null, ?Request $request = null): array
    {
        $residentId = (int) ($input['resident_id'] ?? 0);
        $amount = Money::round((float) ($input['amount'] ?? 0));
        $resident = $this->residents->findById($residentId);

        if ($resident === null) {
            return ['ok' => false, 'error' => 'Житель не найден.'];
        }
        if (abs($amount) < 0.005) {
            return ['ok' => false, 'error' => 'Сумма оплаты не может быть нулевой.'];
        }
        if ($amount < 0 && !$this->config->bool('billing.allow_negative_payments', false)) {
            return ['ok' => false, 'error' => 'Отрицательная оплата запрещена — используйте сторно-счёт.'];
        }

        $paidAt = $this->dateOrNull($input['paid_at'] ?? null) ?? $this->clock->format('Y-m-d');
        $now = $this->clock->format();
        $allocated = [];

        try {
            $paymentId = $this->db->transaction(function () use ($input, $residentId, $amount, $paidAt, $now, $actor, &$allocated): int {
                $invoiceId = isset($input['invoice_id']) && $input['invoice_id'] !== '' && $input['invoice_id'] !== null
                    ? (int) $input['invoice_id'] : null;

                $id = $this->db->table('payments')->insertGetId([
                    'uuid'         => Crypto::uuidv4(),
                    'resident_id'  => $residentId,
                    'invoice_id'   => $invoiceId,
                    'charge_id'    => $invoiceId !== null
                        ? ($this->db->table('invoices')->where('id', '=', $invoiceId)->value('charge_id') ?: null)
                        : (isset($input['charge_id']) && $input['charge_id'] !== '' ? (int) $input['charge_id'] : null),
                    'amount'       => $amount,
                    'allocated_amount' => 0.0,
                    'paid_at'      => $paidAt,
                    'method'       => $this->normalizeMethod($input['method'] ?? 'transfer'),
                    'reference'    => !empty($input['reference']) ? mb_substr(Str::squash((string) $input['reference']), 0, 120) : null,
                    'payer_name'   => !empty($input['payer_name']) ? mb_substr(Str::squash((string) $input['payer_name']), 0, 190) : null,
                    'note'         => isset($input['note']) ? mb_substr(trim((string) $input['note']), 0, 2000) : null,
                    'external_id'  => !empty($input['external_id']) ? mb_substr((string) $input['external_id'], 0, 96) : null,
                    'is_confirmed' => Str::toBoolSafe($input['is_confirmed'] ?? true) ? 1 : 0,
                    'recorded_by'  => $actor?->id,
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]);

                $targets = $invoiceId !== null
                    ? array_filter([$this->db->table('invoices')->where('id', '=', $invoiceId)->first()])
                    : $this->db->table('invoices')
                        ->where('resident_id', '=', $residentId)
                        ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE])
                        ->where('balance', '>', 0)
                        ->orderBy('due_date')->orderBy('id')
                        ->get();

                $remaining = $amount;
                foreach ($targets as $invoice) {
                    if ($remaining <= 0.004) {
                        break;
                    }
                    $balance = (float) $invoice['balance'];
                    if ($balance <= 0.0) {
                        continue;
                    }
                    $apply = min($remaining, $balance);
                    $newPaid = round((float) $invoice['paid_amount'] + $apply, 2);
                    $newBalance = round((float) $invoice['amount'] - $newPaid, 2);
                    $status = $newBalance <= 0.004 ? Invoice::STATUS_PAID : Invoice::STATUS_PARTIAL;

                    $this->db->table('invoices')->where('id', '=', (int) $invoice['id'])->update([
                        'paid_amount' => $newPaid,
                        'balance'     => $newBalance,
                        'status'      => $status,
                        'paid_at'     => $status === Invoice::STATUS_PAID ? $paidAt : ($invoice['paid_at'] ?? null),
                        'updated_at'  => $now,
                    ]);

                    $remaining = round($remaining - $apply, 2);
                    $allocated[] = [
                        'invoice_id' => (int) $invoice['id'],
                        'number'     => (string) $invoice['number'],
                        'applied'    => $apply,
                        'status'     => $status,
                    ];
                }

                $applied = round(array_sum(array_column($allocated, 'applied')), 2);
                $update = ['allocated_amount' => $applied];
                if ($invoiceId === null && $allocated !== []) {
                    // Keep a reference to the primary invoice for reporting;
                    // `allocated_amount` remains the source of truth for the balance.
                    $update['invoice_id'] = $allocated[0]['invoice_id'];
                    $update['charge_id'] = $this->db->table('invoices')->where('id', '=', $allocated[0]['invoice_id'])->value('charge_id');
                }
                $this->db->table('payments')->where('id', '=', $id)->update($update);

                return $id;
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Оплата не проведена: ' . $e->getMessage()];
        }

        $balance = $this->recalculateBalance($residentId);
        $this->refreshChargeAggregatesForResident($residentId);
        $this->db->table('residents')->where('id', '=', $residentId)->update(['last_payment_at' => $paidAt]);

        $overpay = round($amount - array_sum(array_column($allocated, 'applied')), 2);
        $this->history($residentId, 'payment', $paymentId, 'paid', $amount, $actor, [
            'allocated' => $allocated, 'overpay' => $overpay, 'method' => (string) ($input['method'] ?? 'transfer'),
        ]);

        $this->audit->record('billing.payment.record', $actor, [
            'subject_type' => 'payment', 'subject_id' => (string) $paymentId,
            'subject_label' => sprintf('%s · %s', $resident->shortName(), Money::format($amount)),
            'meta' => ['resident_id' => $residentId, 'amount' => $amount, 'allocated' => $allocated, 'overpay' => $overpay],
        ], $request);

        return ['ok' => true, 'id' => $paymentId, 'allocated' => $allocated, 'overpay' => $overpay, 'balance' => $balance];
    }

    public function deletePayment(int $paymentId, ?User $actor = null, ?Request $request = null): bool
    {
        $row = $this->db->table('payments')->where('id', '=', $paymentId)->first();
        if ($row === null) {
            return false;
        }

        $this->db->transaction(function () use ($paymentId, $row): void {
            $invoiceId = $row['invoice_id'] ?? null;
            if ($invoiceId !== null) {
                $invoice = $this->db->table('invoices')->where('id', '=', (int) $invoiceId)->first();
                if ($invoice !== null) {
                    $paid = max(0.0, round((float) $invoice['paid_amount'] - (float) $row['amount'], 2));
                    $balance = round((float) $invoice['amount'] - $paid, 2);
                    $this->db->table('invoices')->where('id', '=', (int) $invoiceId)->update([
                        'paid_amount' => $paid,
                        'balance'     => $balance,
                        'status'      => $balance <= 0.004
                            ? Invoice::STATUS_PAID
                            : ($paid > 0.004 ? Invoice::STATUS_PARTIAL : Invoice::STATUS_PENDING),
                        'paid_at'     => $balance <= 0.004 ? $invoice['paid_at'] : null,
                        'updated_at'  => $this->clock->format(),
                    ]);
                }
            }
            $this->db->table('payments')->where('id', '=', $paymentId)->delete();
        });

        $this->recalculateBalance((int) $row['resident_id']);
        $this->refreshChargeAggregatesForResident((int) $row['resident_id']);
        $this->history((int) $row['resident_id'], 'payment', $paymentId, 'payment_deleted', -(float) $row['amount'], $actor);
        $this->audit->info('billing.payment.delete', $actor, 'payment', (string) $paymentId, null, $request, ['amount' => $row['amount']]);

        return true;
    }

    /** @param array<string, mixed> $filters */
    public function payments(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        $q = $this->db->table('payments p')
            ->select('p.*', 'r.full_name AS resident_name', 'r.apartment', 'r.is_active', 'i.number AS invoice_number', 'i.period_label')
            ->leftJoin('residents r', 'r.id', '=', 'p.resident_id')
            ->leftJoin('invoices i', 'i.id', '=', 'p.invoice_id');

        if (!empty($filters['resident_id'])) {
            $q = $q->where('p.resident_id', '=', (int) $filters['resident_id']);
        }
        if (!empty($filters['from'])) {
            $q = $q->where('p.paid_at', '>=', (string) $filters['from']);
        }
        if (!empty($filters['to'])) {
            $q = $q->where('p.paid_at', '<=', (string) $filters['to']);
        }
        if (!empty($filters['method'])) {
            $q = $q->where('p.method', '=', (string) $filters['method']);
        }
        if (!empty($filters['only_active'])) {
            $q = $q->where('r.is_active', '=', 1)->whereNull('r.deleted_at');
        }
        if (!empty($filters['q'])) {
            $q = $q->whereSearch(['r.full_name', 'r.apartment', 'p.reference', 'p.payer_name', 'p.note', 'i.number'], (string) $filters['q']);
        }

        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortColumn = match ((string) ($filters['sort'] ?? 'paid_at')) {
            'amount' => 'p.amount',
            'name'   => 'r.full_name',
            'method' => 'p.method',
            default  => 'p.paid_at',
        };

        $paginator = $q->orderBy($sortColumn, $dir)->orderBy('p.id', 'desc')->paginate($perPage, $page);

        return new Paginator(
            array_map(static fn (array $r): Payment => Payment::fromRow($r), $paginator->items()),
            $paginator->total(), $paginator->perPage(), $paginator->currentPage(), $paginator->pageName()
        );
    }

    /** @return array<int, Payment> */
    public function paymentsForResident(int $residentId, int $limit = 200): array
    {
        $rows = $this->db->table('payments p')
            ->select('p.*', 'i.number AS invoice_number', 'i.period_label')
            ->leftJoin('invoices i', 'i.id', '=', 'p.invoice_id')
            ->where('p.resident_id', '=', $residentId)
            ->orderBy('p.paid_at', 'desc')->orderBy('p.id', 'desc')
            ->limit($limit)
            ->get();

        return array_map(static fn (array $r): Payment => Payment::fromRow($r), $rows);
    }

    /* ═══════════════════════════ balances & reports ═══════════════════════════ */

    /**
     * balance = payments − allocated − open invoice balances
     * (positive = overpayment, negative = debt).
     */
    public function recalculateBalance(int $residentId): float
    {
        $invoices = (float) $this->db->table('invoices')
            ->where('resident_id', '=', $residentId)
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE])
            ->sum('balance');

        $paid = (float) $this->db->table('payments')
            ->where('resident_id', '=', $residentId)
            ->where('is_confirmed', '=', 1)
            ->sum('amount');

        $allocated = (float) $this->db->table('payments')
            ->where('resident_id', '=', $residentId)
            ->where('is_confirmed', '=', 1)
            ->sum('allocated_amount');

        $balance = round($paid - $allocated - $invoices, 2);
        $this->db->table('residents')->where('id', '=', $residentId)->update(['balance' => $balance]);

        return $balance;
    }

    /** @return array{debt: float, overpay: float, invoices: int, oldest_due: ?string} */
    public function residentStatement(int $residentId): array
    {
        $row = $this->db->table('invoices')
            ->select(
                "COALESCE(SUM(CASE WHEN status IN ('pending','partial','overdue') THEN balance ELSE 0 END), 0) AS debt",
                "COALESCE(SUM(CASE WHEN status IN ('pending','partial','overdue') THEN 1 ELSE 0 END), 0) AS cnt",
                "MIN(CASE WHEN status IN ('pending','partial','overdue') THEN due_date END) AS oldest"
            )
            ->where('resident_id', '=', $residentId)
            ->first();

        $paid = (float) $this->db->table('payments')->where('resident_id', '=', $residentId)->where('is_confirmed', '=', 1)->sum('amount');
        $allocated = (float) $this->db->table('payments')->where('resident_id', '=', $residentId)->where('is_confirmed', '=', 1)->sum('allocated_amount');
        $debt = round((float) ($row['debt'] ?? 0), 2);

        return [
            'debt'       => max(0.0, $debt),
            'overpay'    => round(max(0.0, $paid - $allocated - $debt), 2),
            'invoices'   => (int) ($row['cnt'] ?? 0),
            'oldest_due' => isset($row['oldest']) && $row['oldest'] !== null ? (string) $row['oldest'] : null,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function debtors(int $limit = 500, bool $onlyActive = true): array
    {
        $q = $this->db->table('invoices i')
            ->select(
                'i.resident_id', 'r.full_name', 'r.apartment', 'r.building', 'r.phone', 'r.email', 'r.is_active',
                'COALESCE(SUM(i.balance), 0) AS debt',
                'MIN(i.due_date) AS oldest_due',
                'COUNT(*) AS invoices'
            )
            ->leftJoin('residents r', 'r.id', '=', 'i.resident_id')
            ->whereIn('i.status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE])
            ->whereNull('r.deleted_at')
            ->groupBy('i.resident_id', 'r.full_name', 'r.apartment', 'r.building', 'r.phone', 'r.email', 'r.is_active')
            ->having('COALESCE(SUM(i.balance), 0) > 0.005');

        if ($onlyActive) {
            $q = $q->where('r.is_active', '=', 1);
        }

        $rows = $q->orderByRaw('COALESCE(SUM(i.balance), 0) DESC')->limit($limit)->get();
        $now  = $this->clock->now();

        return array_map(static fn (array $r): array => [
            'resident_id'  => (int) $r['resident_id'],
            'name'         => (string) $r['full_name'],
            'apartment'    => (string) $r['apartment'],
            'building'     => $r['building'],
            'phone'        => $r['phone'],
            'email'        => $r['email'],
            'active'       => (bool) $r['is_active'],
            'debt'         => round((float) $r['debt'], 2),
            'oldest_due'   => $r['oldest_due'],
            'invoices'     => (int) $r['invoices'],
            'days_overdue' => $r['oldest_due'] !== null
                ? max(0, (int) floor(($now - strtotime((string) $r['oldest_due'] . ' 23:59:59')) / 86400))
                : 0,
        ], $rows);
    }

    /** @return array<int, array<string, mixed>> */
    public function historyFor(int $residentId, int $limit = 100): array
    {
        return $this->db->table('billing_history')
            ->where('resident_id', '=', $residentId)
            ->orderBy('occurred_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /* ═══════════════════════════ cron jobs ═══════════════════════════ */

    public function markOverdue(): int
    {
        $count = $this->db->table('invoices')
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL])
            ->where('due_date', '<', $this->clock->format('Y-m-d'))
            ->where('balance', '>', 0)
            ->update(['status' => Invoice::STATUS_OVERDUE, 'updated_at' => $this->clock->format()]);

        if ($count > 0) {
            $this->audit->record('billing.overdue.marked', null, [
                'subject_type' => 'invoice', 'subject_label' => $count . ' счетов',
                'actor_name' => 'cron', 'meta' => ['count' => $count],
            ]);
        }

        return $count;
    }

    /** Optionally cut off gate access for long-term debtors. */
    public function suspendDebtors(?User $actor = null): int
    {
        if (!$this->settings->bool('billing.auto_suspend_debtors', false)) {
            return 0;
        }
        $days   = max(1, $this->settings->int('billing.suspend_after_days', 60));
        $cutoff = $this->clock->format('Y-m-d', $this->clock->now() - $days * 86400);

        $ids = $this->db->table('invoices')
            ->select('resident_id')
            ->whereIn('status', [Invoice::STATUS_PENDING, Invoice::STATUS_PARTIAL, Invoice::STATUS_OVERDUE])
            ->where('due_date', '<', $cutoff)
            ->groupBy('resident_id')
            ->having('COALESCE(SUM(balance), 0) > 0.005')
            ->pluck('resident_id');

        $suspended = 0;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ((int) $this->db->table('residents')->where('id', '=', $id)->value('access_granted', 1) === 0) {
                continue;
            }
            $this->db->table('residents')->where('id', '=', $id)->update([
                'access_granted' => 0, 'updated_at' => $this->clock->format(),
            ]);
            $suspended++;
            $this->audit->record('resident.access_revoke', $actor, [
                'subject_type' => 'resident', 'subject_id' => (string) $id,
                'actor_name' => 'cron', 'meta' => ['reason' => 'debt_over_' . $days . '_days'],
            ]);
        }

        return $suspended;
    }

    /* ═══════════════════════════ messaging ═══════════════════════════ */

    /**
     * Build the notification text for one resident.
     *
     * @param array<string, mixed> $context
     * @return array{subject: string, body: string}
     */
    public function renderMessage(string $template, array $context): array
    {
        return $this->templates->render($template, $context, (array) $this->config->get('notify.templates.' . $template, []));
    }

    /**
     * Placeholder context used by every payment-related message.
     *
     * @return array<string, mixed>
     */
    public function messageContext(Resident $resident, ?Charge $charge = null, ?Invoice $invoice = null, ?string $payLink = null): array
    {
        $symbol = $this->config->string('billing.currency_symbol', '₽');
        $amount = $invoice?->amount ?? 0.0;
        $period = $invoice?->periodLabel ?? $charge?->periodLabel ?? '';

        return [
            'name'          => $this->config->bool('billing.use_short_name', true) ? $resident->shortName() : $resident->displayName(),
            'full_name'     => $resident->displayName(),
            'apartment'     => $resident->apartment,
            'building'      => (string) ($resident->building ?? ''),
            'address'       => $resident->address(),
            'amount'        => Money::format($amount, $symbol),
            'amount_raw'    => $amount,
            'currency'      => $this->config->string('billing.currency', 'RUB'),
            'period'        => $period,
            'due_date'      => (string) ($invoice?->dueDate ?? $charge?->dueDate ?? ''),
            'deadline'      => (string) ($invoice?->dueDate ?? $charge?->dueDate ?? ''),
            'invoice_no'    => (string) ($invoice?->number ?? ''),
            'charge_no'     => (string) ($charge?->number ?? ''),
            'balance'       => Money::format($resident->balance, $symbol),
            'debt'          => Money::format(max(0.0, (float) ($resident->debt ?? 0)), $symbol),
            'pay_link'      => (string) $this->expandPayLink($payLink ?? $invoice?->payLink ?? $charge?->payLink, [
                'id' => $resident->id, 'apartment' => $resident->apartment, 'name' => $resident->displayName(),
            ], $amount, $period),
            'organisation'  => $this->settings->string('ui.organisation_name', $this->config->string('app.name', 'BAS-IP')),
            'support_phone' => $this->settings->string('ui.support_phone', ''),
            'support_email' => $this->settings->string('ui.support_email', ''),
            'gate'          => $this->settings->string('ui.organisation_name', ''),
            'today'         => $this->clock->format('d.m.Y'),
        ];
    }

    /**
     * Queue the payment notification for a whole charge (mass mailing).
     *
     * @param array<int, int>    $residentIds empty = everybody in the charge
     * @param array<int, string> $channels
     * @return array{batch_id: int, queued: int, skipped: int}
     */
    public function notifyCharge(int $chargeId, array $residentIds = [], string $template = 'payment_new', ?string $messageOverride = null, ?string $subjectOverride = null, array $channels = [], ?User $actor = null): array
    {
        $charge = $this->charge($chargeId);
        if ($charge === null) {
            throw new RuntimeException('Начисление не найдено.');
        }

        $q = $this->db->table('invoices i')
            ->select('i.*', 'r.full_name', 'r.apartment', 'r.building', 'r.phone', 'r.email', 'r.is_active', 'r.notify_enabled', 'r.balance')
            ->innerJoin('residents r', 'r.id', '=', 'i.resident_id')
            ->where('i.charge_id', '=', $chargeId)
            ->where('i.status', '!=', Invoice::STATUS_VOID);
        if ($residentIds !== []) {
            $q = $q->whereIn('i.resident_id', $residentIds);
        }

        $messages = [];
        $skipped  = 0;
        foreach ($q->get() as $row) {
            // Mass actions always ignore residents who are switched off.
            if (!Str::toBoolSafe($row['is_active'] ?? false) || !Str::toBoolSafe($row['notify_enabled'] ?? true)) {
                $skipped++;
                continue;
            }
            $invoice  = Invoice::fromRow($row);
            $resident = Resident::fromRow($row);
            $context  = $this->messageContext($resident, $charge, $invoice);

            if ($messageOverride !== null && trim($messageOverride) !== '') {
                $rendered = [
                    'subject' => $subjectOverride ?? $charge->title,
                    'body'    => $this->templates->interpolate($messageOverride, $context),
                ];
            } else {
                $rendered = $this->renderMessage($template, $context);
            }
            if ($subjectOverride !== null && trim($subjectOverride) !== '') {
                $rendered['subject'] = $subjectOverride;
            }

            $messages[] = Message::forResident($resident)
                ->template($template)
                ->subject($rendered['subject'])
                ->body($rendered['body'])
                ->context($context)
                ->channels($channels !== [] ? $channels : null)
                ->charge($chargeId)
                ->invoice($invoice->id);
        }

        $batch = $this->notify->queueBatch($messages, [
            'name'       => sprintf('Рассылка по начислению %s', $charge->number),
            'template'   => $template,
            'charge_id'  => $chargeId,
            'created_by' => $actor?->id,
        ]);

        $this->audit->record('billing.notify', $actor, [
            'subject_type' => 'charge', 'subject_id' => (string) $chargeId, 'subject_label' => $charge->number,
            'meta' => ['queued' => count($messages), 'skipped' => $skipped, 'template' => $template, 'batch_id' => $batch['id']],
        ]);

        return ['batch_id' => (int) $batch['id'], 'queued' => count($messages), 'skipped' => $skipped];
    }

    /**
     * Remind every active debtor (cron job or the «Напомнить должникам» button).
     *
     * @param array<int, string> $channels
     * @return array{queued: int, skipped: int, batch_id: int}
     */
    public function sendOverdueReminders(array $channels = [], ?User $actor = null): array
    {
        $messages = [];
        $skipped  = 0;
        $symbol   = $this->config->string('billing.currency_symbol', '₽');

        foreach ($this->debtors(2000) as $row) {
            if (!$row['active']) {
                $skipped++;
                continue;
            }
            $resident = $this->residents->findById((int) $row['resident_id']);
            if ($resident === null) {
                $skipped++;
                continue;
            }
            $resident->debt = (float) $row['debt'];
            $context = $this->messageContext($resident, null, null, $this->settings->string('billing.pay_link', ''));
            $context['amount']   = Money::format((float) $row['debt'], $symbol);
            $context['due_date'] = (string) ($row['oldest_due'] ?? '');
            $rendered = $this->renderMessage('payment_overdue', $context);

            $messages[] = Message::forResident($resident)
                ->template('payment_overdue')
                ->subject($rendered['subject'])
                ->body($rendered['body'])
                ->context($context)
                ->channels($channels !== [] ? $channels : null);
        }

        $batch = $this->notify->queueBatch($messages, [
            'name' => 'Напоминание о просроченной оплате', 'template' => 'payment_overdue', 'created_by' => $actor?->id,
        ]);

        $this->audit->record('billing.remind_overdue', $actor, [
            'subject_type' => 'batch', 'subject_id' => (string) $batch['id'],
            'meta' => ['queued' => count($messages), 'skipped' => $skipped],
        ]);

        return ['queued' => count($messages), 'skipped' => $skipped, 'batch_id' => (int) $batch['id']];
    }

    /* ═══════════════════════════ helpers ═══════════════════════════ */

    /** @param array<string, mixed> $details */
    private function history(int $residentId, string $entity, int $entityId, string $action, float $amount, ?User $actor, array $details = []): void
    {
        try {
            $balance = (float) $this->db->table('residents')->where('id', '=', $residentId)->value('balance', 0);
            $this->db->table('billing_history')->insert([
                'occurred_at'    => $this->clock->format(),
                'resident_id'    => $residentId,
                'entity'         => $entity,
                'entity_id'      => $entityId > 0 ? $entityId : null,
                'action'         => $action,
                'amount'         => $amount,
                'balance_before' => $balance,
                'balance_after'  => $balance,
                'details'        => $details === [] ? null : (string) json_encode($details, JSON_UNESCAPED_UNICODE),
                'user_id'        => $actor?->id,
            ]);
        } catch (Throwable) {
            // History must never break a money operation.
        }
    }

    private function refreshChargeAggregatesForResident(int $residentId): void
    {
        $chargeIds = $this->db->table('invoices')->where('resident_id', '=', $residentId)->whereNotNull('charge_id')->pluck('charge_id');
        foreach (array_unique(array_map('intval', $chargeIds)) as $chargeId) {
            $stats = $this->chargeStats($chargeId);
            $this->db->table('charges')->where('id', '=', $chargeId)->update([
                'invoices_total' => $stats['count'], 'invoices_amount' => $stats['amount'],
                'paid_amount' => $stats['paid'], 'paid_count' => $stats['paid_count'],
                'updated_at' => $this->clock->format(),
            ]);
        }
    }

    private function normalizeMethod(mixed $method): string
    {
        $method = strtolower(trim((string) $method));

        return in_array($method, Payment::METHODS, true) ? $method : 'other';
    }

    public function nextChargeNumber(?string $periodStart = null): string
    {
        $prefix = $this->config->string('billing.charge_prefix', 'CHG-');
        $year = $periodStart !== null ? date('Y', (int) strtotime($periodStart)) : $this->clock->format('Y');
        $seq  = $this->settings->int('billing.charge_counter', 0) + 1;
        $this->settings->set('billing.charge_counter', $seq);

        return sprintf('%s%s-%04d', $prefix, $year, $seq);
    }

    public function nextInvoiceNumber(string $period = '', string $kind = 'regular'): string
    {
        $prefix = $this->config->string('billing.invoice_prefix', 'INV-');
        $year = preg_match('/(20\d{2})/', $period, $m) ? $m[1] : $this->clock->format('Y');
        $seq  = $this->settings->int('billing.invoice_counter', 0) + 1;
        $this->settings->set('billing.invoice_counter', $seq);
        $suffix = $kind === 'adjustment' ? '-Д' : ($kind === 'credit' ? '-С' : '');

        return sprintf('%s%s-%05d%s', $prefix, $year, $seq, $suffix);
    }

    public function normalizePayLink(mixed $link): ?string
    {
        if ($link === null || $link === '') {
            $link = $this->settings->string('billing.pay_link', '') ?: $this->config->string('payment_link', '');
        }
        $link = trim((string) $link);
        if ($link === '') {
            return null;
        }
        if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $link) && !str_starts_with($link, '/')) {
            $link = 'https://' . $link;
        }

        return mb_substr($link, 0, 512);
    }

    public function chargePayLink(int $chargeId): ?string
    {
        $link = $this->db->table('charges')->where('id', '=', $chargeId)->value('pay_link');

        return $link === null || $link === '' ? $this->normalizePayLink(null) : (string) $link;
    }

    /**
     * Expand `{apartment}`, `{amount}`, `{period}`, `{name}`, `{id}`, `{currency}`
     * inside a payment link.
     *
     * @param array<string, mixed> $row
     */
    public function expandPayLink(?string $link, array $row, float $amount = 0.0, string $period = ''): ?string
    {
        if ($link === null || $link === '') {
            return null;
        }

        return strtr($link, [
            '{apartment}' => rawurlencode((string) ($row['apartment'] ?? '')),
            '{apt}'       => rawurlencode((string) ($row['apartment'] ?? '')),
            '{name}'      => rawurlencode((string) ($row['name'] ?? '')),
            '{id}'        => rawurlencode((string) ($row['id'] ?? '')),
            '{amount}'    => number_format($amount, 2, '.', ''),
            '{sum}'       => number_format($amount, 2, '.', ''),
            '{period}'    => rawurlencode($period),
            '{invoice}'   => rawurlencode((string) ($row['invoice'] ?? '')),
            '{currency}'  => $this->config->string('billing.currency', 'RUB'),
        ]);
    }

    /** Default period = the previous calendar month. @return array{0: string, 1: string} */
    public function guessPeriod(string $label = ''): array
    {
        if ($label !== '' && preg_match('/(\d{1,2})[.\/](\d{4})/', $label, $m)) {
            $month = (int) $m[1];
            $year  = (int) $m[2];
        } else {
            $prev  = (new \DateTimeImmutable($this->clock->format('Y-m-01')))->modify('first day of previous month');
            $month = (int) $prev->format('n');
            $year  = (int) $prev->format('Y');
        }

        $start = sprintf('%04d-%02d-01', $year, $month);

        return [$start, date('Y-m-t', (int) strtotime($start))];
    }

    public function periodLabel(?string $start, ?string $end = null): string
    {
        if ($start === null) {
            return $this->clock->format('F Y');
        }
        $months = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
        $ts = (int) strtotime($start);
        $label = ($months[(int) date('n', $ts)] ?? date('F', $ts)) . ' ' . date('Y', $ts);

        if ($end !== null && date('Y-m', (int) strtotime($end)) !== date('Y-m', $ts)) {
            $endTs = (int) strtotime($end);
            $label .= ' — ' . ($months[(int) date('n', $endTs)] ?? date('F', $endTs)) . ' ' . date('Y', $endTs);
        }

        return $label;
    }

    private function dateOrNull(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = strtotime((string) $value);

        return $ts === false ? null : date('Y-m-d', $ts);
    }
}
