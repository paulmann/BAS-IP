<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

/**
 * A billing charge ("начисление" / смета на период).
 *
 * Holds the total maintenance cost for the barrier and the snapshot of how many
 * active residents it was divided by. Issuing a charge creates one
 * {@see Invoice} per participating resident; recalculating creates *adjustment*
 * invoices instead of mutating the originals.
 */
final class Charge extends Entity
{
    public const STATUS_DRAFT     = 'draft';
    public const STATUS_ISSUED    = 'issued';
    public const STATUS_CLOSED    = 'closed';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOCATIONS = ['equal', 'by_persons', 'by_area', 'by_share'];

    protected const BOOLS  = [];
    protected const INTS   = ['id', 'active_count', 'due_days', 'invoices_total', 'paid_count', 'created_by', 'recalc_of'];
    protected const FLOATS = ['total_amount', 'per_unit_amount', 'invoices_amount', 'paid_amount'];

    public int $id = 0;
    public string $uuid = '';
    public string $number = '';
    public string $title = '';
    public ?string $description = null;
    public ?string $periodStart = null;
    public ?string $periodEnd = null;
    public string $periodLabel = '';
    public float $totalAmount = 0.0;
    public int $activeCount = 0;
    public float $perUnitAmount = 0.0;
    public string $allocation = 'equal';
    public int $dueDays = 14;
    public ?string $dueDate = null;
    public ?string $message = null;
    public ?string $payLink = null;
    public string $status = self::STATUS_DRAFT;
    public ?int $recalcOf = null;
    public ?string $recalcReason = null;
    public int $invoicesTotal = 0;
    public float $invoicesAmount = 0.0;
    public float $paidAmount = 0.0;
    public int $paidCount = 0;
    public ?string $notes = null;
    public ?string $issuedAt = null;
    public ?string $closedAt = null;
    public ?string $createdAt = null;

    protected function fill(array $row): void
    {
        $this->id            = (int) ($row['id'] ?? 0);
        $this->uuid          = (string) ($row['uuid'] ?? '');
        $this->number        = (string) ($row['number'] ?? '');
        $this->title         = self::str($row['title'] ?? '');
        $this->description   = isset($row['description']) ? (string) $row['description'] : null;
        $this->periodStart   = isset($row['period_start']) ? (string) $row['period_start'] : null;
        $this->periodEnd     = isset($row['period_end']) ? (string) $row['period_end'] : null;
        $this->periodLabel   = self::str($row['period_label'] ?? '');
        $this->totalAmount   = (float) ($row['total_amount'] ?? 0);
        $this->activeCount   = (int) ($row['active_count'] ?? 0);
        $this->perUnitAmount = (float) ($row['per_unit_amount'] ?? 0);
        $this->allocation    = (string) ($row['allocation'] ?? 'equal');
        $this->dueDays       = (int) ($row['due_days'] ?? 14);
        $this->dueDate       = isset($row['due_date']) ? (string) $row['due_date'] : null;
        $this->message       = isset($row['message']) ? (string) $row['message'] : null;
        $this->payLink       = isset($row['pay_link']) ? (string) $row['pay_link'] : null;
        $this->status        = (string) ($row['status'] ?? self::STATUS_DRAFT);
        $this->recalcOf      = $row['recalc_of'] ?? null;
        $this->recalcReason  = isset($row['recalc_reason']) ? (string) $row['recalc_reason'] : null;
        $this->invoicesTotal = (int) ($row['invoices_total'] ?? 0);
        $this->invoicesAmount = (float) ($row['invoices_amount'] ?? 0);
        $this->paidAmount    = (float) ($row['paid_amount'] ?? 0);
        $this->paidCount     = (int) ($row['paid_count'] ?? 0);
        $this->notes         = isset($row['notes']) ? (string) $row['notes'] : null;
        $this->issuedAt      = isset($row['issued_at']) ? (string) $row['issued_at'] : null;
        $this->closedAt      = isset($row['closed_at']) ? (string) $row['closed_at'] : null;
        $this->createdAt     = isset($row['created_at']) ? (string) $row['created_at'] : null;
    }

    public function isDraft(): bool     { return $this->status === self::STATUS_DRAFT; }
    public function isIssued(): bool    { return $this->status === self::STATUS_ISSUED; }
    public function isClosed(): bool    { return $this->status === self::STATUS_CLOSED; }
    public function isCancelled(): bool { return $this->status === self::STATUS_CANCELLED; }
    public function isRecalc(): bool    { return $this->recalcOf !== null; }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT     => 'Черновик',
            self::STATUS_ISSUED    => 'Выставлено',
            self::STATUS_CLOSED    => 'Закрыто',
            self::STATUS_CANCELLED => 'Отменено',
            default                => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT     => 'secondary',
            self::STATUS_ISSUED    => 'info',
            self::STATUS_CLOSED    => 'success',
            self::STATUS_CANCELLED => 'destructive',
            default                => 'secondary',
        };
    }

    public function allocationLabel(): string
    {
        return match ($this->allocation) {
            'equal'      => 'Поровну',
            'by_persons' => 'По числу проживающих',
            'by_area'    => 'По площади',
            'by_share'   => 'По доле',
            default      => $this->allocation,
        };
    }

    public function collectedPercent(): float
    {
        if ($this->invoicesAmount <= 0.0) {
            return 0.0;
        }

        return round(min(100.0, ($this->paidAmount / $this->invoicesAmount) * 100), 1);
    }

    public function outstanding(): float
    {
        return round(max(0.0, $this->invoicesAmount - $this->paidAmount), 2);
    }

    public function periodRange(): string
    {
        if ($this->periodLabel !== '') {
            return $this->periodLabel;
        }
        if ($this->periodStart !== null && $this->periodEnd !== null) {
            return date('d.m.Y', (int) strtotime($this->periodStart)) . ' — ' . date('d.m.Y', (int) strtotime($this->periodEnd));
        }

        return '—';
    }
}
