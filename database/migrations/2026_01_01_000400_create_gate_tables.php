<?php

declare(strict_types=1);

use BasIp\Db\Blueprint;
use BasIp\Db\Database;
use BasIp\Db\Migration;
use BasIp\Db\Schema;

/**
 * Everything that touches the physical barrier: the immutable open/close log
 * and the guest passes (12-hour guest access by default).
 */
return new class implements Migration {
    public function describe(): string
    {
        return 'Журнал открываний шлагбаума и гостевые доступы';
    }

    public function up(Schema $schema, Database $db): void
    {
        $schema->create('gate_events', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->dateTime('occurred_at')->nullable();            // когда

            $t->bigInteger('device_id')->nullable();            // какой шлагбаум
            $t->integer('channel')->default(1);

            // кто
            $t->string('actor_type', 24)->default('system');    // resident|guest|admin|operator|system|api|panel|schedule
            $t->bigInteger('resident_id')->nullable();
            $t->bigInteger('user_id')->nullable();
            $t->bigInteger('guest_pass_id')->nullable();
            $t->string('actor_name', 190)->nullable();
            $t->string('apartment', 24)->nullable();

            // по какому доступу
            $t->string('access_type', 24)->default('own');      // own|guest|manual|remote|plate|rfid|scheduled|emergency|api|test
            $t->string('action', 16)->default('open');          // open|close|status|denied|error
            $t->string('source', 24)->default('web');           // web|cabinet|admin|api|cli|telegram|panel|cron
            $t->string('result', 16)->default('success');       // success|denied|error
            $t->string('reason', 190)->nullable();              // причина отказа / комментарий

            $t->string('plate', 32)->nullable();                // номер машины, если известен
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->string('request_id', 32)->nullable();
            $t->integer('duration_ms')->nullable();
            $t->text('device_response')->nullable();            // JSON сырого ответа панели
            $t->text('meta')->nullable();

            $t->index(['occurred_at']);
            $t->index(['resident_id', 'occurred_at']);
            $t->index(['access_type', 'occurred_at']);
            $t->index(['result']);
            $t->index(['guest_pass_id']);
            $t->index(['device_id', 'occurred_at']);
        });

        $schema->create('guest_passes', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->string('code', 24)->default('');                // человекочитаемый код: 7K4M-Q2XD
            $t->string('token_hash', 128)->default('');         // sha256 одноразовой ссылки
            $t->string('pin', 12)->nullable();                  // короткий PIN для клавиатуры панели

            $t->bigInteger('resident_id')->nullable();          // кто выдал
            $t->bigInteger('created_by')->nullable();           // user_id (админ) или resident_id
            $t->string('created_by_type', 16)->default('resident');

            $t->string('guest_name', 190)->nullable();
            $t->string('guest_phone', 32)->nullable();
            $t->string('car_plate', 32)->nullable();
            $t->string('car_plate_normalized', 32)->nullable();
            $t->string('purpose', 190)->nullable();             // «курьер», «гости», «ремонт»
            $t->integer('max_guests')->default(1);

            $t->dateTime('valid_from')->nullable();
            $t->dateTime('valid_until')->nullable();            // +12 часов по умолчанию
            $t->integer('ttl_hours')->default(12);
            $t->integer('max_uses')->default(0);                // 0 = без ограничений
            $t->integer('used_count')->default(0);
            $t->dateTime('last_used_at')->nullable();

            $t->string('status', 16)->default('active');        // active|expired|revoked|used
            $t->dateTime('revoked_at')->nullable();
            $t->bigInteger('revoked_by')->nullable();
            $t->string('revoke_reason', 190)->nullable();

            $t->string('ip_created', 45)->nullable();
            $t->string('ip_last_used', 45)->nullable();
            $t->boolean('notify_on_use')->default(false);
            $t->text('meta')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();

            $t->unique(['uuid']);
            $t->unique(['code']);
            $t->unique(['token_hash']);
            $t->index(['resident_id', 'status']);
            $t->index(['status', 'valid_until']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['guest_passes', 'gate_events'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
