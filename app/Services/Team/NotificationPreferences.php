<?php

namespace App\Services\Team;

use App\Enums\Team\NotificationChannel;
use App\Enums\Team\NotificationType;
use App\Enums\Team\Permission;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who wants which notification, where. A person's own choice wins; otherwise the
 * organization's default; otherwise the built-in default (NotificationType::defaultFor()).
 * Preferences are stored per person and organization, so they never leak across businesses.
 */
class NotificationPreferences
{
    public function enabled(User $user, NotificationType $type, NotificationChannel $channel, ?Organization $organization = null): bool
    {
        $own = UserNotificationPreference::query()
            ->where('user_id', $user->id)->where('organization_id', $user->organization_id)
            ->where('type', $type->value)->where('channel', $channel->value)->value('enabled');

        if ($own !== null) {
            return (bool) $own;
        }

        $organization ??= $user->organization;

        return $organization?->businessSettings()->notificationDefault($type, $channel) ?? $type->defaultFor($channel);
    }

    /**
     * The organization's defaults as [type => [channel => bool]].
     *
     * @return array<string, array<string, bool>>
     */
    public function organizationDefaults(Organization $organization): array
    {
        $settings = $organization->businessSettings();
        $matrix = [];

        foreach (NotificationType::cases() as $type) {
            foreach (NotificationChannel::cases() as $channel) {
                $matrix[$type->value][$channel->value] = $settings->notificationDefault($type, $channel);
            }
        }

        return $matrix;
    }

    /**
     * What the person gets: their own choices over the organization defaults.
     *
     * @return array<string, array<string, bool>>
     */
    public function effective(User $user): array
    {
        $matrix = $this->organizationDefaults($user->organization);

        foreach ($this->overrides($user) as $row) {
            if (isset($matrix[$row->type][$row->channel])) {
                $matrix[$row->type][$row->channel] = $row->enabled;
            }
        }

        return $matrix;
    }

    public function hasOverrides(User $user): bool
    {
        return $this->overrides($user)->isNotEmpty();
    }

    /**
     * Save a person's choices: only differences from the organization default are stored, so a
     * later change to the default still reaches everyone who never chose otherwise.
     *
     * @param  array<string, array<string, mixed>>  $matrix
     */
    public function saveForUser(User $user, array $matrix): void
    {
        $defaults = $this->organizationDefaults($user->organization);

        DB::transaction(function () use ($user, $matrix, $defaults) {
            UserNotificationPreference::query()->where('user_id', $user->id)->where('organization_id', $user->organization_id)->delete();

            foreach (NotificationType::cases() as $type) {
                foreach (NotificationChannel::cases() as $channel) {
                    $value = (bool) filter_var($matrix[$type->value][$channel->value] ?? $defaults[$type->value][$channel->value], FILTER_VALIDATE_BOOLEAN);

                    if ($value !== $defaults[$type->value][$channel->value]) {
                        $row = new UserNotificationPreference;
                        $row->forceFill(['user_id' => $user->id, 'organization_id' => $user->organization_id, 'type' => $type->value, 'channel' => $channel->value, 'enabled' => $value])->save();
                    }
                }
            }
        });
    }

    public function resetForUser(User $user): void
    {
        UserNotificationPreference::query()->where('user_id', $user->id)->where('organization_id', $user->organization_id)->delete();
    }

    /**
     * @param  array<string, array<string, mixed>>  $matrix
     *
     * @throws AuthorizationException
     */
    public function saveOrganizationDefaults(User $actor, array $matrix): void
    {
        if (! $actor->hasPermission(Permission::ManageBusinessDefaults)) {
            throw new AuthorizationException('You can’t change notification defaults.');
        }

        $organization = Organization::query()->lockForUpdate()->findOrFail($actor->organization_id);
        $before = $this->organizationDefaults($organization);
        $after = [];
        $changes = [];

        foreach (NotificationType::cases() as $type) {
            foreach (NotificationChannel::cases() as $channel) {
                $value = (bool) filter_var($matrix[$type->value][$channel->value] ?? false, FILTER_VALIDATE_BOOLEAN);
                $after[$type->value][$channel->value] = $value;

                if ($value !== $before[$type->value][$channel->value]) {
                    $changes[$type->label().' ('.$channel->label().')'] = ['from' => $before[$type->value][$channel->value] ? 'On' : 'Off', 'to' => $value ? 'On' : 'Off'];
                }
            }
        }

        $organization->forceFill(['settings' => $organization->businessSettings()->with(['notifications' => $after])->toArray()])->save();

        if ($changes !== []) {
            OrganizationActivity::record($organization, 'notification_defaults_updated', $actor, ['changes' => $changes]);
        }
    }

    /**
     * @return Collection<int, UserNotificationPreference>
     */
    private function overrides(User $user): Collection
    {
        return UserNotificationPreference::query()->where('user_id', $user->id)->where('organization_id', $user->organization_id)->get();
    }
}
