<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task 14F: when payment first failed (starts the grace period), when billing restrictions were
 * applied, and when a sign-up trial was found to have ended (so the expiry runs once).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->timestampTz('past_due_since')->nullable();
            $table->timestampTz('restricted_at')->nullable();
        });

        Schema::table('organizations', fn (Blueprint $table) => $table->timestampTz('trial_expired_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('trial_expired_at'));
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn(['past_due_since', 'restricted_at']));
    }
};
