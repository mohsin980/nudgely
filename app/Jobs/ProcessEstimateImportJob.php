<?php

namespace App\Jobs;

use App\Models\EstimateImport;
use App\Services\Estimates\Import\EstimateImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Imports one CSV file's rows. Unique per import, and every row is idempotent, so a retry continues where it stopped.
 */
class ProcessEstimateImportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Kept under the queue's retry_after (300 s), so no other worker can start this job while it runs. */
    public int $timeout = 240;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $importId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function uniqueId(): string
    {
        return 'estimate-import:'.$this->importId;
    }

    public function handle(EstimateImportService $imports): void
    {
        $import = EstimateImport::query()->find($this->importId);

        if ($import === null || $import->status === EstimateImport::STATUS_COMPLETED) {
            return;
        }

        $imports->process($import);
    }

    public function failed(?Throwable $exception): void
    {
        $import = EstimateImport::query()->find($this->importId);

        if ($import !== null) {
            app(EstimateImportService::class)->markFailed($import, 'The import stopped before it finished. Rows already imported are kept; start again to import the rest.');
        }
    }
}
