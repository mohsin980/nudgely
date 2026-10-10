<?php

use App\Support\Database\PartialIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 16D: indexes that match the hourly scans, and removal of single-column indexes that a composite
 * index already serves (a btree on (a, b) answers every query on a).
 *
 * Partial indexes keep the hourly scans small as tenants grow: they cover only the rows those jobs look at.
 * Each statement is short and does not rewrite a table. CREATE INDEX (without CONCURRENTLY) takes a
 * SHARE lock on the table while it builds; on a large production table, see docs/database-performance.md
 * for the concurrent procedure that must be run outside a transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Estimate expiry (hourly): only sent or viewed estimates can expire.
        PartialIndex::index('estimates', 'estimates_expiry_due_index', ['valid_until'], "status IN ('sent', 'viewed')");

        // Trial expiry (hourly): sign-up trials that ended and were not expired yet.
        PartialIndex::index('organizations', 'organizations_trial_expiry_index', ['trial_ends_at'], 'trial_expired_at IS NULL');

        // Past-due grace (hourly): subscriptions past due and not yet restricted.
        PartialIndex::index('subscriptions', 'subscriptions_past_due_grace_index', ['past_due_since'], "status = 'past_due' AND restricted_at IS NULL");

        // Covered by a composite index that starts with the same column.
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropIndex('conversations_organization_id_index');
            $table->dropIndex('conversations_customer_id_index');
        });
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_organization_id_index');
        });
        Schema::table('messages', function (Blueprint $table) {
            $table->dropIndex('messages_organization_id_index');
            $table->dropIndex('messages_status_index');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_organization_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->index('organization_id'));
        Schema::table('messages', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('status');
        });
        Schema::table('customers', fn (Blueprint $table) => $table->index('organization_id'));
        Schema::table('conversations', function (Blueprint $table) {
            $table->index('organization_id');
            $table->index('customer_id');
        });

        PartialIndex::drop('subscriptions', 'subscriptions_past_due_grace_index');
        PartialIndex::drop('organizations', 'organizations_trial_expiry_index');
        PartialIndex::drop('estimates', 'estimates_expiry_due_index');
    }
};
