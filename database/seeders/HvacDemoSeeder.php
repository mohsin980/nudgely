<?php

namespace Database\Seeders;

use App\Enums\ClassificationStatus;
use App\Enums\ConversationStatus;
use App\Enums\CustomerReplyIntent;
use App\Enums\EstimateStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Models\Automation;
use App\Models\AutomationActionRun;
use App\Models\AutomationRun;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\Estimate;
use App\Models\Message;
use App\Models\MessageClassification;
use App\Models\User;
use App\Services\FollowUps\FollowUpService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * A realistic HVAC business for looking at the dashboard in development: customers, conversations, replies with
 * AI classifications, follow-ups (overdue, today, upcoming), estimates in every status, automation runs and one
 * failed email.
 *
 * Development only. It refuses to run in production, and it needs an explicit owner so it cannot fill the wrong
 * business. Every customer is a demo customer, so the app never emails them. Run it with:
 *
 *   DEMO_OWNER_EMAIL=owner@example.test php artisan db:seed --class=HvacDemoSeeder
 *
 * Running it twice does nothing the second time.
 */
class HvacDemoSeeder extends Seeder
{
    private const DOMAIN = 'demo.quoteflow.test';

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Demo data is never seeded in production.');

            return;
        }

        $owner = User::query()->where('email', mb_strtolower((string) env('DEMO_OWNER_EMAIL')))->first();

        if ($owner === null || $owner->organization_id === null) {
            $this->command?->error('Set DEMO_OWNER_EMAIL to the email of an existing business owner.');

            return;
        }

        $organization = $owner->organization;

        if (Customer::query()->where('organization_id', $organization->id)->where('email', 'like', '%@'.self::DOMAIN)->exists()) {
            $this->command?->info('Demo data is already there; nothing changed.');

            return;
        }

        $connection = $organization->emailConnections()->default()->first()
            ?? tap(EmailConnection::factory()->for($organization)->verified()->create(), fn (EmailConnection $c) => $c->markAsDefault());

        $people = [
            ['Sarah Johnson', 'AC repair needed', 'Can you give me a price for a new AC unit?', CustomerReplyIntent::PriceObjection],
            ['Mike Wilson', 'HVAC maintenance', 'Thanks for the quick response!', null],
            ['Emily Carter', 'Quote follow-up', "I'd like to move forward. When can you schedule the install?", CustomerReplyIntent::ReadyToBook],
            ['David Lee', 'System replacement', 'Do you offer financing options?', CustomerReplyIntent::Question],
            ['Jennifer Adams', 'Filter service', 'Perfect, let us move forward with the estimate.', CustomerReplyIntent::ReadyToBook],
            ['Robert Martinez', 'New AC unit', 'Can you give me a price for a new AC unit?', CustomerReplyIntent::Question],
            ['Lisa Thompson', 'Furnace tune-up', 'Thanks, I will think about it.', null],
            ['James Wilson', 'Duct cleaning', "I'm interested in scheduling a service.", CustomerReplyIntent::ReadyToBook],
        ];

        $conversations = [];

        foreach ($people as $i => [$name, $subject, $reply, $intent]) {
            $customer = Customer::factory()->for($organization)->create([
                'name' => $name,
                'email' => Str::slug($name).'@'.self::DOMAIN,
                'phone' => '(214) 555-01'.str_pad((string) (10 + $i), 2, '0', STR_PAD_LEFT),
                'is_demo' => true,
            ]);

            $conversation = Conversation::factory()->for($customer)->create([
                'organization_id' => $organization->id, 'subject' => $subject, 'last_message_at' => now()->subHours(2 + $i),
            ]);

            $message = (new Message)->forceFill([
                'organization_id' => $organization->id, 'conversation_id' => $conversation->id, 'direction' => MessageDirection::Inbound,
                'channel' => 'email', 'provider' => 'postmark', 'from_address' => $customer->email,
                'to_address' => 'reply+'.Str::random(40).'@inbound.quoteflow.ai', 'subject' => 'Re: '.$subject, 'body_text' => $reply,
                'provider_message_id' => (string) Str::uuid(), 'status' => MessageStatus::Received, 'received_at' => now()->subHours(2 + $i),
            ]);
            $message->save();
            $conversation->refresh()->recordActivity($message->received_at);

            if ($intent !== null) {
                (new MessageClassification)->forceFill([
                    'organization_id' => $organization->id, 'conversation_id' => $conversation->id, 'message_id' => $message->id,
                    'request_id' => 'demo-'.$message->id, 'status' => ClassificationStatus::Succeeded, 'intent' => $intent,
                    'confidence' => 0.82 + ($i % 4) * 0.04, 'summary' => 'Demo classification', 'requires_human_review' => false,
                    'model' => 'demo', 'classified_at' => now(),
                ])->save();
                $conversation->forceFill(['latest_intent' => $intent])->save();
            }

            // Some customers have been answered, so only part of the inbox is waiting on the business.
            if ($i % 3 === 1) {
                $conversation->forceFill(['status' => ConversationStatus::WaitingCustomer])->save();
            }

            $conversations[] = $conversation;
        }

        $this->followUps($owner, $conversations);
        $this->estimates($conversations);
        $this->automations($organization->id, $conversations[0]);

        // One email that failed to send, so the "Emails failed" alert has something real behind it.
        Message::factory()->create(['email_connection_id' => $connection->id, 'status' => MessageStatus::Failed, 'to_address' => 'david-lee@'.self::DOMAIN]);

        $this->command?->info('HVAC demo data created for '.$organization->name.'.');
    }

    /**
     * @param  list<Conversation>  $conversations
     */
    private function followUps(User $owner, array $conversations): void
    {
        $service = app(FollowUpService::class);
        $schedule = [
            [0, now()->subDay()->setTime(15, 0), 'Overdue: call about the AC quote'],
            [3, now()->subDays(2)->setTime(16, 0), 'Overdue: check on financing question'],
            [2, now()->setTime(16, 0), 'Quote follow-up'],
            [4, now()->setTime(18, 30), 'Filter service reminder'],
            [5, now()->addDays(2)->setTime(15, 0), 'Check on heat pump estimate'],
            [6, now()->addDays(3)->setTime(15, 0), 'Furnace tune-up reminder'],
            [7, now()->addDays(5)->setTime(15, 0), 'Duct cleaning follow-up'],
        ];

        foreach ($schedule as [$index, $due, $notes]) {
            $conversation = $conversations[$index];
            $followUp = $service->scheduleManual($owner, $conversation->customer, now()->addHour(), $notes, $conversation);
            $followUp->forceFill(['due_at' => $due])->save();
        }
    }

    /**
     * @param  list<Conversation>  $conversations
     */
    private function estimates(array $conversations): void
    {
        $rows = [
            // [conversation, status, title, total, extra]
            [0, EstimateStatus::Viewed, 'AC installation', '4600.00', ['sent_at' => now()->subDays(2), 'viewed_at' => now()->subDay(), 'valid_until' => now()->addDays(2)->toDateString()]],
            [1, EstimateStatus::Sent, 'Tune-up', '299.00', ['sent_at' => now()->subDays(3), 'valid_until' => now()->addDays(12)->toDateString()]],
            [2, EstimateStatus::Accepted, 'Heat pump replacement', '7200.00', ['sent_at' => now()->subDays(8), 'accepted_at' => now()->subDays(3)]],
            [3, EstimateStatus::Sent, 'Duct cleaning', '450.00', ['sent_at' => now()->subDays(1), 'valid_until' => now()->addDays(20)->toDateString()]],
            [4, EstimateStatus::Declined, 'Service call', '180.00', ['sent_at' => now()->subDays(9), 'declined_at' => now()->subDays(6)]],
            [5, EstimateStatus::Draft, 'Furnace replacement', '5400.00', []],
            [6, EstimateStatus::Accepted, 'Mini-split install', '3200.00', ['sent_at' => now()->subDays(15), 'accepted_at' => now()->subDays(9)]],
            [7, EstimateStatus::Expired, 'Attic ventilation', '950.00', ['sent_at' => now()->subDays(50), 'expired_at' => now()->subDays(20)]],
        ];

        foreach ($rows as $i => [$index, $status, $title, $total, $extra]) {
            Estimate::factory()->create($extra + [
                'customer_id' => $conversations[$index]->customer_id, 'organization_id' => $conversations[$index]->organization_id,
                'estimate_number' => 'EST-D'.(1001 + $i), 'status' => $status, 'title' => $title, 'total' => $total,
                'created_at' => now()->subDays(10 - $i),
            ]);
        }
    }

    private function automations(int $organizationId, Conversation $conversation): void
    {
        $active = Automation::factory()->active()->create(['organization_id' => $organizationId, 'name' => 'Follow up after estimate']);
        Automation::factory()->paused()->create(['organization_id' => $organizationId, 'name' => 'Welcome new customers']);

        foreach (['completed', 'completed', 'completed', 'failed'] as $i => $status) {
            $run = AutomationRun::query()->forceCreate([
                'organization_id' => $organizationId, 'automation_id' => $active->id, 'conversation_id' => $conversation->id,
                'event_type' => 'customer_reply_classified', 'event_id' => 'demo-run-'.$i, 'status' => $status,
                'failure_reason' => $status === 'failed' ? 'The sending email is not connected.' : null,
            ]);

            if ($status === 'completed') {
                AutomationActionRun::query()->forceCreate(['automation_run_id' => $run->id, 'action_type' => 'create_task', 'status' => 'completed', 'result' => ['message' => 'Task created']]);
            }
        }
    }
}
