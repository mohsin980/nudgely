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
        Schema::table('organizations', function (Blueprint $table) {
            // IANA name, e.g. America/Chicago. Null means config('follow_ups.default_timezone').
            $table->string('timezone', 64)->nullable();
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->string('type', 16)->default('manual')->after('automation_run_id');
            $table->foreignId('created_by')->nullable()->after('type')->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancelled_reason', 32)->nullable();
            $table->string('skip_reason', 32)->nullable();
            $table->text('notes')->nullable();
            $table->text('completion_notes')->nullable();
            $table->jsonb('metadata')->nullable();
            // Set when the owner was told the follow-up is due / overdue, so each notice is sent once.
            $table->timestampTz('due_notified_at')->nullable();
            $table->timestampTz('overdue_notified_at')->nullable();

            // A manual reminder may have no conversation and no email template.
            $table->foreignId('conversation_id')->nullable()->change();
            $table->string('subject', 200)->nullable()->change();
            $table->text('body')->nullable()->change();

            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['customer_id', 'status']);
        });

        // Follow-ups created before this migration came from automations. "processing" no longer exists:
        // in-flight work is protected by a row lock instead.
        DB::table('follow_ups')->update(['type' => 'automated']);
        DB::table('follow_ups')->where('status', 'processing')->update(['status' => 'due']);
        DB::table('follow_ups')->where('status', 'completed')->whereNull('completed_at')->update(['completed_at' => DB::raw('processed_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('follow_ups')->whereIn('status', ['due', 'cancelled'])->update(['status' => 'skipped']);
        DB::table('follow_ups')->whereNull('conversation_id')->delete();

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'status', 'due_at']);
            $table->dropIndex(['customer_id', 'status']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('assigned_to');
            $table->dropConstrainedForeignId('completed_by');
            $table->dropColumn([
                'type', 'completed_at', 'cancelled_at', 'cancelled_reason', 'skip_reason', 'notes',
                'completion_notes', 'metadata', 'due_notified_at', 'overdue_notified_at',
            ]);
        });

        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('timezone'));
    }
};
