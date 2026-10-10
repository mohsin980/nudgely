<?php

use Illuminate\Database\Migrations\Migration;
use App\Support\Database\PartialIndex;
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
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('automations_enabled')->default(true);
            // Safe defaults: automations never email customers until the business opts in.
            $table->boolean('automatic_email_enabled')->default(false);
            $table->boolean('require_approval_for_email')->default(true);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->timestampTz('email_opted_out_at')->nullable();
        });

        Schema::table('automation_runs', function (Blueprint $table) {
            // How many automations deep this run is in a chain (0 = triggered directly by the app).
            $table->unsignedSmallInteger('depth')->default(0);
            // The event facts the run was evaluated with (IDs, intent, confidence); never content.
            $table->jsonb('context')->nullable();
        });

        // An automated email is sent at most once per action and event, even under concurrent retries.
        // NULLs never collide in a unique index, so rows without a key are unaffected.
        PartialIndex::jsonKey('messages', 'automation_key', 'metadata', 'automation_key');
        DB::statement('CREATE UNIQUE INDEX messages_automation_key_unique ON messages (automation_key)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        PartialIndex::drop('messages', 'messages_automation_key_unique');
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('automation_key'));
        Schema::table('automation_runs', fn (Blueprint $table) => $table->dropColumn(['depth', 'context']));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('email_opted_out_at'));
        Schema::table('organizations', fn (Blueprint $table) => $table->dropColumn(['automations_enabled', 'automatic_email_enabled', 'require_approval_for_email']));
    }
};
