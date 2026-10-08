<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 15: onboarding progress lives on the organization (the server always knows where a business
 * is), the business type, and a flag marking sample customers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('business_type', 32)->nullable();
            $table->string('onboarding_step', 32)->nullable();
            $table->jsonb('onboarding_progress')->nullable();
            $table->timestampTz('onboarding_started_at')->nullable();
            $table->timestampTz('onboarding_completed_at')->nullable();
            $table->timestampTz('onboarding_skipped_at')->nullable();
            $table->timestampTz('onboarding_checklist_done_at')->nullable();
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false);
            $table->index(['organization_id', 'is_demo']);
        });

        // Businesses that existed before onboarding are already set up.
        DB::table('organizations')->update(['onboarding_completed_at' => now(), 'onboarding_checklist_done_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'is_demo']);
            $table->dropColumn('is_demo');
        });

        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn([
            'business_type', 'onboarding_step', 'onboarding_progress', 'onboarding_started_at',
            'onboarding_completed_at', 'onboarding_skipped_at', 'onboarding_checklist_done_at',
        ]));
    }
};
