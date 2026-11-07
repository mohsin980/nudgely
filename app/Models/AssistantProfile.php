<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * How the AI WhatsApp assistant presents the business: its name, languages, and what it should know and ask.
 */
class AssistantProfile extends Model
{
    public const LANGUAGES = ['en' => 'English', 'ur' => 'Urdu'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return list<string> language codes, e.g. ['en', 'ur']
     */
    public function languageList(): array
    {
        return array_values(array_intersect(explode(',', (string) $this->languages), array_keys(self::LANGUAGES)));
    }
}
