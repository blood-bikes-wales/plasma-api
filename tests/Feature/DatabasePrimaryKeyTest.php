<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabasePrimaryKeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_primary_keys_are_not_integer_types(): void
    {
        $integerPrimaryKeys = match (DB::connection()->getDriverName()) {
            'pgsql' => $this->postgresIntegerPrimaryKeys(),
            'sqlite' => $this->sqliteIntegerPrimaryKeys(),
            default => $this->markTestSkipped('Unsupported database driver.'),
        };

        $this->assertEmpty(
            $integerPrimaryKeys,
            'Every primary key must use UUID or string types, never integers: '
            .json_encode($integerPrimaryKeys),
        );
    }

    public function test_uuid_primary_keys_default_to_uuid_generate_v4(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('uuid_generate_v4() defaults are PostgreSQL-only.');
        }

        $uuidPrimaryKeys = DB::select("
            SELECT c.table_name, c.column_name, c.column_default
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.columns c
                ON c.table_schema = kcu.table_schema
                AND c.table_name = kcu.table_name
                AND c.column_name = kcu.column_name
            WHERE tc.constraint_type = 'PRIMARY KEY'
                AND tc.table_schema = 'public'
                AND c.udt_name = 'uuid'
        ");

        $this->assertNotEmpty($uuidPrimaryKeys, 'Expected at least one UUID primary key.');

        foreach ($uuidPrimaryKeys as $column) {
            $this->assertStringContainsString(
                'uuid_generate_v4()',
                (string) $column->column_default,
                "Table {$column->table_name}.{$column->column_name} must default to uuid_generate_v4().",
            );
        }
    }

    /**
     * @return array<int, object{table_name: string, column_name: string, data_type: string}>
     */
    private function postgresIntegerPrimaryKeys(): array
    {
        return DB::select("
            SELECT c.table_name, c.column_name, c.data_type
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
                ON tc.constraint_name = kcu.constraint_name
                AND tc.table_schema = kcu.table_schema
            JOIN information_schema.columns c
                ON c.table_schema = kcu.table_schema
                AND c.table_name = kcu.table_name
                AND c.column_name = kcu.column_name
            WHERE tc.constraint_type = 'PRIMARY KEY'
                AND tc.table_schema = 'public'
                AND c.table_name != 'migrations'
                AND c.data_type IN ('bigint', 'integer', 'smallint')
        ");
    }

    /**
     * @return array<int, object{table_name: string, column_name: string, data_type: string}>
     */
    private function sqliteIntegerPrimaryKeys(): array
    {
        $tables = DB::select("
            SELECT name
            FROM sqlite_master
            WHERE type = 'table'
                AND name NOT LIKE 'sqlite_%'
                AND name != 'migrations'
        ");

        return $tables === []
            ? []
            : array_merge(...array_map(
                fn (object $table): array => $this->sqliteIntegerPrimaryKeyColumnsForTable((string) $table->name),
                $tables,
            ));
    }

    /**
     * @return array<int, object{table_name: string, column_name: string, data_type: string}>
     */
    private function sqliteIntegerPrimaryKeyColumnsForTable(string $tableName): array
    {
        $primaryKeyColumns = DB::select(
            'SELECT name AS column_name, type AS data_type FROM pragma_table_info(?) WHERE pk = 1',
            [$tableName],
        );

        return array_values(array_filter(array_map(
            function (object $column) use ($tableName): ?object {
                if (! in_array(strtoupper((string) $column->data_type), ['INTEGER', 'BIGINT', 'INT', 'INT2', 'INT8'], true)) {
                    return null;
                }

                return (object) [
                    'table_name' => $tableName,
                    'column_name' => $column->column_name,
                    'data_type' => $column->data_type,
                ];
            },
            $primaryKeyColumns,
        )));
    }
}
