<?php

namespace App\Support\Database;

use Illuminate\Support\Facades\DB;

/**
 * Database-level tenant checks: a record and the record it points to must belong to the same organization.
 * Plain foreign keys only prove the parent exists, not whose it is.
 *
 * PostgreSQL uses one trigger function shared by every link. MySQL cannot share a function across triggers
 * with this check, so each link gets its own insert and update trigger with the check inline.
 */
final class TenantIntegrity
{
    /** @var array<string, array<string, string>> table => [column => parent table] */
    public const LINKS = [
        'conversations' => ['customer_id' => 'customers'],
        'messages' => ['conversation_id' => 'conversations', 'email_connection_id' => 'email_connections'],
        'estimates' => ['customer_id' => 'customers', 'conversation_id' => 'conversations', 'created_by' => 'users'],
        'follow_ups' => ['customer_id' => 'customers', 'conversation_id' => 'conversations', 'automation_id' => 'automations', 'message_id' => 'messages', 'estimate_id' => 'estimates', 'assigned_to' => 'users', 'created_by' => 'users'],
        'tasks' => ['customer_id' => 'customers', 'conversation_id' => 'conversations', 'assigned_to' => 'users', 'created_by' => 'users'],
        'automations' => ['created_by' => 'users'],
        'automation_runs' => ['automation_id' => 'automations', 'conversation_id' => 'conversations', 'customer_id' => 'customers'],
        'email_reply_routes' => ['conversation_id' => 'conversations'],
        'message_classifications' => ['conversation_id' => 'conversations', 'message_id' => 'messages'],
        'conversation_events' => ['conversation_id' => 'conversations', 'customer_id' => 'customers', 'estimate_id' => 'estimates'],
    ];

    public static function install(): void
    {
        if (self::isPostgres()) {
            self::installPostgresFunction();
        }

        foreach (array_keys(self::LINKS) as $table) {
            self::installFor($table);
        }
    }

    public static function remove(): void
    {
        foreach (self::LINKS as $table => $links) {
            self::removeFor($table);
        }

        if (self::isPostgres()) {
            DB::unprepared('DROP FUNCTION IF EXISTS enforce_same_organization()');
        }
    }

    /** Recreates the checks on one table (used by tests that must write inconsistent data). */
    public static function installFor(string $table): void
    {
        foreach (self::LINKS[$table] ?? [] as $column => $parent) {
            if (self::isPostgres()) {
                DB::unprepared(sprintf(
                    'CREATE TRIGGER %s BEFORE INSERT OR UPDATE OF %s ON %s FOR EACH ROW EXECUTE FUNCTION enforce_same_organization(%s, %s)',
                    self::name($table, $column), $column, $table, "'{$parent}'", "'{$column}'",
                ));

                continue;
            }

            // PostgreSQL's "BEFORE UPDATE OF column" fires only when the link changes; the update trigger matches that.
            DB::unprepared(self::mysqlTrigger(self::name($table, $column).'_insert', $table, $column, $parent, 'INSERT', false));
            DB::unprepared(self::mysqlTrigger(self::name($table, $column).'_update', $table, $column, $parent, 'UPDATE', true));
        }
    }

    /** Drops the checks on one table. */
    public static function removeFor(string $table): void
    {
        foreach (array_keys(self::LINKS[$table] ?? []) as $column) {
            if (self::isPostgres()) {
                DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s ON %s', self::name($table, $column), $table));

                continue;
            }

            foreach (['insert', 'update'] as $suffix) {
                DB::unprepared('DROP TRIGGER IF EXISTS '.self::name($table, $column).'_'.$suffix);
            }
        }
    }

    private static function installPostgresFunction(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION enforce_same_organization() RETURNS trigger AS $$
            DECLARE
                parent_table text := TG_ARGV[0];
                link_column text := TG_ARGV[1];
                link_id bigint;
                parent_org bigint;
            BEGIN
                EXECUTE format('SELECT ($1).%I', link_column) INTO link_id USING NEW;

                IF link_id IS NULL OR NEW.organization_id IS NULL THEN
                    RETURN NEW;
                END IF;

                EXECUTE format('SELECT organization_id FROM %I WHERE id = $1', parent_table) INTO parent_org USING link_id;

                IF parent_org IS DISTINCT FROM NEW.organization_id THEN
                    RAISE EXCEPTION 'Tenant integrity: %.% refers to a record that belongs to another organization', TG_TABLE_NAME, link_column USING ERRCODE = '23514';
                END IF;

                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;
        SQL);
    }

    private static function mysqlTrigger(string $name, string $table, string $column, string $parent, string $event, bool $onlyWhenLinkChanges): string
    {
        $message = "Tenant integrity: {$table}.{$column} refers to a record that belongs to another organization";
        $changed = $onlyWhenLinkChanges ? "NOT (NEW.{$column} <=> OLD.{$column}) AND " : '';

        return <<<SQL
            CREATE TRIGGER {$name} BEFORE {$event} ON {$table} FOR EACH ROW
            BEGIN
                DECLARE parent_org BIGINT UNSIGNED;

                IF {$changed}NEW.{$column} IS NOT NULL AND NEW.organization_id IS NOT NULL THEN
                    SET parent_org = (SELECT organization_id FROM {$parent} WHERE id = NEW.{$column});

                    IF parent_org IS NULL OR parent_org <> NEW.organization_id THEN
                        SIGNAL SQLSTATE '23000' SET MESSAGE_TEXT = '{$message}';
                    END IF;
                END IF;
            END
            SQL;
    }

    private static function name(string $table, string $column): string
    {
        return "{$table}_{$column}_same_org";
    }

    private static function isPostgres(): bool
    {
        return DB::getDriverName() === 'pgsql';
    }
}
