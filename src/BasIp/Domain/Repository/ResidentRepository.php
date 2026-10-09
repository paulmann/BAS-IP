<?php

declare(strict_types=1);

namespace BasIp\Domain\Repository;

use BasIp\Core\Clock;
use BasIp\Db\Database;
use BasIp\Db\Query;
use BasIp\Db\SubQuery;
use BasIp\Db\WhereGroup;
use BasIp\Domain\Model\Car;
use BasIp\Domain\Model\Contact;
use BasIp\Domain\Model\Resident;
use BasIp\Support\Money;
use BasIp\Support\Paginator;
use BasIp\Support\Phone;
use BasIp\Support\Str;

/**
 * Read side of the resident database.
 *
 * Every query that feeds a calculation or a mass action goes through
 * {@see scopeActive()} / {@see scopeBillable()}, so "only active residents are
 * counted" is enforced in one place instead of being repeated per screen.
 */
final class ResidentRepository
{
    public const SORTABLE = [
        'full_name'     => 'residents.full_name',
        'last_name'     => 'residents.last_name',
        'apartment'     => 'residents.apartment_no',
        'building'      => 'residents.building',
        'balance'       => 'residents.balance',
        'debt'          => 'debt_total',
        'created_at'    => 'residents.created_at',
        'last_payment'  => 'residents.last_payment_at',
        'last_event'    => 'residents.last_gate_event_at',
        'is_active'     => 'residents.is_active',
        'phone'         => 'residents.phone',
    ];

    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function query(): Query
    {
        return $this->db->table('residents');
    }

    /** Only residents that are switched on and not soft-deleted. */
    public function scopeActive(?Query $query = null): Query
    {
        return ($query ?? $this->query())
            ->where('residents.is_active', '=', 1)
            ->whereNull('residents.deleted_at');
    }

    /** Active **and** participating in the maintenance split. */
    public function scopeBillable(?Query $query = null): Query
    {
        return $this->scopeActive($query)->where('residents.is_billed', '=', 1);
    }

    public function countActive(): int
    {
        return $this->scopeActive()->count();
    }

    public function countBillable(): int
    {
        return $this->scopeBillable()->count();
    }

    public function countAll(): int
    {
        return $this->query()->whereNull('deleted_at')->count();
    }

    public function countInactive(): int
    {
        return $this->query()->where('is_active', '=', 0)->whereNull('deleted_at')->count();
    }

    public function countWithoutAccess(): int
    {
        return $this->query()->where('access_granted', '=', 0)->whereNull('deleted_at')->count();
    }

    public function countDeleted(): int
    {
        return $this->query()->whereNotNull('deleted_at')->count();
    }

    public function findById(int $id, bool $withDeleted = false): ?Resident
    {
        $q = $this->db->table('residents')->where('residents.id', '=', $id);
        if (!$withDeleted) {
            $q = $q->whereNull('residents.deleted_at');
        }
        $row = $q->first();

        return $row === null ? null : $this->hydrate($row, true);
    }

    public function findByUuid(string $uuid): ?Resident
    {
        $row = $this->db->table('residents')->where('uuid', '=', $uuid)->whereNull('deleted_at')->first();

        return $row === null ? null : $this->hydrate($row, true);
    }

    /**
     * Bulk-load residents with their contacts and cars for a mass mailing.
     *
     * Unlike {@see findByIds()} this keeps switched-off residents: the caller
     * decides the audience (mass actions filter by `is_active` themselves) and
     * must never fall back to a partial row — see BillingService::notifyCharge.
     *
     * @param array<int, int> $ids
     * @return array<int, Resident> keyed by resident id
     */
    public function findManyWithRelations(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $rows = $this->db->table('residents')->whereIn('residents.id', $ids)->whereNull('residents.deleted_at')->get();

        $out = [];
        foreach ($this->loadRelations($this->hydrateMany($rows, false)) as $resident) {
            $out[$resident->id] = $resident;
        }

        return $out;
    }

    /** @return array<int, Resident> */
    public function findByIds(array $ids, bool $onlyActive = true): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }
        $q = $this->db->table('residents')->whereIn('residents.id', $ids)->whereNull('residents.deleted_at');
        if ($onlyActive) {
            $q = $q->where('residents.is_active', '=', 1);
        }

        return $this->hydrateMany($q->get(), true);
    }

    /** Every billable resident with their allocation weight — used by the split. */
    public function billableForCharge(string $allocation = 'equal'): array
    {
        $rows = $this->scopeBillable()
            ->select('residents.*')
            ->orderBy('residents.apartment_no')
            ->orderBy('residents.last_name')
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $resident = Resident::fromRow($row);
            $out[] = ['resident' => $resident, 'weight' => $resident->billingWeight($allocation)];
        }

        return $out;
    }

    /** @return array<int, Resident> */
    public function allActive(): array
    {
        return $this->hydrateMany($this->scopeActive()->select('residents.*')->orderBy('residents.apartment_no')->get());
    }

    /**
     * Filtered, sorted, paginated listing for the admin table.
     *
     * @param array<string, mixed> $filters
     *   q, status(active|inactive|all|no_access|deleted|debtors), building,
     *   apartment_from, apartment_to, tag, has_phone, has_email, has_car,
     *   created_from, created_to, sort, dir
     */
    public function search(array $filters = [], int $perPage = 25, int $page = 1): Paginator
    {
        $q = $this->filtered($filters);
        [$sort, $dir] = $this->resolveSort($filters);

        if ($sort === 'debt_total') {
            $q = $q->orderByRaw($this->db->isPgsql()
                ? 'COALESCE(SUM(CASE WHEN i.status <> \'void\' THEN i.balance ELSE 0 END), 0) ' . ($dir === 'asc' ? 'ASC' : 'DESC')
                : 'COALESCE(SUM(CASE WHEN i.status <> \'void\' THEN i.balance ELSE 0 END), 0) ' . ($dir === 'asc' ? 'ASC' : 'DESC'));
        } else {
            $q = $q->orderBy($sort, $dir);
        }
        $q = $q->orderBy('residents.id', 'asc');

        $paginator = $q->paginate($perPage, $page);

        return new Paginator(
            $this->hydrateMany($paginator->items(), true),
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            $paginator->pageName()
        );
    }

    /** @param array<string, mixed> $filters */
    public function filtered(array $filters): Query
    {
        $status = (string) ($filters['status'] ?? 'active');
        $today  = $this->db->quote($this->clock->format('Y-m-d'));

        $q = $this->db->table('residents')
            ->select(
                'residents.*',
                "COALESCE(SUM(CASE WHEN i.status <> 'void' THEN i.balance ELSE 0 END), 0) AS debt_total",
                "COALESCE(SUM(CASE WHEN i.status <> 'void' AND i.due_date < $today THEN i.balance ELSE 0 END), 0) AS overdue_total",
                'COUNT(DISTINCT i.id) AS invoices_pending'
            )
            ->leftJoin('invoices i', 'i.resident_id', '=', 'residents.id');

        // MySQL and SQLite accept grouping by the primary key alone; PostgreSQL
        // insists on every non-aggregated column, so we list them explicitly.
        $groupBy = $this->db->isPgsql()
            ? array_map(static fn (string $c): string => 'residents.' . $c, $this->db->columns('residents'))
            : ['residents.id'];
        $q = $q->groupBy(...$groupBy);

        switch ($status) {
            case 'active':
                $q = $q->where('residents.is_active', '=', 1)->whereNull('residents.deleted_at');
                break;
            case 'inactive':
                $q = $q->where('residents.is_active', '=', 0)->whereNull('residents.deleted_at');
                break;
            case 'no_access':
                $q = $q->where('residents.access_granted', '=', 0)->whereNull('residents.deleted_at');
                break;
            case 'deleted':
                $q = $q->whereNotNull('residents.deleted_at');
                break;
            case 'debtors':
                $q = $q->whereNull('residents.deleted_at')
                ->having("COALESCE(SUM(CASE WHEN i.status <> 'void' THEN i.balance ELSE 0 END), 0) > 0.005");
                break;
            case 'overdue':
                $q = $q->whereNull('residents.deleted_at')
                ->having("COALESCE(SUM(CASE WHEN i.status <> 'void' AND i.due_date < $today THEN i.balance ELSE 0 END), 0) > 0.005");
                break;
            default: // all
                $q = $q->whereNull('residents.deleted_at');
        }

        if (!empty($filters['q'])) {
            $term = Str::squash((string) $filters['q']);
            $q = $q->where(function (WhereGroup $sub) use ($term): void {
                $like = '%' . Query::escapeLike($term) . '%';
                $sub->where('residents.search_index', 'like', mb_strtolower($like, 'UTF-8'))
                    ->orWhere('residents.full_name', 'like', $like)
                    ->orWhere('residents.apartment', 'like', $like)
                    ->orWhere('residents.phone', 'like', '%' . Query::escapeLike(preg_replace('/\D/', '', $term) ?? $term) . '%')
                    ->orWhere('residents.email', 'like', $like)
                    ->orWhere('residents.notes', 'like', $like)
                    ->orWhere('residents.building', 'like', $like)
                    ->orWhereExists(function (SubQuery $inner) use ($like): void {
                        $inner->from('cars')->whereRaw('cars.resident_id = residents.id')->where('cars.plate', 'like', $like);
                    })
                    ->orWhereExists(function (SubQuery $inner) use ($like): void {
                        $inner->from('contacts')->whereRaw('contacts.resident_id = residents.id')->where('contacts.value', 'like', $like);
                    });
            });
        }

        if (!empty($filters['building'])) {
            $q = $q->where('residents.building', '=', (string) $filters['building']);
        }
        if (isset($filters['apartment_from']) && $filters['apartment_from'] !== '' && $filters['apartment_from'] !== null) {
            $q = $q->where('residents.apartment_no', '>=', (int) $filters['apartment_from']);
        }
        if (isset($filters['apartment_to']) && $filters['apartment_to'] !== '' && $filters['apartment_to'] !== null) {
            $q = $q->where('residents.apartment_no', '<=', (int) $filters['apartment_to']);
        }
        if (!empty($filters['tag'])) {
            $needle = (string) $filters['tag'];
            $q = $q->where(function (WhereGroup $sub) use ($needle): void {
                $sub->where('residents.tags', 'like', '%' . Query::escapeLike($needle) . '%');
            });
        }
        if (!empty($filters['access'])) {
            $q = $q->where('residents.access_granted', '=', (int) Str::toBoolSafe($filters['access']));
        }
        if (!empty($filters['has_phone'])) {
            $q = $q->whereNotNull('residents.phone');
        }
        if (!empty($filters['has_email'])) {
            $q = $q->whereNotNull('residents.email');
        }
        if (!empty($filters['has_car'])) {
            $q = $q->whereExists(function (SubQuery $inner): void {
                $inner->from('cars')->whereRaw('cars.resident_id = residents.id')->where('cars.is_active', '=', 1);
            });
        }
        if (!empty($filters['no_contact'])) {
            $q = $q->whereNull('residents.phone')->whereNull('residents.email');
        }
        if (!empty($filters['created_from'])) {
            $q = $q->where('residents.created_at', '>=', date('Y-m-d 00:00:00', (int) strtotime((string) $filters['created_from'])));
        }
        if (!empty($filters['created_to'])) {
            $q = $q->where('residents.created_at', '<=', date('Y-m-d 23:59:59', (int) strtotime((string) $filters['created_to'])));
        }

        return $q;
    }

    /** @param array<string, mixed> $filters @return array{0: string, 1: string} */
    private function resolveSort(array $filters): array
    {
        $sort = (string) ($filters['sort'] ?? 'apartment');
        $dir  = strtolower((string) ($filters['dir'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        $column = self::SORTABLE[$sort] ?? self::SORTABLE['apartment'];

        return [$column, $dir];
    }

    /** @param array<int, array<string, mixed>> $rows @return array<int, Resident> */
    public function hydrateMany(array $rows, bool $withRelations = false): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $this->hydrate($row, $withRelations);
        }

        return $out;
    }

    /** @param array<string, mixed> $row */
    public function hydrate(array $row, bool $withRelations = false): Resident
    {
        $row['debt']     = isset($row['debt_total']) ? round((float) $row['debt_total'], 2) : null;
        $row['overdue']  = isset($row['overdue_total']) ? round((float) $row['overdue_total'], 2) : null;
        $row['invoices_pending'] = isset($row['invoices_pending']) ? (int) $row['invoices_pending'] : null;

        $resident = Resident::fromRow($row);
        $resident->debt    = $row['debt'];
        $resident->overdue = $row['overdue'];
        $resident->invoicesPending = $row['invoices_pending'];

        if ($withRelations && $resident->id > 0) {
            $resident->contacts = $this->contactsFor($resident->id);
            $resident->cars     = $this->carsFor($resident->id);
        }

        return $resident;
    }

    /** Bulk-load relations for a page of residents (avoids N+1). @param array<int, Resident> $residents */
    public function loadRelations(array $residents): array
    {
        $ids = array_values(array_filter(array_map(static fn (Resident $r): ?int => $r->id > 0 ? $r->id : null, $residents)));
        if ($ids === []) {
            return $residents;
        }

        $contacts = [];
        foreach ($this->db->table('contacts')->whereIn('resident_id', $ids)->orderBy('is_primary', 'desc')->orderBy('id')->get() as $row) {
            $contacts[(int) $row['resident_id']][] = $row;
        }
        $cars = [];
        foreach ($this->db->table('cars')->whereIn('resident_id', $ids)->orderBy('is_active', 'desc')->orderBy('plate')->get() as $row) {
            $cars[(int) $row['resident_id']][] = $row;
        }

        foreach ($residents as $resident) {
            $resident->contacts = $contacts[$resident->id] ?? [];
            $resident->cars     = $cars[$resident->id] ?? [];
        }

        return $residents;
    }

    /** @return array<int, array<string, mixed>> */
    public function contactsFor(int $residentId): array
    {
        return $this->db->table('contacts')
            ->where('resident_id', '=', $residentId)
            ->orderBy('is_primary', 'desc')
            ->orderBy('type')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, Car> */
    public function carsFor(int $residentId, bool $onlyActive = false): array
    {
        $q = $this->db->table('cars')->where('resident_id', '=', $residentId)->orderBy('is_active', 'desc')->orderBy('plate');
        if ($onlyActive) {
            $q = $q->where('is_active', '=', 1);
        }
        $out = [];
        foreach ($q->get() as $row) {
            $out[] = Car::fromRow($row);
        }

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function rawCarsFor(int $residentId): array
    {
        return $this->db->table('cars')->where('resident_id', '=', $residentId)->orderBy('is_active', 'desc')->orderBy('plate')->get();
    }

    public function findCarByPlate(string $plate): ?Car
    {
        $normalized = \BasIp\Support\Plate::normalize($plate);
        $row = $this->db->table('cars')->where('plate_normalized', '=', $normalized)->first();

        return $row === null ? null : Car::fromRow($row);
    }

    /** @return array<int, string> distinct buildings for the filter dropdown */
    public function buildings(): array
    {
        $rows = $this->db->table('residents')->select('building')->whereNotNull('building')->distinct()->orderBy('building')->get();

        return array_values(array_filter(array_map(static fn ($r) => (string) ($r['building'] ?? ''), $rows)));
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        $tags = [];
        foreach ($this->db->table('residents')->select('tags')->whereNotNull('tags')->get() as $row) {
            foreach ((array) \BasIp\Domain\Model\Entity::decodeJson($row['tags'] ?? null, []) as $tag) {
                $tag = Str::squash((string) $tag);
                if ($tag !== '') {
                    $tags[Str::lower($tag)] = $tag;
                }
            }
        }
        $out = array_values($tags);
        sort($out);

        return $out;
    }

    /** Apartment → resident map, used by the gate/ANPR and the Telegram bot. */
    public function apartmentIndex(): array
    {
        $out = [];
        foreach ($this->scopeActive()->select('residents.id', 'residents.apartment', 'residents.building', 'residents.full_name')->get() as $row) {
            $key = trim(($row['building'] ?? '') . '/' . $row['apartment'], '/');
            $out[$key] = ['id' => (int) $row['id'], 'name' => (string) $row['full_name']];
        }

        return $out;
    }

    /** Total outstanding debt across active residents. */
    public function totalDebt(): float
    {
        $sql = 'SELECT COALESCE(SUM(i.balance), 0) AS total FROM ' . $this->db->quoteIdentifier($this->db->prefixTable('invoices')) . ' i'
            . ' INNER JOIN ' . $this->db->quoteIdentifier($this->db->prefixTable('residents')) . ' r ON r.id = i.resident_id'
            . " WHERE i.status <> 'void' AND r.is_active = 1 AND r.deleted_at IS NULL";

        return round((float) $this->db->scalar($sql, [], 0.0), 2);
    }

    public function totalOverdue(): float
    {
        $sql = 'SELECT COALESCE(SUM(i.balance), 0) AS total FROM ' . $this->db->quoteIdentifier($this->db->prefixTable('invoices')) . ' i'
            . ' INNER JOIN ' . $this->db->quoteIdentifier($this->db->prefixTable('residents')) . ' r ON r.id = i.resident_id'
            . ' WHERE i.status <> \'void\' AND i.due_date < ? AND r.is_active = 1 AND r.deleted_at IS NULL';

        return round((float) $this->db->scalar($sql, [$this->clock->format('Y-m-d')], 0.0), 2);
    }

    public function totalCollected(?string $from = null, ?string $to = null): float
    {
        $q = $this->db->table('payments')->where('is_confirmed', '=', 1);
        if ($from !== null) {
            $q = $q->where('paid_at', '>=', $from);
        }
        if ($to !== null) {
            $q = $q->where('paid_at', '<=', $to);
        }

        return round($q->sum('amount'), 2);
    }

    /** Distribution of residents per building/apartment range for the charts. */
    public function statsByBuilding(): array
    {
        $rows = $this->scopeActive()
            ->select('residents.building', 'COUNT(*) AS cnt')
            ->groupBy('residents.building')
            ->orderBy('residents.building')
            ->get();

        return array_map(static fn (array $r): array => [
            'label' => ($r['building'] ?? null) !== null && $r['building'] !== '' ? 'Корп. ' . $r['building'] : 'Без корпуса',
            'value' => (int) $r['cnt'],
        ], $rows);
    }

    /** Sanity check used by the installer and `doctor`. */
    public function hasDuplicates(): array
    {
        $sql = 'SELECT apartment, building, full_name, COUNT(*) AS cnt FROM '
            . $this->db->quoteIdentifier($this->db->prefixTable('residents'))
            . ' WHERE deleted_at IS NULL GROUP BY apartment, building, full_name HAVING COUNT(*) > 1';
        try {
            return $this->db->select($sql);
        } catch (\Throwable) {
            return [];
        }
    }

    public function money(int|float|null $minor): string
    {
        return Money::format((float) $minor);
    }

    /** Normalise a phone for storage. */
    public static function normalizePhone(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return Phone::normalize($value);
    }
}
