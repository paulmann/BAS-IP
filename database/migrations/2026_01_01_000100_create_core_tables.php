<?php

declare(strict_types=1);

use BasIp\Db\Blueprint;
use BasIp\Db\Database;
use BasIp\Db\Migration;
use BasIp\Db\Schema;

/**
 * Core / identity tables: accounts, settings, sessions, login attempts,
 * API tokens and the audit trail.
 */
return new class implements Migration {
    public function describe(): string
    {
        return 'Учётные записи, настройки, сессии, попытки входа, API-токены, аудит';
    }

    public function up(Schema $schema, Database $db): void
    {
        $schema->create('users', function (Blueprint $t): void {
            $t->id();
            $t->char('uuid', 36)->default('');
            $t->string('login', 64)->default('');
            $t->string('email', 190)->nullable();
            $t->string('password_hash', 255)->default('');
            $t->string('full_name', 190)->default('');
            $t->string('role', 16)->default('resident');      // admin | operator | resident
            $t->bigInteger('resident_id')->nullable();         // link to residents.id for role=resident
            $t->boolean('is_active')->default(true);
            $t->boolean('must_change_password')->default(false);
            $t->string('remember_token', 100)->nullable();
            $t->string('totp_secret', 255)->nullable();        // encrypted at rest
            $t->boolean('totp_enabled')->default(false);
            $t->text('recovery_codes')->nullable();            // encrypted JSON
            $t->integer('failed_attempts')->default(0);
            $t->dateTime('locked_until')->nullable();
            $t->dateTime('last_login_at')->nullable();
            $t->string('last_login_ip', 45)->nullable();
            $t->dateTime('password_changed_at')->nullable();
            $t->text('preferences')->nullable();               // JSON: theme, locale, per_page…
            $t->string('locale', 8)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->dateTime('deleted_at')->nullable();
            $t->unique(['login']);
            $t->index(['role', 'is_active']);
            $t->index(['resident_id']);
        });

        $schema->create('settings', function (Blueprint $t): void {
            $t->string('key', 120)->primary();
            $t->text('value')->nullable();
            $t->string('type', 12)->default('string');         // string|int|float|bool|json
            $t->string('group', 40)->default('general');
            $t->boolean('is_secret')->default(false);
            $t->boolean('is_public')->default(false);          // safe to expose to the JS layer
            $t->string('updated_by', 64)->nullable();
            $t->dateTime('updated_at')->nullable();
            $t->index(['group']);
        });

        $schema->create('sessions_meta', function (Blueprint $t): void {
            $t->id();
            $t->string('session_id', 128)->default('');
            $t->bigInteger('user_id')->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->dateTime('last_used_at')->nullable();
            $t->dateTime('expires_at')->nullable();
            $t->unique(['session_id']);
            $t->index(['user_id']);
        });

        $schema->create('login_attempts', function (Blueprint $t): void {
            $t->id();
            $t->string('identifier', 190)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->boolean('successful')->default(false);
            $t->bigInteger('user_id')->nullable();
            $t->string('reason', 64)->nullable();
            $t->string('method', 16)->default('password');     // password|totp|recovery|token
            $t->dateTime('created_at')->nullable();
            $t->index(['ip', 'created_at']);
            $t->index(['identifier', 'created_at']);
        });

        $schema->create('api_tokens', function (Blueprint $t): void {
            $t->id();
            $t->string('name', 120)->default('');
            $t->string('token_hash', 128)->default('');
            $t->string('prefix', 16)->nullable();              // first chars, for display
            $t->text('scopes')->nullable();                    // JSON array
            $t->bigInteger('user_id')->nullable();
            $t->boolean('is_active')->default(true);
            $t->dateTime('expires_at')->nullable();
            $t->dateTime('last_used_at')->nullable();
            $t->string('last_used_ip', 45)->nullable();
            $t->dateTime('created_at')->nullable();
            $t->string('created_by', 64)->nullable();
            $t->unique(['token_hash']);
            $t->index(['is_active']);
        });

        $schema->create('audit_log', function (Blueprint $t): void {
            $t->id();
            $t->dateTime('occurred_at')->nullable();
            $t->bigInteger('user_id')->nullable();
            $t->string('actor_name', 120)->nullable();
            $t->string('actor_role', 24)->nullable();
            $t->string('action', 48)->default('');             // login, logout, create, update, delete, gate.open…
            $t->string('subject_type', 48)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->string('subject_label', 190)->nullable();
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 512)->nullable();
            $t->string('request_id', 32)->nullable();
            $t->text('changes')->nullable();                   // JSON {before, after}
            $t->text('meta')->nullable();                      // JSON
            $t->boolean('is_security')->default(false);
            $t->index(['occurred_at']);
            $t->index(['user_id', 'occurred_at']);
            $t->index(['subject_type', 'subject_id']);
            $t->index(['action']);
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        foreach (['audit_log', 'api_tokens', 'login_attempts', 'sessions_meta', 'settings', 'users'] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
