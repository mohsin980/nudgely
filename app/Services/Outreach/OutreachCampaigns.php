<?php

namespace App\Services\Outreach;

use App\Enums\Outreach\OutreachCampaignStatus;
use App\Enums\Platform\PlatformPermission;
use App\Jobs\Outreach\SendOutreachCampaignJob;
use App\Models\OutreachCampaign;
use App\Models\OutreachCategory;
use App\Models\OutreachTemplate;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Starts a campaign: one approved template, sent to every eligible contact in one category, on one channel.
 * The work happens in a queued job, so the admin's request returns straight away.
 */
class OutreachCampaigns
{
    public function start(User $actor, OutreachTemplate $template, OutreachCategory $category): OutreachCampaign
    {
        if (! AdminAccess::allows($actor, PlatformPermission::SendOutreach)) {
            throw new AuthorizationException('You are not allowed to send outreach messages.');
        }

        if (! $template->isApproved()) {
            throw ValidationException::withMessages(['template' => 'Only an approved template can be sent.']);
        }

        if ($template->outreach_category_id !== $category->id) {
            throw ValidationException::withMessages(['category' => 'That template belongs to a different category.']);
        }

        if (! $category->is_active) {
            throw ValidationException::withMessages(['category' => 'This category is turned off.']);
        }

        $campaign = OutreachCampaign::query()->create([
            'outreach_template_id' => $template->id,
            'outreach_category_id' => $category->id,
            'channel' => $template->channel,
            'status' => OutreachCampaignStatus::Queued,
            'created_by' => $actor->id,
        ]);

        SendOutreachCampaignJob::dispatch($campaign->id)->onQueue('outreach');

        return $campaign;
    }
}
