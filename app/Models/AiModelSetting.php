<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A platform administrator's settings for one AI model in the chain. The key is encrypted at rest and never shown
 * again. Written only by AiModelSettings.
 */
class AiModelSetting extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted', 'enabled' => 'boolean'];
    }
}
