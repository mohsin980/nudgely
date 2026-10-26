<?php

use App\Enums\FollowUpSkipReason;
use App\Enums\FollowUpStatus;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Livewire\Customers\ShowCustomer;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\FollowUp;
use App\Models\User;
use App\Services\Customers\EmailPreferenceService;
use App\Services\Email\Data\OutboundEmail;
use App\Services\Email\EmailService;
use App\Services\Email\Providers\PostmarkEmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function unsubscribeSetup(): array
{
    $owner = User::factory()->owner()->create();
    $customer = Customer::factory()->create(['organization_id' => $owner->organization_id, 'email' => 'jane.smith@example.com']);

    return [$owner, $customer];
}

test('the unsubscribe page only asks; opening the link does not unsubscribe anyone', function () {
    [, $customer] = unsubscribeSetup();
    $url = app(EmailPreferenceService::class)->unsubscribeUrl($customer);

    $this->get($url)->assertOk()->assertSee('data-state="confirm"', false)->assertSee('ja•••@example.com')->assertDontSee('jane.smith');

    expect($customer->refresh()->hasOptedOutOfEmail())->toBeFalse();
});

test('posting to the signed link opts the customer out, from the page or a one-click mail button', function () {
    [, $customer] = unsubscribeSetup();
    $url = app(EmailPreferenceService::class)->unsubscribeUrl($customer);

    // A mail app's one-click button sends this body and no CSRF token.
    $this->post($url, ['List-Unsubscribe' => 'One-Click'])->assertOk()->assertSee('data-state="unsubscribed"', false);

    $customer->refresh();
    expect($customer->hasOptedOutOfEmail())->toBeTrue()
        ->and($customer->email_opted_out_source)->toBe('link');

    $this->get($url)->assertOk()->assertSee('data-state="unsubscribed"', false);
});

test('a link that was changed or unsigned is refused', function () {
    [, $customer] = unsubscribeSetup();
    $other = Customer::factory()->create(['organization_id' => $customer->organization_id]);
    $url = app(EmailPreferenceService::class)->unsubscribeUrl($customer);

    $this->post(route('unsubscribe.store', $customer))->assertForbidden();
    $this->post(str_replace("/{$customer->id}?", "/{$other->id}?", $url))->assertForbidden();

    expect($customer->refresh()->hasOptedOutOfEmail())->toBeFalse()
        ->and($other->refresh()->hasOptedOutOfEmail())->toBeFalse();
});

test('unsubscribing skips that customer\'s open automated follow-ups and nobody else\'s', function () {
    [, $customer] = unsubscribeSetup();
    $other = Customer::factory()->create(['organization_id' => $customer->organization_id]);
    $conversation = fn (Customer $c) => Conversation::factory()->create(['organization_id' => $c->organization_id, 'customer_id' => $c->id]);
    $mine = FollowUp::factory()->automated()->create(['conversation_id' => $conversation($customer)->id]);
    $theirs = FollowUp::factory()->automated()->create(['conversation_id' => $conversation($other)->id]);

    $this->post(app(EmailPreferenceService::class)->unsubscribeUrl($customer))->assertOk();

    expect($mine->refresh()->status)->toBe(FollowUpStatus::Skipped)
        ->and($mine->skip_reason)->toBe(FollowUpSkipReason::OptedOut)
        ->and($theirs->refresh()->status)->toBe(FollowUpStatus::Pending);
});

test('an opted-out customer is not emailed again', function () {
    Queue::fake();
    [$owner, $customer] = unsubscribeSetup();
    $this->post(app(EmailPreferenceService::class)->unsubscribeUrl($customer))->assertOk();
    $conversation = Conversation::factory()->create(['organization_id' => $owner->organization_id, 'customer_id' => $customer->id]);

    expect(fn () => app(EmailService::class)->sendToConversation($conversation->refresh(), 'Hello', null, 'Hi'))
        ->toThrow(EmailSendingNotAllowedException::class);
    Queue::assertNothingPushed();
});

test('customer emails carry an unsubscribe footer and a signed link that works', function () {
    Queue::fake();
    [$owner, $customer] = unsubscribeSetup();
    EmailConnection::factory()->verified()->default()->create(['organization_id' => $owner->organization_id]);
    $conversation = Conversation::factory()->create(['organization_id' => $owner->organization_id, 'customer_id' => $customer->id]);

    $message = app(EmailService::class)->sendToConversation($conversation, 'Hello', '<p>Hi</p>', 'Hi');

    preg_match('~Unsubscribe: (\S+)~', $message->body_text, $m);
    expect($message->body_text)->toStartWith('Hi')->and($message->body_html)->toContain('Unsubscribe</a>')
        ->and($m[1] ?? null)->not->toBeNull();

    $this->post($m[1])->assertOk();
    expect($customer->refresh()->hasOptedOutOfEmail())->toBeTrue();
});

test('Postmark sends include the List-Unsubscribe headers for customer email only', function () {
    Http::fake(['api.postmarkapp.com/*' => Http::response(['ErrorCode' => 0, 'MessageID' => 'abc-123'])]);

    $email = new OutboundEmail(1, 'sales@example.com', 'Sales', 'jane@example.com', null, 'Hi', 'Hi', null,
        headers: ['List-Unsubscribe' => '<https://example.com/u>', 'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click']);
    (new PostmarkEmailProvider(['server_token' => 'pm-token', 'message_stream' => 'outbound', 'base_url' => 'https://api.postmarkapp.com', 'timeout' => 5]))->send($email);

    Http::assertSent(fn ($request) => collect($request['Headers'])->pluck('Value', 'Name')->all() === [
        'List-Unsubscribe' => '<https://example.com/u>',
        'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
    ]);
});

test('staff can stop and restore email to a customer, and another business cannot', function () {
    [$owner, $customer] = unsubscribeSetup();

    Livewire::actingAs($owner)->test(ShowCustomer::class, ['customerId' => $customer->id])
        ->call('stopEmailing')->assertSee('data-opt-out', false);
    expect($customer->refresh()->email_opted_out_source)->toBe('staff');

    Livewire::actingAs($owner)->test(ShowCustomer::class, ['customerId' => $customer->id])
        ->call('allowEmailing')->assertDontSee('data-opt-out', false);
    expect($customer->refresh()->hasOptedOutOfEmail())->toBeFalse();

    $stranger = User::factory()->owner()->create();
    expect(fn () => app(EmailPreferenceService::class)->optIn($stranger, $customer))->toThrow(NotFoundHttpException::class);
});
