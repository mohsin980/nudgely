<?php

namespace App\Livewire\Settings\Concerns;

use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Shared by settings pages: the signed-in person's organization (never one from the request),
 * the save message, validation errors, and the change history for the page.
 */
trait SettingsPage
{
    public ?string $statusMessage = null;

    public string $statusType = 'success';

    protected function user(): User
    {
        return Auth::user();
    }

    protected function organization(): Organization
    {
        return $this->user()->organization ?? abort(403);
    }

    protected function saved(string $message): void
    {
        $this->resetErrorBag();
        $this->statusMessage = $message;
        $this->statusType = 'success';
        // Tells the unsaved-changes guard the form is clean again.
        $this->dispatch('settings-saved');
    }

    protected function failed(string $message): void
    {
        $this->statusMessage = $message;
        $this->statusType = 'error';
    }

    /**
     * @param  array<string, string>  $map  Service field => component property.
     */
    protected function showErrors(ValidationException $e, array $map = []): void
    {
        $this->statusMessage = null;

        foreach ($e->errors() as $key => $messages) {
            $this->addError($map[$key] ?? $key, $messages[0]);
        }
    }

    /**
     * @param  list<string>  $actions
     * @return Collection<int, OrganizationActivity>
     */
    protected function history(array $actions, int $limit = 10): Collection
    {
        return OrganizationActivity::query()->with(['user:id,name', 'subject:id,name'])
            ->where('organization_id', $this->organization()->id)->ofActions($actions)
            ->latest('created_at')->latest('id')->limit($limit)->get();
    }
}
