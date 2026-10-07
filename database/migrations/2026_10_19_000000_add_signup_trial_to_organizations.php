<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A no-card trial that starts at sign-up and runs inside QuoteFollow (no subscription needed).
 * trial_used_at (Task 14B) already guarantees one trial per business.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->timestampTz('trial_ends_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn('trial_ends_at'));
    }
};
