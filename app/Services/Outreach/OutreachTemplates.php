<?php

namespace App\Services\Outreach;

use App\Enums\Outreach\OutreachTemplateStatus;
use App\Enums\Platform\PlatformPermission;
use App\Models\OutreachTemplate;
use App\Models\User;
use App\Support\Admin\AdminAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Template lifecycle: draft → pending (submitted) → approved or rejected. Changing an approved template sends it back
 * to draft, so the wording that was approved is the wording that gets sent. Nobody approves their own template.
 */
class OutreachTemplates
{
    /** @param  array<string, mixed>  $data */
    public function create(User $actor, array $data): OutreachTemplate
    {
        $this->authorizeEdit($actor);

        return OutreachTemplate::query()->create($this->attributes($data) + [
            'status' => OutreachTemplateStatus::Draft,
            'created_by' => $actor->id,
        ]);
    }

    /** @param  array<string, mixed>  $data */
    public function update(User $actor, OutreachTemplate $template, array $data): OutreachTemplate
    {
        $this->authorizeEdit($actor);

        $attributes = $this->attributes($data);
        $changed = collect($attributes)->contains(function ($value, $key) use ($template) {
            $current = $template->getAttribute($key);

            return ($current instanceof \BackedEnum ? $current->value : (string) $current) !== (string) ($value instanceof \BackedEnum ? $value->value : $value);
        });

        if ($changed && $template->status !== OutreachTemplateStatus::Draft) {
            $attributes['status'] = OutreachTemplateStatus::Draft;
            $attributes['reviewed_by'] = null;
            $attributes['reviewed_at'] = null;
            $attributes['review_note'] = null;
            $attributes['submitted_at'] = null;
        }

        $template->forceFill($attributes)->save();

        return $template;
    }

    public function submit(User $actor, OutreachTemplate $template): OutreachTemplate
    {
        $this->authorizeEdit($actor);

        if ($template->status !== OutreachTemplateStatus::Draft && $template->status !== OutreachTemplateStatus::Rejected) {
            throw ValidationException::withMessages(['status' => 'Only a draft or a rejected template can be submitted.']);
        }

        $template->forceFill(['status' => OutreachTemplateStatus::Pending, 'submitted_at' => now(), 'review_note' => null])->save();

        return $template;
    }

    public function approve(User $actor, OutreachTemplate $template, ?string $note = null): OutreachTemplate
    {
        $this->decide($actor, $template, OutreachTemplateStatus::Approved, $note);

        return $template;
    }

    public function reject(User $actor, OutreachTemplate $template, string $note): OutreachTemplate
    {
        $this->decide($actor, $template, OutreachTemplateStatus::Rejected, $note);

        return $template;
    }

    private function decide(User $actor, OutreachTemplate $template, OutreachTemplateStatus $to, ?string $note): void
    {
        if (! AdminAccess::allows($actor, PlatformPermission::ApproveOutreachTemplates)) {
            throw new AuthorizationException('Only a super admin can approve or reject templates.');
        }

        if ($template->status !== OutreachTemplateStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Only a submitted template can be decided.']);
        }

        if ($template->created_by === $actor->id) {
            throw ValidationException::withMessages(['status' => 'Someone else must review this template.']);
        }

        $template->forceFill([
            'status' => $to,
            'reviewed_by' => $actor->id,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();

        Log::info('Outreach template reviewed.', ['template_id' => $template->id, 'decision' => $to->value, 'reviewer_id' => $actor->id]);
    }

    private function authorizeEdit(User $actor): void
    {
        if (! AdminAccess::allows($actor, PlatformPermission::ManageOutreachTemplates)) {
            throw new AuthorizationException('You are not allowed to write outreach templates.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'outreach_category_id' => $data['outreach_category_id'],
            'channel' => $data['channel'],
            'name' => $data['name'],
            'subject' => ($data['channel'] ?? null) === 'email' ? ($data['subject'] ?? null) : null,
            'body' => $data['body'],
            'whatsapp_template_name' => ($data['channel'] ?? null) === 'whatsapp' ? ($data['whatsapp_template_name'] ?? null) : null,
            'whatsapp_language' => ($data['channel'] ?? null) === 'whatsapp' ? ($data['whatsapp_language'] ?? 'en') : null,
        ];
    }
}
