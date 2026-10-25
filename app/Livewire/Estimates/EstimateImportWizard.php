<?php

namespace App\Livewire\Estimates;

use App\Models\Estimate;
use App\Models\EstimateImport;
use App\Models\EstimateImportRow;
use App\Services\Estimates\Import\EstimateImportService;
use App\Services\Estimates\Import\ImportColumns;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Upload a CSV, match its columns, review the rows, then import them as drafts. Each step saves its result, so a
 * page reload or a failed step never loses the work already done.
 */
#[Layout('components.layouts.app')]
#[Title('Import estimates')]
class EstimateImportWizard extends Component
{
    use WithFileUploads;

    public ?int $importId = null;

    /** @var mixed */
    public $file = null;

    /** @var array<string, string|null> field => column index as a string, or '' for "not in the file" */
    public array $map = [];

    public bool $confirmed = false;

    public function mount(?int $importId = null): void
    {
        $this->authorize('create', Estimate::class);

        if ($importId !== null) {
            $import = $this->import ?? abort(404);
            $this->importId = $import->id;
            $this->map = collect($import->column_map ?? [])->map(fn ($index) => $index === null ? '' : (string) $index)->all();
        }
    }

    #[Computed]
    public function import(): ?EstimateImport
    {
        if ($this->importId === null) {
            return null;
        }

        return EstimateImport::query()->where('organization_id', Auth::user()->organization_id)->find($this->importId);
    }

    public function upload(EstimateImportService $imports): void
    {
        $this->resetErrorBag();
        $this->validate(['file' => ['required', 'file', 'max:5120']], ['file.max' => 'The file is larger than 5 MB. Split it into smaller files.']);

        try {
            $import = $imports->upload(Auth::user(), $this->file);
        } catch (ValidationException $e) {
            $this->addError('file', $e->validator->errors()->first());

            return;
        }

        $this->importId = $import->id;
        $this->map = collect($import->column_map ?? [])->map(fn ($index) => $index === null ? '' : (string) $index)->all();
        $this->file = null;
        unset($this->import);
    }

    public function saveMapping(EstimateImportService $imports): void
    {
        $this->resetErrorBag();
        $map = [];

        foreach (array_keys(ImportColumns::all()) as $field) {
            $value = $this->map[$field] ?? '';
            $map[$field] = $value === '' || $value === null ? null : (int) $value;
        }

        try {
            $imports->saveMapping(Auth::user(), $this->import ?? abort(404), $map);
        } catch (ValidationException $e) {
            $this->addError('map', $e->validator->errors()->first());
        }

        unset($this->import);
    }

    public function start(EstimateImportService $imports): void
    {
        $this->resetErrorBag();

        try {
            $imports->start(Auth::user(), $this->import ?? abort(404), $this->confirmed);
        } catch (ValidationException $e) {
            $this->addError('start', $e->validator->errors()->first());
        }

        $this->confirmed = false;
        unset($this->import);
    }

    public function startOver(): void
    {
        $this->importId = null;
        $this->map = [];
        $this->confirmed = false;
        unset($this->import);
    }

    public function render(EstimateImportService $imports): View
    {
        $import = $this->import;
        $headers = $import?->summary['file']['headers'] ?? [];
        $columns = ImportColumns::all();

        return view('livewire.estimates.estimate-import-wizard', [
            'import' => $import,
            'headers' => $headers,
            'columns' => $columns,
            'required' => ImportColumns::REQUIRED,
            'counts' => $import ? $imports->counts($import) : [],
            'preview' => $import?->status === EstimateImport::STATUS_READY
                ? EstimateImportRow::query()->where('estimate_import_id', $import->id)->orderBy('row_number')->limit(10)->get()
                : collect(),
            'currency' => Auth::user()->organization->currencyCode(),
        ]);
    }
}
