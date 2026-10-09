<?php

namespace App\Services\Reliability;

use App\Enums\Automation\AutomationActionRunStatus;
use App\Enums\Automation\AutomationRunStatus;
use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\FollowUp;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read-only counts of background work by state, for operators and the monitoring work that follows.
 * Failed jobs are reported by job class and count only: their payloads are never printed.
 */
class OperationsReport
{
    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $now = now();
        $strandedAfter = $now->copy()->subMinutes((int) config('reliability.follow_ups.stranded_after_minutes'));
        $stalledAfter = $now->copy()->subMinutes((int) config('reliability.automation.stalled_after_minutes'));

        return [
            'follow_ups' => [
                'by_status' => $this->countsByStatus(FollowUp::class, FollowUpStatus::cases()),
                'stranded_due' => FollowUp::query()->where('status', FollowUpStatus::Due)->whereNull('due_notified_at')
                    ->whereNull('processed_at')->where('updated_at', '<', $strandedAfter)->count(),
            ],
            'emails_outbound_last_24h' => [
                'by_status' => $this->countsByStatus(Message::class, MessageStatus::cases(), fn ($q) => $q
                    ->where('direction', MessageDirection::Outbound)->where('created_at', '>=', $now->copy()->subDay())),
                'stuck_sending' => Message::query()->where('status', MessageStatus::Sending)
                    ->where('updated_at', '<', $now->copy()->subMinutes((int) config('reliability.email.stuck_sending_minutes')))->count(),
            ],
            'automation_runs' => [
                'by_status' => $this->countsByStatus(AutomationRun::class, AutomationRunStatus::cases()),
                'stalled_runs' => AutomationRun::query()->where('status', AutomationRunStatus::Running)->where('updated_at', '<', $stalledAfter)->count(),
                'overdue_waits' => AutomationRun::query()->where('status', AutomationRunStatus::Waiting)->where('resume_at', '<', $stalledAfter)->count(),
                'stalled_steps' => AutomationActionRun::query()->where('status', AutomationActionRunStatus::Pending)->where('updated_at', '<', $stalledAfter)->count(),
            ],
            'queue' => [
                'queued' => DB::table(config('queue.connections.database.table', 'jobs'))->count(),
                'failed' => $this->failedJobsByClass(),
            ],
        ];
    }

    /**
     * @param  class-string  $model
     * @param  array<int, \UnitEnum>  $cases
     * @param  (\Closure(Builder): mixed)|null  $scope
     * @return array<string, int>
     */
    private function countsByStatus(string $model, array $cases, ?\Closure $scope = null): array
    {
        $query = $model::query();

        if ($scope !== null) {
            $scope($query);
        }

        $counts = $query->toBase()->reorder()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return collect($cases)->mapWithKeys(fn ($case) => [$case->value => (int) ($counts[$case->value] ?? 0)])->all();
    }

    /**
     * @return array{total: int, by_class: array<string, int>}
     */
    private function failedJobsByClass(): array
    {
        $rows = DB::table('failed_jobs')->orderByDesc('id')->limit(500)->get(['payload']);
        $byClass = [];

        foreach ($rows as $row) {
            $class = (string) (json_decode((string) $row->payload, true)['displayName'] ?? 'unknown');
            $byClass[$class] = ($byClass[$class] ?? 0) + 1;
        }

        return ['total' => DB::table('failed_jobs')->count(), 'by_class' => $byClass];
    }
}
