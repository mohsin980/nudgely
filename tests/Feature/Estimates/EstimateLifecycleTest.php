<?php

use App\Enums\ConversationStatus;
use App\Enums\EstimateDeclineReason;
use App\Enums\EstimateStatus;
use App\Enums\MessageStatus;
use App\Events\EstimateAccepted;
use App\Events\EstimateCreated;
use App\Events\EstimateDeclined;
use App\Events\EstimateExpired;
use App\Events\EstimateSent;
use App\Events\EstimateViewed;
use App\Exceptions\Email\EmailProviderException;
use App\Exceptions\Estimates\EstimateException;
use App\Jobs\SendEmailJob;
use App\Models\ConversationEvent;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Message;
use App\Services\Email\EmailService;
use App\Services\Estimates\EstimateService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // 15:00 UTC = 10:00 AM in Chicago.
    $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00:00', 'UTC'));
    [$this->admin, $this->john, $this->conversation] = estimateBusiness();
    $this->provider = fakeEmailProvider();
    $this->estimates = app(EstimateService::class);
    $this->eventTypes = fn (Estimate $e) => ConversationEvent::where('estimate_id', $e->id)->orderBy('id')->pluck('type')->all();
});

// Drafts

test('a draft can be edited', function () {
    $estimate = draftEstimate($this->admin, $this->john);

    $this->estimates->update($this->admin, $estimate, estimateInput($this->john, [
        'title' => 'AC Installation + Thermostat',
        'tax_rate' => '8.25',
        'items' => [['description' => 'AC Installation', 'quantity' => '1', 'unit_price' => '3000']],
    ]));

    $estimate->refresh();
    expect($estimate->title)->toBe('AC Installation + Thermostat')
        ->and($estimate->total)->toBe('3247.50')
        ->and($estimate->items()->count())->toBe(1)
        ->and($estimate->estimate_number)->toBe('EST-1001');
});

test('a draft can be saved without sending, even without items', function () {
    Event::fake([EstimateCreated::class, EstimateSent::class]);

    $estimate = draftEstimate($this->admin, $this->john, ['items' => []]);

    expect($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->total)->toBe('0.00')
        ->and($this->provider->sent)->toBe([])
        ->and(Message::count())->toBe(0)
        ->and(($this->eventTypes)($estimate))->toBe(['estimate_created']);
    Event::assertDispatchedTimes(EstimateCreated::class, 1);
    Event::assertNotDispatched(EstimateSent::class);
});

test('an estimate without items cannot be sent', function () {
    $estimate = draftEstimate($this->admin, $this->john, ['items' => []]);

    expect(fn () => $this->estimates->send($this->admin, $estimate))->toThrow(EstimateException::class, 'Add at least one item before sending this estimate.')
        ->and($estimate->refresh()->status)->toBe(EstimateStatus::Draft)
        ->and(Message::count())->toBe(0);
});

test('a sent estimate cannot be edited', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    expect(fn () => $this->estimates->update($this->admin, $estimate, estimateInput($this->john, ['title' => 'Changed'])))
        ->toThrow(EstimateException::class, 'Create a revision instead')
        ->and($estimate->refresh()->title)->toBe('AC Installation');
});

// Sending

test('an estimate is sent through EmailService and becomes sent when delivered', function () {
    Event::fake([EstimateSent::class]);
    $estimate = draftEstimate($this->admin, $this->john);

    $message = $this->estimates->send($this->admin, $estimate);
    $estimate->refresh();

    expect($estimate->status)->toBe(EstimateStatus::Sent)
        ->and($estimate->sent_at)->not->toBeNull()
        ->and($estimate->send_message_id)->toBe($message->id)
        ->and($estimate->public_token_hash)->toHaveLength(64)
        // The estimate went into a conversation; the customer's reply comes back to it.
        ->and($estimate->conversation_id)->not->toBeNull()
        ->and($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($message->conversation_id)->toBe($estimate->conversation_id)
        ->and($message->metadata)->toMatchArray(['type' => 'estimate', 'estimate_id' => (string) $estimate->id])
        ->and($this->provider->sent)->toHaveCount(1);

    $email = $this->provider->sent[0];
    expect($email->fromEmail)->toBe('sales@example.com')
        ->and($email->toEmail)->toBe('john@example.com')
        ->and($email->subject)->toBe('Estimate EST-1001 from Dallas HVAC')
        ->and($email->replyTo)->toStartWith('reply+')
        ->and($email->textBody)->toContain('Hi John,')
        ->and($email->textBody)->toContain('Total: $2,750.00')
        ->and($email->textBody)->toContain($estimate->publicUrl())
        ->and($email->htmlBody)->toContain('AC Installation')
        ->and($email->htmlBody)->toContain('$2,750.00')
        ->and($email->htmlBody)->toContain(e($estimate->publicUrl()));

    expect(($this->eventTypes)($estimate))->toBe(['estimate_created', 'estimate_sent']);
    Event::assertDispatchedTimes(EstimateSent::class, 1);
    Event::assertDispatched(EstimateSent::class, fn (EstimateSent $e) => $e->estimateId === $estimate->id && $e->organizationId === $estimate->organization_id
        && $e->customerId === $this->john->id && $e->conversationId === $estimate->conversation_id);
});

test('the email is queued, not sent during the request', function () {
    Queue::fake();
    $estimate = draftEstimate($this->admin, $this->john);

    $message = $this->estimates->send($this->admin, $estimate);

    Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->messageId === $message->id);
    expect($this->provider->sent)->toBe([])
        ->and($message->status)->toBe(MessageStatus::Queued)
        // Not "sent" until it is delivered.
        ->and($estimate->refresh()->status)->toBe(EstimateStatus::Draft)
        ->and($this->estimates->isSending($estimate))->toBeTrue()
        ->and(fn () => $this->estimates->send($this->admin, $estimate))->toThrow(EstimateException::class, 'already being sent')
        ->and(fn () => $this->estimates->update($this->admin, $estimate, estimateInput($this->john)))->toThrow(EstimateException::class, 'being sent');
});

test('the estimate uses its linked conversation instead of creating another', function () {
    $estimate = draftEstimate($this->admin, $this->john, ['conversation_id' => (string) $this->conversation->id]);

    $this->estimates->send($this->admin, $estimate);

    expect($estimate->refresh()->conversation_id)->toBe($this->conversation->id)
        ->and($this->john->conversations()->count())->toBe(1)
        ->and($this->conversation->refresh()->status)->toBe(ConversationStatus::WaitingCustomer);
});

test('a failed email does not mark the estimate as sent, and it can be sent again', function () {
    Event::fake([EstimateSent::class]);
    $this->provider->sendFailures = [EmailProviderException::rejected('Inactive recipient')];
    $estimate = draftEstimate($this->admin, $this->john);

    $this->estimates->send($this->admin, $estimate);

    $estimate->refresh();
    expect($estimate->status)->toBe(EstimateStatus::Draft)
        ->and($estimate->sent_at)->toBeNull()
        ->and($this->estimates->failedSend($estimate))->not->toBeNull()
        ->and(($this->eventTypes)($estimate))->toBe(['estimate_created', 'estimate_send_failed']);
    Event::assertNotDispatched(EstimateSent::class);

    // Try again: delivered this time.
    $this->estimates->send($this->admin, $estimate);
    expect($estimate->refresh()->status)->toBe(EstimateStatus::Sent);
    Event::assertDispatchedTimes(EstimateSent::class, 1);
});

test('a message that fails after retries also leaves the estimate unsent', function () {
    Queue::fake();
    $estimate = draftEstimate($this->admin, $this->john);
    $message = $this->estimates->send($this->admin, $estimate);

    app(EmailService::class)->markFailedIfUnsent($message, 'The email provider did not respond after several attempts.');

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Draft)
        ->and($this->estimates->failedSend($estimate)?->id)->toBe($message->id);
});

test('an unverified sender prevents sending', function () {
    $this->admin->organization->emailConnections()->update(['verification_status' => 'pending', 'verified_at' => null]);
    $estimate = draftEstimate($this->admin, $this->john);

    expect(fn () => $this->estimates->send($this->admin, $estimate))->toThrow(EstimateException::class, 'Unable to send estimate.')
        ->and($estimate->refresh()->status)->toBe(EstimateStatus::Draft)
        ->and(Message::count())->toBe(0)
        ->and($this->john->conversations()->count())->toBe(1);
});

test('sending is refused for an opted-out customer or a past "valid until" date', function () {
    $estimate = draftEstimate($this->admin, $this->john);
    $estimate->forceFill(['valid_until' => '2026-10-01'])->save();
    expect(fn () => $this->estimates->send($this->admin, $estimate))->toThrow(EstimateException::class, 'valid until');

    $estimate->forceFill(['valid_until' => '2026-11-01'])->save();
    $this->john->forceFill(['email_opted_out_at' => now()])->save();
    expect(fn () => $this->estimates->send($this->admin, $estimate))->toThrow(EstimateException::class, 'opted out');
});

test('amounts are recalculated from the items before sending', function () {
    $estimate = draftEstimate($this->admin, $this->john);
    // Tampered stored total.
    Estimate::whereKey($estimate->id)->update(['total' => '1.00', 'subtotal' => '1.00']);

    $this->estimates->send($this->admin, $estimate);

    expect($estimate->refresh()->total)->toBe('2750.00')
        ->and($this->provider->sent[0]->textBody)->toContain('$2,750.00');
});

// Customer view

test('the secure link shows the estimate and marks it viewed once', function () {
    Event::fake([EstimateViewed::class]);
    $estimate = sentEstimate($this->admin, $this->john);

    $this->get($estimate->publicUrl())
        ->assertOk()
        ->assertSee('EST-1001')->assertSee('Dallas HVAC')->assertSee('John Smith')->assertSee('AC Installation')
        ->assertSee('$2,750.00')->assertSee('Accept Estimate')->assertSee('Decline Estimate')->assertSee('Ask a Question')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $estimate->refresh();
    expect($estimate->status)->toBe(EstimateStatus::Viewed)->and($estimate->viewed_at)->not->toBeNull();

    $this->get($estimate->publicUrl())->assertOk();
    Event::assertDispatchedTimes(EstimateViewed::class, 1);
    expect(($this->eventTypes)($estimate))->toBe(['estimate_created', 'estimate_sent', 'estimate_viewed']);
});

test('the business previewing the link does not count as the customer viewing it', function () {
    Event::fake([EstimateViewed::class]);
    $estimate = sentEstimate($this->admin, $this->john);

    $this->actingAs($this->admin)->get($estimate->publicUrl())->assertOk()->assertSee('EST-1001');

    expect($estimate->refresh()->status)->toBe(EstimateStatus::Sent);
    Event::assertNotDispatched(EstimateViewed::class);
});

test('deleting an organization removes its estimates; a customer with estimates can\'t be deleted alone', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    expect(fn () => DB::transaction(fn () => $this->john->delete()))->toThrow(QueryException::class);

    $this->admin->organization->delete();
    expect(Estimate::whereKey($estimate->id)->exists())->toBeFalse()
        ->and(EstimateItem::where('estimate_id', $estimate->id)->exists())->toBeFalse();
});

test('"Ask a question" replies into the estimate conversation', function () {
    $estimate = sentEstimate($this->admin, $this->john);
    $replyTo = $estimate->sendMessage->reply_to;

    $this->get($estimate->publicUrl())->assertSee('mailto:'.$replyTo, false);
});

test('an invalid token shows "Estimate not found."', function (string $token) {
    sentEstimate($this->admin, $this->john);

    $this->get('/estimate/view/'.$token)->assertNotFound();
})->with([
    'random' => [str_repeat('a', 48)],
    'too short' => ['abc'],
    'an estimate id' => ['1'],
]);

test('a wrong token gets the same page as any other wrong token', function () {
    sentEstimate($this->admin, $this->john);

    $this->get('/estimate/view/'.str_repeat('Z', 48))->assertNotFound()->assertSee('Estimate not found.')->assertDontSee('EST-1001');
});

test('a draft is never visible through a link', function () {
    $estimate = draftEstimate($this->admin, $this->john);
    // Even with a token (e.g. a send that failed), a draft is not shown.
    $estimate->forceFill(['public_token' => $token = str_repeat('d', 48), 'public_token_hash' => hash('sha256', $token)])->save();

    $this->get('/estimate/view/'.$token)->assertNotFound();
});

test('the token is stored encrypted and looked up by hash; it contains no IDs', function () {
    $estimate = sentEstimate($this->admin, $this->john);
    $raw = DB::table('estimates')->where('id', $estimate->id)->first();
    $token = $estimate->public_token;

    expect($token)->toMatch('/^[A-Za-z0-9]{48}$/')
        ->and($raw->public_token)->not->toContain($token)
        ->and($raw->public_token_hash)->toBe(hash('sha256', $token))
        ->and($estimate->publicUrl())->not->toContain('/'.$estimate->id.'/')
        ->and($estimate->toArray())->not->toHaveKeys(['public_token', 'public_token_hash']);
});

test('an expired estimate is shown as expired and cannot be accepted', function () {
    Event::fake([EstimateExpired::class, EstimateAccepted::class]);
    $estimate = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-05']);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 15:00:00', 'UTC'));

    // Expired on view even before the scheduler runs.
    $this->get($estimate->publicUrl())->assertOk()->assertSee('This estimate has expired.')->assertDontSee('Accept Estimate');
    expect($estimate->refresh()->status)->toBe(EstimateStatus::Expired);

    $this->post($estimate->publicUrl().'/accept')->assertRedirect($estimate->publicUrl());
    $this->get($estimate->publicUrl())->assertSee('This estimate has expired. Please contact us for an updated estimate.');
    expect($estimate->refresh()->status)->toBe(EstimateStatus::Expired);
    Event::assertDispatchedTimes(EstimateExpired::class, 1);
    Event::assertNotDispatched(EstimateAccepted::class);
});

test('a cancelled estimate\'s link is revoked', function () {
    $estimate = sentEstimate($this->admin, $this->john);
    $url = $estimate->publicUrl();

    $this->estimates->cancel($this->admin, $estimate);

    $this->get($url)->assertNotFound();
    expect($estimate->refresh()->status)->toBe(EstimateStatus::Cancelled)
        ->and($estimate->public_token_hash)->toBeNull()
        ->and($estimate->cancelled_at)->not->toBeNull();
});

test('a customer cannot reach another estimate with their link', function () {
    $mine = sentEstimate($this->admin, $this->john);
    $other = sentEstimate($this->admin, $this->john, ['title' => 'Furnace Repair', 'items' => [['description' => 'Furnace repair', 'quantity' => '1', 'unit_price' => '900']]]);

    $this->get($mine->publicUrl())->assertSee('AC Installation')->assertDontSee('Furnace Repair')->assertDontSee('EST-1002');
    $this->post($mine->publicUrl().'/accept');

    expect($mine->refresh()->status)->toBe(EstimateStatus::Accepted)
        ->and($other->refresh()->status)->toBe(EstimateStatus::Sent);
});

// Acceptance

test('the customer can accept; EstimateAccepted fires once', function () {
    Event::fake([EstimateAccepted::class]);
    $estimate = sentEstimate($this->admin, $this->john);
    $this->get($estimate->publicUrl());

    $this->post($estimate->publicUrl().'/accept')->assertRedirect($estimate->publicUrl());
    $this->get($estimate->publicUrl())->assertSee('You accepted this estimate')->assertDontSee('Accept Estimate');

    $estimate->refresh();
    expect($estimate->status)->toBe(EstimateStatus::Accepted)
        ->and($estimate->accepted_at)->not->toBeNull()
        ->and(($this->eventTypes)($estimate))->toBe(['estimate_created', 'estimate_sent', 'estimate_viewed', 'estimate_accepted']);
    Event::assertDispatchedTimes(EstimateAccepted::class, 1);
});

test('accepting twice does not create a second acceptance', function () {
    Event::fake([EstimateAccepted::class]);
    $estimate = sentEstimate($this->admin, $this->john);

    $this->post($estimate->publicUrl().'/accept');
    $firstAcceptedAt = $estimate->refresh()->accepted_at;
    $this->travel(5)->minutes();
    $this->post($estimate->publicUrl().'/accept');
    $this->estimates->accept($estimate);

    expect($estimate->refresh()->accepted_at->equalTo($firstAcceptedAt))->toBeTrue()
        ->and(ConversationEvent::where('estimate_id', $estimate->id)->where('type', 'estimate_accepted')->count())->toBe(1);
    Event::assertDispatchedTimes(EstimateAccepted::class, 1);
});

test('an accepted estimate cannot be declined, cancelled or revised', function () {
    $estimate = sentEstimate($this->admin, $this->john);
    $this->estimates->accept($estimate);

    expect(fn () => $this->estimates->decline($estimate))->toThrow(EstimateException::class, 'already accepted')
        ->and(fn () => $this->estimates->cancel($this->admin, $estimate))->toThrow(EstimateException::class)
        ->and(fn () => $this->estimates->revise($this->admin, $estimate))->toThrow(EstimateException::class, 'accepted');
});

// Decline

test('the customer can decline with a reason; EstimateDeclined fires once', function () {
    Event::fake([EstimateDeclined::class]);
    $estimate = sentEstimate($this->admin, $this->john);

    $this->post($estimate->publicUrl().'/decline', ['reason' => 'too_expensive', 'note' => 'Got a cheaper quote.'])->assertRedirect($estimate->publicUrl());
    $this->post($estimate->publicUrl().'/decline', ['reason' => 'other']);

    $estimate->refresh();
    expect($estimate->status)->toBe(EstimateStatus::Declined)
        ->and($estimate->declined_at)->not->toBeNull()
        ->and($estimate->decline_reason)->toBe(EstimateDeclineReason::TooExpensive)
        ->and($estimate->decline_note)->toBe('Got a cheaper quote.');
    Event::assertDispatchedTimes(EstimateDeclined::class, 1);

    $this->get($estimate->publicUrl())->assertSee('You declined this estimate');
});

test('an unknown decline reason is rejected', function () {
    $estimate = sentEstimate($this->admin, $this->john);

    $this->post($estimate->publicUrl().'/decline', ['reason' => 'php_code'])->assertSessionHasErrors('reason');
    expect($estimate->refresh()->status)->toBe(EstimateStatus::Sent);
});

// Expiration

test('the scheduler expires sent and viewed estimates after their "valid until" date', function () {
    Event::fake([EstimateExpired::class]);
    $sent = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-03']);
    $viewed = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-03']);
    $this->estimates->recordView($viewed);
    $stillValid = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-10']);
    $noDate = sentEstimate($this->admin, $this->john, ['valid_until' => '']);

    // Valid through Oct 3 (Chicago). Still valid late on Oct 3 local time…
    $this->travelTo(CarbonImmutable::parse('2026-10-04 04:00:00', 'UTC')); // 11 PM Oct 3 in Chicago
    Artisan::call('estimates:expire');
    expect($sent->refresh()->status)->toBe(EstimateStatus::Sent);

    // …expired on Oct 4.
    $this->travelTo(CarbonImmutable::parse('2026-10-04 06:00:00', 'UTC'));
    Artisan::call('estimates:expire');

    expect($sent->refresh()->status)->toBe(EstimateStatus::Expired)
        ->and($sent->expired_at)->not->toBeNull()
        ->and($viewed->refresh()->status)->toBe(EstimateStatus::Expired)
        ->and($stillValid->refresh()->status)->toBe(EstimateStatus::Sent)
        ->and($noDate->refresh()->status)->toBe(EstimateStatus::Sent);
    Event::assertDispatchedTimes(EstimateExpired::class, 2);

    // Running again changes nothing.
    Artisan::call('estimates:expire');
    Event::assertDispatchedTimes(EstimateExpired::class, 2);
});

test('accepted, declined, cancelled and draft estimates never expire', function () {
    $accepted = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-03']);
    $this->estimates->accept($accepted);
    $declined = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-03']);
    $this->estimates->decline($declined);
    $cancelled = sentEstimate($this->admin, $this->john, ['valid_until' => '2026-10-03']);
    $this->estimates->cancel($this->admin, $cancelled);
    $draft = draftEstimate($this->admin, $this->john, ['valid_until' => '2026-10-03']);

    $this->travelTo(CarbonImmutable::parse('2026-10-10 15:00:00', 'UTC'));
    expect(app(EstimateService::class)->expireDue())->toBe(0)
        ->and($accepted->refresh()->status)->toBe(EstimateStatus::Accepted)
        ->and($declined->refresh()->status)->toBe(EstimateStatus::Declined)
        ->and($cancelled->refresh()->status)->toBe(EstimateStatus::Cancelled)
        ->and($draft->refresh()->status)->toBe(EstimateStatus::Draft);
});

test('the expiry command is scheduled', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command);

    expect($events->contains(fn ($c) => str_contains((string) $c, 'estimates:expire')))->toBeTrue();
});

// Revisions

test('a revision keeps the sent estimate on record and replaces it only when sent', function () {
    Event::fake([EstimateSent::class, EstimateCreated::class]);
    $original = sentEstimate($this->admin, $this->john);
    $originalUrl = $original->publicUrl();

    $revision = $this->estimates->revise($this->admin, $original);
    expect($revision->displayNumber())->toBe('EST-1001-R2')
        ->and($revision->status)->toBe(EstimateStatus::Draft)
        ->and($revision->revision_of_id)->toBe($original->id)
        ->and($revision->items()->pluck('amount')->all())->toBe(['2500.00', '250.00'])
        // Asking again returns the same open revision.
        ->and($this->estimates->revise($this->admin, $original)->id)->toBe($revision->id);

    $this->estimates->update($this->admin, $revision, estimateInput($this->john, ['items' => [['description' => 'AC Installation', 'quantity' => '1', 'unit_price' => '3000']]]));

    // Until the revision is sent, the customer still has the original.
    $this->get($originalUrl)->assertOk()->assertSee('$2,750.00');

    $this->estimates->send($this->admin, $revision);

    $original->refresh();
    expect($original->status)->toBe(EstimateStatus::Cancelled)
        ->and($original->total)->toBe('2750.00')   // what was sent stays on record
        ->and($original->items()->sum('amount'))->toEqual(2750)
        ->and($revision->refresh()->status)->toBe(EstimateStatus::Sent)
        ->and($revision->total)->toBe('3000.00')
        ->and(($this->eventTypes)($original))->toContain('estimate_replaced');
    $this->get($originalUrl)->assertNotFound();
    expect($this->provider->sent[1]->subject)->toBe('Estimate EST-1001-R2 from Dallas HVAC');
});

test('a revision cannot be sent after the customer accepted the original', function () {
    $original = sentEstimate($this->admin, $this->john);
    $revision = $this->estimates->revise($this->admin, $original);
    $this->estimates->accept($original);

    expect(fn () => $this->estimates->send($this->admin, $revision))->toThrow(EstimateException::class, 'already accepted another version');
});
