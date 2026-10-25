<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an import: the values as the file gave them (mapped to our fields), and what happened to them.
 */
class EstimateImportRow extends Model
{
    public const PENDING = 'pending';

    public const IMPORTED = 'imported';

    public const SKIPPED = 'skipped';

    public const REJECTED = 'rejected';

    protected $fillable = ['organization_id', 'estimate_import_id', 'row_number', 'status', 'values', 'errors', 'warnings', 'estimate_id', 'customer_id'];

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<EstimateImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(EstimateImport::class, 'estimate_import_id');
    }
}
