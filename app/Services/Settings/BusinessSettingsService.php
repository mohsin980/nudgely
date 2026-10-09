<?php

namespace App\Services\Settings;

use App\Enums\TaskPriority;
use App\Enums\Team\Permission;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Support\Settings\BusinessHours;
use App\Support\Settings\OrganizationSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every change to the business's settings goes through here: permission check, validation,
 * save, and an OrganizationActivity entry listing what changed (from → to).
 * The organization is always the actor's own; nothing is taken from the request.
 */
class BusinessSettingsService
{
    public const COUNTRIES = ['US' => 'United States', 'CA' => 'Canada'];

    public const LOGO_MAX_KB = 2048;

    /** Detected image type → file extension. No SVG: it can carry scripts. */
    private const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];

    private const PROFILE_LABELS = [
        'name' => 'Business name', 'legal_name' => 'Legal name', 'email' => 'Business email', 'phone' => 'Phone', 'website' => 'Website',
        'address_line1' => 'Address line 1', 'address_line2' => 'Address line 2', 'city' => 'City', 'state' => 'State', 'postal_code' => 'ZIP / postal code', 'country' => 'Country',
    ];

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updateProfile(User $actor, array $input): Organization
    {
        $this->authorize($actor, Permission::ManageBusinessProfile);
        $input = array_map(fn ($v) => is_string($v) ? trim($v) : $v, $input);
        $input['country'] = strtoupper((string) ($input['country'] ?? 'US'));

        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:100'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9().\-\s]{7,25}$/'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'address_line1' => ['nullable', 'string', 'max:150'],
            'address_line2' => ['nullable', 'string', 'max:150'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'postal_code' => ['nullable', 'string', 'max:20', $input['country'] === 'US' ? 'regex:/^\d{5}(-\d{4})?$/' : ($input['country'] === 'CA' ? 'regex:/^[A-Za-z]\d[A-Za-z] ?\d[A-Za-z]\d$/' : 'string')],
            'country' => ['required', Rule::in(array_keys(self::COUNTRIES))],
        ], [
            'phone.regex' => 'Enter a phone number, e.g. (214) 555-1234.',
            'website.url' => 'Enter a full web address, e.g. https://dallashvac.com.',
            'postal_code.regex' => $input['country'] === 'US' ? 'Enter a 5-digit ZIP code.' : 'Enter a postal code like A1A 1A1.',
        ], ['email' => 'business email', 'address_line1' => 'address line 1'])->validate();

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['email'] = isset($data['email']) ? Str::lower($data['email']) : null;

        return $this->saveColumns($actor, $data, self::PROFILE_LABELS, 'business_profile_updated');
    }

    /**
     * Timezone, currency, date/time format and default task priority.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updatePreferences(User $actor, array $input): Organization
    {
        $this->authorize($actor, Permission::ManageBusinessProfile);

        $data = Validator::make($input, [
            'timezone' => ['required', 'string', 'timezone:all'],
            'currency' => ['required', Rule::in(array_keys(Organization::CURRENCIES))],
            'date_format' => ['required', Rule::in(array_keys(Organization::DATE_FORMATS))],
            'time_format' => ['required', Rule::in(array_keys(Organization::TIME_FORMATS))],
            'task_priority' => ['required', Rule::enum(TaskPriority::class)],
        ], ['timezone.timezone' => 'Choose a valid timezone.'])->validate();

        return DB::transaction(function () use ($actor, $data) {
            $organization = $this->lockOrganization($actor);
            $changes = $this->diff(array_merge($organization->only(['currency', 'date_format', 'time_format']), ['timezone' => $organization->timezone()]),
                $data, ['timezone' => 'Timezone', 'currency' => 'Currency', 'date_format' => 'Date format', 'time_format' => 'Time format']);
            $changes += $this->diff(['task_priority' => $organization->businessSettings()->taskPriority()->value], $data, ['task_priority' => 'Default task priority']);

            $organization->forceFill([
                'timezone' => $data['timezone'], 'currency' => $data['currency'], 'date_format' => $data['date_format'], 'time_format' => $data['time_format'],
                'settings' => $organization->businessSettings()->with(['task_priority' => $data['task_priority']])->toArray(),
            ])->save();

            return $this->recorded($organization, $actor, 'preferences_updated', $changes);
        });
    }

    /**
     * @param  array<string, mixed>  $input  [day => [open, start, end]]
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updateBusinessHours(User $actor, array $input): Organization
    {
        $this->authorize($actor, Permission::ManageBusinessProfile);
        [$days, $errors] = BusinessHours::validate($input);

        if ($errors !== []) {
            throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $day) => ["hours.{$day}" => $m])->all());
        }

        return DB::transaction(function () use ($actor, $days) {
            $organization = $this->lockOrganization($actor);
            $before = $organization->businessSettings()->businessHours()->toArray();
            $changes = [];

            foreach ($days as $day => $hours) {
                if ($hours !== $before[$day]) {
                    $changes[OrganizationSettings::DAYS[$day]] = ['from' => self::hoursLabel($before[$day]), 'to' => self::hoursLabel($hours)];
                }
            }

            $organization->forceFill(['settings' => $organization->businessSettings()->with(['business_hours' => $days])->toArray()])->save();

            return $this->recorded($organization, $actor, 'business_hours_updated', $changes);
        });
    }

    /**
     * @param  array<string, mixed>  $input  valid_days, notes, tax_rate
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updateEstimateDefaults(User $actor, array $input): Organization
    {
        $this->authorize($actor, Permission::ManageBusinessDefaults);
        $input['tax_rate'] = trim((string) ($input['tax_rate'] ?? ''));
        $input['notes'] = trim((string) ($input['notes'] ?? ''));

        $data = Validator::make($input, [
            'valid_days' => ['required', 'integer', 'between:1,365'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tax_rate' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,3})?$/', 'numeric', 'between:0,100'],
        ], ['tax_rate.regex' => 'Enter a percentage like 8.25.'], ['valid_days' => 'validity period'])->validate();

        return $this->saveSettings($actor, [
            'estimate_valid_days' => (int) $data['valid_days'],
            'estimate_notes' => ($data['notes'] ?? '') === '' ? null : $data['notes'],
            'estimate_tax_rate' => ($data['tax_rate'] ?? '') === '' ? null : $data['tax_rate'],
        ], fn (OrganizationSettings $s) => ['estimate_valid_days' => $s->estimateValidDays(), 'estimate_notes' => $s->estimateNotes(), 'estimate_tax_rate' => $s->estimateTaxRate()],
            ['estimate_valid_days' => 'Default estimate validity (days)', 'estimate_notes' => 'Default notes', 'estimate_tax_rate' => 'Default tax rate (%)'],
            'estimate_defaults_updated');
    }

    /**
     * @param  array<string, mixed>  $input  delay_days, time
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updateFollowUpDefaults(User $actor, array $input): Organization
    {
        $this->authorize($actor, Permission::ManageBusinessDefaults);

        $data = Validator::make($input, [
            'delay_days' => ['required', 'integer', 'between:1,60'],
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ], ['time.regex' => 'Enter a time like 10:00.'], ['delay_days' => 'default delay'])->validate();

        return $this->saveSettings($actor, ['follow_up_delay_days' => (int) $data['delay_days'], 'follow_up_time' => $data['time']],
            fn (OrganizationSettings $s) => ['follow_up_delay_days' => $s->followUpDelayDays(), 'follow_up_time' => $s->followUpTime()],
            ['follow_up_delay_days' => 'Default follow-up delay (days)', 'follow_up_time' => 'Default follow-up time'],
            'follow_up_defaults_updated');
    }

    /**
     * Defaults for who automations act for, assign tasks to and notify. Only active members.
     *
     * @param  array<string, mixed>  $input  automation_owner_id, task_assignee_id, notify_user_id ('' = none)
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updateAutomationDefaults(User $actor, array $input): Organization
    {
        $this->authorize($actor, Permission::ManageBusinessDefaults);
        $values = [];
        $errors = [];

        foreach (['automation_owner_id', 'task_assignee_id', 'notify_user_id'] as $key) {
            $value = $input[$key] ?? '';

            if ($value === '' || $value === null) {
                $values[$key] = null;
            } elseif (ctype_digit((string) $value) && User::query()->activeIn($actor->organization_id)->whereKey((int) $value)->exists()) {
                $values[$key] = (int) $value;
            } else {
                $errors[$key] = 'Choose an active member of your team.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $name = fn (?int $id) => $id === null ? null : User::query()->whereKey($id)->value('name');

        return $this->saveSettings($actor, $values,
            fn (OrganizationSettings $s) => ['automation_owner_id' => $s->automationOwnerId(), 'task_assignee_id' => $s->taskAssigneeId(), 'notify_user_id' => $s->notifyUserId()],
            ['automation_owner_id' => 'Default automation owner', 'task_assignee_id' => 'Default task assignee', 'notify_user_id' => 'Default notification recipient'],
            'automation_defaults_updated', $name);
    }

    /**
     * Validate the image by its content (not its name), store it under a random name, then
     * delete the previous logo once the new one is saved.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function uploadLogo(User $actor, UploadedFile $file): Organization
    {
        $this->authorize($actor, Permission::ManageBusinessProfile);

        Validator::make(['logo' => $file], [
            'logo' => ['required', 'file', 'max:'.self::LOGO_MAX_KB, 'mimes:png,jpg,jpeg,webp', 'mimetypes:image/png,image/jpeg,image/webp', 'dimensions:max_width=4000,max_height=4000'],
        ], [
            'logo.mimes' => 'Upload a PNG, JPEG or WebP image.',
            'logo.mimetypes' => 'Upload a PNG, JPEG or WebP image.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
            'logo.dimensions' => 'The logo must be at most 4000 × 4000 pixels.',
        ])->validate();

        $info = @getimagesize($file->getRealPath());
        $extension = is_array($info) ? (self::LOGO_TYPES[$info['mime']] ?? null) : null;

        if ($extension === null) {
            throw ValidationException::withMessages(['logo' => 'Upload a PNG, JPEG or WebP image.']);
        }

        $disk = Storage::disk(config('team.logo_disk', 'local'));
        $path = 'logos/'.Str::lower(Str::random(40)).'.'.$extension;
        $disk->putFileAs('logos', $file, basename($path));

        try {
            $previous = DB::transaction(function () use ($actor, $path) {
                $organization = $this->lockOrganization($actor);
                $previous = $organization->logo_path;
                $organization->forceFill(['logo_path' => $path])->save();
                OrganizationActivity::record($organization, 'logo_updated', $actor);

                return $previous;
            });
        } catch (\Throwable $e) {
            $disk->delete($path);

            throw $e;
        }

        if ($previous !== null) {
            $disk->delete($previous);
        }

        return $actor->organization->refresh();
    }

    /**
     * @throws AuthorizationException
     */
    public function removeLogo(User $actor): Organization
    {
        $this->authorize($actor, Permission::ManageBusinessProfile);

        $previous = DB::transaction(function () use ($actor) {
            $organization = $this->lockOrganization($actor);
            $previous = $organization->logo_path;

            if ($previous !== null) {
                $organization->forceFill(['logo_path' => null])->save();
                OrganizationActivity::record($organization, 'logo_removed', $actor);
            }

            return $previous;
        });

        if ($previous !== null) {
            Storage::disk(config('team.logo_disk', 'local'))->delete($previous);
        }

        return $actor->organization->refresh();
    }

    /**
     * @param  array{open: bool, start: string, end: string}  $hours
     */
    public static function hoursLabel(array $hours): string
    {
        return $hours['open'] ? "{$hours['start']}–{$hours['end']}" : 'Closed';
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $labels
     */
    private function saveColumns(User $actor, array $data, array $labels, string $action): Organization
    {
        return DB::transaction(function () use ($actor, $data, $labels, $action) {
            $organization = $this->lockOrganization($actor);
            $changes = $this->diff($organization->only(array_keys($data)), $data, $labels);
            $organization->forceFill($data)->save();

            return $this->recorded($organization, $actor, $action, $changes);
        });
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  \Closure(OrganizationSettings): array<string, mixed>  $current
     * @param  array<string, string>  $labels
     */
    private function saveSettings(User $actor, array $values, \Closure $current, array $labels, string $action, ?\Closure $display = null): Organization
    {
        return DB::transaction(function () use ($actor, $values, $current, $labels, $action, $display) {
            $organization = $this->lockOrganization($actor);
            $changes = $this->diff($current($organization->businessSettings()), $values, $labels, $display);
            $organization->forceFill(['settings' => $organization->businessSettings()->with($values)->toArray()])->save();

            return $this->recorded($organization, $actor, $action, $changes);
        });
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, string>  $labels
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diff(array $before, array $after, array $labels, ?\Closure $display = null): array
    {
        $changes = [];

        foreach ($labels as $key => $label) {
            if (! array_key_exists($key, $after)) {
                continue;
            }

            $from = $before[$key] ?? null;
            $to = $after[$key];

            if ((string) $from !== (string) $to) {
                $changes[$label] = ['from' => $display ? $display($from) : $from, 'to' => $display ? $display($to) : $to];
            }
        }

        return $changes;
    }

    /**
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     */
    private function recorded(Organization $organization, User $actor, string $action, array $changes): Organization
    {
        if ($changes !== []) {
            OrganizationActivity::record($organization, $action, $actor, ['changes' => $changes]);
            Log::info('Business settings changed.', ['organization_id' => $organization->id, 'user_id' => $actor->id, 'action' => $action, 'fields' => array_keys($changes)]);
        }

        return $organization;
    }

    private function lockOrganization(User $actor): Organization
    {
        return Organization::query()->lockForUpdate()->findOrFail($actor->organization_id);
    }

    /**
     * @throws AuthorizationException
     */
    private function authorize(User $actor, Permission $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new AuthorizationException('You can’t change these settings.');
        }
    }
}
