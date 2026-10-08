<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Task 16A: defense in depth for multi-tenancy. The application always scopes by organization, but a
 * bug or a hand-written query must not be able to link one business's record to another's. A trigger
 * checks, on insert and on change of the link, that the record and the record it points to have the
 * same organization_id. (Plain foreign keys only prove the parent exists, not whose it is.)
 */
return new class extends Migration
{
    /** @var array<string, array<string, string>> table => [column => parent table] */
    private const LINKS = [
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

    public function up(): void
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

        foreach (self::LINKS as $table => $links) {
            foreach ($links as $column => $parent) {
                DB::unprepared(sprintf(
                    'CREATE TRIGGER %s BEFORE INSERT OR UPDATE OF %s ON %s FOR EACH ROW EXECUTE FUNCTION enforce_same_organization(%s, %s)',
                    $this->name($table, $column), $column, $table, "'{$parent}'", "'{$column}'",
                ));
            }
        }
    }

    public function down(): void
    {
        foreach (self::LINKS as $table => $links) {
            foreach (array_keys($links) as $column) {
                DB::unprepared(sprintf('DROP TRIGGER IF EXISTS %s ON %s', $this->name($table, $column), $table));
            }
        }

        DB::unprepared('DROP FUNCTION IF EXISTS enforce_same_organization()');
    }

    private function name(string $table, string $column): string
    {
        return "{$table}_{$column}_same_org";
    }
};
