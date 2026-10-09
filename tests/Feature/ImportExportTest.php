<?php

declare(strict_types=1);

namespace BasIp\Tests\Feature;

use BasIp\Services\ImportExportService;
use BasIp\Tests\Support\TestApp;
use BasIp\Tests\Support\TestCase;

/**
 * Round-tripping the resident database: CSV/JSON export, header recognition,
 * dry-run, upsert semantics and payment-statement import.
 */
final class ImportExportTest extends TestCase
{
    private ImportExportService $io;

    protected function setUp(): void
    {
        TestApp::boot();
        $db = TestApp::db();
        foreach (['payments', 'invoices', 'cars', 'contacts', 'residents'] as $table) {
            $db->table($table)->where('id', '>', 0)->delete();
        }
        $this->io = TestApp::get(ImportExportService::class);
    }

    private function seedThree(): void
    {
        $a = TestApp::makeResident(['apartment' => '1', 'last_name' => 'Иванов', 'first_name' => 'Иван', 'full_name' => 'Иванов Иван Иванович']);
        $b = TestApp::makeResident(['apartment' => '2', 'last_name' => 'Петрова', 'first_name' => 'Мария', 'full_name' => 'Петрова Мария', 'is_active' => 0]);
        TestApp::db()->table('contacts')->insert([
            'resident_id' => $a, 'type' => 'phone', 'value' => '+79161234567', 'value_normalized' => '+79161234567',
            'is_primary' => 1, 'notify' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        TestApp::db()->table('contacts')->insert([
            'resident_id' => $a, 'type' => 'email', 'value' => 'ivanov@example.com', 'value_normalized' => 'ivanov@example.com',
            'is_primary' => 1, 'notify' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        TestApp::db()->table('cars')->insert([
            'resident_id' => $a, 'plate' => 'А123ВС77', 'plate_normalized' => 'A123BC77',
            'brand' => 'Skoda', 'model' => 'Octavia', 'is_active' => 1, 'created_at' => date('Y-m-d H:i:s'),
        ]);
        unset($b);
    }

    public function testCsvExportContainsHeadersAndRows(): void
    {
        $this->seedThree();
        $result = $this->io->exportResidents(['status' => 'all'], ['format' => 'csv', 'scope' => 'all', 'include' => ['contacts', 'cars']]);

        $this->assertSame(2, $result['rows']);
        $this->assertTrue(is_file($result['path']));
        $csv = (string) file_get_contents($result['path']);

        // BOM so Excel opens Cyrillic correctly.
        $this->assertStringContains("\xEF\xBB\xBF", $csv);
        $this->assertStringContains('Фамилия', $csv);
        $this->assertStringContains('Квартира', $csv);
        $this->assertStringContains('Иванов Иван Иванович', $csv);
        $this->assertStringContains('+79161234567', $csv);
        $this->assertStringContains('А123ВС77', $csv);
        @unlink($result['path']);
    }

    public function testJsonExportRoundTrips(): void
    {
        $this->seedThree();
        $export = $this->io->exportResidents(['status' => 'all'], ['format' => 'json', 'scope' => 'all', 'include' => ['contacts', 'cars', 'payments']]);
        $content = (string) file_get_contents($export['path']);
        $decoded = json_decode($content, true);

        $this->assertNotNull($decoded);
        $this->assertSame(2, $decoded['meta']['rows']);
        $this->assertCount(2, $decoded['residents']);

        $ivanov = $decoded['residents'][0];
        $this->assertSame('Иванов Иван Иванович', $ivanov['full_name']);
        $this->assertCount(2, $ivanov['contacts']);
        $this->assertCount(1, $ivanov['cars']);
        $this->assertSame('Skoda', $ivanov['cars'][0]['brand']);

        // Re-import must recognise every record as an update, not a duplicate.
        $import = $this->io->importResidents($content, 'json', ['mode' => 'dry-run']);
        $this->assertTrue($import['ok']);
        $this->assertSame(2, $import['total']);
        $this->assertSame(0, $import['imported'], 'nothing new should be created');
        $this->assertSame(2, $import['updated']);
        @unlink($export['path']);
    }

    public function testRussianHeadersAreRecognised(): void
    {
        $csv = "Фамилия;Имя;Квартира;Телефон;Почта;Номер машины;Марка;Доступ;Активен\n"
             . "Сидоров;Алексей;10;89031234567;sid@mail.ru;В111АА50;Lada Vesta;да;да\n";

        $info = $this->io->inspectCsv($csv, ';');
        $mapped = array_column($info['columns'], 'mapped');
        $this->assertContains('last_name', $mapped);
        $this->assertContains('apartment', $mapped);
        $this->assertContains('phone', $mapped);
        $this->assertContains('car_plate', $mapped);
        $this->assertContains('is_active', $mapped);
        $this->assertSame(';', $info['delimiter']);

        $result = $this->io->importResidents($csv, 'csv', ['mode' => 'upsert', 'delimiter' => ';']);
        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['failed']);

        $row = TestApp::db()->table('residents')->where('apartment', '=', '10')->first();
        $this->assertNotNull($row);
        $this->assertSame('Сидоров Алексей', $row['full_name'], 'a single ФИО-less pair must be composed into full_name');
        $this->assertSame(1, (int) $row['is_active']);

        // The phone must be normalised and mirrored into contacts.
        $this->assertSame('+79031234567', $row['phone']);
        $contacts = TestApp::db()->table('contacts')->where('resident_id', '=', (int) $row['id'])->get();
        $this->assertGreaterThan(0, count($contacts));

        // The car must be normalised too.
        $car = TestApp::db()->table('cars')->where('resident_id', '=', (int) $row['id'])->first();
        $this->assertNotNull($car);
        // The input starts with a Cyrillic В, which maps to the Latin B.
        $this->assertSame('B111AA50', $car['plate_normalized']);
        $this->assertSame('Lada Vesta', $car['brand']);
    }

    public function testDryRunChangesNothing(): void
    {
        $csv = "Фамилия;Квартира\nНовиков;77\n";
        $before = TestApp::db()->table('residents')->count();

        $result = $this->io->importResidents($csv, 'csv', ['mode' => 'dry-run', 'delimiter' => ';']);
        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['total']);
        $this->assertSame(1, $result['imported']);
        $this->assertCount(1, $result['preview']);
        $this->assertSame('create', $result['preview'][0]['action']);
        $this->assertSame($before, TestApp::db()->table('residents')->count(), 'dry-run must not write');
    }

    public function testUpsertMatchesOnApartmentAndName(): void
    {
        TestApp::makeResident(['apartment' => '5', 'last_name' => 'Кузнецов', 'first_name' => 'Олег', 'full_name' => 'Кузнецов Олег', 'phone' => null, 'email' => null]);

        $csv = "Фамилия;Имя;Квартира;Телефон\nКузнецов;Олег;5;+79998887766\n";
        $result = $this->io->importResidents($csv, 'csv', ['mode' => 'upsert', 'delimiter' => ';', 'match_on' => ['apartment', 'full_name']]);

        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame(1, TestApp::db()->table('residents')->where('apartment', '=', '5')->count(), 'no duplicate must appear');
        $this->assertSame('+79998887766', TestApp::db()->table('residents')->where('apartment', '=', '5')->value('phone'));
    }

    public function testInsertModeSkipsExisting(): void
    {
        TestApp::makeResident(['apartment' => '6', 'full_name' => 'Соловьёв Артём', 'last_name' => 'Соловьёв', 'first_name' => 'Артём']);
        $csv = "Фамилия;Имя;Квартира\nСоловьёв;Артём;6\n";
        $result = $this->io->importResidents($csv, 'csv', ['mode' => 'insert', 'delimiter' => ';', 'match_on' => ['apartment', 'full_name']]);
        $this->assertSame(0, $result['imported']);
        $this->assertSame(1, $result['skipped']);
    }

    public function testExternalIdTakesPrecedenceWhenMatching(): void
    {
        $id = TestApp::makeResident(['apartment' => '8', 'full_name' => 'Старое Имя', 'last_name' => 'Старое', 'first_name' => 'Имя', 'external_id' => 'EXT-1']);
        $csv = "Внешний ID;Фамилия;Имя;Квартира\nEXT-1;Новое;Имя;9\n";
        $result = $this->io->importResidents($csv, 'csv', ['mode' => 'upsert', 'delimiter' => ';']);
        $this->assertSame(1, $result['updated']);
        $row = TestApp::db()->table('residents')->where('id', '=', $id)->first();
        $this->assertSame('9', $row['apartment'], 'the record must be matched by external_id even when the apartment changed');
    }

    public function testMultiplePhonesAndCarsInOneCell(): void
    {
        $csv = "Фамилия;Квартира;Все телефоны;Все машины\nМорозов;11;+79161112233, +79031112233;А111АА50 Lada Vesta | В222ВВ50 Skoda Octavia\n";
        $result = $this->io->importResidents($csv, 'csv', ['mode' => 'upsert', 'delimiter' => ';']);
        $this->assertSame(1, $result['imported']);

        $residentId = (int) TestApp::db()->table('residents')->where('apartment', '=', '11')->value('id');
        $phones = TestApp::db()->table('contacts')->where('resident_id', '=', $residentId)->where('type', '=', 'phone')->get();
        $this->assertCount(2, $phones);

        $cars = TestApp::db()->table('cars')->where('resident_id', '=', $residentId)->get();
        $this->assertCount(2, $cars);
        $this->assertSame('A111AA50', $cars[0]['plate_normalized']);
        $this->assertSame('Lada Vesta', trim($cars[0]['brand'] . ' ' . $cars[0]['model']));
    }

    public function testBadRowsAreReportedButDoNotStopTheImport(): void
    {
        $csv = "Фамилия;Квартира;Почта\nХороший;1;good@example.com\n;2;bad@example.com\nПлохой;3;not-an-email\n";
        $result = $this->io->importResidents($csv, 'csv', ['mode' => 'upsert', 'delimiter' => ';', 'skip_errors' => true]);

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['imported'], 'only the valid row is created');
        $this->assertGreaterThan(0, $result['failed'] + $result['skipped']);
        $this->assertGreaterThan(0, count($result['errors']));
    }

    public function testEmptyFileIsRejected(): void
    {
        $result = $this->io->importResidents('', 'csv', ['mode' => 'upsert']);
        $this->assertFalse($result['ok']);
        $this->assertNotSame('', (string) $result['error']);
    }

    public function testBrokenJsonIsRejected(): void
    {
        $result = $this->io->importResidents('{not json', 'json', ['mode' => 'upsert']);
        $this->assertFalse($result['ok']);
    }

    public function testImportJobIsRecorded(): void
    {
        $csv = "Фамилия;Квартира\nЖурналов;21\n";
        $result = $this->io->importResidents($csv, 'csv', ['mode' => 'upsert', 'delimiter' => ';', 'filename' => 'people.csv']);
        $this->assertTrue($result['ok']);

        $job = TestApp::db()->table('import_jobs')->where('id', '=', (int) $result['job_id'])->first();
        $this->assertNotNull($job);
        $this->assertSame('people.csv', $job['filename']);
        $this->assertSame('done', $job['status']);
        $this->assertSame(1, (int) $job['rows_total']);
    }

    public function testPaymentStatementImport(): void
    {
        $residentId = TestApp::makeResident(['apartment' => '33']);
        TestApp::db()->table('invoices')->insert([
            'uuid' => \BasIp\Core\Crypto::uuidv4(), 'number' => 'INV-1', 'resident_id' => $residentId,
            'amount' => 1000, 'paid_amount' => 0, 'balance' => 1000, 'status' => 'pending',
            'due_date' => date('Y-m-d'), 'created_at' => date('Y-m-d H:i:s'),
        ]);

        $csv = "Квартира;Сумма;Дата;Номер платежа\n33;1000;" . date('Y-m-d') . ";ПП-1\n34;500;" . date('Y-m-d') . ";ПП-2\n";
        $result = $this->io->importPayments($csv, ['delimiter' => ';']);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['failed'], 'an unknown apartment must fail, not create a phantom payment');
        $this->assertSame('paid', TestApp::db()->table('invoices')->where('number', '=', 'INV-1')->value('status'));
    }

    public function testSampleTemplateIsUsable(): void
    {
        $sample = $this->io->sampleCsv();
        $this->assertStringContains('Фамилия', $sample);
        $this->assertStringContains('Иванов', $sample);
        $this->assertGreaterThan(2, substr_count($sample, "\n"));
    }
}
