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
        Schema::create('automations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('trigger_type', 64);
            // Nullable so removing a user never deletes the organization's automations.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Engine lookup: an organization's active automations for a trigger.
            $table->index(['organization_id', 'status', 'trigger_type']);
            // Target for composite foreign keys that pin child rows to the same organization.
            $table->unique(['id', 'organization_id']);
        });

        Schema::create('automation_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->string('operator', 32);
            // Compared as data by the engine (e.g. "interested", "0.8", "3"); never evaluated as code.
            $table->string('value', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['automation_id', 'sort_order']);
        });

        Schema::create('automation_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            // Per-action settings (e.g. {"delay_days": 2}); validated per action type by the engine.
            // A JSON column default must be an expression on MySQL (8.0.13+); a literal is refused.
            $table->jsonb('configuration')->default(DB::raw("('{}')"));
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('requires_approval')->default(false);
            $table->timestamps();

            $table->index(['automation_id', 'sort_order']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('automation_id');
            $table->unsignedBigInteger('organization_id');
            $table->string('event_type', 64);
            // Stable ID of the triggering event (e.g. "classification:42").
            $table->string('event_id', 100)->nullable();
            $table->string('status', 16);
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->foreign('organization_id')->references('id')->on('organizations')->cascadeOnDelete();
            // The run's organization must be its automation's organization.
            $table->foreign(['automation_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('automations')
                ->cascadeOnDelete();

            // Idempotency boundary: one run per automation per triggering event.
            $table->unique(['organization_id', 'automation_id', 'event_type', 'event_id'], 'automation_runs_idempotency_unique');
            $table->index(['organization_id', 'created_at']);
            $table->index(['automation_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('automation_action_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_run_id')->constrained()->cascadeOnDelete();
            // Kept as history if the action is later edited away.
            $table->foreignId('automation_action_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action_type', 64);
            $table->string('status', 16)->default('pending');
            $table->jsonb('result')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->timestamps();

            // Each action executes at most once per run.
            // Named explicitly: MySQL caps identifiers at 64 characters, and the generated name is longer.
            $table->unique(['automation_run_id', 'automation_action_id'], 'automation_action_runs_run_action_unique');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('automation_action_runs');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_actions');
        Schema::dropIfExists('automation_conditions');
        Schema::dropIfExists('automations');
    }
};
