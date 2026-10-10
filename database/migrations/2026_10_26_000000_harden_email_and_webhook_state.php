<?php

use App\Support\Database\PartialIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 16C: explicit delivery timestamps and correlation on messages, a lifecycle with attempt
 * counts on webhook events, and a suppression record on customers whose address bounced or complained.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('bounced_at')->nullable();
            $table->timestampTz('complained_at')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->unsignedSmallInteger('send_attempts')->default(0);
            $table->index(['status', 'created_at']);
        });

        // One outbound message per provider message ID: a duplicate delivery event can never match two rows.
        PartialIndex::unique('messages', 'messages_outbound_provider_message_unique', ['provider', 'provider_message_id'], "direction = 'outbound' AND provider_message_id IS NOT NULL");

        Schema::table('webhook_events', function (Blueprint $table) {
            // Set once the event is traced to a tenant (from a reply route or a message), never from the payload.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('received');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->timestampTz('received_at')->nullable();
            $table->string('correlation_id', 64)->nullable();
            $table->char('payload_hash', 64)->nullable();
            $table->index(['status', 'created_at']);
            $table->index(['organization_id', 'created_at']);
        });

        DB::table('webhook_events')->whereNotNull('processed_at')->update(['status' => 'processed']);
        DB::table('webhook_events')->whereNull('processed_at')->whereNotNull('failed_at')->update(['status' => 'failed']);
        DB::table('webhook_events')->update(['received_at' => DB::raw('created_at')]);

        Schema::table('customers', function (Blueprint $table) {
            // The address that bounced or complained. It only suppresses while it still equals the customer's
            // email, so correcting the address re-enables sending without any manual step.
            $table->string('email_suppressed_address', 254)->nullable();
            $table->string('email_suppression_reason', 16)->nullable();
            $table->timestampTz('email_suppressed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['email_suppressed_address', 'email_suppression_reason', 'email_suppressed_at']);
        });

        // The foreign key goes first: MySQL will not drop an index that a foreign key still needs.
        Schema::table('webhook_events', function (Blueprint $table) {
            $table->dropForeign(['organization_id']);
            $table->dropIndex(['status', 'created_at']);
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropColumn(['organization_id', 'status', 'attempt_count', 'received_at', 'correlation_id', 'payload_hash']);
        });

        PartialIndex::drop('messages', 'messages_outbound_provider_message_unique');

        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn(['delivered_at', 'bounced_at', 'complained_at', 'correlation_id', 'send_attempts']);
        });
    }
};
