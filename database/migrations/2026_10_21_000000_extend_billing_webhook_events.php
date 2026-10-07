<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 14C: webhook events get explicit names, a processing status and a little debug metadata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->renameColumn('event_id', 'provider_event_id');
            $table->renameColumn('type', 'event_type');
            $table->renameColumn('failure_reason', 'detail');
        });

        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->string('status', 16)->default('received')->after('event_type');
            $table->foreignId('organization_id')->nullable()->after('status')->constrained()->nullOnDelete();
            $table->jsonb('metadata')->nullable()->after('detail');
            $table->index('status');
        });

        DB::table('billing_webhook_events')->whereNotNull('processed_at')->update(['status' => 'processed']);
        DB::table('billing_webhook_events')->whereNull('processed_at')->whereNotNull('failed_at')->update(['status' => 'failed']);
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['status', 'metadata']);
        });

        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->renameColumn('provider_event_id', 'event_id');
            $table->renameColumn('event_type', 'type');
            $table->renameColumn('detail', 'failure_reason');
        });
    }
};
