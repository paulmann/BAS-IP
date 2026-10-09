<?php

declare(strict_types=1);

namespace BasIp\Services;

use BasIp\Auth\User;
use BasIp\Core\Clock;
use BasIp\Core\Config;
use BasIp\Core\Crypto;
use BasIp\Core\Request;
use BasIp\Db\Database;
use BasIp\Domain\Model\Contact;
use BasIp\Domain\Model\Resident;
use BasIp\Domain\Repository\ResidentRepository;
use BasIp\Support\Money;
use BasIp\Support\Phone;
use BasIp\Support\Plate;
use BasIp\Support\Str;
use BasIp\Support\Validator;
use Throwable;

/**
 * Write side of the resident database.
 *
 * Handles the nested aggregate (resident → several contacts → several cars) in
 * one transaction, keeps the denormalised `phone` / `email` / `search_index` /
 * `balance` columns in sync, and writes an audit record for every change.
 */
final class ResidentService
{
    public function __construct(
        private readonly Database $db,
        private readonly Config $config,
        private readonly Clock $clock,
        private readonly ResidentRepository $residents,
        private readonly AuditService $audit,
        private readonly BillingService $billing,
    ) {
    }

    /** @param array<string, mixed> $input @return array{ok: bool, id?: int, errors?: array<string, array<int, string>>} */
    public function create(array $input, ?User $actor = null, ?Request $request = null): array
    {
        $validator = $this->validator($input);
        if (!$validator->validate()) {
            return ['ok' => false, 'errors' => $validator->errors()];
        }
        $data = $this->mergeNested($validator->validated(), $input);

        $now = $this->clock->format();
        $row = $this->toRow($data, $now);
        $row['uuid'] = Crypto::uuidv4();
        $row['created_at'] = $now;
        $row['updated_at'] = $now;
        $row['created_by'] = $actor?->id;

        try {
            $id = $this->db->transaction(function () use ($row, $data, $actor, $request): int {
                $id = $this->db->table('residents')->insertGetId($row);
                $this->replaceContacts($id, (array) ($data['contacts'] ?? []));
                $this->replaceCars($id, (array) ($data['cars'] ?? []));
                $this->syncPrimaryContacts($id);
                $this->touchSearchIndex($id);
                $this->audit->changed('resident.create', $actor, 'resident', $id, $this->labelOf($row), [], $row, $request);

                return $id;
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['_' => ['Не удалось сохранить жителя: ' . $e->getMessage()]]];
        }

        return ['ok' => true, 'id' => $id];
    }

    /** @param array<string, mixed> $input @return array{ok: bool, errors?: array<string, array<int, string>>} */
    public function update(int $id, array $input, ?User $actor = null, ?Request $request = null): array
    {
        $existing = $this->db->table('residents')->where('id', '=', $id)->first();
        if ($existing === null) {
            return ['ok' => false, 'errors' => ['_' => ['Житель не найден.']]];
        }

        $validator = $this->validator($input, false);
        if (!$validator->validate()) {
            return ['ok' => false, 'errors' => $validator->errors()];
        }
        $data = $this->mergeNested($validator->validated(), $input);
        $now  = $this->clock->format();
        $row  = $this->toRow($data, $now, $existing);
        $row['updated_at'] = $now;
        $row['updated_by'] = $actor?->id;

        try {
            $this->db->transaction(function () use ($id, $row, $data, $existing, $actor, $request): void {
                $this->db->table('residents')->where('id', '=', $id)->update($row);
                if (array_key_exists('contacts', $data)) {
                    $this->replaceContacts($id, (array) $data['contacts']);
                }
                if (array_key_exists('cars', $data)) {
                    $this->replaceCars($id, (array) $data['cars']);
                }
                $this->syncPrimaryContacts($id);
                $this->touchSearchIndex($id);

                $changed = [];
                foreach ($row as $key => $value) {
                    if (($existing[$key] ?? null) != $value) {
                        $changed[$key] = $value;
                    }
                }
                if ($changed !== []) {
                    $this->audit->changed('resident.update', $actor, 'resident', $id, (string) ($existing['full_name'] ?? '#' . $id), $existing, array_merge($existing, $row), $request);
                }
            });
        } catch (Throwable $e) {
            return ['ok' => false, 'errors' => ['_' => ['Не удалось сохранить изменения: ' . $e->getMessage()]]];
        }

        return ['ok' => true];
    }

    public function delete(int $id, bool $hard = false, ?User $actor = null, ?Request $request = null): bool
    {
        $resident = $this->residents->findById($id, true);
        if ($resident === null) {
            return false;
        }

        return $this->db->transaction(function () use ($id, $hard, $actor, $request, $resident): bool {
            if ($hard) {
                $this->db->table('gate_events')->where('resident_id', '=', $id)->update(['resident_id' => null]);
                $this->db->table('guest_passes')->where('resident_id', '=', $id)->update(['resident_id' => null]);
                $this->db->table('users')->where('resident_id', '=', $id)->update(['resident_id' => null, 'is_active' => 0]);
                $this->db->table('residents')->where('id', '=', $id)->delete();
                $action = 'resident.delete_hard';
            } else {
                $this->db->table('residents')->where('id', '=', $id)->update([
                    'deleted_at'  => $this->clock->format(),
                    'is_active'   => 0,
                    'updated_at'  => $this->clock->format(),
                    'updated_by'  => $actor?->id,
                ]);
                $action = 'resident.delete';
            }
            $this->audit->record($action, $actor, [
                'subject_type' => 'resident', 'subject_id' => (string) $id,
                'subject_label' => $resident->displayName(), 'is_security' => $hard,
            ], $request);

            return true;
        });
    }

    public function restore(int $id, ?User $actor = null, ?Request $request = null): bool
    {
        $affected = $this->db->table('residents')->where('id', '=', $id)->update([
            'deleted_at' => null,
            'is_active'  => 1,
            'archived_at' => null,
            'updated_at' => $this->clock->format(),
            'updated_by' => $actor?->id,
        ]);
        if ($affected > 0) {
            $this->audit->info('resident.restore', $actor, 'resident', (string) $id, null, $request);
        }

        return $affected > 0;
    }

    /* ─────────────────────────── flags & bulk actions ─────────────────────────── */

    public function setActive(int $id, bool $active, ?User $actor = null, ?Request $request = null): bool
    {
        return $this->setFlag($id, 'is_active', $active, $active ? 'resident.activate' : 'resident.deactivate', $actor, $request);
    }

    public function setAccess(int $id, bool $granted, ?User $actor = null, ?Request $request = null): bool
    {
        return $this->setFlag($id, 'access_granted', $granted, $granted ? 'resident.access_grant' : 'resident.access_revoke', $actor, $request);
    }

    public function setBilled(int $id, bool $billed, ?User $actor = null, ?Request $request = null): bool
    {
        return $this->setFlag($id, 'is_billed', $billed, 'resident.billing_flag', $actor, $request);
    }

    private function setFlag(int $id, string $column, bool $value, string $action, ?User $actor, ?Request $request): bool
    {
        $before = $this->db->table('residents')->where('id', '=', $id)->value($column);
        $affected = $this->db->table('residents')->where('id', '=', $id)->update([
            $column => $value ? 1 : 0,
            'updated_at' => $this->clock->format(),
            'updated_by' => $actor?->id,
        ]);
        if ($affected > 0) {
            $this->audit->changed($action, $actor, 'resident', $id, null, [$column => $before], [$column => $value], $request);
        }

        return $affected > 0;
    }

    /**
     * Mass actions. Always operate on **active** residents unless the caller
     * explicitly passes ids (which are then still filtered through `onlyActive`).
     *
     * @param array<int, int> $ids
     * @return array{affected: int, skipped: int}
     */
    public function bulk(string $action, array $ids, array $payload = [], ?User $actor = null, ?Request $request = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return ['affected' => 0, 'skipped' => 0];
        }

        $onlyActive = (bool) ($payload['only_active'] ?? true);
        $q = $this->db->table('residents')->whereIn('id', $ids)->whereNull('deleted_at');
        if ($onlyActive) {
            $q = $q->where('is_active', '=', 1);
        }
        $targets = $q->pluck('id');
        $skipped = count($ids) - count($targets);

        $affected = 0;
        $now = $this->clock->format();

        $this->db->transaction(function () use ($action, $targets, $payload, $actor, $request, $now, &$affected): void {
            foreach ($targets as $id) {
                $id = (int) $id;
                switch ($action) {
                    case 'activate':
                        $affected += (int) $this->setActive($id, true, $actor, $request);
                        break;
                    case 'deactivate':
                        $affected += (int) $this->setActive($id, false, $actor, $request);
                        break;
                    case 'grant_access':
                        $affected += (int) $this->setAccess($id, true, $actor, $request);
                        break;
                    case 'revoke_access':
                        $affected += (int) $this->setAccess($id, false, $actor, $request);
                        break;
                    case 'bill':
                        $affected += (int) $this->setBilled($id, true, $actor, $request);
                        break;
                    case 'unbill':
                        $affected += (int) $this->setBilled($id, false, $actor, $request);
                        break;
                    case 'delete':
                        $affected += (int) $this->delete($id, (bool) ($payload['hard'] ?? false), $actor, $request);
                        break;
                    case 'tag':
                        $tag = Str::squash((string) ($payload['tag'] ?? ''));
                        if ($tag === '') {
                            break;
                        }
                        $current = (array) \BasIp\Domain\Model\Entity::decodeJson(
                            $this->db->table('residents')->where('id', '=', $id)->value('tags'),
                            []
                        );
                        if (($payload['mode'] ?? 'add') === 'remove') {
                            $current = array_values(array_filter($current, static fn ($t) => Str::lower((string) $t) !== Str::lower($tag)));
                        } else {
                            $current[] = $tag;
                            $current = array_values(array_unique(array_map(static fn ($t) => Str::squash((string) $t), $current)));
                        }
                        $this->db->table('residents')->where('id', '=', $id)->update([
                            'tags' => json_encode($current, JSON_UNESCAPED_UNICODE), 'updated_at' => $now,
                        ]);
                        $affected++;
                        break;
                    case 'note':
                        $note = trim((string) ($payload['note'] ?? ''));
                        if ($note === '') {
                            break;
                        }
                        $existing = (string) $this->db->table('residents')->where('id', '=', $id)->value('notes', '');
                        $merged = $existing === '' ? $note : $existing . "\n" . $note;
                        $this->db->table('residents')->where('id', '=', $id)->update([
                            'notes' => $merged, 'updated_at' => $now,
                        ]);
                        $affected++;
                        break;
                    case 'recalc_balance':
                        $this->billing->recalculateBalance($id);
                        $affected++;
                        break;
                }
            }
        });

        if ($affected > 0) {
            $this->audit->record('resident.bulk.' . $action, $actor, [
                'subject_type' => 'resident',
                'subject_label' => sprintf('%d %s', count($targets), Str::plural(count($targets), 'запись', 'записи', 'записей')),
                'meta' => ['action' => $action, 'ids' => $targets, 'skipped' => $skipped, 'payload' => Arr_safe($payload)],
            ], $request);
        }

        return ['affected' => $affected, 'skipped' => $skipped];
    }

    /* ─────────────────────────── contacts & cars ─────────────────────────── */

    /** @param array<int, array<string, mixed>> $contacts */
    public function replaceContacts(int $residentId, array $contacts): void
    {
        $this->db->table('contacts')->where('resident_id', '=', $residentId)->delete();
        $now = $this->clock->format();
        $seenPrimary = [];
        $rows = [];

        foreach ($contacts as $contact) {
            $type  = strtolower(trim((string) ($contact['type'] ?? 'phone')));
            $value = Str::squash((string) ($contact['value'] ?? ''));
            if ($value === '' || !in_array($type, Contact::TYPES, true)) {
                continue;
            }
            $isPrimary = Str::toBoolSafe($contact['is_primary'] ?? false);
            if ($isPrimary && ($seenPrimary[$type] ?? false)) {
                $isPrimary = false;              // only one primary per type
            }
            $seenPrimary[$type] = $seenPrimary[$type] ?? $isPrimary;

            $rows[] = [
                'resident_id'      => $residentId,
                'type'             => $type,
                'value'            => mb_substr(Contact::normalizeValue($type, $value) ?: $value, 0, 190),
                // `value` holds the canonical form (E.164 for phones, lowercase
                // for e-mails, @handle for Telegram) so the denormalised
                // residents.phone / residents.email columns and the search index
                // can never disagree with the contact row.
                'value_normalized' => mb_substr(Contact::normalizeValue($type, $value), 0, 190),
                'label'            => isset($contact['label']) && $contact['label'] !== '' ? mb_substr((string) $contact['label'], 0, 40) : null,
                'is_primary'       => $isPrimary ? 1 : 0,
                'notify'           => Str::toBoolSafe($contact['notify'] ?? true) ? 1 : 0,
                'verified'         => Str::toBoolSafe($contact['verified'] ?? false) ? 1 : 0,
                'created_at'       => $now,
            ];
        }

        // If nothing was marked primary, promote the first of each type.
        foreach (array_unique(array_column($rows, 'type')) as $type) {
            $hasPrimary = false;
            foreach ($rows as $row) {
                if ($row['type'] === $type && $row['is_primary'] === 1) {
                    $hasPrimary = true;
                    break;
                }
            }
            if (!$hasPrimary) {
                foreach ($rows as $i => $row) {
                    if ($row['type'] === $type) {
                        $rows[$i]['is_primary'] = 1;
                        break;
                    }
                }
            }
        }

        if ($rows !== []) {
            $this->db->table('contacts')->insertBatch($rows);
        }
    }

    /** @param array<int, array<string, mixed>> $cars */
    public function replaceCars(int $residentId, array $cars): void
    {
        $this->db->table('cars')->where('resident_id', '=', $residentId)->delete();
        $now  = $this->clock->format();
        $rows = [];

        foreach ($cars as $car) {
            $plate = Str::squash((string) ($car['plate'] ?? ''));
            $brand = Str::squash((string) ($car['brand'] ?? ''));
            if ($plate === '' && $brand === '') {
                continue;
            }
            $rows[] = [
                'resident_id'      => $residentId,
                'plate'            => mb_substr($plate, 0, 32),
                'plate_normalized' => mb_substr(Plate::normalize($plate), 0, 32),
                'brand'            => $brand !== '' ? mb_substr($brand, 0, 64) : null,
                'model'            => ($car['model'] ?? '') !== '' ? mb_substr(Str::squash((string) $car['model']), 0, 64) : null,
                'color'            => ($car['color'] ?? '') !== '' ? mb_substr(Str::squash((string) $car['color']), 0, 32) : null,
                'year'             => isset($car['year']) && $car['year'] !== '' ? max(1900, min(2200, (int) $car['year'])) : null,
                'type'             => $this->carType($car['type'] ?? null),
                'rfid_tag'         => ($car['rfid_tag'] ?? '') !== '' ? mb_substr(Str::squash((string) $car['rfid_tag']), 0, 64) : null,
                'sticker'          => ($car['sticker'] ?? '') !== '' ? mb_substr(Str::squash((string) $car['sticker']), 0, 32) : null,
                'is_active'        => Str::toBoolSafe($car['is_active'] ?? true) ? 1 : 0,
                'valid_from'       => $this->parseDate($car['valid_from'] ?? null),
                'valid_until'      => $this->parseDate($car['valid_until'] ?? null),
                'notes'            => ($car['notes'] ?? '') !== '' ? mb_substr(trim((string) $car['notes']), 0, 2000) : null,
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        }

        if ($rows !== []) {
            $this->db->table('cars')->insertBatch($rows);
        }
    }

    /** Validate a car type, falling back to `car` for anything unknown. */
    private function carType(mixed $value): string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($value, ['car', 'suv', 'motorcycle', 'truck', 'ev', 'other'], true) ? $value : 'car';
    }

    /** Copy the primary phone/e-mail into the denormalised columns. */
    public function syncPrimaryContacts(int $residentId): void
    {
        $update = [];
        foreach (['phone', 'email'] as $type) {
            $row = $this->db->table('contacts')
                ->where('resident_id', '=', $residentId)
                ->where('type', '=', $type)
                ->orderBy('is_primary', 'desc')
                ->orderBy('id')
                ->first();
            if ($row !== null) {
                // Prefer the canonical form; fall back to what was typed.
                $value = trim((string) ($row['value_normalized'] ?? ''));
                if ($value === '') {
                    $value = (string) $row['value'];
                }
                $update[$type] = mb_substr($value, 0, $type === 'phone' ? 32 : 190);
            }
        }
        if ($update !== []) {
            $this->db->table('residents')->where('id', '=', $residentId)->update($update);
        }
    }

    /** Lowercase haystack used by the search filter. */
    public function touchSearchIndex(int $residentId): void
    {
        $row = $this->db->table('residents')->where('id', '=', $residentId)->first();
        if ($row === null) {
            return;
        }
        $parts = [
            $row['full_name'] ?? '',
            $row['last_name'] ?? '', $row['first_name'] ?? '', $row['patronymic'] ?? '',
            'кв ' . ($row['apartment'] ?? ''), 'кв. ' . ($row['apartment'] ?? ''),
            $row['apartment'] ?? '', $row['building'] ?? '', $row['entrance'] ?? '',
            preg_replace('/\D/', '', (string) ($row['phone'] ?? '')) ?? '',
            $row['email'] ?? '', $row['external_id'] ?? '',
        ];
        foreach ($this->db->table('cars')->where('resident_id', '=', $residentId)->pluck('plate') as $plate) {
            $parts[] = (string) $plate;
            $parts[] = Plate::normalize((string) $plate);
        }
        $index = mb_substr(mb_strtolower(Str::squash(implode(' ', array_filter($parts))), 'UTF-8'), 0, 512);
        $this->db->table('residents')->where('id', '=', $residentId)->update(['search_index' => $index]);
    }

    /* ─────────────────────────── validation & mapping ─────────────────────────── */

    /** @param array<string, mixed> $input */
    public function validator(array $input, bool $creating = true): Validator
    {
        $rules = [
            'last_name'    => ($creating ? 'required|' : '') . 'max:120',
            'first_name'   => 'nullable|max:120',
            'patronymic'   => 'nullable|max:120',
            'full_name'    => 'nullable|max:255',
            'apartment'    => ($creating ? 'required|' : '') . 'max:24',
            'apartment_no' => 'nullable|integer|between:0,1000000',
            'building'     => 'nullable|max:24',
            'entrance'     => 'nullable|max:16',
            'floor'        => 'nullable|integer|between:-10,500',
            'area'         => 'nullable|numeric|between:0,100000',
            'persons'      => 'nullable|integer|between:1,50',
            'share'        => 'nullable|numeric|between:0,1000',
            'role_in_home' => 'nullable|in:owner,tenant,relative,manager,other',
            'phone'        => 'nullable|phone|max:32',
            'email'        => 'nullable|email|max:190',
            'notes'        => 'nullable|max:8000',
        ];

        return Validator::make($input, $rules, [
            'last_name'    => 'Фамилия',
            'first_name'   => 'Имя',
            'patronymic'   => 'Отчество',
            'full_name'    => 'ФИО',
            'apartment'    => 'Номер квартиры',
            'apartment_no' => 'Номер квартиры (число)',
            'building'     => 'Корпус',
            'entrance'     => 'Подъезд',
            'floor'        => 'Этаж',
            'area'         => 'Площадь',
            'persons'      => 'Проживающих',
            'share'        => 'Доля',
            'role_in_home' => 'Статус',
            'phone'        => 'Телефон',
            'email'        => 'E-mail',
            'notes'        => 'Комментарий',
        ]);
    }

    /**
     * The validator only returns scalar fields it knows about; the nested
     * aggregate (contacts / cars / tags) has to be carried through separately,
     * otherwise an import or a form submit would silently drop them.
     *
     * @param array<string, mixed> $validated
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function mergeNested(array $validated, array $input): array
    {
        foreach (['contacts', 'cars', 'tags', 'meta', 'external_id'] as $key) {
            if (array_key_exists($key, $input) && !array_key_exists($key, $validated)) {
                $validated[$key] = $input[$key];
            }
        }

        return $validated;
    }

    /** @param array<string, mixed> $data @param array<string, mixed>|null $existing @return array<string, mixed> */
    private function toRow(array $data, string $now, ?array $existing = null): array
    {
        $lastName   = Str::squash((string) ($data['last_name'] ?? $existing['last_name'] ?? ''));
        $firstName  = Str::squash((string) ($data['first_name'] ?? $existing['first_name'] ?? ''));
        $patronymic = Str::squash((string) ($data['patronymic'] ?? $existing['patronymic'] ?? ''));
        $fullName   = Str::squash((string) ($data['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = Str::squash(trim($lastName . ' ' . $firstName . ' ' . $patronymic));
        }
        $apartment = Str::squash((string) ($data['apartment'] ?? $existing['apartment'] ?? ''));

        $row = [
            'last_name'   => mb_substr($lastName, 0, 120),
            'first_name'  => mb_substr($firstName, 0, 120),
            'patronymic'  => $patronymic !== '' ? mb_substr($patronymic, 0, 120) : null,
            'full_name'   => mb_substr($fullName, 0, 255),
            'apartment'   => mb_substr($apartment, 0, 24),
            'apartment_no' => $this->apartmentNumber($apartment, $data['apartment_no'] ?? null),
            'building'    => isset($data['building']) && $data['building'] !== '' ? mb_substr(Str::squash((string) $data['building']), 0, 24) : ($existing['building'] ?? null),
            'entrance'    => isset($data['entrance']) && $data['entrance'] !== '' ? mb_substr(Str::squash((string) $data['entrance']), 0, 16) : ($existing['entrance'] ?? null),
            'floor'       => isset($data['floor']) && $data['floor'] !== '' && $data['floor'] !== null ? (int) $data['floor'] : ($existing['floor'] ?? null),
            'area'        => round((float) ($data['area'] ?? $existing['area'] ?? 0), 2),
            'persons'     => max(1, (int) ($data['persons'] ?? $existing['persons'] ?? 1)),
            'share'       => round((float) ($data['share'] ?? $existing['share'] ?? 1), 4),
            'role_in_home' => (string) ($data['role_in_home'] ?? $existing['role_in_home'] ?? 'owner'),
            'phone'       => ($data['phone'] ?? null) !== null && $data['phone'] !== '' ? Phone::normalize((string) $data['phone']) : ($existing['phone'] ?? null),
            'email'       => ($data['email'] ?? null) !== null && $data['email'] !== '' ? mb_strtolower(trim((string) $data['email'])) : ($existing['email'] ?? null),
            'notes'       => ($data['notes'] ?? null) !== null ? trim((string) $data['notes']) : ($existing['notes'] ?? null),
        ];

        foreach (['access_granted' => true, 'is_active' => true, 'is_billed' => true, 'allow_guest_pass' => true, 'notify_enabled' => true] as $flag => $default) {
            if (array_key_exists($flag, $data)) {
                $row[$flag] = Str::toBoolSafe($data[$flag]) ? 1 : 0;
            } elseif ($existing === null) {
                $row[$flag] = $default ? 1 : 0;
            }
        }

        if (array_key_exists('tags', $data)) {
            $tags = is_array($data['tags'])
                ? $data['tags']
                : (preg_split('/[,;\n]+/', (string) $data['tags']) ?: []);
            $tags = array_values(array_unique(array_filter(
                array_map(static fn ($t): string => Str::squash((string) $t), $tags),
                static fn (string $t): bool => $t !== ''
            )));
            $row['tags'] = $tags === [] ? null : (string) json_encode($tags, JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('external_id', $data)) {
            $row['external_id'] = $data['external_id'] !== '' ? mb_substr((string) $data['external_id'], 0, 64) : null;
        }
        if (array_key_exists('meta', $data) && $data['meta'] !== null) {
            $row['meta'] = is_string($data['meta']) ? $data['meta'] : (string) json_encode($data['meta'], JSON_UNESCAPED_UNICODE);
        }

        return $row;
    }

    /** "12А" → 12, "12-б" → 12, "" → null. */
    private function apartmentNumber(string $apartment, mixed $explicit): ?int
    {
        if ($explicit !== null && $explicit !== '' && is_numeric($explicit)) {
            return (int) $explicit;
        }
        if (preg_match('/(\d+)/', $apartment, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function parseDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $ts = is_numeric($value) ? (int) $value : strtotime((string) $value);

        return $ts === false || $ts <= 0 ? null : date('Y-m-d H:i:s', $ts);
    }

    /** @param array<string, mixed> $row */
    private function labelOf(array $row): string
    {
        return Str::squash(($row['full_name'] ?? '') . ' · кв. ' . ($row['apartment'] ?? ''));
    }

    /** Refresh the cached balance for everybody (used after imports). */
    public function recalculateAllBalances(): int
    {
        $count = 0;
        $ids = $this->db->table('residents')->whereNull('deleted_at')->pluck('id');
        foreach ($ids as $id) {
            $this->billing->recalculateBalance((int) $id);
            $count++;
        }

        return $count;
    }

    public function ensureSearchIndexes(): int
    {
        $count = 0;
        foreach ($this->db->table('residents')->whereNull('search_index')->pluck('id') as $id) {
            $this->touchSearchIndex((int) $id);
            $count++;
        }

        return $count;
    }
}

/**
 * Audit payloads must never contain secrets.
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function Arr_safe(array $payload): array
{
    return \BasIp\Support\Arr::redact($payload, ['password', 'token', 'secret', 'otp']);
}
