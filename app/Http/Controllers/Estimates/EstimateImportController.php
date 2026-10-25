<?php

namespace App\Http\Controllers\Estimates;

use App\Http\Controllers\Controller;
use App\Models\EstimateImport;
use App\Services\Estimates\Import\EstimateImportService;
use Illuminate\Http\Response;

/**
 * The sample file and the problem report. Both are plain text downloads, scoped to the signed-in business.
 */
class EstimateImportController extends Controller
{
    public function sample(EstimateImportService $imports): Response
    {
        return response($imports->sampleCsv(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="quoteflow-estimates-sample.csv"',
        ]);
    }

    public function problems(int $importId, EstimateImportService $imports): Response
    {
        $import = EstimateImport::query()->where('organization_id', auth()->user()->organization_id)->findOrFail($importId);

        return response($imports->rejectedReport($import), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="quoteflow-import-'.$import->id.'-problems.csv"',
        ]);
    }
}
