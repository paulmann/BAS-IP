<?php

declare(strict_types=1);

use BasIp\Db\Blueprint;
use BasIp\Db\Database;
use BasIp\Db\Migration;
use BasIp\Db\Schema;

/**
 * Notification outbox (the mass-mailing queue), import/export jobs, backups
 * and the update tracker.
 */
return new class implements Migration {
    public function describe(): string
    {
        return 'Очередь уведомлений/рассылок, импорт-экспорт, резервные копии, автообновление';
    }

    public function up(Schema $schema, Database $db): void
    {
        $schema->create('notification_batches', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->string('name', 190)->default('');
            $t->string('template', 64)->default('custom');
            $t->string('subject', 255)->nullable();
            $t->text('message')->nullable();
            $t->text('pay_link')->nullable();
            $t->bigInteger('charge_id')->nullable();
            $t->text('channels')->nullable();                   // JSON ["mail","telegram"]
            $t->text('audience')->nullable();                   // JSON filter snapshot
            $t->integer('recipients')->default(0);
            $t->integer('sent')->default(0);
            $t->integer('failed')->default(0);
            $t->integer('skipped')->default(0);
            $t->string('status', 16)->default('queued');        // queued|running|done|partial|failed|cancelled
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('finished_at')->nullable();
            $t->unique(['uuid']);
            $t->index(['status']);
        });

        $schema->create('outbox', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->bigInteger('batch_id')->nullable();
            $t->string('channel', 24)->default('log');          // log|mail|telegram|sms|webhook|whatsapp
            $t->bigInteger('resident_id')->nullable();
            $t->string('recipient', 255)->default('');          // e-mail / phone / chat id
            $t->string('subject', 255)->nullable();
            $t->text('body')->nullable();
            $t->string('template', 64)->nullable();
            $t->text('context')->nullable();                    // JSON placeholders
            $t->string('status', 16)->default('pending');       // pending|processing|sent|failed|skipped|cancelled
            $t->integer('attempts')->default(0);
            $t->integer('max_attempts')->default(5);
            $t->text('last_error')->nullable();
            $t->string('provider_id', 128)->nullable();
            $t->dateTime('scheduled_at')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->unique(['uuid']);
            $t->index(['status', 'scheduled_at']);
            $t->index(['batch_id']);
            $t->index(['resident_id']);
        });

        $schema->create('import_jobs', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->string('kind', 24)->default('residents');       // residents|payments|cars|settings
            $t->string('filename', 255)->default('');
            $t->string('format', 12)->default('csv');
            $t->string('mode', 16)->default('upsert');          // upsert|insert|replace|dry-run
            $t->integer('rows_total')->default(0);
            $t->integer('rows_imported')->default(0);
            $t->integer('rows_skipped')->default(0);
            $t->integer('rows_failed')->default(0);
            $t->text('errors')->nullable();                     // JSON (max 200 entries)
            $t->text('mapping')->nullable();                    // JSON column map
            $t->string('status', 16)->default('running');       // running|done|failed|cancelled
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('started_at')->nullable();
            $t->dateTime('finished_at')->nullable();
            $t->unique(['uuid']);
            $t->index(['kind', 'started_at']);
        });

        $schema->create('export_jobs', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->string('kind', 32)->default('residents');
            $t->string('format', 12)->default('csv');
            $t->string('filename', 255)->default('');
            $t->integer('rows')->default(0);
            $t->integer('size_bytes')->default(0);
            $t->text('filters')->nullable();                    // JSON
            $t->bigInteger('created_by')->nullable();
            $t->string('ip', 45)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->unique(['uuid']);
            $t->index(['created_at']);
        });

        $schema->create('backups', function (Blueprint $t): void {
            $t->id();
            $t->string('filename', 255)->default('');
            $t->string('path', 512)->default('');
            $t->string('type', 16)->default('db');              // db|full
            $t->integer('size_bytes')->default(0);
            $t->string('checksum', 64)->nullable();
            $t->string('driver', 24)->nullable();
            $t->boolean('encrypted')->default(false);
            $t->string('status', 16)->default('done');          // done|failed|restored
            $t->text('note')->nullable();
            $t->text('meta')->nullable();                       // JSON: tables, rows, versions
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->index(['created_at']);
        });

        $schema->create('app_state', function (Blueprint $t): void {
            $t->string('key', 120)->primary();
            $t->text('value')->nullable();
            $t->dateTime('updated_at')->nullable();
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['app_state', 'backups', 'export_jobs', 'import_jobs', 'outbox', 'notification_batches'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
