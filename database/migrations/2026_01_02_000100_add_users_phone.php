<?php

declare(strict_types=1);

use BasIp\Db\Blueprint;
use BasIp\Db\Database;
use BasIp\Db\Migration;
use BasIp\Db\Schema;

/**
 * Contact phone for user accounts.
 *
 * The installer collects the administrator's phone; it is also useful for
 * staff notifications and operational contact (2FA recovery by the admin).
 */
return new class implements Migration {
    public function describe(): string
    {
        return 'users.phone — контактный телефон учётной записи';
    }

    public function up(Schema $schema, Database $db): void
    {
        if ($schema->hasColumn('users', 'phone')) {
            return;
        }

        $schema->table('users', function (Blueprint $t): void {
            $t->string('phone', 32)->nullable();
        });
    }

    public function down(Schema $schema, Database $db): void
    {
        if (!$schema->hasColumn('users', 'phone')) {
            return;
        }

        $schema->table('users', function (Blueprint $t): void {
            $t->dropColumn('phone');
        });
    }
};
