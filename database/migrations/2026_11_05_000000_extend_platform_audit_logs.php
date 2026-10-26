<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SA-10: platform_audit_logs becomes the platform's one audit trail (no second audit system). Adds a structured
 * target, the outcome (success, denied or failed) and the request correlation ID, plus the indexes the audit page
 * filters on. Existing rows get their target filled in from what they already recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            $table->string('target_type', 32)->nullable()->after('action');
            $table->string('target_id', 64)->nullable()->after('target_type');
            $table->string('outcome', 12)->default('success')->after('target_id');
            $table->string('correlation_id', 64)->nullable()->after('outcome');

            $table->index(['action', 'id'], 'platform_audit_action_idx');
            $table->index(['actor_id', 'id'], 'platform_audit_actor_idx');
            $table->index(['target_type', 'target_id'], 'platform_audit_target_idx');
            $table->index(['outcome', 'id'], 'platform_audit_outcome_idx');
        });

        DB::table('platform_audit_logs')->whereIn('action', ['organization_suspended', 'organization_reactivated'])
            ->update(['target_type' => 'organization', 'target_id' => DB::raw('organization_id')]);
        DB::table('platform_audit_logs')->whereIn('action', ['user_deactivated', 'user_reactivated'])
            ->update(['target_type' => 'user', 'target_id' => DB::raw('subject_user_id')]);

        // Plans and subscriptions kept their target inside the JSON details.
        foreach (DB::table('platform_audit_logs')->whereIn('action', ['plan_updated', 'plan_reset', 'subscription_refreshed'])->get(['id', 'action', 'changes']) as $row) {
            $details = json_decode((string) $row->changes, true) ?: [];
            $plan = str_starts_with($row->action, 'plan_');
            $target = $plan ? ($details['plan'] ?? null) : ($details['subscription_id'] ?? null);

            DB::table('platform_audit_logs')->where('id', $row->id)->update(['target_type' => $plan ? 'plan' : 'subscription', 'target_id' => $target === null ? null : (string) $target]);
        }
    }

    public function down(): void
    {
        Schema::table('platform_audit_logs', function (Blueprint $table) {
            // MySQL keeps the actor foreign key on the composite (actor_id, id) index and refuses to drop it until a
            // plain actor_id index exists again (the one Laravel names for the foreign key).
            $table->index('actor_id', 'platform_audit_logs_actor_id_foreign');

            $table->dropIndex('platform_audit_action_idx');
            $table->dropIndex('platform_audit_actor_idx');
            $table->dropIndex('platform_audit_target_idx');
            $table->dropIndex('platform_audit_outcome_idx');
            $table->dropColumn(['target_type', 'target_id', 'outcome', 'correlation_id']);
        });
    }
};
