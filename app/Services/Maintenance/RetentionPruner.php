<?php

namespace App\Services\Maintenance;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes technical records past their retention period, in small batches so no single statement
 * holds locks for long. Only tables with no business meaning are touched: processed webhook
 * deliveries, and invitations and reply addresses that expired unused. Organization data is
 * never pruned here, so this runs for every tenant the same way and cannot cross tenants.
 */
class RetentionPruner
{
    /** Safety bound: at most this many batches per table in one run. */
    private const MAX_BATCHES = 200;

    /**
     * @return array<string, int> Rows deleted per table.
     */
    public function prune(): array
    {
        $now = now();
        $chunk = (int) config('reliability.retention.chunk_size');
        $days = fn (string $key) => $now->copy()->subDays((int) config("reliability.retention.{$key}"));

        return [
            'webhook_events' => $this->deleteInBatches(
                DB::table('webhook_events')->whereNotNull('processed_at')->where('processed_at', '<', $days('webhook_events_days')), $chunk),
            'billing_webhook_events' => $this->deleteInBatches(
                DB::table('billing_webhook_events')->whereIn('status', ['processed', 'ignored'])->where('created_at', '<', $days('billing_webhook_events_days')), $chunk),
            'webhook_payloads_redacted' => $this->redactFailedWebhookPayloads($now->copy()->subDays((int) config('reliability.retention.failed_webhook_payload_days')), $chunk),
            'team_invitations' => $this->deleteInBatches(
                DB::table('team_invitations')->whereNull('accepted_at')->whereNotNull('expires_at')->where('expires_at', '<', $days('expired_invitations_days')), $chunk),
            'email_reply_routes' => $this->deleteInBatches(
                DB::table('email_reply_routes')->whereNotNull('expires_at')->where('expires_at', '<', $days('expired_reply_routes_days')), $chunk),
        ];
    }

    /**
     * Failed webhook events keep their metadata (provider, reason, attempts, correlation ID) so they can be
     * troubleshooted, but the stored payload, which may contain customer email content, is replaced.
     * A payload redacted this way can no longer be replayed; the operator is told so by the replay refusal.
     */
    private function redactFailedWebhookPayloads(\DateTimeInterface $before, int $chunk): int
    {
        $redacted = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $ids = DB::table('webhook_events')
                ->where('status', 'failed')
                ->where('failed_at', '<', $before)
                // Already-redacted payloads are {"redacted": true}; this skips them without a JSON literal.
                ->whereJsonDoesntContainKey('payload->redacted')
                ->orderBy('id')->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $redacted += DB::table('webhook_events')->whereIn('id', $ids)->update(['payload' => json_encode(['redacted' => true]), 'updated_at' => now()]);
        }

        return $redacted;
    }

    private function deleteInBatches(Builder $matching, int $chunk): int
    {
        $table = $matching->from;
        $deleted = 0;

        for ($batch = 0; $batch < self::MAX_BATCHES; $batch++) {
            $ids = (clone $matching)->orderBy('id')->limit($chunk)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $deleted += DB::table($table)->whereIn('id', $ids)->delete();
        }

        return $deleted;
    }
}
