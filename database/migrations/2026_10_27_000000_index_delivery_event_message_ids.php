<?php

use App\Support\Database\PartialIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Operators find a message's provider events by its provider message ID (email:trace). That lookup reads a
 * JSON field, so the field is copied into a generated column and indexed, limited to delivery events.
 */
return new class extends Migration
{
    public function up(): void
    {
        PartialIndex::jsonKey('webhook_events', 'delivery_message_id', 'payload', 'MessageID');
        PartialIndex::index('webhook_events', 'webhook_events_delivery_message_id_index', ['delivery_message_id'], "event_type = 'delivery_event'");
    }

    public function down(): void
    {
        PartialIndex::drop('webhook_events', 'webhook_events_delivery_message_id_index');

        Schema::table('webhook_events', fn ($table) => $table->dropColumn('delivery_message_id'));
    }
};
