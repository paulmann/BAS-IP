<?php

declare(strict_types=1);

namespace BasIp\Tests\Feature;

use BasIp\Domain\Model\Invoice;
use BasIp\Services\BillingService;
use BasIp\Tests\Support\TestApp;
use BasIp\Tests\Support\TestCase;

/**
 * The money engine: splitting, issuing, FIFO allocation, recalculation with
 * adjustment invoices, balances and debtor reporting.
 */
final class BillingTest extends TestCase
{
    private BillingService $billing;

    protected function setUp(): void
    {
        TestApp::boot();
        $this->billing = TestApp::get(BillingService::class);
        // Each test starts from an empty resident set.
        TestApp::db()->table('invoices')->where('id', '>', 0)->delete();
        TestApp::db()->table('payments')->where('id', '>', 0)->delete();
        TestApp::db()->table('charges')->where('id', '>', 0)->delete();
        TestApp::db()->table('contacts')->where('id', '>', 0)->delete();
        TestApp::db()->table('outbox')->where('id', '>', 0)->delete();
        TestApp::db()->table('residents')->where('id', '>', 0)->delete();
    }

    /**
     * Regression: the notification query joins invoices with residents; if the
     * resident is hydrated from the joined row, `i.*` shadows `r.id` and every
     * letter is addressed to the neighbour whose id equals the invoice id —
     * a personal-data leak between residents.
     */
    public function testChargeNotificationsGoToTheRightResident(): void
    {
        $expected = [];
        for ($i = 1; $i <= 5; $i++) {
            $apartment = (string) (200 + $i);
            $email     = 'kv' . $apartment . '@example.test';
            $lastName  = 'Квартирин' . $i;
            $id = TestApp::makeResident([
                'apartment'  => $apartment,
                'last_name'  => $lastName,
                'first_name' => 'Житель',
                'patronymic' => 'Тестович',
                'full_name'  => $lastName . ' Житель Тестович',
                'email'      => $email,
            ]);
            TestApp::db()->table('contacts')->insert([
                'resident_id'      => $id,
                'type'             => 'email',
                'value'            => $email,
                'value_normalized' => $email,
                'label'            => 'основная',
                'is_primary'       => 1,
                'notify'           => 1,
                'created_at'       => date('Y-m-d H:i:s'),
            ]);
            $expected[$id] = ['email' => $email, 'name' => $lastName];
        }

        $result = $this->billing->createCharge(['total_amount' => 5000, 'title' => 'Рассылка', 'issue' => true]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));

        $notify = $this->billing->notifyCharge((int) $result['id']);
        $this->assertSame(5, $notify['queued']);

        $rows = TestApp::db()->table('outbox')->where('id', '>', 0)->get();
        $this->assertSame(5, count($rows));
        foreach ($rows as $row) {
            $residentId = (int) $row['resident_id'];
            $this->assertTrue(isset($expected[$residentId]), 'outbox.resident_id must reference a charged resident, not an invoice id');
            $this->assertSame($expected[$residentId]['email'], (string) $row['recipient'], 'the letter must be addressed to its own resident');
            $this->assertStringContains($expected[$residentId]['name'], (string) $row['body']);
        }
    }

    public function testPeriodLabelParsingAndNormalization(): void
    {
        foreach ([['2026-10', '2026-10-01'], ['10.2026', '2026-10-01'], ['Октябрь 2026', '2026-10-01'], ['2026-10-15', '2026-10-01'], ['за 09.2026 год', '2026-09-01']] as [$label, $start]) {
            $parsed = $this->billing->parsePeriodLabel($label);
            $this->assertTrue($parsed !== null, '«' . $label . '» must be recognised');
            $this->assertSame($start, $parsed[0]);
        }
        $this->assertTrue($this->billing->parsePeriodLabel('мусор') === null);
        $this->assertTrue($this->billing->parsePeriodLabel('13.2026') === null);

        TestApp::makeResident(['apartment' => '301']);

        // A technical label is normalised for invoices and letters…
        $r = $this->billing->createCharge(['total_amount' => 1000, 'period_label' => '2026-11', 'issue' => false, 'title' => 'T']);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertSame('Ноябрь 2026', $this->billing->charge((int) $r['id'])->periodLabel);

        // …while free text is preserved verbatim.
        $r2 = $this->billing->createCharge(['total_amount' => 1000, 'period_label' => 'Сентябрь 2026', 'issue' => false, 'title' => 'T2']);
        $this->assertTrue($r2['ok'], (string) ($r2['error'] ?? ''));
        $this->assertSame('Сентябрь 2026', $this->billing->charge((int) $r2['id'])->periodLabel);
    }

    public function testSplitIsExactToTheKopeck(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            TestApp::makeResident(['apartment' => (string) $i]);
        }

        $preview = $this->billing->preview(15000.00, 'equal');
        $this->assertSame(7, $preview['participants']);
        $this->assertFloat(15000.00, $preview['sum'], 0.001, 'the parts must sum to the total');
        $this->assertFloat(0.0, $preview['rounding_delta'], 0.001);
        // 15000/7 = 2142.857… — the spread must be at most one kopeck.
        $amounts = array_column($preview['rows'], 'amount');
        $this->assertLessThan(0.02, max($amounts) - min($amounts));
    }

    public function testOnlyActiveBillableResidentsTakePart(): void
    {
        TestApp::makeResident(['apartment' => '1']);
        TestApp::makeResident(['apartment' => '2']);
        TestApp::makeResident(['apartment' => '3', 'is_active' => 0]);       // switched off
        TestApp::makeResident(['apartment' => '4', 'is_billed' => 0]);       // not billed
        TestApp::makeResident(['apartment' => '5', 'deleted_at' => date('Y-m-d H:i:s')]);

        $preview = $this->billing->preview(10000.00);
        $this->assertSame(2, $preview['participants'], 'only active + billed residents may be charged');
        $this->assertFloat(5000.00, $preview['per_unit']);
    }

    public function testAllocationByAreaUsesWeights(): void
    {
        TestApp::makeResident(['apartment' => '1', 'area' => 30.0]);
        TestApp::makeResident(['apartment' => '2', 'area' => 50.0]);
        TestApp::makeResident(['apartment' => '3', 'area' => 20.0]);

        $preview = $this->billing->preview(10000.00, 'by_area');
        $amounts = array_column($preview['rows'], 'amount');
        $this->assertFloat(3000.00, $amounts[0]);
        $this->assertFloat(5000.00, $amounts[1]);
        $this->assertFloat(2000.00, $amounts[2]);
        $this->assertFloat(10000.00, array_sum($amounts), 0.001);
    }

    public function testChargeIssuesOneInvoicePerParticipant(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            TestApp::makeResident(['apartment' => (string) $i]);
        }

        $result = $this->billing->createCharge(['total_amount' => 5000, 'title' => 'Test', 'issue' => true]);
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(5, $result['invoices']);

        $stats = $this->billing->chargeStats((int) $result['id']);
        $this->assertSame(5, $stats['count']);
        $this->assertFloat(5000.00, $stats['amount'], 0.001);
        $this->assertFloat(0.00, $stats['paid'], 0.001);

        $charge = $this->billing->charge((int) $result['id']);
        $this->assertTrue($charge->isIssued());
        $this->assertSame(5, $charge->activeCount);
    }

    public function testDraftChargeDoesNotIssueInvoices(): void
    {
        TestApp::makeResident(['apartment' => '1']);
        $result = $this->billing->createCharge(['total_amount' => 1000, 'issue' => false]);
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['invoices']);
        $this->assertTrue($this->billing->charge((int) $result['id'])->isDraft());
    }

    public function testChargeWithoutParticipantsIsRejected(): void
    {
        $result = $this->billing->createCharge(['total_amount' => 1000]);
        $this->assertFalse($result['ok']);
        $this->assertStringContains('активного жителя', (string) $result['error']);
    }

    public function testPaymentIsAllocatedFifoToOldestInvoice(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $db = TestApp::db();
        $now = date('Y-m-d H:i:s');

        $old = $db->table('invoices')->insertGetId([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'OLD', 'resident_id' => $residentId,
            'kind' => 'regular', 'amount' => 100, 'paid_amount' => 0, 'balance' => 100,
            'due_date' => '2026-01-10', 'status' => 'pending', 'created_at' => $now,
        ]);
        $new = $db->table('invoices')->insertGetId([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'NEW', 'resident_id' => $residentId,
            'kind' => 'regular', 'amount' => 200, 'paid_amount' => 0, 'balance' => 200,
            'due_date' => '2026-05-10', 'status' => 'pending', 'created_at' => $now,
        ]);

        $result = $this->billing->recordPayment(['resident_id' => $residentId, 'amount' => 250, 'method' => 'cash']);
        $this->assertTrue($result['ok']);
        $this->assertCount(2, $result['allocated']);
        $this->assertSame($old, $result['allocated'][0]['invoice_id'], 'oldest invoice must be settled first');
        $this->assertSame(Invoice::STATUS_PAID, $result['allocated'][0]['status']);
        $this->assertSame($new, $result['allocated'][1]['invoice_id']);
        $this->assertSame(Invoice::STATUS_PARTIAL, $result['allocated'][1]['status']);

        $this->assertFloat(50.0, (float) $db->table('invoices')->where('id', '=', $new)->value('balance'));
        $this->assertFloat(-50.0, $this->billing->recalculateBalance($residentId), 0.01, 'remaining 50 is a debt');
    }

    public function testOverpaymentStaysOnTheBalance(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $db = TestApp::db();
        $db->table('invoices')->insert([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'INV', 'resident_id' => $residentId,
            'amount' => 100, 'paid_amount' => 0, 'balance' => 100, 'status' => 'pending',
            'due_date' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $result = $this->billing->recordPayment(['resident_id' => $residentId, 'amount' => 350]);
        $this->assertTrue($result['ok']);
        $this->assertFloat(250.0, (float) $result['overpay']);
        $this->assertFloat(250.0, (float) $result['balance'], 0.01);

        $statement = $this->billing->residentStatement($residentId);
        $this->assertFloat(0.0, $statement['debt']);
        $this->assertFloat(250.0, $statement['overpay'], 0.01);
    }

    public function testDeletingAPaymentRollsTheInvoiceBack(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $db = TestApp::db();
        $invoiceId = $db->table('invoices')->insertGetId([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'INV', 'resident_id' => $residentId,
            'amount' => 500, 'paid_amount' => 0, 'balance' => 500, 'status' => 'pending',
            'due_date' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $payment = $this->billing->recordPayment(['resident_id' => $residentId, 'amount' => 500, 'invoice_id' => $invoiceId]);
        $this->assertSame(Invoice::STATUS_PAID, (string) $db->table('invoices')->where('id', '=', $invoiceId)->value('status'));

        $this->assertTrue($this->billing->deletePayment((int) $payment['id']));
        $row = $db->table('invoices')->where('id', '=', $invoiceId)->first();
        $this->assertSame(Invoice::STATUS_PENDING, (string) $row['status']);
        $this->assertFloat(500.0, (float) $row['balance']);
        $this->assertFloat(0.0, (float) $db->table('payments')->where('id', '=', (int) $payment['id'])->count());
    }

    public function testRecalculationCreatesAdjustmentsWithoutTouchingIssuedInvoices(): void
    {
        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $ids[] = TestApp::makeResident(['apartment' => (string) $i]);
        }
        $charge = $this->billing->createCharge(['total_amount' => 4000]);
        $chargeId = (int) $charge['id'];
        $original = $this->billing->invoicesForCharge($chargeId);
        $this->assertCount(4, $original);
        $this->assertFloat(1000.0, $original[0]->amount);

        // Total grows to 5000 → each resident owes 250 more.
        $result = $this->billing->recalculate($chargeId, ['total_amount' => 5000]);
        $this->assertTrue($result['ok']);
        $this->assertSame(4, $result['adjustments']);
        $this->assertSame(0, $result['new_invoices']);
        $this->assertFloat(1000.0, $result['adjustment_sum'], 0.001);

        // The original invoices keep their amount — that is the whole point.
        $stillOriginal = TestApp::db()->table('invoices')->where('id', '=', $original[0]->id)->first();
        $this->assertFloat(1000.0, (float) $stillOriginal['amount']);

        $adjustments = TestApp::db()->table('invoices')->where('charge_id', '=', $chargeId)->where('kind', '=', 'adjustment')->get();
        $this->assertCount(4, $adjustments);
        $this->assertFloat(250.0, (float) $adjustments[0]['amount']);
        $this->assertNotNull($adjustments[0]['parent_invoice_id'], 'adjustments must link back to the original invoice');

        $stats = $this->billing->chargeStats($chargeId);
        $this->assertFloat(5000.0, $stats['amount'], 0.001);
    }

    public function testRecalculationPicksUpNewResidentsAndCreditsDisabledOnes(): void
    {
        $a = TestApp::makeResident(['apartment' => '1']);
        $b = TestApp::makeResident(['apartment' => '2']);
        $chargeId = (int) $this->billing->createCharge(['total_amount' => 2000])['id'];

        // One resident joins, another is switched off.
        $c = TestApp::makeResident(['apartment' => '3']);
        TestApp::db()->table('residents')->where('id', '=', $b)->update(['is_active' => 0]);

        $dry = $this->billing->recalculate($chargeId, [], null, null, true);
        $this->assertTrue($dry['ok']);
        $this->assertSame(1, $dry['new_invoices'], 'the newcomer gets a regular invoice');
        $this->assertSame(1, $dry['credits'], 'the disabled resident gets a full credit note');
        $this->assertSame(2, $dry['participants']);
        $this->assertTrue($dry['dry_run']);

        // Dry-run must not write anything.
        $this->assertSame(2, TestApp::db()->table('invoices')->where('charge_id', '=', $chargeId)->count());

        $applied = $this->billing->recalculate($chargeId, []);
        $this->assertTrue($applied['ok']);
        $credits = TestApp::db()->table('invoices')->where('charge_id', '=', $chargeId)->where('kind', '=', 'credit')->get();
        $this->assertCount(1, $credits);
        $this->assertFloat(-1000.0, (float) $credits[0]['amount'], 0.001, 'the credit note reverses the full amount');

        $newOnes = TestApp::db()->table('invoices')->where('charge_id', '=', $chargeId)->where('resident_id', '=', $c)->get();
        $this->assertCount(1, $newOnes);
        $this->assertFloat(1000.0, (float) $newOnes[0]['amount'], 0.001);
    }

    public function testRecalculationWithNoChangeIsANoOp(): void
    {
        TestApp::makeResident(['apartment' => '1']);
        TestApp::makeResident(['apartment' => '2']);
        $chargeId = (int) $this->billing->createCharge(['total_amount' => 2000])['id'];

        $result = $this->billing->recalculate($chargeId, ['total_amount' => 2000]);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['unchanged'] ?? false);
        $this->assertSame(2, TestApp::db()->table('invoices')->where('charge_id', '=', $chargeId)->count());
    }

    public function testManualInvoiceAndVoid(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $created = $this->billing->createManualInvoice(['resident_id' => $residentId, 'amount' => 750, 'note' => 'Ремонт привода']);
        $this->assertTrue($created['ok']);
        $invoice = $this->billing->invoice((int) $created['id']);
        $this->assertSame('manual', $invoice->kind);
        $this->assertFloat(750.0, $invoice->amount);

        $this->assertTrue($this->billing->voidInvoice((int) $created['id'], 'ошибка оператора'));
        $this->assertSame(Invoice::STATUS_VOID, $this->billing->invoice((int) $created['id'])->status);
        $this->assertFloat(0.0, $this->billing->residentStatement($residentId)['debt']);
    }

    public function testNegativeManualInvoiceBecomesACredit(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $created = $this->billing->createManualInvoice(['resident_id' => $residentId, 'amount' => -300]);
        $this->assertTrue($created['ok']);
        $this->assertSame('credit', $this->billing->invoice((int) $created['id'])->kind);
    }

    public function testMarkOverdue(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        TestApp::db()->table('invoices')->insert([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'OLD', 'resident_id' => $residentId,
            'amount' => 100, 'balance' => 100, 'status' => 'pending',
            'due_date' => date('Y-m-d', strtotime('-10 days')), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->assertSame(1, $this->billing->markOverdue());
        $this->assertTrue($this->billing->invoiceTotals()['overdue_count'] >= 1);
    }

    public function testDebtorsListOnlyIncludesOutstanding(): void
    {
        $a = TestApp::makeResident(['apartment' => '1']);
        $b = TestApp::makeResident(['apartment' => '2']);
        $now = date('Y-m-d H:i:s');
        TestApp::db()->table('invoices')->insert([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'A', 'resident_id' => $a,
            'amount' => 500, 'balance' => 500, 'status' => 'pending', 'due_date' => date('Y-m-d'), 'created_at' => $now,
        ]);
        TestApp::db()->table('invoices')->insert([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'B', 'resident_id' => $b,
            'amount' => 500, 'paid_amount' => 500, 'balance' => 0, 'status' => 'paid', 'due_date' => date('Y-m-d'), 'created_at' => $now,
        ]);

        $debtors = $this->billing->debtors();
        $this->assertCount(1, $debtors);
        $this->assertSame($a, $debtors[0]['resident_id']);
        $this->assertFloat(500.0, (float) $debtors[0]['debt']);
    }

    public function testNegativePaymentIsRejectedByDefault(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $result = $this->billing->recordPayment(['resident_id' => $residentId, 'amount' => -100]);
        $this->assertFalse($result['ok']);
    }

    public function testZeroAmountPaymentIsRejected(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $this->assertFalse($this->billing->recordPayment(['resident_id' => $residentId, 'amount' => 0])['ok']);
    }

    public function testPaymentLinkPlaceholdersAreExpanded(): void
    {
        $link = $this->billing->expandPayLink('https://pay.example.com/?apt={apartment}&sum={amount}&p={period}&n={name}',
            ['apartment' => '12А', 'amount' => 0, 'name' => 'Иванов И.И.'], 2142.86, 'Сентябрь 2026');
        $this->assertStringContains('apt=12%D0%90', (string) $link);
        $this->assertStringContains('sum=2142.86', (string) $link);
        $this->assertStringContains('p=%D0%A1%D0%B5%D0%BD%D1%82%D1%8F%D0%B1%D1%80%D1%8C', (string) $link);
    }

    public function testInvoiceTotalsIgnoreVoidInvoices(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '1']);
        $now = date('Y-m-d H:i:s');
        TestApp::db()->table('invoices')->insert(['uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'A', 'resident_id' => $residentId, 'amount' => 100, 'balance' => 100, 'status' => 'pending', 'due_date' => date('Y-m-d', strtotime('+5 days')), 'created_at' => $now]);
        TestApp::db()->table('invoices')->insert(['uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'B', 'resident_id' => $residentId, 'amount' => 900, 'balance' => 0, 'status' => 'void', 'due_date' => date('Y-m-d'), 'created_at' => $now]);

        $totals = $this->billing->invoiceTotals();
        $this->assertFloat(100.0, $totals['amount'], 0.001);
        $this->assertFloat(100.0, $totals['outstanding'], 0.001);
        $this->assertSame(1, $totals['count']);
    }
}
