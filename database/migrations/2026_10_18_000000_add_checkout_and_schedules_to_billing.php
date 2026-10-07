<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 14B: one free trial per organization, and plan changes scheduled for the period end.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Set when the organization's first trial starts; a business never gets a second one.
            $table->timestampTz('trial_used_at')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            // A downgrade waiting for the end of the paid period (the provider's schedule, if any).
            $table->string('scheduled_plan', 32)->nullable()->after('plan');
            $table->timestampTz('scheduled_change_at')->nullable()->after('scheduled_plan');
            $table->string('provider_schedule_id', 255)->nullable()->after('provider_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['scheduled_plan', 'scheduled_change_at', 'provider_schedule_id']));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('trial_used_at'));
    }
};
