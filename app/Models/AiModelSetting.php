<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A platform administrator's settings for one AI model in the chain (its keys are AiModelKey rows).
 * Written only by AiModelSettings.
 */
class AiModelSetting extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }
}
