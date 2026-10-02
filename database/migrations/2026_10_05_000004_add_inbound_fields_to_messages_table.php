<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->foreignId('conversation_id')->nullable()->after('organization_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('email_reply_route_id')->nullable()->after('email_connection_id')->constrained()->nullOnDelete();
            $table->string('header_message_id', 998)->nullable()->after('provider_message_id');
            $table->string('in_reply_to', 998)->nullable()->after('header_message_id');
            $table->text('references')->nullable()->after('in_reply_to');
            $table->timestampTz('received_at')->nullable()->after('sent_at');
        });

        // The same inbound email can never be stored twice, even under concurrent webhook deliveries.
        DB::statement(
            'CREATE UNIQUE INDEX messages_inbound_provider_message_unique '
            ."ON messages (provider, provider_message_id) WHERE direction = 'inbound'"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS messages_inbound_provider_message_unique');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('conversation_id');
            $table->dropConstrainedForeignId('email_reply_route_id');
            $table->dropColumn(['header_message_id', 'in_reply_to', 'references', 'received_at']);
        });
    }
};
