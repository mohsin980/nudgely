<?php

use App\Events\CustomerCreated;
use App\Livewire\Customers\CustomerImport;
use App\Models\Customer;
use App\Services\Customers\Import\CustomerImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

beforeEach(function () {
    [$this->admin, $this->existing] = followUpBusiness(); // John Smith, john@example.com
    $this->organization = $this->admin->organization;
});

function customerCsv(string $content): UploadedFile
{
    return UploadedFile::fake()->createWithContent('customers.csv', $content);
}

test('the customers page links to the import', function () {
    $this->actingAs($this->admin)->get(route('customers.index'))->assertSee(route('customers.import'), false);
    $this->actingAs($this->admin)->get(route('customers.import'))->assertOk()->assertSee('Import customers');
});

test('uploading matches the columns it recognises and previews the rows', function () {
    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv("Full Name,E-mail Address,Mobile,Company Name\nJane Smith,jane@example.com,214 555 0142,Smith Plumbing\n"))
        ->assertHasNoErrors()
        ->assertSet('rowCount', 1)
        ->assertSet('map.name', '0')->assertSet('map.email', '1')->assertSet('map.phone', '2')->assertSet('map.company', '3')
        ->assertSet('map.first_name', '')
        ->assertSee('Jane')->assertSee('Smith Plumbing')->assertSee('Import 1 customer');
});

test('importing adds new customers, skips existing ones and lists rows that need fixing', function () {
    $csv = "First name,Last name,Email,Phone\n"
        ."Jane,Smith,jane@example.com,(214) 555-0142\n"
        ."John,Smith,JOHN@example.com,\n"          // already a customer (email case differs)
        ."Jane,Again,jane@example.com,\n"           // twice in the file
        ."Bad,Email,not-an-email,\n"
        ."\n"
        ."Mike,Brown,mike@example.com,\n";

    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv($csv))
        ->call('import')
        ->assertSet('result.created', 2)
        ->assertSet('result.duplicates', 2)
        ->assertSet('result.failed', [['row' => 5, 'message' => 'Enter a valid email address.']])
        ->assertSee('2 customers imported');

    expect(Customer::where('organization_id', $this->organization->id)->pluck('email')->sort()->values()->all())
        ->toBe(['jane@example.com', 'john@example.com', 'mike@example.com'])
        ->and(Customer::where('email', 'jane@example.com')->value('phone'))->toBe('(214) 555-0142');
});

test('a full name column fills first and last name', function () {
    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv("Name,Email\nMary Ann Lee,mary@example.com\n"))
        ->call('import');

    expect(Customer::where('email', 'mary@example.com')->first())
        ->first_name->toBe('Mary')
        ->last_name->toBe('Ann Lee');
});

test('imported customers do not start automations unless asked', function () {
    Event::fake([CustomerCreated::class]);

    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv("First name,Last name,Email\nJane,Smith,jane@example.com\n"))
        ->call('import');
    Event::assertNotDispatched(CustomerCreated::class);

    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv("First name,Last name,Email\nMike,Brown,mike@example.com\n"))
        ->set('runAutomations', true)
        ->call('import');
    Event::assertDispatchedTimes(CustomerCreated::class, 1);
});

test('the import stops at the plan limit and says how many rows were left out', function () {
    tightFreePlan(['customers' => 2]); // John Smith is already one

    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv("First name,Last name,Email\nA,One,a@example.com\nB,Two,b@example.com\nC,Three,c@example.com\nD,Four,d@example.com\n"))
        ->call('import')
        ->assertSet('result.created', 1)
        ->assertSee('2 more rows were not imported because of the plan limit.');

    expect(Customer::where('organization_id', $this->organization->id)->count())->toBe(2);
});

test('a file without a name or email column cannot be imported', function () {
    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv("Phone,Company\n214 555 0142,Smith Plumbing\n"))
        ->call('import')
        ->assertHasErrors('map');

    expect(Customer::count())->toBe(1);
});

test('empty, header-only and non UTF-8 files are refused with a clear message', function (string $content, string $message) {
    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv($content))
        ->assertHasErrors('file')
        ->assertSee($message);
})->with([
    'header only' => ["First name,Last name,Email\n", 'no customers under it'],
    'not UTF-8' => ["First name,Last name,Email\nJos\xE9,Garc\xEDa,jose@example.com\n", 'not UTF-8 encoded'],
]);

test('the file may not have more rows than the limit', function () {
    $rows = collect(range(1, CustomerImportService::MAX_ROWS + 1))->map(fn ($i) => "P,{$i},p{$i}@example.com")->implode("\n");

    Livewire::actingAs($this->admin)->test(CustomerImport::class)
        ->set('file', customerCsv("First name,Last name,Email\n".$rows."\n"))
        ->assertHasErrors('file');
});

test('the sample file downloads', function () {
    $this->actingAs($this->admin)->get(route('customers.import.sample'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertSee('First name,Last name,Email', false);
});

test('customers are only ever added to the importer\'s own business', function () {
    [$otherAdmin] = followUpBusiness('Other Co');

    Livewire::actingAs($otherAdmin)->test(CustomerImport::class)
        ->set('file', customerCsv("First name,Last name,Email\nJane,Smith,jane@example.com\n"))
        ->call('import');

    expect(Customer::where('email', 'jane@example.com')->pluck('organization_id')->all())->toBe([$otherAdmin->organization_id]);
});
