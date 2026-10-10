<?php

use App\Support\Database\PartialIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The business-facing automation builder (Task 12): WHEN → WAIT → IF (all/any) → THEN.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('automations', function (Blueprint $table) {
            // "all": every condition must match; "any": at least one.
            $table->string('condition_match', 8)->default('all')->after('trigger_type');
            // WAIT between the trigger and checking conditions (null = no wait).
            $table->unsignedInteger('wait_minutes')->nullable()->after('condition_match');
            $table->timestampTz('archived_at')->nullable();
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            // The customer the run is about (for logs and the customer timeline), set from the event.
            $table->foreignId('customer_id')->nullable()->after('conversation_id')->constrained()->nullOnDelete();
            // A waiting run resumes at this time (conditions are checked then).
            $table->timestampTz('resume_at')->nullable()->after('status');
            // What each condition compared, for the execution detail.
            $table->jsonb('condition_results')->nullable();

            $table->index(['customer_id', 'created_at']);
        });

        // Existing runs: their customer is the conversation's.
        DB::statement('update automation_runs set customer_id = (select c.customer_id from conversations c where c.id = automation_runs.conversation_id) where customer_id is null');

        // The scheduler's lookup of waiting runs that are due.
        PartialIndex::index('automation_runs', 'automation_runs_waiting_index', ['resume_at'], "status = 'waiting'");

        Schema::table('automation_action_runs', function (Blueprint $table) {
            // How many times a worker started this action (retries included).
            $table->unsignedSmallInteger('attempts')->default(0);
        });

        // Changes people made to automations (created, edited, activated, paused, archived, …).
        Schema::create('automation_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 32);
            $table->jsonb('data')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['automation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_history');
        Schema::table('automation_action_runs', fn (Blueprint $table) => $table->dropColumn('attempts'));
        PartialIndex::drop('automation_runs', 'automation_runs_waiting_index');
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'created_at']);
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['resume_at', 'condition_results']);
        });
        Schema::table('automations', fn (Blueprint $table) => $table->dropColumn(['condition_match', 'wait_minutes', 'archived_at']));
    }
};
