<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One API key for an AI provider. Encrypted at rest and never shown again (only its last 4 characters).
 * Written only by AiModelSettings.
 */
class AiModelKey extends Model
{
    protected $guarded = ['*'];

    protected $hidden = ['api_key', 'fingerprint'];

    protected function casts(): array
    {
        return ['api_key' => 'encrypted'];
    }

    public function hint(): string
    {
        return '••••'.mb_substr((string) $this->api_key, -4);
    }
}
