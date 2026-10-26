<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SA-08: when the billing provider last confirmed this subscription (webhook, scheduled sync or an administrator's
 * refresh). Lets the Super Admin panel tell confirmed, stale and never-confirmed data apart. Existing rows stay null
 * ("not yet confirmed") until their next sync, rather than pretending to be fresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->timestampTz('last_synced_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('subscriptions', fn (Blueprint $table) => $table->dropColumn('last_synced_at'));
    }
};
