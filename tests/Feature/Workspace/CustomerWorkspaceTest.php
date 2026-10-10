<?php

use App\Enums\ConversationStatus;
use App\Enums\CustomerStatus;
use App\Exceptions\Conversations\DuplicateCustomerException;
use App\Jobs\SendEmailJob;
use App\Livewire\Customers\CustomerForm;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Customers\ShowCustomer;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Services\FollowUps\FollowUpService;
use App\Services\Tasks\TaskService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));
    [$this->admin, $this->john, $this->conversation] = followUpBusiness('Dallas HVAC');
    $this->organization = $this->admin->organization;
});

function addCustomer(Organization $organization, string $first, string $last, array $attributes = []): Customer
{
    return Customer::factory()->for($organization)->create(array_merge([
        'first_name' => $first, 'last_name' => $last, 'email' => strtolower($first.'.'.$last).'@example.com',
    ], $attributes));
}

function customerList(User $user, array $params = [])
{
    return Livewire::withQueryParams($params)->actingAs($user)->test(CustomerIndex::class);
}

// Create & edit

test('a customer can be created', function () {
    Livewire::actingAs($this->admin)->test(CustomerForm::class)
        ->set('first_name', 'Sarah')->set('last_name', 'Wilson')->set('email', '  Sarah.Wilson@Example.COM ')
        ->set('phone', '(555) 123-4567')->set('company', 'Wilson Dental')->set('notes', 'Prefers email.')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('customers.show', Customer::where('first_name', 'Sarah')->sole()->id));

    expect(Customer::where('first_name', 'Sarah')->sole())
        ->organization_id->toBe($this->organization->id)
        ->name->toBe('Sarah Wilson')
        ->email->toBe('sarah.wilson@example.com')
        ->phone->toBe('(555) 123-4567')
        ->phone_digits->toBe('5551234567')
        ->company->toBe('Wilson Dental')
        ->status->toBe(CustomerStatus::Active);
});

test('required fields and formats are validated', function () {
    Livewire::actingAs($this->admin)->test(CustomerForm::class)
        ->set('email', 'not-an-email')->set('phone', 'call me')
        ->call('save')
        ->assertHasErrors(['first_name', 'last_name', 'email', 'phone']);

    expect(Customer::count())->toBe(1);
});

test('a customer can be edited, including status, and the email is normalized', function () {
    Livewire::actingAs($this->admin)->test(CustomerForm::class, ['customerId' => $this->john->id])
        ->assertSet('first_name', 'John')->assertSet('last_name', 'Smith')
        ->set('last_name', 'Smithson')->set('email', 'JOHN.S@Example.com')->set('status', 'inactive')->set('phone', '+1 555 000 1111')
        ->call('save')->assertHasNoErrors();

    expect($this->john->fresh())
        ->name->toBe('John Smithson')
        ->email->toBe('john.s@example.com')
        ->status->toBe(CustomerStatus::Inactive)
        ->and(Customer::count())->toBe(1); // inactive, not deleted
});

test('a duplicate email is detected and linked, never merged', function () {
    $sarah = addCustomer($this->organization, 'Sarah', 'Wilson');

    Livewire::actingAs($this->admin)->test(CustomerForm::class)
        ->set('first_name', 'Johnny')->set('last_name', 'Smith')->set('email', 'John@Example.com')
        ->call('save')
        ->assertHasErrors('email')
        ->assertSee('Customer already exists.')
        ->assertSeeHtml('href="'.route('customers.show', $this->john->id).'"')
        ->assertSee('View Customer');

    // Changing a customer's email to someone else's is refused too.
    Livewire::actingAs($this->admin)->test(CustomerForm::class, ['customerId' => $sarah->id])
        ->set('email', 'JOHN@example.com')->call('save')->assertHasErrors('email');

    expect(Customer::count())->toBe(2)->and($sarah->fresh()->email)->toBe('sarah.wilson@example.com');

    // The database enforces it as well, and another organization may use the same email.
    expect(fn () => DB::transaction(fn () => Customer::factory()->for($this->organization)->create(['email' => 'john@example.com'])))->toThrow(UniqueConstraintViolationException::class);
    expect(Customer::factory()->create(['email' => 'john@example.com'])->exists)->toBeTrue();
});

test('email normalization treats case and spaces as the same email', function () {
    expect(Customer::normalizeEmail('  John@Example.COM '))->toBe('john@example.com');

    expect(fn () => app(CustomerService::class)->create($this->admin, ['first_name' => 'J', 'last_name' => 'S', 'email' => 'JOHN@EXAMPLE.COM']))
        ->toThrow(DuplicateCustomerException::class);
});

// List: search, filters, sort, pagination

test('customer search matches first, last and full name, email and phone', function () {
    addCustomer($this->organization, 'Sarah', 'Wilson', ['phone' => '(555) 987-6543', 'company' => 'Wilson Dental']);
    addCustomer($this->organization, 'Mike', 'Johnson', ['email' => 'mjohnson@corp.example']);

    $names = fn (string $term) => customerList($this->admin, ['search' => $term])->viewData('customers')->pluck('name')->all();

    expect($names('sarah'))->toBe(['Sarah Wilson'])
        ->and($names('JOHNSON'))->toBe(['Mike Johnson'])
        ->and($names('sarah wilson'))->toBe(['Sarah Wilson'])
        ->and($names('mjohnson@corp'))->toBe(['Mike Johnson'])
        ->and($names('987-6543'))->toBe(['Sarah Wilson'])
        ->and($names('5559876543'))->toBe(['Sarah Wilson'])
        ->and($names('dental'))->toBe(['Sarah Wilson'])
        ->and($names('100%_'))->toBe([]); // LIKE wildcards are literal
});

test('customers can be filtered by status, conversation, follow-up and intent', function () {
    $sarah = addCustomer($this->organization, 'Sarah', 'Wilson', ['status' => 'inactive']);
    $mike = addCustomer($this->organization, 'Mike', 'Johnson');
    Conversation::factory()->for($mike)->create(['organization_id' => $this->organization->id, 'status' => 'closed', 'latest_intent' => 'not_interested']);
    $this->conversation->forceFill(['status' => 'waiting_business', 'latest_intent' => 'ready_to_book'])->save();
    $reminder = app(FollowUpService::class)->scheduleManual($this->admin, $this->john, now()->addHour());
    $reminder->forceFill(['due_at' => now()->subDays(2)])->save();

    $names = fn (array $f) => customerList($this->admin, $f)->viewData('customers')->pluck('name')->sort()->values()->all();

    expect($names(['status' => 'inactive']))->toBe(['Sarah Wilson'])
        ->and($names(['status' => 'active']))->toBe(['John Smith', 'Mike Johnson'])
        ->and($names(['conversation' => 'waiting_business']))->toBe(['John Smith'])
        ->and($names(['conversation' => 'closed']))->toBe(['Mike Johnson'])
        ->and($names(['follow_up' => 'overdue']))->toBe(['John Smith'])
        ->and($names(['follow_up' => 'none']))->toBe(['Mike Johnson', 'Sarah Wilson'])
        ->and($names(['intent' => 'ready_to_book']))->toBe(['John Smith'])
        ->and($names(['intent' => 'not_interested']))->toBe(['Mike Johnson']);

    customerList($this->admin)->assertSee(['Ready to book', 'Price objection', 'Wants callback', 'Other']);
});

test('customers are sorted (recently active by default) and paginated in the database', function () {
    foreach (range(1, 30) as $i) {
        addCustomer($this->organization, "First{$i}", sprintf('Last%02d', $i), ['last_activity_at' => now()->subMinutes($i)]);
    }

    $page = customerList($this->admin)->viewData('customers');
    expect($page->perPage())->toBe(25)->and($page->total())->toBe(31)->and($page->count())->toBe(25)
        ->and($page->first()->name)->toBe('First1 Last01');

    expect(customerList($this->admin, ['page' => 2])->viewData('customers')->count())->toBe(6)
        ->and(customerList($this->admin, ['per_page' => 50])->viewData('customers')->count())->toBe(31)
        ->and(customerList($this->admin, ['per_page' => 7])->viewData('customers')->perPage())->toBe(25) // not an allowed size
        ->and(customerList($this->admin, ['sort' => 'name_asc'])->viewData('customers')->first()->name)->toBe('First1 Last01')
        ->and(customerList($this->admin, ['sort' => 'name_desc'])->viewData('customers')->first()->name)->toBe('John Smith')
        ->and(customerList($this->admin, ['sort' => 'oldest'])->viewData('customers')->first()->name)->toBe('John Smith');

    DB::enableQueryLog();
    customerList($this->admin);
    // Every query listing customers is paged by the database (counts and the "any customers?" check aside).
    $selects = collect(DB::getQueryLog())->pluck('query')->map(fn ($q) => str_replace('`', '"', $q))
        ->filter(fn ($q) => str_contains($q, 'from "customers"') && ! str_contains($q, 'count(*)') && ! str_contains($q, 'select exists'));
    expect($selects)->not->toBeEmpty()->and($selects->every(fn ($q) => str_contains($q, 'limit 25')))->toBeTrue();
});

test('the empty state invites adding a customer', function () {
    $newAdmin = User::factory()->admin()->create();

    customerList($newAdmin)->assertSee('No customers yet.')->assertSeeHtml('href="'.route('customers.create').'"');
});

// Customer details

test('the customer page shows header, conversation, follow-ups, tasks and activity', function () {
    $this->john->forceFill(['phone' => '(555) 123-4567'])->save();
    customerReply($this->conversation, "Yes, let's schedule.");
    app(TaskService::class)->create($this->admin, $this->john, 'Call John about the install', conversation: $this->conversation);

    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->john->id])
        ->assertSeeInOrder(['John Smith', 'john@example.com', '(555) 123-4567', 'Status:', 'Active', 'Send Email', 'Schedule Follow-Up', 'Edit',
            'Conversation', 'Your HVAC estimate', 'Activity', 'Customer replied', "Yes, let's schedule.", 'Customer created',
            'Upcoming follow-ups', 'No follow-ups scheduled.', 'Tasks', 'Call John about the install']);

    $empty = addCustomer($this->organization, 'Nina', 'New');
    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $empty->id])
        ->assertSee('No conversation yet.')->assertSee('No follow-ups scheduled.')->assertSee('No tasks for this customer.');
});

test('"Send Email" opens the active conversation, or starts a new one', function () {
    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $this->john->id])
        ->call('openEmailForm')
        ->assertRedirect(route('inbox.show', ['conversationId' => $this->conversation->id, 'compose' => 1]));

    Queue::fake([SendEmailJob::class]);
    allowAutomaticFollowUpEmail($this->organization);
    $sarah = addCustomer($this->organization, 'Sarah', 'Wilson');

    Livewire::actingAs($this->admin)->test(ShowCustomer::class, ['customerId' => $sarah->id])
        ->call('openEmailForm')->assertSet('showEmailForm', true)
        ->set('emailSubject', 'Your estimate')->set('emailBody', 'Hi Sarah, your estimate is attached.')
        ->call('sendEmail')
        ->assertRedirect();

    $conversation = $sarah->conversations()->sole();
    expect($conversation->status)->toBe(ConversationStatus::WaitingCustomer)
        ->and($conversation->messages()->sole()->from_address)->toBe('sales@example.com');
});

// Isolation & authorization

test('organization A cannot see organization B customers', function () {
    [$otherAdmin, $foreign] = followUpBusiness('Houston Plumbing');
    $foreign->update(['first_name' => 'Foreign', 'last_name' => 'Fred']);

    customerList($this->admin)->assertDontSee('Foreign Fred');
    customerList($this->admin, ['search' => 'foreign'])->assertSee('No customers match');
    $this->actingAs($this->admin)->get("/customers/{$foreign->id}")->assertNotFound();
    customerList($otherAdmin)->assertSee('Foreign Fred');
});

test('organization A cannot edit organization B customers', function () {
    [, $foreign] = followUpBusiness('Houston Plumbing');

    $this->actingAs($this->admin)->get("/customers/{$foreign->id}/edit")->assertNotFound();
    expect(fn () => app(CustomerService::class)->update($this->admin, $foreign, ['first_name' => 'X', 'last_name' => 'Y', 'email' => 'x@example.com', 'status' => 'active']))
        ->toThrow(NotFoundHttpException::class);

    expect($foreign->fresh()->first_name)->toBe('John');
});

test('users without an organization, and guests, cannot reach customers', function () {
    $loner = User::factory()->create(['organization_id' => null]);

    $this->actingAs($loner)->get('/customers')->assertForbidden();
    $this->actingAs($loner)->get('/customers/create')->assertForbidden();
    $this->actingAs($loner)->get("/customers/{$this->john->id}/edit")->assertForbidden();
    auth()->logout();
    $this->get('/customers')->assertRedirect();
});

test('organization_id from the form is ignored', function () {
    [$otherAdmin] = followUpBusiness('Houston Plumbing');

    app(CustomerService::class)->create($this->admin, ['first_name' => 'Ann', 'last_name' => 'Lee', 'email' => 'ann@example.com', 'organization_id' => $otherAdmin->organization_id]);

    expect(Customer::where('email', 'ann@example.com')->sole()->organization_id)->toBe($this->organization->id);
});
