<?php

declare(strict_types=1);

namespace BasIp\Db;

/**
 * Contract implemented by every file in `database/migrations`.
 *
 * Migrations are plain PHP files returning an anonymous class — no code
 * generation, no diffing tool, and they run identically on MySQL, PostgreSQL
 * and SQLite.
 */
interface Migration
{
    public function up(Schema $schema, Database $db): void;

    public function down(Schema $schema, Database $db): void;

    /** Optional human description shown by `bin/bas-ip migrate:status`. */
    public function describe(): string;
}
