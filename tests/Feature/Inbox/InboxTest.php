<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ShowConversation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Message;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use RefreshDatabase;

    private User $member;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // Any organization member (not only admins) can read the inbox.
        $this->member = User::factory()->create();
        $customer = Customer::factory()->for($this->member->organization)->create(['name' => 'John Smith', 'email' => 'john@example.com']);
        $this->conversation = Conversation::factory()->for($customer)->create([
            'organization_id' => $this->member->organization_id,
            'subject' => 'HVAC Estimate',
            'last_message_at' => now(),
        ]);
    }

    private function outbound(string $text): Message
    {
        $connection = EmailConnection::factory()->for($this->member->organization)->verified()->create(['sender_name' => 'Dallas Cooling']);

        return Message::factory()->create([
            'organization_id' => $this->member->organization_id,
            'conversation_id' => $this->conversation->id,
            'email_connection_id' => $connection->id,
            'to_address' => 'john@example.com',
            'subject' => 'Your HVAC estimate',
            'body_text' => $text,
            'status' => MessageStatus::Sent,
            'sent_at' => now()->subHour(),
        ]);
    }

    private function inbound(array $attributes = []): Message
    {
        $message = new Message;
        $message->forceFill(array_merge([
            'organization_id' => $this->member->organization_id,
            'conversation_id' => $this->conversation->id,
            'direction' => MessageDirection::Inbound,
            'channel' => 'email',
            'provider' => 'postmark',
            'from_address' => 'john@example.com',
            'from_name' => 'John Smith',
            'to_address' => 'reply+'.str_repeat('a', 40).'@inbound.quoteflow.ai',
            'subject' => 'Re: Your HVAC estimate',
            'body_text' => 'Can you lower the price?',
            'provider_message_id' => fake()->uuid(),
            'status' => MessageStatus::Received,
            'received_at' => now(),
        ], $attributes))->save();

        return $message;
    }

    public function test_inbox_lists_the_organizations_conversations_only(): void
    {
        Conversation::factory()->create(['subject' => 'Other company estimate']);

        $this->actingAs($this->member)
            ->get(route('inbox.index'))
            ->assertOk()
            ->assertSeeLivewire(ConversationList::class)
            ->assertSee('John Smith')
            ->assertSee('HVAC Estimate')
            ->assertDontSee('Other company estimate');
    }

    public function test_conversation_shows_outbound_and_inbound_messages_distinctly(): void
    {
        $this->outbound('Hi John, just following up on your estimate...');
        $this->inbound();

        $this->actingAs($this->member)
            ->get(route('inbox.show', $this->conversation->id))
            ->assertOk()
            ->assertSeeInOrder(['Dallas Cooling', 'Sent', 'Hi John, just following up on your estimate...', 'John Smith', 'Received', 'Can you lower the price?'])
            ->assertSeeHtml('data-direction="outbound"')
            ->assertSeeHtml('data-direction="inbound"')
            ->assertSeeHtml('bg-indigo-50 sm:ml-12')
            ->assertSeeHtml('bg-white sm:mr-12');
    }

    public function test_customer_html_is_sanitized_when_rendered(): void
    {
        // Even if unsafe HTML reached the database, it is sanitized again before rendering.
        $this->inbound([
            'body_text' => null,
            'body_html' => '<p>Hello</p><script>alert("x")</script><img src=x onerror="alert(1)"><a href="javascript:alert(1)">click</a>',
        ]);

        $html = Livewire::actingAs($this->member)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])->html();

        $this->assertStringContainsString('<p>Hello</p>', $html);
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:alert', $html);
    }

    public function test_plain_text_is_escaped(): void
    {
        $this->inbound(['body_text' => '<b>not bold</b><script>alert(1)</script>']);

        $this->actingAs($this->member)
            ->get(route('inbox.show', $this->conversation->id))
            ->assertSee('<b>not bold</b>', escape: true)
            ->assertDontSeeHtml('<script>alert(1)</script>');
    }

    public function test_users_cannot_open_another_organizations_conversation(): void
    {
        $foreign = Conversation::factory()->create();

        $this->actingAs($this->member)->get(route('inbox.show', $foreign->id))->assertNotFound();
        Livewire::actingAs($this->member)->test(ShowConversation::class, ['conversationId' => $foreign->id])->assertNotFound();
    }

    public function test_guests_and_users_without_an_organization_are_kept_out(): void
    {
        $this->get(route('inbox.index'))->assertRedirect('/');
        $this->get(route('inbox.show', $this->conversation->id))->assertRedirect('/');

        $loner = User::factory()->create(['organization_id' => null]);
        $this->actingAs($loner)->get(route('inbox.index'))->assertForbidden();
        $this->actingAs($loner)->get(route('inbox.show', $this->conversation->id))->assertForbidden();
    }

    public function test_replies_needing_review_are_listed_but_not_threaded(): void
    {
        $this->inbound(['conversation_id' => null, 'status' => MessageStatus::NeedsReview, 'from_address' => 'stranger@else.test', 'from_name' => null, 'subject' => 'Suspicious reply']);

        Livewire::actingAs($this->member)->test(ConversationList::class)
            ->assertSee('Needs review')
            ->assertSee('stranger@else.test')
            ->assertSee('Suspicious reply');

        $this->actingAs($this->member)->get(route('inbox.show', $this->conversation->id))->assertDontSee('Suspicious reply');
    }

    public function test_inbox_navigation_link_is_shown(): void
    {
        $this->actingAs($this->member)
            ->get(route('inbox.index'))
            ->assertSeeHtml('href="'.route('inbox.index').'"')
            ->assertSee('Conversations');
    }
}
