<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use App\Services\Customers\Import\CustomerImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use RuntimeException;

/**
 * Import customers from a CSV: upload, match columns (with a preview), import. Existing customers are skipped.
 */
#[Layout('components.layouts.app')]
#[Title('Import customers')]
class CustomerImport extends Component
{
    use WithFileUploads;

    /** @var mixed */
    public $file = null;

    /** @var list<string> */
    public array $headers = [];

    /** @var array<string, string> field => column index as a string, '' when the file doesn't have it */
    public array $map = [];

    public int $rowCount = 0;

    /** Start "Customer created" automations (such as a new lead follow-up) for the imported customers. */
    public bool $runAutomations = false;

    /** @var array{created: int, duplicates: int, failed: list<array{row: int, message: string}>}|null */
    public ?array $result = null;

    public function mount(): void
    {
        $this->authorize('create', Customer::class);
    }

    public function updatedFile(CustomerImportService $imports): void
    {
        $this->resetErrorBag();
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.intdiv(CustomerImportService::MAX_BYTES, 1024)]], [], ['file' => 'CSV file']);

        try {
            $read = $imports->read($this->file->getRealPath());
        } catch (RuntimeException $e) {
            $this->reset('file');
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->headers = $read['headers'];
        $this->rowCount = count($read['rows']);
        $this->map = collect($imports->suggest($read['headers']))->map(fn (?int $index) => $index === null ? '' : (string) $index)->all();
    }

    public function import(CustomerImportService $imports): void
    {
        $this->authorize('create', Customer::class);

        if ($this->file === null) {
            $this->addError('file', 'Choose a CSV file first.');

            return;
        }

        $map = $this->columnMap();

        if ($map['email'] === null || ($map['name'] === null && $map['first_name'] === null)) {
            $this->addError('map', 'Match at least the email column and a name column (full name, or first and last name).');

            return;
        }

        try {
            $rows = $imports->read($this->file->getRealPath())['rows'];
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());

            return;
        }

        $this->result = $imports->import(Auth::user(), $rows, $map, $this->runAutomations);
        $this->reset('file', 'headers', 'map', 'rowCount');
    }

    public function startOver(): void
    {
        $this->reset('file', 'headers', 'map', 'rowCount', 'result', 'runAutomations');
        $this->resetErrorBag();
    }

    public function render(CustomerImportService $imports): View
    {
        $map = $this->headers === [] ? [] : $this->columnMap();
        $preview = [];

        if ($this->file !== null && $this->headers !== []) {
            try {
                $preview = array_map(fn (array $cells) => $imports->input($cells, $map), array_slice($imports->read($this->file->getRealPath())['rows'], 0, 5));
            } catch (RuntimeException) {
                $preview = [];
            }
        }

        return view('livewire.customers.customer-import', [
            'fields' => CustomerImportService::FIELDS,
            'preview' => $preview,
            'maxRows' => CustomerImportService::MAX_ROWS,
        ]);
    }

    /**
     * @return array<string, ?int> only indexes that exist in the file
     */
    private function columnMap(): array
    {
        return collect(CustomerImportService::FIELDS)->map(function ($definition, string $field) {
            $value = $this->map[$field] ?? '';

            return $value !== '' && ctype_digit($value) && array_key_exists((int) $value, $this->headers) ? (int) $value : null;
        })->all();
    }
}
