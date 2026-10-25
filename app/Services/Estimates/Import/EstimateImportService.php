<?php

namespace App\Services\Estimates\Import;

use App\Enums\Billing\LimitKey;
use App\Enums\CustomerStatus;
use App\Exceptions\Billing\PlanLimitException;
use App\Exceptions\Estimates\EstimateImportException;
use App\Jobs\ProcessEstimateImportJob;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateImport;
use App\Models\EstimateImportRow;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Estimates\EstimateService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Imports estimates from a CSV file.
 *
 * Nothing here sends email or starts follow-ups. Imported estimates are drafts, and imported customers without an
 * email address are kept but cannot receive email. Each row is written in its own transaction together with its
 * estimate and customer, so a retried job never creates a record twice.
 */
class EstimateImportService
{
    private const SAMPLE = "Estimate ID,Customer name,Customer email,Amount,Estimate date,Expiration date,Status,Description\n"
        ."EST-2001,Jane Smith,jane.smith@example.com,2750.00,2026-09-15,2026-10-15,sent,AC installation\n"
        ."EST-2002,Mike Brown,,480.00,2026-09-18,,draft,Furnace tune-up\n"
        ."EST-2003,Sarah Jones,sarah.jones@example.com,1250,09/20/2026,10/20/2026,accepted,Water heater replacement\n";

    public function __construct(
        private readonly CsvEstimateParser $parser,
        private readonly EstimateImportRowValidator $validator,
        private readonly EstimateService $estimates,
    ) {}

    /**
     * The sample file the page offers as a download.
     */
    public function sampleCsv(): string
    {
        return self::SAMPLE;
    }

    /**
     * Store an uploaded file and read its headers. Nothing is imported yet.
     *
     * @throws ValidationException
     */
    public function upload(User $actor, UploadedFile $file): EstimateImport
    {
        $this->authorizeImport($actor);

        if (strtolower($file->getClientOriginalExtension()) !== 'csv') {
            throw ValidationException::withMessages(['file' => 'Upload a CSV file (.csv).']);
        }

        $path = $file->getRealPath();

        try {
            $inspection = $this->parser->inspect($path);
        } catch (EstimateImportException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $sha = hash_file('sha256', $path);
        $stored = Storage::disk('local')->putFileAs('estimate-imports/'.$actor->organization_id, $file, Str::uuid().'.csv');

        return DB::transaction(fn () => EstimateImport::query()->create([
            'organization_id' => $actor->organization_id,
            'created_by' => $actor->id,
            'original_name' => Str::limit(basename($file->getClientOriginalName()), 255, ''),
            'status' => EstimateImport::STATUS_UPLOADED,
            'file_path' => $stored,
            'file_sha256' => $sha,
            'column_map' => ImportColumns::suggest($inspection['headers']),
            'summary' => ['file' => ['headers' => $inspection['headers'], 'rows' => $inspection['rows']]],
        ]));
    }

    /**
     * Confirm which header holds each field, then check every row. Rows with errors are kept (and reported), not dropped.
     *
     * @param  array<string, ?int>  $map  field => column index, or null
     *
     * @throws ValidationException
     */
    public function saveMapping(User $actor, EstimateImport $import, array $map): EstimateImport
    {
        $this->authorizeFor($actor, $import);

        // Locked like start(): a mapping save and a start can never interleave, so rows being imported are never replaced.
        return DB::transaction(function () use ($import, $map) {
            $locked = EstimateImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [EstimateImport::STATUS_UPLOADED, EstimateImport::STATUS_READY], true)) {
                throw ValidationException::withMessages(['map' => 'This import has already started, so its columns can no longer change.']);
            }

            return $this->storeMapping($locked, $map);
        });
    }

    /**
     * @param  array<string, ?int>  $map
     *
     * @throws ValidationException
     */
    private function storeMapping(EstimateImport $import, array $map): EstimateImport
    {
        $headers = $import->summary['file']['headers'] ?? [];
        $clean = [];

        foreach (array_keys(ImportColumns::all()) as $field) {
            $index = $map[$field] ?? null;
            $clean[$field] = $index === null || $index === '' ? null : (int) $index;
        }

        foreach (ImportColumns::REQUIRED as $field) {
            if ($clean[$field] === null) {
                throw ValidationException::withMessages(['map' => 'Choose the column for '.strtolower(ImportColumns::all()[$field]['label']).'.']);
            }
        }

        $used = array_filter($clean, fn (?int $index) => $index !== null);

        foreach ($used as $field => $index) {
            if (! array_key_exists($index, $headers)) {
                throw ValidationException::withMessages(['map' => 'One of the chosen columns does not exist in the file.']);
            }
        }

        if (count(array_unique($used)) !== count($used)) {
            throw ValidationException::withMessages(['map' => 'Each column can be used for only one field.']);
        }

        try {
            $counts = $this->checkRows($import, $clean);
        } catch (EstimateImportException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $import->forceFill([
            'status' => EstimateImport::STATUS_READY,
            'column_map' => $clean,
            'summary' => array_merge($import->summary ?? [], ['counts' => $counts]),
        ])->save();

        return $import->refresh();
    }

    /**
     * The explicit, authenticated step that imports the rows. Imported estimates are drafts and nothing is sent.
     *
     * @throws ValidationException
     */
    public function start(User $actor, EstimateImport $import, bool $confirmed): EstimateImport
    {
        $this->authorizeFor($actor, $import);

        if (! $confirmed) {
            throw ValidationException::withMessages(['confirmed' => 'Confirm that you understand the estimates will be imported as drafts and nothing will be emailed.']);
        }

        return DB::transaction(function () use ($import) {
            $locked = EstimateImport::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [EstimateImport::STATUS_READY, EstimateImport::STATUS_FAILED], true)) {
                throw ValidationException::withMessages(['status' => 'This import is already running or finished.']);
            }

            if (! EstimateImportRow::query()->where('estimate_import_id', $locked->id)->where('status', EstimateImportRow::PENDING)->exists()) {
                throw ValidationException::withMessages(['status' => 'Nothing can be imported: every row has an error. Download the report, fix the rows, and upload again.']);
            }

            $locked->forceFill(['status' => EstimateImport::STATUS_QUEUED, 'queued_at' => now(), 'error_message' => null])->save();

            ProcessEstimateImportJob::dispatch($locked->id)->afterCommit();

            return $locked->refresh();
        });
    }

    /**
     * Import every pending row. Safe to run again: finished rows are never touched, and a row is written atomically.
     */
    public function process(EstimateImport $import): void
    {
        $import->forceFill(['status' => EstimateImport::STATUS_PROCESSING, 'started_at' => $import->started_at ?? now()])->save();

        $organization = $import->organization;
        $batch = (int) config('estimates.import.batch_size', 100);

        EstimateImportRow::query()
            ->where('estimate_import_id', $import->id)
            ->where('status', EstimateImportRow::PENDING)
            ->chunkById($batch, function ($rows) use ($import, $organization) {
                foreach ($rows as $row) {
                    try {
                        $this->importRow($organization, $import, $row);
                    } catch (PlanLimitException $e) {
                        // The plan is full: this row and the rest of the file are reported, not silently dropped.
                        $this->rejectFromHere($import, $row, $e->getMessage());

                        return false;
                    }
                }

                return true;
            });

        $this->finish($import);
    }

    public function markFailed(EstimateImport $import, string $message): void
    {
        $import->forceFill(['status' => EstimateImport::STATUS_FAILED, 'error_message' => Str::limit($message, 500, '')])->save();
        Log::error('estimate_import.failed', ['event' => 'estimate_import.failed', 'organization_id' => $import->organization_id, 'estimate_import_id' => $import->id]);
    }

    /**
     * Rows by outcome, for the progress and summary screens.
     *
     * @return array<string, int>
     */
    public function counts(EstimateImport $import): array
    {
        $counts = EstimateImportRow::query()->where('estimate_import_id', $import->id)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n)->all();

        return $counts + ['pending' => 0, 'imported' => 0, 'skipped' => 0, 'rejected' => 0];
    }

    /**
     * The rows that were not imported, with the reasons, for the business to fix and upload again.
     */
    public function rejectedReport(EstimateImport $import): string
    {
        $this->authorizeForReport($import);

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, ['Line', 'Customer name', 'Customer email', 'Amount', 'Estimate date', 'Expiration date', 'Status', 'Estimate ID', 'Description', 'Problems']);

        EstimateImportRow::query()->where('estimate_import_id', $import->id)->where('status', EstimateImportRow::REJECTED)->orderBy('row_number')
            ->each(function (EstimateImportRow $row) use ($handle) {
                $values = $row->values;
                fputcsv($handle, array_map(self::safeCell(...), [
                    (string) $row->row_number,
                    $values[ImportColumns::CUSTOMER_NAME] ?? '',
                    $values[ImportColumns::CUSTOMER_EMAIL] ?? '',
                    $values[ImportColumns::AMOUNT] ?? '',
                    $values[ImportColumns::ESTIMATE_DATE] ?? '',
                    $values[ImportColumns::EXPIRATION_DATE] ?? '',
                    $values[ImportColumns::STATUS] ?? '',
                    $values[ImportColumns::EXTERNAL_REFERENCE] ?? '',
                    $values[ImportColumns::DESCRIPTION] ?? '',
                    implode(' ', $row->errors ?? []),
                ]));
            });

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Spreadsheet programs run cells that begin with = + - @ as formulas. Such cells are written as plain text.
     */
    public static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * Check every row against the chosen mapping. Stores the rows; returns the counts.
     *
     * @param  array<string, ?int>  $map
     * @return array{rows: int, valid: int, invalid: int, warnings: int}
     *
     * @throws EstimateImportException
     */
    private function checkRows(EstimateImport $import, array $map): array
    {
        $path = Storage::disk('local')->path($import->file_path);
        $currency = $import->organization->currencyCode();
        $references = [];
        $rows = [];
        $counts = ['rows' => 0, 'valid' => 0, 'invalid' => 0, 'warnings' => 0];

        EstimateImportRow::query()->where('estimate_import_id', $import->id)->delete();

        foreach ($this->parser->rows($path) as $line => $cells) {
            $values = [];

            foreach ($map as $field => $index) {
                if ($index !== null) {
                    $values[$field] = $cells[$index] ?? '';
                }
            }

            $result = $this->validator->validate($values, $currency);
            $errors = $result['errors'];

            $reference = $result['data']['external_reference'] ?? null;

            // A repeated ID within the file: the second copy is rejected, so the file cannot import one estimate twice.
            if ($reference !== null && isset($references[$reference])) {
                $errors[] = "Estimate ID \"{$reference}\" is already used on line {$references[$reference]} of this file.";
            } elseif ($reference !== null) {
                $references[$reference] = $line + 1;
            }

            $status = $errors === [] ? EstimateImportRow::PENDING : EstimateImportRow::REJECTED;
            $counts['rows']++;
            $counts[$errors === [] ? 'valid' : 'invalid']++;
            $counts['warnings'] += $errors === [] && $result['warnings'] !== [] ? 1 : 0;

            $rows[] = [
                'organization_id' => $import->organization_id,
                'estimate_import_id' => $import->id,
                'row_number' => $line + 1,
                'status' => $status,
                'values' => json_encode($values),
                'errors' => json_encode($errors === [] ? null : $errors),
                'warnings' => json_encode($result['warnings'] === [] ? null : $result['warnings']),
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if (count($rows) === 500) {
                EstimateImportRow::query()->insert($rows);
                $rows = [];
            }
        }

        if ($rows !== []) {
            EstimateImportRow::query()->insert($rows);
        }

        return $counts;
    }

    /**
     * Import one row. Throws PlanLimitException when the plan has no room (the caller reports the rest).
     */
    private function importRow(Organization $organization, EstimateImport $import, EstimateImportRow $row): void
    {
        DB::transaction(function () use ($organization, $import, $row) {
            $locked = EstimateImportRow::query()->whereKey($row->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== EstimateImportRow::PENDING) {
                return;
            }

            $result = $this->validator->validate($locked->values, $organization->currencyCode());

            if ($result['data'] === null) {
                $locked->forceFill(['status' => EstimateImportRow::REJECTED, 'errors' => $result['errors']])->save();

                return;
            }

            $data = $result['data'];

            if ($data['external_reference'] !== null) {
                $existing = Estimate::query()->where('organization_id', $organization->id)->where('external_reference', $data['external_reference'])->first();

                if ($existing !== null) {
                    $locked->forceFill(['status' => EstimateImportRow::SKIPPED, 'estimate_id' => $existing->id, 'warnings' => ["Already imported as {$existing->displayNumber()}. Nothing was changed."]])->save();

                    return;
                }
            } elseif ($possible = $this->possibleDuplicate($organization, $data)) {
                $locked->forceFill(['status' => EstimateImportRow::REJECTED, 'errors' => ["This looks like {$possible->displayNumber()}, which is already in your estimates (same customer, amount and description). Add an estimate ID to import it anyway."]])->save();

                return;
            }

            $customer = $this->customerFor($organization, $data);
            $estimate = $this->estimates->importDraft($organization, $customer, $import->id, $data);

            $locked->forceFill([
                'status' => EstimateImportRow::IMPORTED,
                'estimate_id' => $estimate->id,
                'customer_id' => $customer->id,
            ])->save();
        });
    }

    /**
     * An existing customer with the same email, or a new one. Customers without an email are always new (there is nothing to match).
     *
     * @param  array<string, mixed>  $data
     */
    private function customerFor(Organization $organization, array $data): Customer
    {
        if ($data['email'] !== null) {
            $existing = $organization->customers()->where('email', $data['email'])->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        // Created directly, not through CustomerService: that dispatches "customer created", which automations react to.
        return app(EntitlementService::class)->guard($organization, LimitKey::Customers, fn () => $organization->customers()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'status' => CustomerStatus::Active,
        ]));
    }

    /**
     * Without an estimate ID, an estimate for the same customer with the same amount and description is probably the same one.
     *
     * @param  array<string, mixed>  $data
     */
    private function possibleDuplicate(Organization $organization, array $data): ?Estimate
    {
        return Estimate::query()
            ->where('organization_id', $organization->id)
            ->where('total', Money::toDecimal($data['amount']))
            ->where('title', Str::limit($data['description'], 200, ''))
            ->whereIn('customer_id', function ($query) use ($organization, $data) {
                $query->select('id')->from('customers')->where('organization_id', $organization->id)
                    ->when($data['email'] !== null, fn ($q) => $q->where('email', $data['email']), fn ($q) => $q->whereNull('email')->where('name', $data['name']));
            })
            ->latest('id')
            ->first();
    }

    private function rejectFromHere(EstimateImport $import, EstimateImportRow $current, string $message): void
    {
        EstimateImportRow::query()->where('estimate_import_id', $import->id)->where('status', EstimateImportRow::PENDING)
            ->where('id', '>=', $current->id)
            ->update(['status' => EstimateImportRow::REJECTED, 'errors' => json_encode([$message]), 'updated_at' => now()]);
    }

    private function finish(EstimateImport $import): void
    {
        $counts = $this->counts($import);
        $summary = $import->summary ?? [];

        $import->forceFill([
            'status' => EstimateImport::STATUS_COMPLETED,
            'finished_at' => now(),
            'summary' => array_merge($summary, ['counts' => $counts + ['rows' => array_sum($counts)]]),
        ])->save();

        // The file's contents now live in the rows, so the stored copy is no longer needed.
        if ($import->file_path !== null) {
            Storage::disk('local')->delete($import->file_path);
            $import->forceFill(['file_path' => null])->save();
        }
    }

    private function authorizeImport(User $actor): void
    {
        Gate::forUser($actor)->authorize('create', Estimate::class);
    }

    private function authorizeFor(User $actor, EstimateImport $import): void
    {
        $this->authorizeImport($actor);

        // Another business's import is not found, not refused: its existence is not revealed.
        abort_unless($actor->organization_id === $import->organization_id, 404);
    }

    private function authorizeForReport(EstimateImport $import): void
    {
        abort_unless(auth()->user()?->organization_id === $import->organization_id, 404);
    }
}
