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

    public const VIA_MANUAL = 'manual';

    public const VIA_EMBEDDED_SIGNUP = 'embedded_signup';

    protected $table = 'whatsapp_connections';

    protected $hidden = ['access_token', 'app_secret', 'webhook_verify_token', 'registration_pin'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'registration_pin' => 'encrypted',
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

    public function isEmbeddedSignup(): bool
    {
        return $this->connected_via === self::VIA_EMBEDDED_SIGNUP;
    }

    /**
     * The secret Meta signs this number's webhooks with: the business's own Meta app when connected by hand,
     * QuoteFlow's Meta app when connected with one click.
     */
    public function webhookSecret(): ?string
    {
        return $this->isEmbeddedSignup() ? config('services.whatsapp.embedded.app_secret') : $this->app_secret;
    }
}
