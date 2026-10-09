<?php

declare(strict_types=1);

namespace BasIp\Domain\Model;

/** A recorded payment ("оплата") — the fact of money received. */
final class Payment extends Entity
{
    public const METHODS = ['cash', 'card', 'transfer', 'online', 'sbp', 'other'];

    protected const BOOLS  = ['is_confirmed'];
    protected const INTS   = ['id', 'resident_id', 'invoice_id', 'charge_id', 'recorded_by'];
    protected const FLOATS = ['amount'];

    public int $id = 0;
    public string $uuid = '';
    public int $residentId = 0;
    public ?int $invoiceId = null;
    public ?int $chargeId = null;
    public float $amount = 0.0;
    public ?string $paidAt = null;
    public string $method = 'transfer';
    public ?string $reference = null;
    public ?string $payerName = null;
    public ?string $note = null;
    public ?string $externalId = null;
    public bool $isConfirmed = true;
    public ?string $createdAt = null;

    public ?string $residentName = null;
    public ?string $apartment = null;
    public ?string $invoiceNumber = null;
    public ?string $periodLabel = null;

    protected function fill(array $row): void
    {
        $this->id          = (int) ($row['id'] ?? 0);
        $this->uuid        = (string) ($row['uuid'] ?? '');
        $this->residentId  = (int) ($row['resident_id'] ?? 0);
        $this->invoiceId   = $row['invoice_id'] ?? null;
        $this->chargeId    = $row['charge_id'] ?? null;
        $this->amount      = (float) ($row['amount'] ?? 0);
        $this->paidAt      = isset($row['paid_at']) ? (string) $row['paid_at'] : null;
        $this->method      = (string) ($row['method'] ?? 'transfer');
        $this->reference   = isset($row['reference']) && $row['reference'] !== '' ? (string) $row['reference'] : null;
        $this->payerName   = isset($row['payer_name']) && $row['payer_name'] !== '' ? (string) $row['payer_name'] : null;
        $this->note        = isset($row['note']) ? (string) $row['note'] : null;
        $this->externalId  = isset($row['external_id']) ? (string) $row['external_id'] : null;
        $this->isConfirmed = (bool) ($row['is_confirmed'] ?? true);
        $this->createdAt   = isset($row['created_at']) ? (string) $row['created_at'] : null;
        $this->residentName = isset($row['resident_name']) ? (string) $row['resident_name'] : null;
        $this->apartment   = isset($row['apartment']) ? (string) $row['apartment'] : null;
        $this->invoiceNumber = isset($row['invoice_number']) ? (string) $row['invoice_number'] : null;
        $this->periodLabel = isset($row['period_label']) ? (string) $row['period_label'] : null;
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            'cash'     => 'Наличные',
            'card'     => 'Карта',
            'transfer' => 'Перевод',
            'online'   => 'Онлайн',
            'sbp'      => 'СБП',
            default    => $this->method,
        };
    }

    public function dateLabel(string $format = 'd.m.Y'): string
    {
        $source = $this->paidAt ?? $this->createdAt;
        if ($source === null || $source === '') {
            return '—';
        }
        $ts = strtotime($source);

        return $ts === false ? '—' : date($format, $ts);
    }
}
