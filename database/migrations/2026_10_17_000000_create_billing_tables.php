<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 14A: subscriptions and the organization's billing customer.
 * Plans themselves live in config/billing.php, not in the database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // The organization's customer record at the billing provider.
            $table->string('billing_provider', 32)->nullable();
            $table->string('billing_customer_id', 255)->nullable();
            $table->unique(['billing_provider', 'billing_customer_id']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_subscription_id', 255);
            $table->string('plan', 32);
            $table->string('status', 16);
            $table->timestampTz('trial_ends_at')->nullable();
            $table->timestampTz('current_period_start')->nullable();
            $table->timestampTz('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestampTz('canceled_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_subscription_id']);
            $table->index(['organization_id', 'created_at']);
        });

        // At most one current (not cancelled or expired) subscription per organization.
        DB::statement("create unique index subscriptions_one_current_per_organization on subscriptions (organization_id) where status not in ('cancelled', 'expired')");
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['billing_provider', 'billing_customer_id']);
            $table->dropColumn(['billing_provider', 'billing_customer_id']);
        });
    }
};
