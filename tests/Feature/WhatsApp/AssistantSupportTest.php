<?php

use App\Livewire\Settings\AiAssistant;
use App\Models\AppointmentRequest;
use App\Models\AssistantChat;
use App\Models\AssistantProfile;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Services\Assistant\AppointmentBooking;
use App\Services\Assistant\AppointmentSlots;
use App\Services\Assistant\AssistantDecision;
use App\Services\Assistant\AssistantKnowledgeService;
use App\Services\Assistant\AssistantPromptBuilder;
use App\Services\Settings\BusinessSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/*
 * Quote answers, appointment times, after-hours replies and Arabic, through the same path as a real WhatsApp message.
 */

const SUPPORT_PHONE_ID = '106540352242999';
const SUPPORT_SECRET = 'app-secret-support-0123456789';
const SUPPORT_LEAD = '923001234567';

/** Meta delivers a signed message from the customer. */
function supportSays(string $text, string $id = 'wamid.SUPPORT1'): TestResponse
{
    $payload = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => [
        'metadata' => ['phone_number_id' => SUPPORT_PHONE_ID],
        'contacts' => [['profile' => ['name' => 'Ali Raza'], 'wa_id' => SUPPORT_LEAD]],
        'messages' => [['from' => SUPPORT_LEAD, 'id' => $id, 'timestamp' => (string) time(), 'type' => 'text', 'text' => ['body' => $text]]],
    ]]]]]]);

    return test()->call('POST', '/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, SUPPORT_SECRET)], $payload);
}

/** The AI\'s answer, with the fields the assistant reads. */
function supportAi(array $decision = []): void
{
    $content = json_encode($decision + [
        'reply' => 'Hi! How can I help?', 'language' => 'en', 'qualified' => false, 'handover' => false, 'handover_reason' => null,
        'summary' => 'Ali asked about a visit.', 'details' => ['requirement' => null, 'budget' => null, 'location' => null, 'timeline' => null, 'other' => null],
        'appointment_slot' => null,
    ]);
    $answer = ['model' => 'gemini-2.5-flash', 'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $content]]]];

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response($answer),
        'api.groq.com/*' => Http::response($answer),
        'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]]),
    ]);
}

/** Texts the business sent to the customer on WhatsApp. */
function supportSent(): array
{
    return collect(Http::recorded())->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), 'graph.facebook.com') && ($request['to'] ?? null) === SUPPORT_LEAD)
        ->map(fn (Request $request) => $request['text']['body'] ?? '')->values()->all();
}

beforeEach(function () {
    fakeEmailProvider(); // quotes are sent through the fake mail provider
    [$this->admin, $this->customer, $this->conversation] = estimateBusiness('Lahore Estates');
    $this->organization = $this->admin->organization;
    $this->organization->forceFill(['timezone' => 'America/Chicago', 'country' => 'US'])->save();

    config([
        'billing.plans.free.features' => [...config('billing.plans.free.features'), 'ai_assistant'],
        'billing.plans.free.limits.ai_conversations' => 50,
        'ai.chain' => ['gemini', 'groq'],
        'ai.models.gemini.api_key' => 'gemini-key',
        'ai.models.groq.api_key' => 'groq-key',
        'ai.classification.enabled' => false,
    ]);

    app(BusinessSettingsService::class)->updateFollowUpChannels($this->admin, ['channels' => 'both', 'primary' => 'whatsapp']);
    $connection = new WhatsAppConnection;
    $connection->forceFill([
        'organization_id' => $this->organization->id, 'phone_number_id' => SUPPORT_PHONE_ID, 'business_account_id' => '102290129340398',
        'display_phone_number' => '+1 214 555 0100', 'verified_name' => 'Lahore Estates', 'access_token' => 'EAAG-test-token-1234567890',
        'app_secret' => SUPPORT_SECRET, 'webhook_verify_token' => 'verify-me', 'status' => WhatsAppConnection::STATUS_ACTIVE, 'verified_at' => now(),
    ])->save();

    // The customer's WhatsApp number is the one the test messages come from.
    $this->customer->forceFill(['phone' => '+92 300 1234567', 'whatsapp_opt_in_at' => now(), 'whatsapp_opt_in_source' => 'staff'])->save();

    app(AssistantKnowledgeService::class)->saveProfile($this->admin, ['assistant_name' => 'Sara', 'languages' => ['en', 'ur', 'ar'], 'about' => 'Plumbing and HVAC in Chicago.']);
    app(AssistantKnowledgeService::class)->saveSwitch($this->admin, ['enabled' => true, 'owner_whatsapp' => '+1 214 555 0199']);

    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT'.uniqid()]]])]);
});

/** An appointment request, set up as the assistant or the settings page would create it. */
function holdTime(array $attributes): AppointmentRequest
{
    $request = new AppointmentRequest;
    $request->forceFill($attributes)->save();

    return $request->refresh();
}

/** The system prompt the assistant would get for this conversation right now. */
function promptNow(object $test): string
{
    $organization = $test->organization->fresh();
    $profile = $organization->assistantProfile()->firstOrFail();

    return app(AssistantPromptBuilder::class)->messages($organization, $profile, $test->conversation->fresh(), null)[0]['content'];
}

// ─── Quotes ─────────────────────────────────────────────────────────────────────────────

test('the customer\'s own sent quotes are given to the assistant, with number, amount, status and dates', function () {
    $quote = sentEstimate($this->admin, $this->customer, ['valid_until' => '2026-11-20']);
    $other = Customer::factory()->for($this->organization)->create(['name' => 'Someone Else']);
    $otherQuote = sentEstimate($this->admin, $other);
    draftEstimate($this->admin, $this->customer);

    $prompt = promptNow($this);

    expect($prompt)->toContain('CUSTOMER QUOTES')
        ->toContain($quote->displayNumber().': $2,750.00, status sent')
        ->toContain('valid until Nov 20, 2026')
        ->not->toContain($otherQuote->displayNumber())
        ->and(substr_count($prompt, '- EST-'))->toBe(1); // the draft is not listed
});

test('a customer with no quotes is told so, so the assistant does not invent one', function () {
    expect(promptNow($this))->toContain('No quotes have been sent to this customer.');
});

test('the assistant answers quote questions from the quote details and never accepts a quote itself', function () {
    sentEstimate($this->admin, $this->customer, ['valid_until' => '2026-11-20']);
    $prompt = promptNow($this);

    expect($prompt)->toContain('answer questions about this customer\'s quotes only from CUSTOMER QUOTES')
        ->toContain('Never accept, decline or change a quote yourself');
});

// ─── Open and closed ────────────────────────────────────────────────────────────────────

test('the assistant knows the business is open now, or when it next opens', function () {
    // Monday 3:00 PM in Chicago: open.
    $this->travelTo(CarbonImmutable::parse('2026-11-09 15:00', 'America/Chicago'));
    expect(promptNow($this))->toContain("RIGHT NOW\nOpen now.");

    // Sunday 4:00 AM: closed until Monday 8:00 AM.
    $this->travelTo(CarbonImmutable::parse('2026-11-08 04:00', 'America/Chicago'));
    expect(promptNow($this))->toContain('Closed now. Next opening: Mon 9 Nov, 8:00 AM.');
});

test('the opening hours given to the assistant are the weekly hours from Business Preferences', function () {
    expect(promptNow($this))->toContain('Opening hours: Monday 8:00 AM–5:00 PM, Tuesday 8:00 AM–5:00 PM, Wednesday 8:00 AM–5:00 PM, Thursday 8:00 AM–5:00 PM, Friday 8:00 AM–5:00 PM, Saturday closed, Sunday closed');
});

test('a business with no opening hours is told the assistant cannot promise a time', function () {
    app(BusinessSettingsService::class)->updateBusinessHours($this->admin, collect(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'])
        ->mapWithKeys(fn ($day) => [$day => ['open' => false, 'start' => '08:00', 'end' => '17:00']])->all());

    expect(promptNow($this))->toContain('Closed now, and no opening hours are set.');
});

// ─── Appointment times ──────────────────────────────────────────────────────────────────

test('offered times are free one-hour slots inside opening hours, from the next hour on, and skip requested ones', function () {
    $slots = app(AppointmentSlots::class);
    $monday = CarbonImmutable::parse('2026-11-09 10:30', 'America/Chicago');

    $offered = $slots->offered($this->organization, $monday);

    expect($offered)->toHaveCount(AppointmentSlots::MAX_OFFERED)
        ->and($offered[0]['key'])->toBe('2026-11-09T12:00')
        ->and($offered[0]['label'])->toBe('Mon 9 Nov, 12:00 PM')
        ->and(collect($offered)->every(fn ($slot) => ! in_array($slot['at']->format('D'), ['Sat', 'Sun'], true)))->toBeTrue();

    // A requested time is taken for everyone.
    holdTime([
        'organization_id' => $this->organization->id, 'customer_id' => $this->customer->id, 'starts_at' => CarbonImmutable::parse('2026-11-09 12:00', 'America/Chicago')->utc(),
        'status' => AppointmentRequest::STATUS_REQUESTED,
    ]);

    expect($slots->offered($this->organization, $monday)[0]['key'])->toBe('2026-11-09T13:00');
});

test('only a time that is offered can be picked', function () {
    $slots = app(AppointmentSlots::class);
    $monday = CarbonImmutable::parse('2026-11-09 10:30', 'America/Chicago');

    expect($slots->find($this->organization, '2026-11-09T12:00', $monday))->not->toBeNull()
        ->and($slots->find($this->organization, '2026-11-07T09:00', $monday))->toBeNull() // Saturday
        ->and($slots->find($this->organization, '2026-11-09T03:00', $monday))->toBeNull() // before opening
        ->and($slots->find($this->organization, 'tomorrow morning', $monday))->toBeNull();
});

test('the AI\'s appointment key must look like a local time', function () {
    $decision = fn (array $extra) => AssistantDecision::fromJson(json_encode($extra + [
        'reply' => 'ok', 'language' => 'en', 'qualified' => false, 'handover' => false, 'handover_reason' => null,
        'summary' => '', 'details' => [],
    ]));

    expect($decision(['appointment_slot' => '2026-11-09T12:00'])->appointmentSlot)->toBe('2026-11-09T12:00')
        ->and($decision(['appointment_slot' => 'Monday noon'])->appointmentSlot)->toBeNull()
        ->and($decision([])->appointmentSlot)->toBeNull();
});

test('a customer who picks a free time holds it for the team, the chat goes to a person, and the customer is told', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:30', 'America/Chicago'));
    supportAi(['appointment_slot' => '2026-11-09T12:00', 'reply' => 'Great, I have held Mon 9 Nov, 12:00 PM for you. A team member will confirm it.']);

    supportSays('Can I come at 12 on Monday?');

    $request = AppointmentRequest::query()->sole();
    expect($request)->status->toBe(AppointmentRequest::STATUS_REQUESTED)
        ->starts_at->toDateTimeString()->toBe('2026-11-09 18:00:00') // 12:00 Chicago, in UTC
        ->and(AssistantChat::query()->latest('id')->first()->isActive())->toBeFalse()
        ->and(DB::table('notifications')->where('notifiable_id', $this->admin->id)->count())->toBeGreaterThan(0)
        ->and(implode(' ', supportSent()))->toContain('held Mon 9 Nov, 12:00 PM');
});

test('a time that is no longer free is not booked, and the customer is asked for another', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:30', 'America/Chicago'));
    holdTime([
        'organization_id' => $this->organization->id, 'customer_id' => $this->customer->id, 'starts_at' => CarbonImmutable::parse('2026-11-09 12:00', 'America/Chicago')->utc(),
        'status' => AppointmentRequest::STATUS_CONFIRMED,
    ]);
    supportAi(['appointment_slot' => '2026-11-09T12:00', 'reply' => 'Sorry, that time is taken. Would another time work?']);

    supportSays('Can I come at 12 on Monday?');

    expect(AppointmentRequest::query()->count())->toBe(1)
        ->and(AssistantChat::query()->latest('id')->first()->isActive())->toBeFalse();
});

test('a person confirms the time and the customer is told on WhatsApp, while the window is open', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:30', 'America/Chicago'));
    supportAi(['appointment_slot' => '2026-11-09T12:00', 'reply' => 'I have held that time for you.']);
    supportSays('Can I come at 12 on Monday?');
    $request = AppointmentRequest::query()->sole();

    Livewire::actingAs($this->admin)->test(AiAssistant::class)
        ->set('tab', 'appointments')
        ->assertSee('Appointment times')
        ->call('confirmAppointment', $request->id)
        ->assertSee('Appointment confirmed');

    expect($request->fresh())->status->toBe(AppointmentRequest::STATUS_CONFIRMED)->decided_by->toBe($this->admin->id)
        ->and(implode(' ', supportSent()))->toContain('confirmed for Mon 9 Nov, 12:00 PM');
});

test('confirming a time saves it even when WhatsApp can\'t message the customer, and says so', function () {
    $request = holdTime([
        'organization_id' => $this->organization->id, 'customer_id' => $this->customer->id, 'conversation_id' => $this->conversation->id, 'starts_at' => CarbonImmutable::parse('2026-11-09 12:00', 'America/Chicago')->utc(),
    ]);

    $warning = app(AppointmentBooking::class)->confirm($this->admin, $request);

    expect($request->fresh()->status)->toBe(AppointmentRequest::STATUS_CONFIRMED)
        ->and($warning)->toContain('was not messaged on WhatsApp');
});

test('staff cannot confirm or decline appointment times', function () {
    $staff = $this->organization->users()->where('role', 'staff')->first()
        ?? User::factory()->staff()->for($this->organization)->create();
    $request = holdTime([
        'organization_id' => $this->organization->id, 'customer_id' => $this->customer->id, 'starts_at' => now()->addDay(),
    ]);

    expect(fn () => app(AppointmentBooking::class)->confirm($staff, $request))->toThrow(AuthorizationException::class)
        ->and(fn () => app(AppointmentBooking::class)->decline($staff, $request))->toThrow(AuthorizationException::class)
        ->and($request->fresh()->status)->toBe(AppointmentRequest::STATUS_REQUESTED);
});

// ─── Arabic ─────────────────────────────────────────────────────────────────────────────

test('Arabic is a language the assistant can reply in, and the AI\'s language must be one it knows', function () {
    expect(AssistantProfile::LANGUAGES)->toHaveKey('ar')
        ->and(AssistantDecision::fromJson(json_encode(['reply' => 'مرحبا', 'language' => 'ar', 'qualified' => false, 'handover' => false, 'handover_reason' => null, 'summary' => '', 'details' => []]))->language)->toBe('ar')
        ->and(AssistantDecision::fromJson(json_encode(['reply' => 'Hi', 'language' => 'fr', 'qualified' => false, 'handover' => false, 'handover_reason' => null, 'summary' => '', 'details' => []]))->language)->toBe('en');
});

test('a customer who writes in Arabic gets an Arabic reply, and the chat remembers the language', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:30', 'America/Chicago'));
    supportAi(['language' => 'ar', 'reply' => 'مرحبا! أنا ساره من Lahore Estates. كيف يمكنني مساعدتك؟']);

    supportSays('مرحبا، عندي سؤال عن الأسعار');

    expect(AssistantChat::query()->latest('id')->first()->language)->toBe('ar')
        ->and(implode(' ', supportSent()))->toContain('مرحبا');
});

// ─── Hourly reply budget ────────────────────────────────────────────────────────────────

test('when the hour\'s AI replies are used up, the customer is told a person will reply and the chat goes to the team', function () {
    config(['ai.hourly_replies.free' => 2]);
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:30', 'America/Chicago'));
    supportAi(['reply' => 'Happy to help.']);

    supportSays('Do you do boilers?', 'wamid.BUDGET1');
    supportSays('And AC repairs?', 'wamid.BUDGET2');
    expect(AssistantChat::query()->sole()->replies)->toBe(2);

    supportSays('Can someone call me?', 'wamid.BUDGET3');

    $chat = AssistantChat::query()->sole();
    expect($chat->replies)->toBe(2)
        ->and($chat->isActive())->toBeFalse()
        ->and(last(supportSent()))->toBe('Thanks for your message! A member of our team will reply shortly.');

    // The owner hears at 80% of the hour's budget and again when it is used up.
    $limitMessages = $this->admin->notifications()->get()->pluck('data.message')->filter(fn ($m) => preg_match("/has used (\\d+% of|this hour's \\d+ replies)/", (string) $m) === 1);
    expect($limitMessages)->toHaveCount(2);
});

// ─── One chat per day ───────────────────────────────────────────────────────────────────

test('a customer gets one assistant chat per day: messages on the same day share it, the next day starts a new one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-09 10:30', 'America/Chicago'));
    supportAi(['reply' => 'Happy to help.']);

    supportSays('Do you do boilers?', 'wamid.DAY1A');
    supportSays('And AC repairs?', 'wamid.DAY1B');
    expect(AssistantChat::query()->count())->toBe(1);

    $this->travelTo(CarbonImmutable::parse('2026-11-10 10:30', 'America/Chicago'));
    supportSays('Good morning again', 'wamid.DAY2');

    expect(AssistantChat::query()->count())->toBe(2)
        ->and(AssistantChat::query()->latest('id')->first()->replies)->toBe(1);
});
