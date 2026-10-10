<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Partial indexes ("index these rows where ...") for migrations, on both PostgreSQL and MySQL.
 *
 * PostgreSQL has partial indexes natively. MySQL does not, so a unique partial index becomes a
 * generated column that is NULL outside the condition, plus a unique index over (columns, key).
 * A unique index never treats NULLs as equal, so only rows matching the condition can collide.
 *
 * Non-unique partial indexes become plain indexes on MySQL: they stay correct, just less selective.
 */
final class PartialIndex
{
    public static function unique(string $table, string $name, array $columns, string $where): void
    {
        if (self::isPostgres()) {
            DB::statement(sprintf('CREATE UNIQUE INDEX %s ON %s (%s) WHERE %s', $name, $table, implode(', ', $columns), $where));

            return;
        }

        $key = self::keyColumn($name);
        DB::statement(sprintf('ALTER TABLE %s ADD COLUMN %s TINYINT GENERATED ALWAYS AS (CASE WHEN %s THEN 1 END) VIRTUAL', $table, $key, $where));
        DB::statement(sprintf('CREATE UNIQUE INDEX %s ON %s (%s)', $name, $table, implode(', ', [...$columns, $key])));
    }

    public static function index(string $table, string $name, array $columns, string $where): void
    {
        if (self::isPostgres()) {
            DB::statement(sprintf('CREATE INDEX %s ON %s (%s) WHERE %s', $name, $table, implode(', ', $columns), $where));

            return;
        }

        DB::statement(sprintf('CREATE INDEX %s ON %s (%s)', $name, $table, implode(', ', $columns)));
    }

    /**
     * A string column holding one key of a JSON column, kept in step by the database. Index and query the
     * column instead of the JSON path, which is written differently in PostgreSQL and MySQL.
     */
    public static function jsonKey(string $table, string $column, string $json, string $key): void
    {
        if (self::isPostgres()) {
            DB::statement(sprintf("ALTER TABLE %s ADD COLUMN %s TEXT GENERATED ALWAYS AS ((%s->>'%s')) STORED", $table, $column, $json, $key));

            return;
        }

        DB::statement(sprintf("ALTER TABLE %s ADD COLUMN %s VARCHAR(255) GENERATED ALWAYS AS (JSON_UNQUOTE(JSON_EXTRACT(%s, '$.%s'))) VIRTUAL", $table, $column, $json, $key));
    }

    public static function drop(string $table, string $name): void
    {
        if (self::isPostgres()) {
            DB::statement("DROP INDEX IF EXISTS {$name}");

            return;
        }

        if (self::mysqlIndexExists($table, $name)) {
            DB::statement("DROP INDEX {$name} ON {$table}");
        }

        $key = self::keyColumn($name);
        if (Schema::hasColumn($table, $key)) {
            DB::statement("ALTER TABLE {$table} DROP COLUMN {$key}");
        }
    }

    private static function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }

    private static function keyColumn(string $name): string
    {
        return "{$name}_key";
    }

    private static function mysqlIndexExists(string $table, string $name): bool
    {
        return DB::selectOne(
            'select 1 from information_schema.statistics where table_schema = database() and table_name = ? and index_name = ? limit 1',
            [$table, $name],
        ) !== null;
    }
}
