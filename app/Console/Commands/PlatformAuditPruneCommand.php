<?php

namespace App\Console\Commands;

use App\Models\PlatformAuditLog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The retention policy for the audit trail: events older than admin.audit_retention_days (default 730, two years) are
 * removed in small batches. This is the only way audit events are ever deleted, and it records itself.
 */
class PlatformAuditPruneCommand extends Command
{
    protected $signature = 'platform-audit:prune {--dry-run : Only count what would be removed}';

    protected $description = 'Remove audit events older than the retention period (admin.audit_retention_days) and record that it happened';

    public function handle(): int
    {
        $days = (int) config('admin.audit_retention_days', 730);

        if ($days < 30) {
            $this->error('admin.audit_retention_days must be at least 30; refusing to prune.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $expired = DB::table('platform_audit_logs')->where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $this->info((clone $expired)->count()." audit event(s) are older than {$days} days.");

            return self::SUCCESS;
        }

        $deleted = 0;

        // Deleted by ID in batches through the query builder: the model refuses deletes by design.
        while (($ids = (clone $expired)->orderBy('id')->limit(1000)->pluck('id'))->isNotEmpty()) {
            $deleted += DB::table('platform_audit_logs')->whereIn('id', $ids)->delete();
        }

        if ($deleted > 0) {
            PlatformAuditLog::record('audit_log_pruned', null, null, null, null, ['deleted' => $deleted, 'older_than' => $cutoff->toDateString(), 'retention_days' => $days], 'audit_log');
        }

        $this->info("{$deleted} audit event(s) older than {$days} days removed.");

        return self::SUCCESS;
    }
}
