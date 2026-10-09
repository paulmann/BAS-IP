<?php

/**
 * Demo data seeder.
 *
 * Creates a realistic small building: residents with several phone numbers and
 * e-mails, several cars each, an issued charge with invoices, some payments
 * (including partial and overdue), guest passes and gate events.
 *
 *   php bin/bas-ip db:seed --residents=24
 *   php bin/bas-ip install --demo
 *
 * @return callable(Database, Clock, int): int
 */

declare(strict_types=1);

use BasIp\Core\Clock;
use BasIp\Core\Crypto;
use BasIp\Db\Database;

return static function (Database $db, Clock $clock, int $count = 24): int {
    $lastNames = ['Иванов', 'Петрова', 'Сидоров', 'Кузнецова', 'Смирнов', 'Васильева', 'Соколов', 'Михайлова',
        'Фёдоров', 'Морозова', 'Волков', 'Алексеева', 'Лебедев', 'Семёнова', 'Егоров', 'Павлова',
        'Козлов', 'Степанова', 'Николаев', 'Орлова', 'Андреев', 'Макарова', 'Захаров', 'Зайцева'];
    $firstNames = ['Иван', 'Мария', 'Алексей', 'Ольга', 'Дмитрий', 'Анна', 'Сергей', 'Елена',
        'Андрей', 'Наталья', 'Павел', 'Екатерина', 'Николай', 'Татьяна', 'Виктор', 'Юлия'];
    $patronymics = ['Иванович', 'Петрович', 'Сергеевич', 'Андреевич', 'Ивановна', 'Петровна', 'Сергеевна', 'Николаевич'];
    $brands = [['Skoda', 'Octavia'], ['Lada', 'Vesta'], ['Kia', 'Rio'], ['Hyundai', 'Solaris'],
        ['Toyota', 'Camry'], ['Volkswagen', 'Polo'], ['Renault', 'Duster'], ['Chery', 'Tiggo 7'],
        ['Haval', 'Jolion'], ['BMW', 'X5'], ['Geely', 'Coolray'], ['Nissan', 'Qashqai']];
    $colors = ['белый', 'чёрный', 'серебристый', 'синий', 'красный', 'серый'];
    $purposes = ['Гости', 'Курьер', 'Ремонт', 'Доставка еды', 'Такси', 'Клининг', 'Родственники'];
    $letters = 'АВЕКМНОРСТУХ';

    if ($db->table('residents')->count() > 0 && $count === 24) {
        // Already seeded — do not duplicate on a re-run.
        return 0;
    }

    $now = $clock->now();
    $today = $clock->format('Y-m-d');
    $periodStart = date('Y-m-01', strtotime('first day of previous month', $now));
    $periodEnd = date('Y-m-t', strtotime($periodStart));
    $periodLabel = (function (string $start): string {
        $months = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

        return ($months[(int) date('n', strtotime($start))] ?? '') . ' ' . date('Y', strtotime($start));
    })($periodStart);

    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $apartment = (string) ($i + 1);
        $last = $lastNames[$i % count($lastNames)];
        $first = $firstNames[$i % count($firstNames)];
        $middle = $patronymics[$i % count($patronymics)];
        $fullName = trim($last . ' ' . $first . ' ' . $middle);
        $phone = '+79' . str_pad((string) random_int(10000000, 99999999), 9, '0', STR_PAD_LEFT);
        $phone2 = '+79' . str_pad((string) random_int(10000000, 99999999), 9, '0', STR_PAD_LEFT);
        $email = \BasIp\Support\Str::transliterate(strtolower($last)) . '.' . strtolower(substr(\BasIp\Support\Str::transliterate($first), 0, 1)) . $apartment . '@example.com';
        $isActive = $i % 11 !== 7;                 // ~9% отключены
        $access = $i % 17 !== 5;                   // несколько без доступа
        $billed = $i % 13 !== 3;

        $id = $db->table('residents')->insertGetId([
            'uuid' => Crypto::uuidv4(),
            'last_name' => $last, 'first_name' => $first, 'patronymic' => $middle,
            'full_name' => $fullName,
            'search_index' => mb_strtolower($fullName . ' кв ' . $apartment . ' ' . preg_replace('/\D/', '', $phone) . ' ' . $email),
            'apartment' => $apartment, 'apartment_no' => (int) $apartment,
            'building' => (string) (intdiv($i, 12) + 1), 'entrance' => (string) ($i % 4 + 1), 'floor' => $i % 9 + 1,
            'area' => round(28 + ($i % 7) * 11.5, 2),
            'persons' => 1 + $i % 4,
            'share' => 1.0,
            'role_in_home' => $i % 5 === 4 ? 'tenant' : 'owner',
            'phone' => $phone, 'email' => $email,
            'access_granted' => $access ? 1 : 0,
            'is_active' => $isActive ? 1 : 0,
            'is_billed' => $billed ? 1 : 0,
            'allow_guest_pass' => 1,
            'notify_enabled' => $i % 9 !== 8 ? 1 : 0,
            'notes' => $i % 6 === 0 ? 'Просил напоминать об оплате за 3 дня.' : null,
            'tags' => $i % 7 === 0 ? (string) json_encode(['староста', 'актив'], JSON_UNESCAPED_UNICODE) : null,
            'created_at' => date('Y-m-d H:i:s', $now - random_int(30, 900) * 86400),
            'updated_at' => date('Y-m-d H:i:s', $now - random_int(1, 30) * 86400),
        ]);
        $ids[] = $id;

        // ── контакты: несколько телефонов и почт на человека ───────────────
        $contacts = [
            ['type' => 'phone', 'value' => $phone, 'label' => 'мобильный', 'is_primary' => 1, 'notify' => 1],
        ];
        if ($i % 2 === 0) {
            $contacts[] = ['type' => 'phone', 'value' => $phone2, 'label' => 'второй', 'is_primary' => 0, 'notify' => 1];
        }
        if ($i % 3 === 0) {
            $contacts[] = ['type' => 'phone', 'value' => '+7495' . str_pad((string) random_int(1000000, 9999999), 7, '0'), 'label' => 'домашний', 'is_primary' => 0, 'notify' => 0];
        }
        $contacts[] = ['type' => 'email', 'value' => $email, 'label' => 'основная', 'is_primary' => 1, 'notify' => 1];
        if ($i % 4 === 0) {
            $contacts[] = ['type' => 'email', 'value' => 'work.' . strtolower(substr($email, 0, 6)) . '@company.example', 'label' => 'рабочая', 'is_primary' => 0, 'notify' => 0];
        }
        if ($i % 2 === 1) {
            $contacts[] = ['type' => 'telegram', 'value' => '@' . strtolower(\BasIp\Support\Str::transliterate($last)) . $apartment, 'label' => 'telegram', 'is_primary' => 1, 'notify' => 1];
        }
        foreach ($contacts as $contact) {
            $db->table('contacts')->insert($contact + [
                'resident_id' => $id,
                'value_normalized' => $contact['type'] === 'phone'
                    ? \BasIp\Support\Phone::normalize($contact['value'])
                    : ($contact['type'] === 'email' ? strtolower($contact['value']) : $contact['value']),
                'verified' => $i % 3 === 0 ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s', $now),
            ]);
        }

        // ── автомобили: от одного до трёх на жителя ───────────────────────
        $cars = 1 + $i % 3;
        for ($k = 0; $k < $cars; $k++) {
            [$brand, $model] = $brands[random_int(0, count($brands) - 1)];
            // mb_substr, not []: Russian letters are 2 bytes in UTF-8 and byte
            // indexing would produce invalid UTF-8 (MySQL rejects it outright).
            $pick = static fn () => mb_substr($letters, random_int(0, mb_strlen($letters, 'UTF-8') - 1), 1, 'UTF-8');
            $plate = $pick()
                . str_pad((string) random_int(1, 999), 3, '0', STR_PAD_LEFT)
                . $pick() . $pick()
                . random_int(10, 199);
            $db->table('cars')->insert([
                'resident_id' => $id,
                'plate' => $plate,
                'plate_normalized' => \BasIp\Support\Plate::normalize($plate),
                'brand' => $brand, 'model' => $model,
                'color' => $colors[random_int(0, count($colors) - 1)],
                'year' => random_int(2010, 2025),
                'type' => 'car',
                'is_active' => $k === 0 || $i % 5 !== 4 ? 1 : 0,
                'rfid_tag' => $k === 0 && $i % 3 === 0 ? 'RFID-' . strtoupper(bin2hex(random_bytes(4))) : null,
                'created_at' => date('Y-m-d H:i:s', $now),
                'updated_at' => date('Y-m-d H:i:s', $now),
            ]);
        }
    }

    // ── начисление за прошлый месяц + счета ──────────────────────────────
    $billableIds = $db->table('residents')->where('is_active', '=', 1)->where('is_billed', '=', 1)->whereNull('deleted_at')->pluck('id');
    $total = 15000.00;
    $parts = \BasIp\Support\Money::split((int) round($total * 100), count($billableIds));

    $chargeId = $db->table('charges')->insertGetId([
        'uuid' => Crypto::uuidv4(),
        'number' => 'НАЧ-' . date('Y-m', strtotime($periodStart)) . '-0001',
        'title' => 'Обслуживание шлагбаума, ' . $periodLabel,
        'description' => 'Демо-начисление: обслуживание привода, видеонаблюдение, связь, ТО.',
        'period_start' => $periodStart, 'period_end' => $periodEnd, 'period_label' => $periodLabel,
        'total_amount' => $total, 'active_count' => count($billableIds),
        'per_unit_amount' => round($total / max(1, count($billableIds)), 2),
        'allocation' => 'equal', 'due_days' => 14,
        'due_date' => date('Y-m-d', strtotime($periodEnd . ' +14 days')),
        'message' => "Здравствуйте, {name}!\n\nНачислена оплата за обслуживание шлагбаума за {period}: {amount}.\nСрок оплаты: {due_date}.\n\nОплатить: {pay_link}",
        'pay_link' => 'https://pay.example.com/barrier?apt={apartment}&amount={amount}',
        'status' => 'issued', 'issued_at' => date('Y-m-d H:i:s', strtotime($periodEnd)),
        'created_at' => date('Y-m-d H:i:s', strtotime($periodEnd)),
        'updated_at' => date('Y-m-d H:i:s', $now),
    ]);

    $invoiceSum = 0.0;
    foreach ($billableIds as $index => $residentId) {
        $amount = \BasIp\Support\Money::toFloat((int) $parts[$index]);
        $invoiceSum += $amount;
        $resident = $db->table('residents')->where('id', '=', (int) $residentId)->first();
        $db->table('invoices')->insertGetId([
            'uuid' => Crypto::uuidv4(),
            'number' => sprintf('СЧ-%s-%05d', date('Y', strtotime($periodStart)), $index + 1),
            'charge_id' => $chargeId,
            'resident_id' => (int) $residentId,
            'kind' => 'regular',
            'amount' => $amount, 'paid_amount' => 0.0, 'balance' => $amount,
            'period_start' => $periodStart, 'period_end' => $periodEnd, 'period_label' => $periodLabel,
            'due_date' => date('Y-m-d', strtotime($periodEnd . ' +14 days')),
            'issued_date' => $periodEnd,
            'status' => 'pending',
            'pay_link' => sprintf('https://pay.example.com/barrier?apt=%s&amount=%s', rawurlencode((string) ($resident['apartment'] ?? '')), number_format($amount, 2, '.', '')),
            'issued_at' => date('Y-m-d H:i:s', strtotime($periodEnd)),
            'created_at' => date('Y-m-d H:i:s', strtotime($periodEnd)),
            'updated_at' => date('Y-m-d H:i:s', $now),
        ]);
    }
    $db->table('charges')->where('id', '=', $chargeId)->update([
        'invoices_total' => count($billableIds), 'invoices_amount' => round($invoiceSum, 2),
    ]);

    // ── оплаты: часть оплачена полностью, часть частично ─────────────────
    $paidCount = 0;
    $invoices = $db->table('invoices')->where('charge_id', '=', $chargeId)->get();
    foreach ($invoices as $offset => $invoice) {
        $roll = $offset % 5;
        if ($roll > 2) {
            continue;                       // 3/5 остаются неоплаченными
        }
        $amount = (float) $invoice['amount'];
        $payAmount = $roll === 2 ? round($amount / 2, 2) : $amount;
        $paidAt = date('Y-m-d', strtotime($periodEnd . ' +' . random_int(1, 25) . ' days'));

        $paymentId = $db->table('payments')->insertGetId([
            'uuid' => Crypto::uuidv4(),
            'resident_id' => (int) $invoice['resident_id'],
            'invoice_id' => (int) $invoice['id'],
            'charge_id' => $chargeId,
            'amount' => $payAmount,
            'paid_at' => $paidAt,
            'method' => ['transfer', 'card', 'sbp', 'cash'][$roll % 4],
            'reference' => 'ПЛ-' . strtoupper(bin2hex(random_bytes(3))),
            'payer_name' => (string) ($db->table('residents')->where('id', '=', (int) $invoice['resident_id'])->value('full_name') ?? ''),
            'note' => 'Демо-оплата',
            'is_confirmed' => 1,
            'created_at' => date('Y-m-d H:i:s', strtotime($paidAt)),
            'updated_at' => date('Y-m-d H:i:s', strtotime($paidAt)),
        ]);

        $newPaid = $payAmount;
        $balance = round($amount - $newPaid, 2);
        $db->table('invoices')->where('id', '=', (int) $invoice['id'])->update([
            'paid_amount' => $newPaid, 'balance' => $balance,
            'status' => $balance <= 0.004 ? 'paid' : 'partial',
            'paid_at' => $balance <= 0.004 ? $paidAt : null,
        ]);
        $db->table('billing_history')->insert([
            'occurred_at' => date('Y-m-d H:i:s', strtotime($paidAt)),
            'resident_id' => (int) $invoice['resident_id'],
            'entity' => 'payment', 'entity_id' => $paymentId, 'action' => 'paid',
            'amount' => $payAmount, 'balance_before' => 0, 'balance_after' => 0,
            'details' => (string) json_encode(['demo' => true], JSON_UNESCAPED_UNICODE),
        ]);
        $paidCount++;
    }
    $db->table('charges')->where('id', '=', $chargeId)->update([
        'paid_count' => $paidCount,
        'paid_amount' => (float) $db->table('payments')->where('charge_id', '=', $chargeId)->sum('amount'),
    ]);

    // ── перерасчёт: один дополнительный счёт и одно сторно ───────────────
    if (count($invoices) > 3) {
        $first = $invoices[0];
        $db->table('invoices')->insert([
            'uuid' => Crypto::uuidv4(),
            'number' => 'СЧ-' . date('Y') . '-90001-Д',
            'charge_id' => $chargeId,
            'resident_id' => (int) $first['resident_id'],
            'parent_invoice_id' => (int) $first['id'],
            'kind' => 'adjustment',
            'amount' => 150.00, 'paid_amount' => 0.0, 'balance' => 150.00,
            'period_label' => $periodLabel,
            'due_date' => $today, 'issued_date' => $today, 'status' => 'pending',
            'note' => 'Перерасчёт: увеличение стоимости ТО привода',
            'recalc_reason' => 'Перерасчёт: сумма 15 000 → 15 150',
            'issued_at' => date('Y-m-d H:i:s', $now),
            'created_at' => date('Y-m-d H:i:s', $now), 'updated_at' => date('Y-m-d H:i:s', $now),
        ]);
        $second = $invoices[1];
        $db->table('invoices')->insert([
            'uuid' => Crypto::uuidv4(),
            'number' => 'СЧ-' . date('Y') . '-90002-С',
            'charge_id' => $chargeId,
            'resident_id' => (int) $second['resident_id'],
            'parent_invoice_id' => (int) $second['id'],
            'kind' => 'credit',
            'amount' => -100.00, 'paid_amount' => 0.0, 'balance' => -100.00,
            'period_label' => $periodLabel,
            'due_date' => $today, 'issued_date' => $today, 'status' => 'pending',
            'note' => 'Сторно: отсутствие в период обслуживания',
            'issued_at' => date('Y-m-d H:i:s', $now),
            'created_at' => date('Y-m-d H:i:s', $now), 'updated_at' => date('Y-m-d H:i:s', $now),
        ]);
    }

    // ── журнал шлагбаума за 30 дней ──────────────────────────────────────
    $accessTypes = ['own', 'own', 'own', 'guest', 'manual', 'plate'];
    $sources = ['cabinet', 'web', 'admin', 'api', 'telegram'];
    for ($day = 29; $day >= 0; $day--) {
        $opens = random_int(4, 22);
        for ($k = 0; $k < $opens; $k++) {
            $residentId = (int) $ids[random_int(0, count($ids) - 1)];
            $resident = $db->table('residents')->where('id', '=', $residentId)->first();
            if ($resident === null) {
                continue;
            }
            $access = $accessTypes[random_int(0, count($accessTypes) - 1)];
            $ts = strtotime(date('Y-m-d', $now - $day * 86400) . sprintf(' %02d:%02d:%02d', random_int(6, 23), random_int(0, 59), random_int(0, 59)));
            $denied = random_int(1, 14) === 1;
            $db->table('gate_events')->insert([
                'uuid' => Crypto::uuidv4(),
                'occurred_at' => date('Y-m-d H:i:s', $ts),
                'device_id' => null, 'channel' => 1,
                'actor_type' => $access === 'guest' ? 'guest' : ($access === 'manual' ? 'admin' : 'resident'),
                'resident_id' => $residentId,
                'actor_name' => (string) $resident['full_name'],
                'apartment' => (string) $resident['apartment'],
                'access_type' => $access,
                'action' => $denied ? 'denied' : 'open',
                'source' => $sources[random_int(0, count($sources) - 1)],
                'result' => $denied ? 'denied' : 'success',
                'reason' => $denied ? ['Доступ приостановлен', 'Тихие часы', 'Превышен лимит открытий'][random_int(0, 2)] : null,
                'duration_ms' => random_int(120, 900),
                'ip' => '192.168.1.' . random_int(20, 250),
                'meta' => (string) json_encode(['demo' => true], JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    // ── гостевые доступы ─────────────────────────────────────────────────
    for ($k = 0; $k < 6; $k++) {
        $residentId = (int) $ids[random_int(0, min(6, count($ids) - 1))];
        $resident = $db->table('residents')->where('id', '=', $residentId)->first();
        if ($resident === null) {
            continue;
        }
        $issued = $now - random_int(0, 20) * 3600;
        $db->table('guest_passes')->insert([
            'uuid' => Crypto::uuidv4(),
            'code' => \BasIp\Support\Str::friendlyCode(2, 4),
            'token_hash' => Crypto::hashToken(bin2hex(random_bytes(24))),
            'pin' => (string) random_int(100000, 999999),
            'resident_id' => $residentId,
            'created_by_type' => 'resident',
            'guest_name' => ['Курьер Яндекс', 'Гость', 'Мастер по ремонту', 'Друг семьи', 'Доставка'][random_int(0, 4)],
            'purpose' => $purposes[random_int(0, count($purposes) - 1)],
            'ttl_hours' => 12,
            'valid_from' => date('Y-m-d H:i:s', $issued),
            'valid_until' => date('Y-m-d H:i:s', $issued + 12 * 3600),
            'used_count' => random_int(0, 3),
            'last_used_at' => date('Y-m-d H:i:s', $issued + 3600),
            'status' => $issued + 12 * 3600 < $now ? 'expired' : 'active',
            'created_at' => date('Y-m-d H:i:s', $issued),
            'updated_at' => date('Y-m-d H:i:s', $issued),
        ]);
    }

    // ── объявление ───────────────────────────────────────────────────────
    $db->table('announcements')->insert([
        'title' => 'Профилактика шлагбаума',
        'body' => "В субботу с 10:00 до 12:00 проведём ТО привода.\nВ это время шлагбаум открывается диспетчером по телефону.\n\nСпасибо за понимание!",
        'level' => 'warning', 'audience' => 'active',
        'is_published' => 1, 'pin' => 1,
        'publish_at' => date('Y-m-d H:i:s', $now - 3600),
        'expires_at' => date('Y-m-d H:i:s', $now + 7 * 86400),
        'created_at' => date('Y-m-d H:i:s', $now), 'updated_at' => date('Y-m-d H:i:s', $now),
    ]);

    // ── балансы ──────────────────────────────────────────────────────────
    foreach ($ids as $residentId) {
        $debt = (float) $db->table('invoices')
            ->where('resident_id', '=', (int) $residentId)
            ->whereIn('status', ['pending', 'partial', 'overdue'])
            ->sum('balance');
        $paid = (float) $db->table('payments')->where('resident_id', '=', (int) $residentId)->sum('amount');
        $allocated = (float) $db->table('payments')->where('resident_id', '=', (int) $residentId)->whereNotNull('invoice_id')->sum('amount');
        $db->table('residents')->where('id', '=', (int) $residentId)->update([
            'balance' => round($paid - $allocated - $debt, 2),
            'last_payment_at' => $db->table('payments')->where('resident_id', '=', (int) $residentId)->max('paid_at'),
            'last_gate_event_at' => $db->table('gate_events')->where('resident_id', '=', (int) $residentId)->max('occurred_at'),
        ]);
    }

    return count($ids);
};
