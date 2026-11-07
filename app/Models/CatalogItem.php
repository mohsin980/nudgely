<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A product or service the business offers, for the AI assistant to suggest. The price is free text
 * ("1.5 Cr", "$120 per visit", "From $900") because businesses price in many ways.
 */
class CatalogItem extends Model
{
    protected function casts(): array
    {
        return ['is_available' => 'boolean'];
    }
}
