<?php

namespace App\Jobs\Outreach;

use App\Enums\Outreach\OutreachCampaignStatus;
use App\Enums\Outreach\OutreachMessageStatus;
use App\Models\OutreachCampaign;
use App\Models\OutreachMessage;
use App\Services\Outreach\OutreachEligibility;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Plans a campaign: writes one log row per contact (queued, or skipped with the reason), then queues one send per
 * queued row. Safe to run twice: a campaign that has already been planned is left alone.
 */
class SendOutreachCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 240;

    public function __construct(public readonly int $campaignId) {}

    public function handle(OutreachEligibility $eligibility): void
    {
        $campaign = OutreachCampaign::query()->with(['template', 'category'])->find($this->campaignId);

        if ($campaign === null || $campaign->status !== OutreachCampaignStatus::Queued) {
            return;
        }

        $campaign->forceFill(['status' => OutreachCampaignStatus::Running, 'started_at' => now()])->save();

        $queued = 0;
        $skipped = 0;

        $campaign->category->contacts()->chunkById(500, function ($contacts) use ($campaign, $eligibility, &$queued, &$skipped) {
            foreach ($contacts as $contact) {
                $reason = $eligibility->reason($contact, $campaign->channel, $campaign->template);
                $address = $campaign->channel->value === 'email' ? $contact->email : $contact->phone;

                $message = OutreachMessage::query()->create([
                    'outreach_campaign_id' => $campaign->id,
                    'outreach_contact_id' => $contact->id,
                    'channel' => $campaign->channel,
                    'recipient' => (string) $address,
                    'status' => $reason === null ? OutreachMessageStatus::Queued : OutreachMessageStatus::Skipped,
                    'skip_reason' => $reason,
                ]);

                if ($reason === null) {
                    $queued++;
                    SendOutreachMessageJob::dispatch($message->id)->onQueue('outreach');
                } else {
                    $skipped++;
                }
            }
        });

        $campaign->forceFill(['queued_count' => $queued, 'skipped_count' => $skipped])->save();

        if ($queued === 0) {
            $campaign->forceFill(['status' => OutreachCampaignStatus::Completed, 'finished_at' => now()])->save();
        }
    }
}
