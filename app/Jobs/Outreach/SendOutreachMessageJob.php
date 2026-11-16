<?php

namespace App\Jobs\Outreach;

use App\Enums\Outreach\OutreachCampaignStatus;
use App\Enums\Outreach\OutreachMessageStatus;
use App\Exceptions\Outreach\OutreachSendException;
use App\Models\OutreachCampaign;
use App\Models\OutreachMessage;
use App\Services\Outreach\OutreachEligibility;
use App\Services\Outreach\OutreachSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Sends one queued message. The claim is atomic, so a duplicate job can't send twice. Eligibility is checked again
 * here, because consent or an opt-out may have changed since the campaign was planned.
 */
class SendOutreachMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    /** @var list<int> seconds to wait before each retry of a temporary failure */
    public array $backoff = [60, 300, 900];

    public function __construct(public readonly int $messageId) {}

    public function handle(OutreachSender $sender, OutreachEligibility $eligibility): void
    {
        $claimed = OutreachMessage::query()->whereKey($this->messageId)
            ->where('status', OutreachMessageStatus::Queued)
            ->update(['status' => OutreachMessageStatus::Sending, 'updated_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $message = OutreachMessage::query()->with(['campaign.template', 'contact'])->findOrFail($this->messageId);
        $campaign = $message->campaign;

        // Over the channel's per-minute limit: put it back and try again later, without counting a failure.
        $limit = (int) config('outreach.max_per_minute');
        $allowed = RateLimiter::attempt('outreach-send:'.$message->channel->value, $limit, fn () => true, 60);

        if (! $allowed) {
            $message->forceFill(['status' => OutreachMessageStatus::Queued])->save();
            $this->release(60);

            return;
        }

        if ($message->contact === null) {
            $this->fail($message, 'The contact was removed.');

            return;
        }

        $reason = $eligibility->reason($message->contact, $message->channel, $campaign->template);

        if ($reason !== null) {
            $message->forceFill(['status' => OutreachMessageStatus::Skipped, 'skip_reason' => $reason])->save();
            $this->completeIfDone($campaign);

            return;
        }

        try {
            $providerId = $sender->send($message->channel, $message->contact, $campaign->template);
        } catch (OutreachSendException $e) {
            if ($e->transient && $this->attempts() < $this->tries) {
                $message->forceFill(['status' => OutreachMessageStatus::Queued, 'error' => mb_substr($e->getMessage(), 0, 255)])->save();

                throw $e; // the queue retries with the backoff above
            }

            $this->fail($message, $e->getMessage());

            return;
        }

        $message->forceFill([
            'status' => OutreachMessageStatus::Sent,
            'provider_message_id' => mb_substr($providerId, 0, 190),
            'sent_at' => now(),
            'error' => null,
        ])->save();

        $campaign->increment('sent_count');
        $this->completeIfDone($campaign);
    }

    /** Only runs after the last attempt has failed, so a message is never left stuck in "sending". */
    public function failed(Throwable $exception): void
    {
        $message = OutreachMessage::query()->find($this->messageId);

        if ($message !== null && in_array($message->status, [OutreachMessageStatus::Queued, OutreachMessageStatus::Sending], true)) {
            $this->fail($message, mb_substr($exception->getMessage(), 0, 255));
        }
    }

    private function fail(OutreachMessage $message, string $reason): void
    {
        $message->forceFill(['status' => OutreachMessageStatus::Failed, 'error' => mb_substr($reason, 0, 255)])->save();
        $message->campaign->increment('failed_count');
        $this->completeIfDone($message->campaign);
    }

    private function completeIfDone(OutreachCampaign $campaign): void
    {
        $open = $campaign->messages()->whereIn('status', [OutreachMessageStatus::Queued, OutreachMessageStatus::Sending])->exists();

        if (! $open && $campaign->status === OutreachCampaignStatus::Running) {
            $campaign->forceFill(['status' => OutreachCampaignStatus::Completed, 'finished_at' => now()])->save();
        }
    }
}
