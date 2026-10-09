<?php

declare(strict_types=1);

use BasIp\Db\Blueprint;
use BasIp\Db\Database;
use BasIp\Db\Migration;
use BasIp\Db\Schema;

/**
 * Residents (the "user database" of the building), their multiple phone
 * numbers / e-mails / messengers, their multiple cars, the gate devices and
 * the access schedules.
 */
return new class implements Migration {
    public function describe(): string
    {
        return 'Жители, контакты (несколько телефонов/почт), автомобили (несколько на жителя), устройства, расписания доступа';
    }

    public function up(Schema $schema, Database $db): void
    {
        $schema->create('residents', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->string('external_id', 64)->nullable();          // id from the source system / spreadsheet

            // ── ФИО ────────────────────────────────────────────────────────
            $t->string('last_name', 120)->default('');
            $t->string('first_name', 120)->default('');
            $t->string('patronymic', 120)->nullable();
            $t->string('full_name', 255)->default('');          // kept in sync with the parts above
            $t->string('search_index', 512)->nullable();        // lowercase haystack for fast LIKE search

            // ── Квартира ───────────────────────────────────────────────────
            $t->string('apartment', 24)->default('');           // "12", "12А", "12-б" — as written on the door
            $t->integer('apartment_no')->nullable();            // numeric part, used for sorting
            $t->string('building', 24)->nullable();             // корпус
            $t->string('entrance', 16)->nullable();             // подъезд
            $t->integer('floor')->nullable();
            $t->decimal('area', 10, 2)->default(0);             // m², for allocation by area
            $t->integer('persons')->default(1);                 // for allocation by persons
            $t->decimal('share', 10, 4)->default(1);            // weight, for allocation by share
            $t->string('role_in_home', 24)->default('owner');   // owner|tenant|relative|manager|other

            // ── Первичные контакты (дублируются из contacts для скорости) ──
            $t->string('phone', 32)->nullable();
            $t->string('email', 190)->nullable();

            // ── Флаги ──────────────────────────────────────────────────────
            $t->boolean('access_granted')->default(true);       // чекбокс «доступ»
            $t->boolean('is_active')->default(true);            // чекбокс «включен/выключен»
            $t->boolean('is_billed')->default(true);            // участвует в расчёте оплаты
            $t->boolean('allow_guest_pass')->default(true);     // может сам создавать гостевой доступ
            $t->boolean('notify_enabled')->default(true);

            // ── Прочее ─────────────────────────────────────────────────────
            $t->text('notes')->nullable();                      // комментарии
            $t->text('tags')->nullable();                       // JSON array
            $t->decimal('balance', 12, 2)->default(0);          // кэш: переплата (+) / долг (−)
            $t->dateTime('last_payment_at')->nullable();
            $t->dateTime('last_gate_event_at')->nullable();
            $t->string('avatar_path', 255)->nullable();
            $t->text('meta')->nullable();                       // JSON, произвольные поля
            $t->integer('guest_passes_issued')->default(0);

            $t->bigInteger('created_by')->nullable();
            $t->bigInteger('updated_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('archived_at')->nullable();
            $t->dateTime('deleted_at')->nullable();             // soft delete

            $t->unique(['uuid']);
            $t->index(['is_active', 'is_billed']);
            $t->index(['apartment_no', 'building']);
            $t->index(['last_name']);
            $t->index(['email']);
            $t->index(['deleted_at']);
            $t->index(['access_granted']);
        });

        // Several phones / e-mails / messengers per resident.
        $schema->create('contacts', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('resident_id');
            $t->string('type', 16)->default('phone');           // phone|email|telegram|whatsapp|viber|sip|other
            $t->string('value', 190)->default('');
            $t->string('value_normalized', 190)->nullable();    // E.164 / lowercase e-mail
            $t->string('label', 40)->nullable();                // мобильный, домашний, рабочий…
            $t->boolean('is_primary')->default(false);
            $t->boolean('notify')->default(true);               // слать сюда уведомления
            $t->boolean('verified')->default(false);
            $t->dateTime('verified_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->index(['resident_id', 'type']);
            $t->index(['value_normalized']);
            $t->foreign('resident_id', 'id', 'residents', 'cascade');
        });

        // Several cars per resident.
        $schema->create('cars', function (Blueprint $t): void {
            $t->id();
            $t->bigInteger('resident_id');
            $t->string('plate', 32)->default('');               // as typed
            $t->string('plate_normalized', 32)->default('');    // A123BC777 — for lookups / ANPR
            $t->string('brand', 64)->nullable();                // марка
            $t->string('model', 64)->nullable();
            $t->string('color', 32)->nullable();
            $t->integer('year')->nullable();
            $t->string('type', 16)->default('car');             // car|suv|motorcycle|truck|ev|other
            $t->string('rfid_tag', 64)->nullable();
            $t->string('sticker', 32)->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('valid_from')->nullable();
            $t->dateTime('valid_until')->nullable();
            $t->text('notes')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->index(['resident_id']);
            $t->index(['plate_normalized']);
            $t->index(['is_active']);
            $t->foreign('resident_id', 'id', 'residents', 'cascade');
        });

        // Panels / barriers. The first row is the default gate.
        $schema->create('devices', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120)->default('');
            $t->string('slug', 64)->default('');
            $t->string('driver', 24)->default('basip');         // basip|http-relay|dryrun
            $t->string('base_url', 255)->nullable();
            $t->string('login', 120)->nullable();
            $t->text('password_enc')->nullable();               // encrypted with app.key
            $t->integer('channel')->default(1);
            $t->string('location', 190)->nullable();
            $t->boolean('is_default')->default(false);
            $t->boolean('is_active')->default(true);
            $t->text('options')->nullable();                    // JSON overrides of config.device.*
            $t->dateTime('last_seen_at')->nullable();
            $t->text('last_status')->nullable();                // JSON snapshot
            $t->integer('error_count')->default(0);
            $t->text('notes')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->unique(['slug']);
            $t->index(['is_active']);
        });

        // Time windows: global quiet hours or per-resident restrictions.
        $schema->create('access_schedules', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120)->default('');
            $t->bigInteger('resident_id')->nullable();          // NULL = applies to everybody
            $t->string('days', 32)->default('1,2,3,4,5,6,7');   // ISO weekday numbers
            $t->string('start_time', 5)->default('00:00');
            $t->string('end_time', 5)->default('23:59');
            $t->string('effect', 16)->default('allow');         // allow|deny
            $t->integer('priority')->default(0);
            $t->boolean('is_active')->default(true);
            $t->text('notes')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->index(['resident_id', 'is_active']);
        });

        // Broadcast messages shown in the resident cabinet and the dashboard.
        $schema->create('announcements', function (Blueprint $t): void {
            $t->id();
            $t->string('title', 190)->default('');
            $t->text('body')->nullable();
            $t->string('level', 16)->default('info');           // info|success|warning|urgent
            $t->string('audience', 24)->default('active');      // all|active|debtors|admins
            $t->boolean('is_published')->default(false);
            $t->boolean('pin')->default(false);
            $t->dateTime('publish_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->integer('views')->default(0);
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->index(['is_published', 'publish_at']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['announcements', 'access_schedules', 'devices', 'cars', 'contacts', 'residents'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
