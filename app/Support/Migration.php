<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class Migration
{
    public static function enableUuidOsspExtension(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS "uuid-ossp"');
    }

    public static function setUuidPrimaryKeyDefault(string $table, string $column = 'id'): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf(
            'ALTER TABLE %s ALTER COLUMN %s SET DEFAULT uuid_generate_v4()',
            self::wrapIdentifier($table),
            self::wrapIdentifier($column),
        ));
    }

    private static function wrapIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
