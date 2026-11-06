<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customers who never answer a business's follow-ups are marked inactive automatically, and made
 * active again when they reply. The last follow-up email of a chain is flagged so the check can find it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('inactive_reason', 32)->nullable()->after('status');
            $table->timestampTz('status_changed_at')->nullable()->after('inactive_reason');
        });

        Schema::table('follow_ups', function (Blueprint $table) {
            $table->boolean('final_attempt')->default(false)->after('type');
            $table->index(['final_attempt', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('follow_ups', function (Blueprint $table) {
            $table->dropIndex(['final_attempt', 'completed_at']);
            $table->dropColumn('final_attempt');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['inactive_reason', 'status_changed_at']);
        });
    }
};
