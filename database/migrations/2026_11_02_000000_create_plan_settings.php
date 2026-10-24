<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SA-07: administrator overrides for plans. Plans, prices, intervals and provider price IDs stay in
 * config/billing.php (the billing provider owns what is charged); a row here may only change a plan's display
 * name and its limits. platform_audit_logs.changes records what changed (old and new values).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_settings', function (Blueprint $table) {
            $table->id();
            $table->string('plan_key', 32)->unique();
            $table->string('name', 40)->nullable();
            $table->json('limits')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->json('changes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('platform_audit_logs', fn (Blueprint $table) => $table->dropColumn('changes'));
        Schema::dropIfExists('plan_settings');
    }
};
