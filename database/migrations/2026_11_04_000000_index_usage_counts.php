<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SA-09: the usage queries count rows per organization inside a time window (outbound email, new estimates,
 * completed follow-ups). These indexes keep those counts cheap with many organizations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->index(['organization_id', 'direction', 'created_at'], 'messages_org_direction_created_idx'));
        Schema::table('estimates', fn (Blueprint $table) => $table->index(['organization_id', 'revision', 'created_at'], 'estimates_org_revision_created_idx'));
        Schema::table('follow_ups', fn (Blueprint $table) => $table->index(['organization_id', 'status', 'completed_at'], 'follow_ups_org_status_completed_idx'));
    }

    public function down(): void
    {
        Schema::table('messages', fn (Blueprint $table) => $table->dropIndex('messages_org_direction_created_idx'));
        Schema::table('estimates', fn (Blueprint $table) => $table->dropIndex('estimates_org_revision_created_idx'));
        Schema::table('follow_ups', fn (Blueprint $table) => $table->dropIndex('follow_ups_org_status_completed_idx'));
    }
};
