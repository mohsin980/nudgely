<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One CSV file of estimates being imported. Its rows become draft estimates and customers; nothing is sent.
 */
class EstimateImport extends Model
{
    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_READY = 'ready';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = ['organization_id', 'created_by', 'original_name', 'status', 'file_path', 'file_sha256', 'column_map', 'summary', 'error_message'];

    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'summary' => 'array',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return HasMany<EstimateImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(EstimateImportRow::class);
    }
}
