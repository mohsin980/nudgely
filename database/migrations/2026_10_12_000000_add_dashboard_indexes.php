<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the dashboard's per-organization counts and "latest" lists, so they stay
 * index lookups as customers, messages and follow-ups grow.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Recent customer replies / replies today; emails sent today.
            $table->index(['organization_id', 'direction', 'received_at']);
            $table->index(['organization_id', 'direction', 'sent_at']);
        });

        Schema::table('conversations', function (Blueprint $table) {
            // "Waiting for you" and the conversation status overview.
            $table->index(['organization_id', 'status', 'last_message_at']);
        });

        Schema::table('customers', function (Blueprint $table) {
            // New customers today.
            $table->index(['organization_id', 'created_at']);
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            // Follow-ups completed today.
            $table->index(['organization_id', 'completed_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('follow_ups', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'completed_at']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'created_at']));
        Schema::table('conversations', fn (Blueprint $table) => $table->dropIndex(['organization_id', 'status', 'last_message_at']));
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'direction', 'received_at']);
            $table->dropIndex(['organization_id', 'direction', 'sent_at']);
        });
    }
};
