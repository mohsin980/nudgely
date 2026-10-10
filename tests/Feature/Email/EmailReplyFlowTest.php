<?php

namespace Tests\Feature\Email;

use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Models\User;
use App\Services\Email\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The whole Tasks 1–5 flow in one scenario:
 *
 * business sends estimate → sales@example.com with secure Reply-To → customer replies →
 * provider webhook → QuoteFollow identifies business, customer and conversation → saves reply → Inbox.
 */
class EmailReplyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_business_estimate_and_customer_reply_end_up_in_the_inbox(): void
    {
        $this->withoutVite();
        config([
            'email.provider' => 'postmark',
            'email.providers.postmark.server_token' => 'server-token',
            'email.providers.postmark.inbound_webhook_username' => 'postmark',
            'email.providers.postmark.inbound_webhook_secret' => 'inbound-secret',
            'email.inbound.reply_domain' => 'inbound.quoteflow.ai',
        ]);
        Http::fake(['api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-out-1', 'ErrorCode' => 0, 'Message' => 'OK'])]);

        // Business with a verified default sender, a customer and a conversation.
        $admin = User::factory()->admin()->create();
        $organization = $admin->organization;
        EmailConnection::factory()->for($organization)->verified()->default()->create([
            'domain' => 'example.com', 'sender_email' => 'sales@example.com', 'sender_name' => 'Dallas Cooling',
        ]);
        $customer = $organization->customers()->create(['name' => 'John Smith', 'email' => 'john@customer.test']);
        $conversation = new Conversation(['subject' => 'HVAC Estimate']);
        $conversation->forceFill(['organization_id' => $organization->id, 'customer_id' => $customer->id])->save();

        // Business sends the estimate from sales@example.com with a secure Reply-To.
        $outbound = app(EmailService::class)->sendToConversation($conversation, 'Your HVAC estimate', text: 'Hi John, here is your estimate: $4,800.');

        $sent = Http::recorded()[0][0];
        $this->assertSame('"Dallas Cooling" <sales@example.com>', $sent['From']);
        $this->assertSame('"John Smith" <john@customer.test>', $sent['To']);
        $this->assertMatchesRegularExpression('/^reply\+[a-f0-9]{40}@inbound\.quoteflow\.ai$/', $sent['ReplyTo']);
        $this->assertSame(MessageStatus::Sent, $outbound->fresh()->status);

        $token = str($sent['ReplyTo'])->between('reply+', '@')->toString();
        $this->assertStringNotContainsString($token, json_encode(DB::table('email_reply_routes')->get()));

        // Customer replies; the provider posts the inbound webhook.
        $payload = [
            'From' => 'john@customer.test',
            'FromName' => 'John Smith',
            'FromFull' => ['Email' => 'john@customer.test', 'Name' => 'John Smith'],
            'To' => $sent['ReplyTo'],
            'ToFull' => [['Email' => $sent['ReplyTo'], 'Name' => '']],
            'OriginalRecipient' => $sent['ReplyTo'],
            'Subject' => 'Re: Your HVAC estimate',
            'MessageID' => 'pm-in-1',
            'Date' => now()->toRfc2822String(),
            'TextBody' => 'Can you lower the price?',
            'HtmlBody' => '<p>Can you lower the price?</p>',
            'Headers' => [['Name' => 'In-Reply-To', 'Value' => '<pm-out-1@pm.mtasv.net>']],
            // Tampering attempt: payload fields must never choose the tenant or conversation.
            'organization_id' => 999,
            'conversation_id' => 999,
        ];
        $auth = ['Authorization' => 'Basic '.base64_encode('postmark:inbound-secret')];

        $this->postJson('/webhooks/email/inbound/postmark', $payload, ['Authorization' => 'Basic '.base64_encode('postmark:wrong')])
            ->assertUnauthorized();
        $this->postJson('/webhooks/email/inbound/postmark', $payload, $auth)
            ->assertOk()
            ->assertJson(['message' => 'Accepted.']);

        // QuoteFollow identified the business, customer and conversation, and saved the reply.
        $reply = Message::where('direction', 'inbound')->sole();
        $this->assertSame($organization->id, $reply->organization_id);
        $this->assertSame($conversation->id, $reply->conversation_id);
        $this->assertSame($customer->email, $reply->from_address);
        $this->assertSame(MessageStatus::Received, $reply->status);
        $this->assertNotNull($conversation->fresh()->last_message_at);

        // A retried delivery is not stored twice; a stranger using the same address is held for review.
        $this->postJson('/webhooks/email/inbound/postmark', $payload, $auth)->assertOk()->assertJson(['message' => 'Already received.']);
        $this->postJson('/webhooks/email/inbound/postmark', array_merge($payload, [
            'MessageID' => 'pm-in-2',
            'From' => 'x@evil.test',
            'FromFull' => ['Email' => 'x@evil.test', 'Name' => 'John Smith'],
        ]), $auth)->assertOk();

        $this->assertSame(1, $conversation->messages()->where('direction', 'inbound')->count());
        $this->assertSame(MessageStatus::NeedsReview, Message::where('from_address', 'x@evil.test')->sole()->status);

        // The reply shows in the business's Inbox, after the estimate; other organizations can't see it.
        $this->actingAs($admin)->get('/inbox')->assertRedirect('/conversations');
        $this->actingAs($admin)->get('/conversations')->assertOk()->assertSee('John Smith')->assertSee('HVAC Estimate');
        $this->actingAs($admin)->get("/inbox/{$conversation->id}")->assertRedirect("/conversations/{$conversation->id}");
        $this->actingAs($admin)->get("/conversations/{$conversation->id}")
            ->assertOk()
            ->assertSeeInOrder(['Dallas Cooling', 'Hi John, here is your estimate', 'John Smith', 'Can you lower the price?']);
        $this->actingAs(User::factory()->create())->get("/conversations/{$conversation->id}")->assertNotFound();
    }
}
