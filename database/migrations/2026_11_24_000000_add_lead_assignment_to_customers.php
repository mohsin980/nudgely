<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A lead can be given to a team member to handle. Leads that are qualified or quoted but haven't reached Won or
 * Lost get a reminder to record the outcome; the count and time of the last reminder are kept here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('assigned_to')->nullable()->after('lead_stage_changed_at')->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('outcome_reminders')->default(0)->after('assigned_to');
            $table->timestampTz('outcome_reminded_at')->nullable()->after('outcome_reminders');

            $table->index(['organization_id', 'assigned_to']);
            $table->index(['lead_stage', 'lead_stage_changed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['lead_stage', 'lead_stage_changed_at']);
            $table->dropIndex(['organization_id', 'assigned_to']);
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropColumn(['outcome_reminders', 'outcome_reminded_at']);
        });
    }
};
