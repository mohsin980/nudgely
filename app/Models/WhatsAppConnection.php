<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A business's own WhatsApp number on Meta's WhatsApp Cloud API. The access token and app secret are
 * encrypted at rest and never shown again after saving.
 */
class WhatsAppConnection extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    protected $table = 'whatsapp_connections';

    protected $hidden = ['access_token', 'app_secret', 'webhook_verify_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'verified_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
