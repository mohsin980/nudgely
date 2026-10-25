<?php

use App\Enums\EstimateStatus;
use App\Events\CustomerCreated;
use App\Events\EstimateCreated;
use App\Jobs\ProcessEstimateImportJob;
use App\Jobs\SendEmailJob;
use App\Livewire\Estimates\EstimateImportWizard;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\EstimateImport;
use App\Models\EstimateImportRow;
use App\Models\Message;
use App\Models\User;
use App\Services\Estimates\Import\CsvEstimateParser;
use App\Services\Estimates\Import\EstimateImportService;
use App\Services\Estimates\Import\ImportColumns;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Storage::fake('local');
    [$this->admin] = estimateBusiness();
    $this->imports = app(EstimateImportService::class);
});

const IMPORT_HEADER = "Estimate ID,Customer name,Customer email,Amount,Estimate date,Expiration date,Status,Description\n";

/** Upload a CSV, match its columns by name, and check every row. */
function uploadAndCheck(User $owner, string $csv, ?array $map = null): EstimateImport
{
    $imports = app(EstimateImportService::class);
    $import = $imports->upload($owner, UploadedFile::fake()->createWithContent('estimates.csv', $csv));

    return $imports->saveMapping($owner, $import, $map ?? ImportColumns::suggest($import->summary['file']['headers']));
}

/** Start the import and run its job, returning what the queue was asked to do. */
function runImport(User $owner, EstimateImport $import): void
{
    app(EstimateImportService::class)->start($owner, $import, true);
    Queue::assertPushed(ProcessEstimateImportJob::class);
    (new ProcessEstimateImportJob($import->id))->handle(app(EstimateImportService::class));
}

// Upload and matching

test('an uploaded file is read, its columns are matched by name, and nothing is imported yet', function () {
    $import = $this->imports->upload($this->admin, UploadedFile::fake()->createWithContent('estimates.csv', IMPORT_HEADER."EST-2001,Jane Smith,jane@example.com,2750.00,2026-09-15,2026-10-15,sent,AC\n"));

    expect($import->status)->toBe(EstimateImport::STATUS_UPLOADED)
        ->and($import->summary['file']['headers'])->toContain('Customer name')
        ->and($import->column_map[ImportColumns::CUSTOMER_NAME])->toBe(1)
        ->and($import->column_map[ImportColumns::CUSTOMER_EMAIL])->toBe(2)
        ->and(Estimate::query()->count())->toBe(0);
});

test('column names are matched in any order and under alternate names', function () {
    $import = uploadAndCheck($this->admin, "Client,Email Address,Quote Number,Total,Issued,Valid Until,Job\nJane Smith,jane@example.com,Q-1,\"\$2,750.00\",09/15/2026,10/15/2026,AC install\n");

    expect($import->status)->toBe(EstimateImport::STATUS_READY)
        ->and($import->summary['counts'])->toMatchArray(['rows' => 1, 'valid' => 1, 'invalid' => 0]);
});

test('a required column the file does not match must be chosen before the rows are checked', function () {
    $import = $this->imports->upload($this->admin, UploadedFile::fake()->createWithContent('estimates.csv', "Who,Price\nJane,2750\n"));

    expect(fn () => $this->imports->saveMapping($this->admin, $import, ['customer_name' => null, 'amount' => 1] + ImportColumns::suggest(['Who', 'Price'])))
        ->toThrow(ValidationException::class, 'Choose the column for customer name');
    expect($import->refresh()->status)->toBe(EstimateImport::STATUS_UPLOADED);
});

test('a file that is not UTF-8 is refused, with a clear instruction', function () {
    expect(fn () => $this->imports->upload($this->admin, UploadedFile::fake()->createWithContent('estimates.csv', "Customer name,Amount\nJos\xE9,100\n")))
        ->toThrow(ValidationException::class, 'CSV UTF-8');
});

test('a file with more rows than the limit is refused', function () {
    config(['estimates.import.max_rows' => 2]);
    $csv = IMPORT_HEADER.implode('', array_map(fn ($i) => "EST-{$i},Name {$i},,100,,,,\n", range(1, 3)));

    expect(fn () => $this->imports->upload($this->admin, UploadedFile::fake()->createWithContent('estimates.csv', $csv)))
        ->toThrow(ValidationException::class, 'more than 2 rows');
});

test('a file that is not a CSV is refused', function () {
    expect(fn () => $this->imports->upload($this->admin, UploadedFile::fake()->createWithContent('estimates.xlsx', IMPORT_HEADER)))
        ->toThrow(ValidationException::class, 'Upload a CSV file');
});

// Row checks

test('a missing or malformed email is handled by row: a bad address rejects the row, a missing one imports the customer as not eligible', function () {
    $import = uploadAndCheck($this->admin, IMPORT_HEADER
        ."EST-1,Jane Smith,not-an-email,100,,,,AC\n"
        ."EST-2,Mike Brown,,200,,,,Furnace\n"
        ."EST-3,Sarah Jones,sarah@example.com,300,,,,Heater\n");

    expect($import->summary['counts'])->toMatchArray(['valid' => 2, 'invalid' => 1]);

    $rejected = EstimateImportRow::query()->where('estimate_import_id', $import->id)->where('status', EstimateImportRow::REJECTED)->sole();
    expect($rejected->errors[0])->toContain('not a valid email address');

    $noEmail = EstimateImportRow::query()->where('estimate_import_id', $import->id)->where('status', EstimateImportRow::PENDING)->first();
    expect($noEmail->warnings)->toContain('No email address. The customer is imported, but cannot receive email follow-ups.');
});

test('an amount that is not money, or is zero, rejects only that row and explains why', function (string $amount) {
    $import = uploadAndCheck($this->admin, IMPORT_HEADER."EST-1,Jane Smith,jane@example.com,{$amount},,,,AC\n");

    expect($import->summary['counts'])->toMatchArray(['valid' => 0, 'invalid' => 1])
        ->and(EstimateImportRow::query()->sole()->errors[0])->toContain('Estimate amount must be a number');
})->with(['abc', '0', '-5', '1000000.01']);

test('a status the app does not know rejects the row; a sent status is imported as a draft, not as sent', function () {
    $import = uploadAndCheck($this->admin, IMPORT_HEADER
        ."EST-1,Jane Smith,jane@example.com,100,,,pending-ish,AC\n"
        ."EST-2,Mike Brown,mike@example.com,200,,,sent,Furnace\n");

    expect(EstimateImportRow::query()->where('row_number', 2)->sole()->errors[0])->toContain('"pending-ish" is not one we recognise');
    expect(EstimateImportRow::query()->where('row_number', 3)->sole()->warnings)->toContain('Imported as a draft. The file says "sent", but nothing is recorded as sent. Send it from the estimate page when you are ready.');
});

test('a repeated estimate ID in the same file rejects the second copy', function () {
    $import = uploadAndCheck($this->admin, IMPORT_HEADER
        ."EST-1,Jane Smith,jane@example.com,100,,,,AC\n"
        ."EST-1,Jane Smith,jane@example.com,100,,,,AC again\n");

    expect(EstimateImportRow::query()->where('row_number', 3)->sole()->errors[0])->toContain('already used on line 2');
});

// Import

test('a valid file imports drafts and customers, and sends no email and dispatches no customer or estimate events', function () {
    Queue::fake();
    Event::fake([EstimateCreated::class, CustomerCreated::class]);
    $customersBefore = Customer::query()->count();

    $import = uploadAndCheck($this->admin, IMPORT_HEADER
        ."EST-2001,Jane Smith,jane@example.com,2750.00,2026-09-15,2026-10-15,sent,AC installation\n"
        ."EST-2002,Mike Brown,,480.00,2026-09-18,,draft,Furnace tune-up\n");

    runImport($this->admin, $import);

    $estimates = Estimate::query()->where('organization_id', $this->admin->organization_id)->orderBy('id')->get();
    expect($estimates)->toHaveCount(2)
        ->and($estimates->pluck('status')->unique()->all())->toBe([EstimateStatus::Draft])
        ->and($estimates[0]->external_reference)->toBe('EST-2001')
        ->and($estimates[0]->imported_status)->toBe('sent')
        ->and($estimates[0]->total)->toBe('2750.00')
        ->and($estimates[0]->sent_at)->toBeNull()
        ->and($estimates[0]->import_id)->toBe($import->id)
        ->and(Customer::query()->count())->toBe($customersBefore + 2)
        ->and(Customer::query()->whereNull('email')->count())->toBe(1)
        ->and($import->refresh()->status)->toBe(EstimateImport::STATUS_COMPLETED);

    Queue::assertNotPushed(SendEmailJob::class);
    expect(Message::query()->count())->toBe(0);
    Event::assertNotDispatched(EstimateCreated::class);
    Event::assertNotDispatched(CustomerCreated::class);
});

test('a finished import removes its stored file, and keeps the result', function () {
    Queue::fake();
    $import = uploadAndCheck($this->admin, IMPORT_HEADER."EST-1,Jane Smith,jane@example.com,100,,,,AC\n");
    $path = $import->file_path;
    expect(Storage::disk('local')->exists($path))->toBeTrue();

    runImport($this->admin, $import);

    expect(Storage::disk('local')->exists($path))->toBeFalse()
        ->and($import->refresh()->file_path)->toBeNull()
        ->and($import->summary['counts']['imported'])->toBe(1);
});

test('a repeated import does not duplicate estimates or customers, and says what was skipped', function () {
    Queue::fake();
    $csv = IMPORT_HEADER."EST-2001,Jane Smith,jane@example.com,2750.00,,,,AC\n";

    runImport($this->admin, uploadAndCheck($this->admin, $csv));
    $customersAfterFirst = Customer::query()->count();
    $second = uploadAndCheck($this->admin, $csv);
    runImport($this->admin, $second);

    expect(Estimate::query()->where('external_reference', 'EST-2001')->count())->toBe(1)
        ->and(Customer::query()->count())->toBe($customersAfterFirst)
        ->and(EstimateImportRow::query()->where('estimate_import_id', $second->id)->sole()->status)->toBe(EstimateImportRow::SKIPPED)
        ->and(EstimateImportRow::query()->where('estimate_import_id', $second->id)->sole()->warnings[0])->toContain('Already imported as EST-1001');
});

test('a row without an estimate ID that matches an existing estimate is reported as a possible duplicate, not imported twice', function () {
    Queue::fake();
    $csv = IMPORT_HEADER.",Jane Smith,jane@example.com,2750.00,,,,AC installation\n";

    runImport($this->admin, uploadAndCheck($this->admin, $csv));
    $second = uploadAndCheck($this->admin, $csv);
    runImport($this->admin, $second);

    expect(Estimate::query()->count())->toBe(1)
        ->and(EstimateImportRow::query()->where('estimate_import_id', $second->id)->sole()->errors[0])->toContain('looks like EST-1001');
});

test('running the job again after it finished changes nothing', function () {
    Queue::fake();
    $import = uploadAndCheck($this->admin, IMPORT_HEADER."EST-1,Jane Smith,jane@example.com,100,,,,AC\nEST-2,Mike Brown,mike@example.com,200,,,,Furnace\n");
    runImport($this->admin, $import);
    $customers = Customer::query()->count();

    (new ProcessEstimateImportJob($import->id))->handle(app(EstimateImportService::class));
    (new ProcessEstimateImportJob($import->id))->handle(app(EstimateImportService::class));

    expect(Estimate::query()->count())->toBe(2)->and(Customer::query()->count())->toBe($customers);
});

test('when the plan runs out, the rows that could not be imported are reported with the reason, and the rest is counted accurately', function () {
    Queue::fake();
    tightFreePlan(['estimates' => 2]);
    $import = uploadAndCheck($this->admin, IMPORT_HEADER
        ."EST-1,Jane Smith,jane@example.com,100,,,,AC\n"
        ."EST-2,Mike Brown,mike@example.com,200,,,,Furnace\n"
        ."EST-3,Sarah Jones,sarah@example.com,300,,,,Heater\n"
        ."EST-4,Tom Lee,tom@example.com,400,,,,Drain\n");

    runImport($this->admin, $import);

    $counts = $this->imports->counts($import->refresh());
    expect($counts)->toMatchArray(['imported' => 2, 'rejected' => 2, 'pending' => 0])
        ->and(Estimate::query()->count())->toBe(2)
        ->and(EstimateImportRow::query()->where('estimate_import_id', $import->id)->where('status', EstimateImportRow::REJECTED)->first()->errors[0])->toContain('allows up to 2');
});

test('batches are processed in pieces, and every row is still imported', function () {
    Queue::fake();
    config(['estimates.import.batch_size' => 2]);
    $rows = implode('', array_map(fn ($i) => "EST-{$i},Customer {$i},c{$i}@example.com,100,,,,Job {$i}\n", range(1, 5)));

    runImport($this->admin, uploadAndCheck($this->admin, IMPORT_HEADER.$rows));

    expect(Estimate::query()->count())->toBe(5);
});

test('starting an import needs explicit confirmation, and an import with nothing to import is refused', function () {
    Queue::fake();
    $import = uploadAndCheck($this->admin, IMPORT_HEADER."EST-1,Jane Smith,jane@example.com,100,,,,AC\n");

    expect(fn () => $this->imports->start($this->admin, $import, false))->toThrow(ValidationException::class, 'nothing will be emailed');
    Queue::assertNotPushed(ProcessEstimateImportJob::class);

    $broken = uploadAndCheck($this->admin, IMPORT_HEADER."EST-1,,jane@example.com,100,,,,AC\n");
    expect(fn () => $this->imports->start($this->admin, $broken, true))->toThrow(ValidationException::class, 'every row has an error');
});

// Tenant isolation and security

test('another business cannot see, start, or download an import', function () {
    Queue::fake();
    [$stranger] = estimateBusiness('Other Plumbing');
    $import = uploadAndCheck($this->admin, IMPORT_HEADER."EST-1,,jane@example.com,100,,,,AC\n");

    $this->actingAs($stranger)->get(route('estimates.import.problems', $import->id))->assertNotFound();
    expect(fn () => $this->imports->start($stranger, $import, true))->toThrow(HttpException::class);

    Livewire::actingAs($stranger)->test(EstimateImportWizard::class, ['importId' => $import->id])->assertStatus(404);
});

test('a problem report cannot be read by another business, and cannot inject spreadsheet formulas', function () {
    [$stranger] = estimateBusiness('Other Plumbing');
    $import = uploadAndCheck($this->admin, IMPORT_HEADER."EST-1,=HYPERLINK(\"http://x.test\"),jane@example.com,abc,,,,AC\n");

    $this->actingAs($this->admin)->get(route('estimates.import.problems', $import->id))
        ->assertOk()
        ->assertSee("'=HYPERLINK", false);

    $this->actingAs($stranger)->get(route('estimates.import.problems', $import->id))->assertNotFound();
});

test('the wizard imports a file end to end, one step at a time', function () {
    Queue::fake();

    $wizard = Livewire::actingAs($this->admin)->test(EstimateImportWizard::class)
        ->set('file', UploadedFile::fake()->createWithContent('estimates.csv', IMPORT_HEADER."EST-9,Jane Smith,jane@example.com,2750.00,,,,AC\n"))
        ->call('upload')
        ->assertHasNoErrors()
        ->assertSee('Match your columns');

    $import = EstimateImport::query()->sole();
    $wizard->call('saveMapping')->assertHasNoErrors()->assertSee('Import 1 as drafts');

    $wizard->set('confirmed', true)->call('start')->assertHasNoErrors();

    Queue::assertPushed(ProcessEstimateImportJob::class);
    expect($import->refresh()->status)->toBe(EstimateImport::STATUS_QUEUED);
});

test('the sample file downloads and is itself a valid import', function () {
    $this->actingAs($this->admin)->get(route('estimates.import.sample'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

    $sample = app(EstimateImportService::class)->sampleCsv();
    $path = tempnam(sys_get_temp_dir(), 'sample');
    file_put_contents($path, $sample);

    expect(app(CsvEstimateParser::class)->inspect($path)['rows'])->toBe(3);
    @unlink($path);
});

test('the review page links to the problems report when rows need fixing, and the summary says nothing was emailed', function () {
    Queue::fake();
    $import = uploadAndCheck($this->admin, IMPORT_HEADER
        ."EST-1,Jane Smith,jane@example.com,100,,,,AC\n"
        ."EST-2,Mike Brown,not-an-email,200,,,,Furnace\n");

    $this->actingAs($this->admin)->get(route('estimates.import.show', $import->id))
        ->assertOk()
        ->assertSee('Need fixing (not imported)')
        ->assertSee(route('estimates.import.problems', $import->id), false);

    runImport($this->admin, $import);

    $this->actingAs($this->admin)->get(route('estimates.import.show', $import->id))
        ->assertOk()
        ->assertSee('Import complete')
        ->assertSee('Nothing was emailed');
});

test('the column mapping cannot be changed once the import has started, so rows being imported are never replaced', function () {
    Queue::fake();
    $import = uploadAndCheck($this->admin, IMPORT_HEADER."EST-1,Jane Smith,jane@example.com,100,,,,AC\n");
    $this->imports->start($this->admin, $import, true);

    expect(fn () => $this->imports->saveMapping($this->admin, $import->refresh(), ImportColumns::suggest($import->summary['file']['headers'])))
        ->toThrow(ValidationException::class, 'already started');
    expect(EstimateImportRow::query()->where('estimate_import_id', $import->id)->count())->toBe(1);
});
