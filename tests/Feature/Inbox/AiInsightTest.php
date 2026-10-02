<?php

namespace Tests\Feature\Inbox;

use App\Enums\ClassificationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Jobs\ClassifyCustomerReplyJob;
use App\Livewire\Inbox\ConversationList;
use App\Livewire\Inbox\ShowConversation;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AiInsightTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Conversation $conversation;

    private Message $reply;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->admin = User::factory()->admin()->create();
        $customer = Customer::factory()->for($this->admin->organization)->create(['name' => 'John Smith']);
        $this->conversation = Conversation::factory()->for($customer)->create([
            'organization_id' => $this->admin->organization_id,
            'subject' => 'HVAC Estimate',
            'last_message_at' => now(),
        ]);

        $this->reply = new Message;
        $this->reply->forceFill([
            'organization_id' => $this->admin->organization_id,
            'conversation_id' => $this->conversation->id,
            'direction' => MessageDirection::Inbound,
            'channel' => 'email',
            'provider' => 'postmark',
            'from_address' => $customer->email,
            'to_address' => 'reply+'.str_repeat('a', 40).'@inbound.quoteflow.ai',
            'subject' => 'Re: Your estimate',
            'body_text' => 'Can you do the AC replacement for $5,500 instead?',
            'provider_message_id' => 'pm-1',
            'status' => MessageStatus::Received,
            'received_at' => now(),
        ])->save();
    }

    private function classification(array $attributes = []): MessageClassification
    {
        $classification = new MessageClassification;
        $classification->forceFill(array_merge([
            'organization_id' => $this->reply->organization_id,
            'conversation_id' => $this->conversation->id,
            'message_id' => $this->reply->id,
            'request_id' => 'auto-'.$this->reply->id,
            'status' => ClassificationStatus::Succeeded,
            'intent' => CustomerReplyIntent::PriceObjection,
            'confidence' => 0.94,
            'summary' => 'Customer is interested but is negotiating the quoted price.',
            'sentiment' => 'neutral',
            'urgency' => 'medium',
            'requires_human_review' => true,
            'model' => 'gpt-4.1-mini',
            'classified_at' => now(),
        ], $attributes))->save();

        return $classification;
    }

    private function thread()
    {
        return Livewire::actingAs($this->admin)->test(ShowConversation::class, ['conversationId' => $this->conversation->id]);
    }

    public function test_ai_insight_appears_after_classification(): void
    {
        $this->classification();

        $this->thread()
            ->assertSeeInOrder([
                'Can you do the AC replacement for $5,500 instead?',
                'AI Insight', 'Needs attention',
                'Intent', 'Price objection',
                'Confidence', '94%', '(high)',
                'Summary', 'Customer is interested but is negotiating the quoted price.',
                'Needs attention', 'Yes',
                'Neutral sentiment · Medium urgency',
                'AI analysis only; no action has been taken.',
            ]);
    }

    public function test_badge_labels_come_from_the_enum(): void
    {
        $this->classification(['intent' => CustomerReplyIntent::ReadyToBook, 'confidence' => 0.6, 'requires_human_review' => false]);

        $this->thread()
            ->assertSee('Ready to book')
            ->assertDontSee('ready_to_book')
            ->assertSee('60%')
            ->assertSee('(medium)');
    }

    public function test_ai_summary_is_escaped(): void
    {
        $this->classification(['summary' => '<script>alert("ai")</script> wants a discount']);

        $html = $this->thread()->html();

        $this->assertStringNotContainsString('<script>alert("ai")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_pending_and_failed_states(): void
    {
        $this->thread()->assertSee('AI insight pending…');

        $this->classification(['status' => ClassificationStatus::Failed, 'intent' => null, 'confidence' => null, 'summary' => null, 'failure_reason' => 'AI returned an invalid classification: unknown intent.']);

        $this->thread()->assertSee('AI insight is unavailable for this reply.')->assertSee('Try again')->assertDontSee('unknown intent');
    }

    public function test_previous_classifications_remain_visible_after_reclassification(): void
    {
        $this->classification(['intent' => CustomerReplyIntent::PriceObjection, 'confidence' => 0.71, 'model' => 'model-a', 'classified_at' => now()->subHour()]);
        $this->classification(['request_id' => 'reclassify-1', 'intent' => CustomerReplyIntent::Interested, 'confidence' => 0.91, 'model' => 'model-b', 'requires_human_review' => false]);

        $this->thread()
            ->assertSeeInOrder(['Interested', '91%', 'Previous classifications (1)', 'Price objection', '71%', 'model-a']);
    }

    public function test_a_failed_reclassification_keeps_showing_the_previous_result_with_a_note(): void
    {
        $this->classification();
        $this->classification(['request_id' => 'reclassify-2', 'status' => ClassificationStatus::Failed, 'intent' => null, 'confidence' => null, 'summary' => null, 'failure_reason' => 'AI returned an invalid classification: malformed JSON.']);

        $this->thread()
            ->assertSee('Price objection')
            ->assertSee('The latest reclassification attempt failed; showing the previous result.')
            ->assertDontSee('malformed JSON');
    }

    public function test_admin_can_request_reclassification(): void
    {
        Queue::fake();
        $this->classification();

        $this->thread()
            ->call('reclassify', $this->reply->id)
            ->assertSet('statusMessage', 'Reclassification requested. The new AI insight will appear when it is ready.');

        Queue::assertPushed(ClassifyCustomerReplyJob::class, fn (ClassifyCustomerReplyJob $job) => $job->messageId === $this->reply->id
            && $job->reclassify
            && str_starts_with($job->requestId, 'reclassify-'));
        $this->assertSame(1, MessageClassification::count(), 'The existing classification is kept.');
    }

    public function test_members_cannot_reclassify(): void
    {
        Queue::fake();
        $this->classification();
        $member = User::factory()->for($this->admin->organization)->create();

        Livewire::actingAs($member)->test(ShowConversation::class, ['conversationId' => $this->conversation->id])
            ->assertSee('AI Insight')
            ->assertDontSee('Reclassify')
            ->call('reclassify', $this->reply->id)
            ->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_cannot_reclassify_a_message_from_another_conversation(): void
    {
        Queue::fake();
        $other = Conversation::factory()->create();

        $this->thread()->call('reclassify', $other->id + 1000)->assertNotFound();

        Queue::assertNothingPushed();
    }

    public function test_inbox_shows_intent_badges_and_filters(): void
    {
        $this->conversation->forceFill(['latest_intent' => CustomerReplyIntent::PriceObjection, 'needs_attention' => true])->save();
        $readyCustomer = Customer::factory()->for($this->admin->organization)->create(['name' => 'Ready Rita']);
        Conversation::factory()->for($readyCustomer)->create(['organization_id' => $this->admin->organization_id, 'latest_intent' => 'ready_to_book', 'last_message_at' => now()]);
        $quietCustomer = Customer::factory()->for($this->admin->organization)->create(['name' => 'Quiet Quinn']);
        Conversation::factory()->for($quietCustomer)->create(['organization_id' => $this->admin->organization_id, 'last_message_at' => now()]);

        Livewire::actingAs($this->admin)->test(ConversationList::class)
            ->assertSee(['John Smith', 'Ready Rita', 'Quiet Quinn', 'Price objection', 'Ready to book', 'Needs attention'])
            ->set('filter', 'needs_attention')
            ->assertSee('John Smith')->assertDontSee('Ready Rita')->assertDontSee('Quiet Quinn')
            ->set('filter', 'ready_to_book')
            ->assertSee('Ready Rita')->assertDontSee('John Smith')
            ->set('filter', 'question')
            ->assertSee('No matching conversations')
            ->set('filter', 'bogus')
            ->assertSee(['John Smith', 'Ready Rita', 'Quiet Quinn']);
    }

    public function test_filters_never_show_other_organizations(): void
    {
        $foreign = Conversation::factory()->create(['latest_intent' => 'ready_to_book', 'needs_attention' => true]);
        $foreign->customer->update(['name' => 'Foreign Fiona']);

        Livewire::actingAs($this->admin)->test(ConversationList::class)
            ->set('filter', 'ready_to_book')
            ->assertDontSee('Foreign Fiona')
            ->set('filter', 'needs_attention')
            ->assertDontSee('Foreign Fiona');
    }
}
