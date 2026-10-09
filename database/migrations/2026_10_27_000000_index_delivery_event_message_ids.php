<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Operators find a message's provider events by its provider message ID (email:trace). That lookup reads a
 * JSON field, so it gets an expression index limited to delivery events.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE INDEX webhook_events_delivery_message_id_index ON webhook_events ((payload->>'MessageID')) WHERE event_type = 'delivery_event'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS webhook_events_delivery_message_id_index');
    }
};
