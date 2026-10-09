<?php

declare(strict_types=1);

use BasIp\Db\Blueprint;
use BasIp\Db\Database;
use BasIp\Db\Migration;
use BasIp\Db\Schema;

/**
 * Billing model:
 *
 *   charge (начисление, "смета на месяц")
 *     └─ invoice (счёт на жителя)  ← kind: regular | adjustment | credit | manual
 *          └─ payment (фактическая оплата)
 *
 * Recalculation never mutates an issued invoice: it produces *adjustment*
 * invoices linked back through `parent_invoice_id` and a child `charge` whose
 * `recalc_of` points at the original. That keeps an auditable money trail.
 */
return new class implements Migration {
    public function describe(): string
    {
        return 'Начисления (сметы), счета, доп. счета при перерасчёте, оплаты';
    }

    public function up(Schema $schema, Database $db): void
    {
        $schema->create('charges', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->string('number', 48)->default('');              // CHG-2026-01
            $t->string('title', 190)->default('');
            $t->text('description')->nullable();

            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->string('period_label', 64)->default('');        // «Январь 2026»

            $t->decimal('total_amount', 14, 2)->default(0);     // общая сумма на обслуживание шлагбаума
            $t->integer('active_count')->default(0);            // число активных участников (снимок)
            $t->decimal('per_unit_amount', 14, 2)->default(0);  // total / active_count
            $t->string('allocation', 16)->default('equal');     // equal|by_persons|by_area|by_share
            $t->integer('due_days')->default(14);
            $t->date('due_date')->nullable();

            $t->text('message')->nullable();                    // текст рассылки
            $t->text('pay_link')->nullable();                   // ссылка на оплату
            $t->string('status', 16)->default('draft');         // draft|issued|closed|cancelled
            $t->bigInteger('recalc_of')->nullable();            // id исходного начисления
            $t->string('recalc_reason', 255)->nullable();

            $t->integer('invoices_total')->default(0);
            $t->decimal('invoices_amount', 14, 2)->default(0);
            $t->decimal('paid_amount', 14, 2)->default(0);
            $t->integer('paid_count')->default(0);

            $t->text('notes')->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('issued_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();

            $t->unique(['uuid']);
            $t->index(['status']);
            $t->index(['period_start']);
            $t->index(['recalc_of']);
        });

        $schema->create('invoices', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->string('number', 48)->default('');              // СЧ-2026-01-0042
            $t->bigInteger('charge_id')->nullable();
            $t->bigInteger('resident_id');
            $t->bigInteger('parent_invoice_id')->nullable();    // доп. счёт к ранее выставленному

            $t->string('kind', 16)->default('regular');         // regular|adjustment|credit|manual
            $t->decimal('amount', 14, 2)->default(0);           // signed: <0 = сторно
            $t->decimal('paid_amount', 14, 2)->default(0);
            $t->decimal('balance', 14, 2)->default(0);          // amount - paid_amount

            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->string('period_label', 64)->default('');
            $t->date('due_date')->nullable();
            $t->date('issued_date')->nullable();

            $t->string('status', 16)->default('pending');       // pending|partial|paid|void|overdue
            $t->string('pay_link', 512)->nullable();
            $t->text('note')->nullable();
            $t->string('recalc_reason', 255)->nullable();

            $t->dateTime('issued_at')->nullable();
            $t->dateTime('paid_at')->nullable();
            $t->dateTime('voided_at')->nullable();
            $t->bigInteger('created_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();

            $t->unique(['uuid']);
            $t->index(['resident_id', 'status']);
            $t->index(['charge_id']);
            $t->index(['status', 'due_date']);
            $t->index(['period_start']);
            $t->foreign('resident_id', 'id', 'residents', 'cascade');
        });

        $schema->create('payments', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->bigInteger('resident_id');
            $t->bigInteger('invoice_id')->nullable();
            $t->bigInteger('charge_id')->nullable();

            $t->decimal('amount', 14, 2)->default(0);
            // How much of `amount` was applied to invoices. The remainder is an
            // overpayment that stays on the resident's balance — without this
            // column a partial allocation would silently swallow the surplus.
            $t->decimal('allocated_amount', 14, 2)->default(0);
            $t->date('paid_at')->nullable();                    // дата оплаты
            $t->string('method', 24)->default('transfer');      // cash|card|transfer|online|sbp|other
            $t->string('reference', 120)->nullable();           // номер платёжки / чека
            $t->string('payer_name', 190)->nullable();
            $t->text('note')->nullable();
            $t->string('external_id', 96)->nullable();          // id из платёжной системы
            $t->boolean('is_confirmed')->default(true);
            $t->bigInteger('recorded_by')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();

            $t->unique(['uuid']);
            $t->index(['resident_id', 'paid_at']);
            $t->index(['invoice_id']);
            $t->index(['paid_at']);
            $t->foreign('resident_id', 'id', 'residents', 'cascade');
        });

        // History of every amount change — required for a defensible audit.
        $schema->create('billing_history', function (Blueprint $t): void {
            $t->id();
            $t->dateTime('occurred_at')->nullable();
            $t->bigInteger('resident_id')->nullable();
            $t->string('entity', 24)->default('invoice');       // charge|invoice|payment|balance
            $t->bigInteger('entity_id')->nullable();
            $t->string('action', 32)->default('');              // issued|recalculated|adjusted|paid|voided|reminder
            $t->decimal('amount', 14, 2)->default(0);
            $t->decimal('balance_before', 14, 2)->default(0);
            $t->decimal('balance_after', 14, 2)->default(0);
            $t->text('details')->nullable();                    // JSON
            $t->bigInteger('user_id')->nullable();
            $t->index(['resident_id', 'occurred_at']);
            $t->index(['entity', 'entity_id']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['billing_history', 'payments', 'invoices', 'charges'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
