<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

/**
 * A single invoice ("счёт") issued to one resident, optionally as part of a
 * {@see Charge}.
 *
 * `kind`:
 *   regular     — ordinary split of the maintenance total
 *   adjustment  — additional invoice issued after a recalculation
 *   credit      — negative amount (сторно / переплата возвращена)
 *   manual      — created by hand
 */
final class Invoice extends Entity
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_PAID    = 'paid';
    public const STATUS_VOID    = 'void';
    public const STATUS_OVERDUE = 'overdue';

    public const KINDS = ['regular', 'adjustment', 'credit', 'manual'];

    protected const INTS   = ['id', 'charge_id', 'resident_id', 'parent_invoice_id', 'created_by'];
    protected const FLOATS = ['amount', 'paid_amount', 'balance'];

    public int $id = 0;
    public string $uuid = '';
    public string $number = '';
    public ?int $chargeId = null;
    public int $residentId = 0;
    public ?int $parentInvoiceId = null;
    public string $kind = 'regular';
    public float $amount = 0.0;
    public float $paidAmount = 0.0;
    public float $balance = 0.0;
    public ?string $periodStart = null;
    public ?string $periodEnd = null;
    public string $periodLabel = '';
    public ?string $dueDate = null;
    public ?string $issuedDate = null;
    public string $status = self::STATUS_PENDING;
    public ?string $payLink = null;
    public ?string $note = null;
    public ?string $recalcReason = null;
    public ?string $issuedAt = null;
    public ?string $paidAt = null;
    public ?string $voidedAt = null;
    public ?string $createdAt = null;

    /* joined */
    public ?string $residentName = null;
    public ?string $apartment = null;
    public ?string $chargeNumber = null;

    protected function fill(array $row): void
    {
        $this->id              = (int) ($row['id'] ?? 0);
        $this->uuid            = (string) ($row['uuid'] ?? '');
        $this->number          = (string) ($row['number'] ?? '');
        $this->chargeId        = $row['charge_id'] ?? null;
        $this->residentId      = (int) ($row['resident_id'] ?? 0);
        $this->parentInvoiceId = $row['parent_invoice_id'] ?? null;
        $this->kind            = (string) ($row['kind'] ?? 'regular');
        $this->amount          = (float) ($row['amount'] ?? 0);
        $this->paidAmount      = (float) ($row['paid_amount'] ?? 0);
        $this->balance         = (float) ($row['balance'] ?? 0);
        $this->periodStart     = isset($row['period_start']) ? (string) $row['period_start'] : null;
        $this->periodEnd       = isset($row['period_end']) ? (string) $row['period_end'] : null;
        $this->periodLabel     = self::str($row['period_label'] ?? '');
        $this->dueDate         = isset($row['due_date']) ? (string) $row['due_date'] : null;
        $this->issuedDate      = isset($row['issued_date']) ? (string) $row['issued_date'] : null;
        $this->status          = (string) ($row['status'] ?? self::STATUS_PENDING);
        $this->payLink         = isset($row['pay_link']) ? (string) $row['pay_link'] : null;
        $this->note            = isset($row['note']) ? (string) $row['note'] : null;
        $this->recalcReason    = isset($row['recalc_reason']) ? (string) $row['recalc_reason'] : null;
        $this->issuedAt        = isset($row['issued_at']) ? (string) $row['issued_at'] : null;
        $this->paidAt          = isset($row['paid_at']) ? (string) $row['paid_at'] : null;
        $this->voidedAt        = isset($row['voided_at']) ? (string) $row['voided_at'] : null;
        $this->createdAt       = isset($row['created_at']) ? (string) $row['created_at'] : null;
        $this->residentName    = isset($row['resident_name']) ? (string) $row['resident_name'] : null;
        $this->apartment       = isset($row['apartment']) ? (string) $row['apartment'] : null;
        $this->chargeNumber    = isset($row['charge_number']) ? (string) $row['charge_number'] : null;
    }

    public function isPaid(): bool    { return $this->status === self::STATUS_PAID; }
    public function isPending(): bool { return in_array($this->status, [self::STATUS_PENDING, self::STATUS_PARTIAL, self::STATUS_OVERDUE], true); }
    public function isVoid(): bool    { return $this->status === self::STATUS_VOID; }
    public function isAdjustment(): bool { return $this->kind === 'adjustment'; }
    public function isCredit(): bool  { return $this->kind === 'credit' || $this->amount < 0; }

    public function isOverdue(?int $now = null): bool
    {
        if ($this->isPaid() || $this->isVoid()) {
            return false;
        }
        if ($this->dueDate === null || $this->dueDate === '') {
            return false;
        }

        return strtotime($this->dueDate . ' 23:59:59') < ($now ?? time());
    }

    public function daysOverdue(?int $now = null): int
    {
        if (!$this->isOverdue($now) || $this->dueDate === null) {
            return 0;
        }

        return max(0, (int) floor((($now ?? time()) - strtotime($this->dueDate . ' 23:59:59')) / 86400));
    }

    public function daysLeft(?int $now = null): int
    {
        if ($this->dueDate === null || $this->isPaid()) {
            return 0;
        }

        return (int) ceil((strtotime($this->dueDate . ' 23:59:59') - ($now ?? time())) / 86400);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'Не оплачен',
            self::STATUS_PARTIAL => 'Частично',
            self::STATUS_PAID    => 'Оплачен',
            self::STATUS_VOID    => 'Аннулирован',
            self::STATUS_OVERDUE => 'Просрочен',
            default              => $this->status,
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_PAID    => 'success',
            self::STATUS_PARTIAL => 'warning',
            self::STATUS_OVERDUE => 'destructive',
            self::STATUS_VOID    => 'muted',
            default              => 'info',
        };
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'regular'    => 'Основной',
            'adjustment' => 'Доп. счёт',
            'credit'     => 'Сторно',
            'manual'     => 'Вручную',
            default      => $this->kind,
        };
    }

    public function progressPercent(): float
    {
        if ($this->amount <= 0.0) {
            return $this->isPaid() ? 100.0 : 0.0;
        }

        return round(min(100.0, ($this->paidAmount / $this->amount) * 100), 1);
    }
}
