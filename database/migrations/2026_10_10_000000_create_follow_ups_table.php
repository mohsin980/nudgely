<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            // History survives edits: the automation or action may later be removed.
            $table->foreignId('automation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('automation_action_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('automation_run_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 200);
            $table->text('body');
            $table->string('status', 16)->default('pending');
            $table->timestampTz('due_at');
            $table->timestampTz('processed_at')->nullable();
            // Safe, user-facing outcome such as "Follow-up skipped: Customer replied."
            $table->string('outcome')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            // One follow-up per action per triggering event.
            $table->char('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            // Scheduler: due pending follow-ups. Cancellation: pending follow-ups of a conversation.
            $table->index(['status', 'due_at']);
            $table->index(['conversation_id', 'status']);
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            // For the conversation timeline; set only after the conversation is verified to be in the run's organization.
            $table->foreignId('conversation_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            $table->index(['conversation_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'created_at']);
            $table->dropConstrainedForeignId('conversation_id');
        });

        Schema::dropIfExists('follow_ups');
    }
};
