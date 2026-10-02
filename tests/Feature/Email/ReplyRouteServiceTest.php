<?php

namespace Tests\Feature\Email;

use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Jobs\SendEmailJob;
use App\Models\Conversation;
use App\Models\EmailConnection;
use App\Models\EmailReplyRoute;
use App\Models\Message;
use App\Services\Email\EmailService;
use App\Services\Email\ReplyRouteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReplyRouteServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReplyRouteService $routes;

    protected function setUp(): void
    {
        parent::setUp();

        config(['email.inbound.reply_domain' => 'inbound.quoteflow.ai', 'email.inbound.reply_route_ttl_days' => 30]);
        $this->routes = app(ReplyRouteService::class);
    }

    private function tokenOf(string $address): string
    {
        return str($address)->between('reply+', '@')->toString();
    }

    public function test_reply_addresses_use_an_opaque_token(): void
    {
        $conversation = Conversation::factory()->create();

        $address = $this->routes->createFor($conversation);

        $this->assertMatchesRegularExpression('/^reply\+[a-f0-9]{40}@inbound\.quoteflow\.ai$/', $address);
        $this->assertStringNotContainsString((string) $conversation->id.'@', $address);
        $this->assertNotSame($address, $this->routes->createFor($conversation));
    }

    public function test_raw_token_is_never_stored(): void
    {
        $conversation = Conversation::factory()->create();
        $token = $this->tokenOf($this->routes->createFor($conversation));

        $route = EmailReplyRoute::sole();
        $this->assertSame(hash('sha256', $token), $route->token_hash);
        $this->assertSame(substr($token, -4), $route->token_last4);
        $this->assertSame($conversation->organization_id, $route->organization_id);
        $this->assertTrue($route->expires_at->between(now()->addDays(29), now()->addDays(31)));

        $everything = json_encode(DB::table('email_reply_routes')->get());
        $this->assertStringNotContainsString($token, $everything);
    }

    public function test_tokens_resolve_to_their_conversation(): void
    {
        $conversation = Conversation::factory()->create();
        $token = $this->tokenOf($this->routes->createFor($conversation));

        $this->assertSame($conversation->id, $this->routes->resolve($token)->conversation_id);
        $this->assertNull($this->routes->resolve(str_repeat('0', 40)));
        $this->assertNull($this->routes->resolve('not-a-token'));
    }

    public function test_extraction_accepts_only_our_domain_and_tolerates_case(): void
    {
        $token = str_repeat('ab12', 10);

        $this->assertSame($token, $this->routes->extractToken(['someone@else.test', 'REPLY+'.strtoupper($token).'@Inbound.QuoteFlow.ai']));
        $this->assertNull($this->routes->extractToken(["reply+{$token}@evil.test"]));
        $this->assertNull($this->routes->extractToken(["reply+{$token}@inbound.quoteflow.ai.evil.test"]));
        $this->assertNull($this->routes->extractToken(['reply+12345@inbound.quoteflow.ai']));
    }

    public function test_send_to_conversation_sets_a_secure_reply_to_and_links_the_message(): void
    {
        Queue::fake();
        $conversation = Conversation::factory()->create();
        $connection = EmailConnection::factory()->for($conversation->organization)->verified()->default()->create();

        $message = app(EmailService::class)->sendToConversation($conversation, 'Your estimate', text: 'Hi John');

        $this->assertSame($conversation->id, $message->conversation_id);
        $this->assertSame($connection->sender_email, $message->from_address);
        $this->assertSame($conversation->customer->email, $message->to_address);
        $this->assertMatchesRegularExpression('/^reply\+[a-f0-9]{40}@inbound\.quoteflow\.ai$/', $message->reply_to);
        $this->assertSame($conversation->id, $this->routes->resolve($this->tokenOf($message->reply_to))->conversation_id);
        $this->assertNotNull($conversation->fresh()->last_message_at);
        Queue::assertPushed(SendEmailJob::class, fn (SendEmailJob $job) => $job->messageId === $message->id);
    }

    public function test_send_to_conversation_requires_a_verified_sender(): void
    {
        $conversation = Conversation::factory()->create();

        $this->expectException(EmailSendingNotAllowedException::class);

        try {
            app(EmailService::class)->sendToConversation($conversation, 'Hi', text: 'Hi');
        } finally {
            $this->assertSame(0, Message::count());
            $this->assertSame(0, EmailReplyRoute::count());
        }
    }
}
