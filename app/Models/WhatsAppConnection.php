<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The business's WhatsApp Business number, connected through Embedded Signup.
 *
 * organization_id and the access token are set by WhatsAppSignupService only. The token is encrypted
 * at rest and is never serialized, so it cannot reach a view, log or API response by accident.
 */
class WhatsAppConnection extends Model
{
    protected $table = 'whatsapp_connections';

    public const STATUS_ACTIVE = 'active';

    /** The Message provider value for every WhatsApp message (inbound and outbound). */
    public const PROVIDER = 'meta';

    protected $guarded = ['*'];

    protected $hidden = ['access_token'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'connected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
