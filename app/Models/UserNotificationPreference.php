<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A person's own choice for one notification type and channel in one organization,
 * overriding the organization default.
 */
class UserNotificationPreference extends Model
{
    protected $guarded = ['*'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
