<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Packages managed from the admin panel. plan_settings now holds every package an administrator has created or
 * edited, plans and add-ons alike (`kind`). Config/billing.php stays the default: a package with no row uses its
 * configured values. Prices are minor units per currency; provider price IDs are per currency.
 * legacy_provider_price_ids keeps old Stripe prices mapped to their package, because a Stripe price's amount
 * cannot change, so existing subscribers keep the price they started on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_settings', function (Blueprint $table) {
            $table->string('kind', 10)->default('plan')->after('plan_key');
            $table->string('description', 500)->nullable()->after('name');
            $table->json('prices')->nullable()->after('limits');
            $table->json('provider_price_ids')->nullable()->after('prices');
            $table->json('legacy_provider_price_ids')->nullable()->after('provider_price_ids');
            $table->json('features')->nullable()->after('legacy_provider_price_ids');
            $table->json('applies_to')->nullable()->after('features');
            $table->boolean('active')->default(true)->after('applies_to');
            $table->unsignedSmallInteger('sort_order')->default(0)->after('active');
            $table->index(['kind', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('plan_settings', function (Blueprint $table) {
            $table->dropIndex(['kind', 'active']);
            $table->dropColumn(['kind', 'description', 'prices', 'provider_price_ids', 'legacy_provider_price_ids', 'features', 'applies_to', 'active', 'sort_order']);
        });
    }
};
