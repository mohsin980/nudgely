<?php

use App\Livewire\Settings\AiAssistant;
use App\Models\AssistantFaq;
use App\Models\CatalogItem;
use App\Models\Organization;
use App\Services\Assistant\AssistantKnowledgeService;
use App\Services\Assistant\CatalogImportService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->team = teamBusiness();
    $this->owner = $this->team['owner'];
    $this->organization = $this->team['organization'];
});

function catalogCsv(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('catalog.csv', $contents);
}

// ─── Access ─────────────────────────────────────────────────────────────────────────────

test('the owner opens the AI assistant settings from the settings menu', function () {
    $this->actingAs($this->owner)->get(route('settings.assistant'))
        ->assertOk()->assertSee('Get your assistant ready')->assertSee('AI Assistant');
});

test('staff cannot open or change what the assistant knows', function () {
    $this->actingAs($this->team['staff'])->get(route('settings.assistant'))->assertForbidden();

    expect(fn () => app(AssistantKnowledgeService::class)->saveFaq($this->team['staff'], ['question' => 'Q?', 'answer' => 'A']))
        ->toThrow(AuthorizationException::class);
});

// ─── Business profile ───────────────────────────────────────────────────────────────────

test('the business profile is checked and saved with the chosen languages', function () {
    Livewire::actingAs($this->owner)->test(AiAssistant::class)
        ->assertSet('languages', ['en', 'ur'])
        ->set('about', '')->set('languages', [])->call('saveProfile')
        ->assertHasErrors(['about', 'languages'])
        ->set('assistantName', 'Sara')->set('languages', ['ur', 'en'])
        ->set('about', 'Real estate agency in Lahore selling plots in DHA.')
        ->set('qualifyingQuestions', 'Budget, area, plot size')
        ->call('saveProfile')
        ->assertHasNoErrors()->assertSee('Assistant profile saved.');

    expect($this->organization->assistantProfile()->sole())
        ->assistant_name->toBe('Sara')
        ->languages->toBe('en,ur')
        ->about->toBe('Real estate agency in Lahore selling plots in DHA.')
        ->qualifying_questions->toBe('Budget, area, plot size')
        ->hours->toBeNull();
});

test('only supported languages are accepted', function () {
    Livewire::actingAs($this->owner)->test(AiAssistant::class)
        ->set('about', 'We sell plots.')->set('languages', ['fr'])->call('saveProfile')
        ->assertHasErrors('languages.0');
});

// ─── Questions & answers ────────────────────────────────────────────────────────────────

test('questions are added, edited and removed', function () {
    $page = Livewire::actingAs($this->owner)->test(AiAssistant::class, ['tab' => 'faqs'])
        ->set('question', 'Do you offer installments?')->set('answer', 'Yes, 25% down and 2 years.')
        ->call('saveFaq')->assertHasNoErrors()->assertSee('Do you offer installments?');

    $faq = AssistantFaq::sole();
    $page->call('editFaq', $faq->id)->assertSet('question', 'Do you offer installments?')
        ->set('answer', 'Yes, 20% down and 3 years.')->call('saveFaq')->assertSee('Question updated.');
    expect($faq->fresh()->answer)->toBe('Yes, 20% down and 3 years.');

    $page->call('deleteFaq', $faq->id)->assertSee('Question removed.');
    expect(AssistantFaq::count())->toBe(0);
});

test('a business cannot edit another business\'s questions', function () {
    $other = Organization::factory()->create();
    $foreign = new AssistantFaq;
    $foreign->forceFill(['organization_id' => $other->id, 'question' => 'Theirs?', 'answer' => 'Theirs.'])->save();

    expect(fn () => Livewire::actingAs($this->owner)->test(AiAssistant::class, ['tab' => 'faqs'])->call('editFaq', $foreign->id))
        ->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(AssistantKnowledgeService::class)->deleteFaq($this->owner, $foreign->id))
        ->toThrow(ModelNotFoundException::class);
    expect($foreign->fresh())->not->toBeNull();
});

// ─── Catalog ────────────────────────────────────────────────────────────────────────────

test('catalog items are added by hand and found by search', function () {
    Livewire::actingAs($this->owner)->test(AiAssistant::class, ['tab' => 'catalog'])
        ->call('newItem')->set('itemName', '')->call('saveItem')->assertHasErrors('itemName')
        ->set('itemName', '5 Marla plot, DHA Phase 5')->set('itemCategory', 'Plots')->set('itemPrice', '1.5 Cr')
        ->call('saveItem')->assertHasNoErrors()->assertSee('Item added to your catalog.')
        ->call('newItem')->set('itemName', '10 Marla house, Bahria Town')->set('itemCategory', 'Houses')->set('itemAvailable', false)->call('saveItem')
        ->set('search', 'bahria')->assertSee('10 Marla house, Bahria Town')->assertDontSee('5 Marla plot, DHA Phase 5');

    expect(CatalogItem::where('name', '10 Marla house, Bahria Town')->sole()->is_available)->toBeFalse();
});

test('two catalog items cannot share a name', function () {
    $knowledge = app(AssistantKnowledgeService::class);
    $plot = $knowledge->saveCatalogItem($this->owner, ['name' => 'Corner plot']);
    $knowledge->saveCatalogItem($this->owner, ['name' => 'Corner plot', 'price' => '1 Cr'], $plot->id); // editing itself is fine

    expect(fn () => $knowledge->saveCatalogItem($this->owner, ['name' => 'corner PLOT']))->toThrow(ValidationException::class)
        ->and(CatalogItem::count())->toBe(1);
});

test('a catalog CSV adds new items, updates existing ones by name and reports bad rows', function () {
    $existing = new CatalogItem;
    $existing->forceFill(['organization_id' => $this->organization->id, 'name' => 'AC service', 'price' => '$100'])->save();

    $csv = "\xEF\xBB\xBFProduct Name,Category,Price,Description,In Stock\n"
        ."\"5 Marla plot, DHA Phase 5\",Plots,1.5 Cr,Corner plot,yes\n"
        ."ac service,Maintenance,\$120 per visit,,\n"
        .",Plots,2 Cr,No name,yes\n"
        ."Old plot,Plots,1 Cr,,sold\n";

    Livewire::actingAs($this->owner)->test(AiAssistant::class, ['tab' => 'catalog'])
        ->set('file', catalogCsv($csv))->call('importCatalog')
        ->assertHasNoErrors()
        ->assertSet('importResult.created', 2)->assertSet('importResult.updated', 1)
        ->assertSee('Row 4: The name field is required.');

    expect(CatalogItem::count())->toBe(3)
        ->and($existing->fresh())->price->toBe('$120 per visit')->category->toBe('Maintenance')
        ->and(CatalogItem::where('name', 'Old plot')->sole()->is_available)->toBeFalse()
        ->and(CatalogItem::where('name', '5 Marla plot, DHA Phase 5')->sole())->details->toBe('Corner plot')->is_available->toBeTrue();
});

test('a catalog CSV without a name column is refused with a clear message', function () {
    Livewire::actingAs($this->owner)->test(AiAssistant::class, ['tab' => 'catalog'])
        ->set('file', catalogCsv("Title of thing,Cost\n"))->call('importCatalog')
        ->assertHasErrors('file');

    Livewire::actingAs($this->owner)->test(AiAssistant::class, ['tab' => 'catalog'])
        ->set('file', catalogCsv("Item,Cost\nPlot,1 Cr\n"))->call('importCatalog')
        ->assertHasNoErrors()->assertSet('importResult.created', 1);

    Livewire::actingAs($this->owner)->test(AiAssistant::class, ['tab' => 'catalog'])
        ->set('file', catalogCsv("Cost,Notes\n1 Cr,x\n"))->call('importCatalog')
        ->assertHasErrors(['file' => 'The file needs a "Name" column. Download the sample file to see the columns.']);
});

test('the sample catalog file downloads and imports cleanly', function () {
    Livewire::actingAs($this->owner)->test(AiAssistant::class, ['tab' => 'catalog'])
        ->call('downloadSample')->assertFileDownloaded('catalog-sample.csv');

    $path = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($path, CatalogImportService::sample());

    expect(app(CatalogImportService::class)->import($this->owner, $path))->toBe(['created' => 2, 'updated' => 0, 'failed' => []]);
});

// ─── Readiness ──────────────────────────────────────────────────────────────────────────

test('readiness shows what the assistant still needs', function () {
    $knowledge = app(AssistantKnowledgeService::class);
    expect(collect($knowledge->readiness($this->organization))->pluck('done')->all())->toBe([false, false, false]);

    $knowledge->saveProfile($this->owner, ['assistant_name' => 'Sara', 'languages' => ['en'], 'about' => 'We sell plots.']);
    $knowledge->saveFaq($this->owner, ['question' => 'Where are you?', 'answer' => 'DHA Phase 5.']);

    expect(collect($knowledge->readiness($this->organization))->pluck('done')->all())->toBe([true, true, false]);
});
