<?php

namespace App\Services\Automation;

use App\Enums\Automation\AutomationActionType as A;
use App\Enums\Automation\AutomationTriggerType as T;
use App\Enums\ConversationStatus;
use App\Enums\FollowUpStatus;
use App\Enums\MessageDirection;
use App\Enums\OrganizationRole;
use App\Exceptions\Automation\InvalidAutomationConditionException;
use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Automation;
use App\Models\AutomationAction;
use App\Models\AutomationCondition;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\FollowUp;
use App\Models\Message;
use App\Models\Organization;
use App\Models\User;
use App\Services\Automation\Registry\ActionRegistry;
use App\Services\Automation\Registry\TriggerDefinition;
use App\Services\Automation\Registry\TriggerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "Test automation": runs an automation's conditions against a real record and describes
 * what each action would do. A dry run: no email is sent, nothing is created or changed
 * (it runs inside a transaction that is always rolled back, as a backstop).
 */
class AutomationTester
{
    public function __construct(
        private readonly ConditionEvaluator $conditions,
        private readonly AutomatedEmailPolicy $emailPolicy,
    ) {}

    /**
     * Recent records the automation can be tested with, for its trigger.
     *
     * @return array<string, string> "type:id" => label
     */
    public function samples(Organization $organization, TriggerDefinition $trigger): array
    {
        $org = $organization->id;

        return match (true) {
            $trigger->type === T::CustomerCreated => Customer::query()->where('organization_id', $org)->latest('id')->limit(10)->get()
                ->mapWithKeys(fn (Customer $c) => ["customer:{$c->id}" => $c->name])->all(),
            in_array($trigger->type, [T::CustomerReplyReceived, T::CustomerReplyClassified], true) => Message::query()
                ->where('organization_id', $org)->where('direction', MessageDirection::Inbound)->whereNotNull('conversation_id')
                ->when($trigger->type === T::CustomerReplyClassified, fn ($q) => $q->whereHas('latestClassification'))
                ->with(['conversation.customer', 'latestClassification'])->latest('id')->limit(10)->get()
                ->mapWithKeys(fn (Message $m) => ["message:{$m->id}" => $m->conversation?->customer?->name.': “'.Str::limit(trim((string) $m->body_text), 50).'”'
                    .($m->latestClassification?->intent ? ' ('.$m->latestClassification->intent->label().')' : '')])->all(),
            $trigger->type->isEstimateTrigger() => Estimate::query()->where('organization_id', $org)
                ->when($trigger->type !== T::EstimateCreated, fn ($q) => $q->whereNotNull('sent_at'))
                ->with('customer')->latest('id')->limit(10)->get()
                ->mapWithKeys(fn (Estimate $e) => ["estimate:{$e->id}" => "{$e->displayNumber()} · {$e->customer?->name} · {$e->money('total')} ({$e->status->label()})"])->all(),
            in_array($trigger->type, [T::FollowUpDue, T::FollowUpCompleted], true) => FollowUp::query()->where('organization_id', $org)
                ->with('customer')->latest('id')->limit(10)->get()
                ->mapWithKeys(fn (FollowUp $f) => ["follow_up:{$f->id}" => "{$f->customer?->name}: ".Str::limit($f->reason(), 40)." ({$f->status->label()})"])->all(),
            default => Conversation::query()->where('organization_id', $org)->with('customer')->latest('last_message_at')->limit(10)->get()
                ->mapWithKeys(fn (Conversation $c) => ["conversation:{$c->id}" => "{$c->customer?->name}: ".($c->subject ?? '(no subject)')])->all(),
        };
    }

    /**
     * @param  array<string, mixed>  $data  Output of AutomationBuilder::validated().
     * @return array{trigger: string, record: string, wait: ?string, match: string, matched: bool, reason: ?string, conditions: list<array{label: string, actual: string, passed: bool}>, actions: list<array{label: string, ok: bool, detail: string}>, summary: string}
     *
     * @throws \InvalidArgumentException when the sample record isn't found in the organization
     */
    public function run(Organization $organization, array $data, string $sample): array
    {
        $trigger = TriggerRegistry::get($data['trigger_type']);
        $samples = $this->samples($organization, $trigger);

        if (! isset($samples[$sample])) {
            throw new \InvalidArgumentException('Choose a record to test with.');
        }

        DB::beginTransaction();

        try {
            $context = $this->context($organization, $trigger, $sample);
            $automation = $this->unsaved($organization, $data);

            try {
                $outcome = $this->conditions->explain($automation, $context);
            } catch (InvalidAutomationConditionException $e) {
                $outcome = ['matched' => false, 'match' => $data['condition_match'], 'results' => [], 'reason' => $e->getMessage()];
            }

            $actions = $outcome['matched']
                ? array_map(fn (AutomationAction $a) => $this->preview($a, $context, $organization), $automation->actions->all())
                : [];
        } finally {
            DB::rollBack();
        }

        $wait = Automation::describeWait($data['wait_minutes']);

        return [
            'trigger' => $trigger->label,
            'record' => $samples[$sample],
            'wait' => $wait,
            'match' => $outcome['match'],
            'matched' => $outcome['matched'],
            'reason' => $outcome['reason'],
            'conditions' => $outcome['results'],
            'actions' => $actions,
            'summary' => $outcome['matched']
                ? 'With this record, the automation would run '.count($actions).' '.Str::plural('action', count($actions)).($wait ? " after waiting {$wait} (conditions are checked again then)." : '.')
                : 'With this record, the automation would stop: '.$outcome['reason'],
        ];
    }

    private function context(Organization $organization, TriggerDefinition $trigger, string $sample): AutomationContext
    {
        [$type, $id] = explode(':', $sample, 2);
        $base = ['organizationId' => $organization->id, 'triggerType' => $trigger->type, 'eventId' => 'test:'.$sample];

        return match ($type) {
            'customer' => new AutomationContext(...$base, customerId: (int) $id, occurredAt: CarbonImmutable::now()),
            'message' => (function () use ($base, $organization, $id) {
                $message = Message::query()->where('organization_id', $organization->id)->with(['conversation', 'latestClassification'])->findOrFail((int) $id);

                return new AutomationContext(...$base, customerId: $message->conversation->customer_id, conversationId: $message->conversation_id, messageId: $message->id,
                    classificationId: $message->latestClassification?->id, intent: $message->latestClassification?->intent, confidence: $message->latestClassification?->confidence,
                    occurredAt: CarbonImmutable::instance($message->received_at ?? $message->created_at));
            })(),
            'estimate' => (function () use ($base, $organization, $id) {
                $estimate = Estimate::query()->where('organization_id', $organization->id)->findOrFail((int) $id);

                return new AutomationContext(...$base, customerId: $estimate->customer_id, conversationId: $estimate->conversation_id, estimateId: $estimate->id,
                    occurredAt: CarbonImmutable::instance($estimate->sent_at ?? $estimate->created_at));
            })(),
            'follow_up' => (function () use ($base, $organization, $id) {
                $followUp = FollowUp::query()->where('organization_id', $organization->id)->findOrFail((int) $id);

                return new AutomationContext(...$base, customerId: $followUp->customer_id, conversationId: $followUp->conversation_id, estimateId: $followUp->estimate_id,
                    followUpId: $followUp->id, occurredAt: CarbonImmutable::instance($followUp->due_at));
            })(),
            default => (function () use ($base, $organization, $id) {
                $conversation = Conversation::query()->where('organization_id', $organization->id)->findOrFail((int) $id);

                return new AutomationContext(...$base, customerId: $conversation->customer_id, conversationId: $conversation->id, occurredAt: CarbonImmutable::now());
            })(),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function unsaved(Organization $organization, array $data): Automation
    {
        $automation = new Automation;
        $automation->forceFill(['organization_id' => $organization->id, 'condition_match' => $data['condition_match'], 'trigger_type' => $data['trigger_type']]);
        $automation->setRelation('conditions', collect($data['conditions'])->map(fn (array $c) => tap(new AutomationCondition, fn ($m) => $m->setRawAttributes($c))));
        $automation->setRelation('actions', collect($data['actions'])->map(fn (array $a) => tap(new AutomationAction, fn ($m) => $m->forceFill($a))));

        return $automation;
    }

    /**
     * What the action would do, from its settings and the record. Never executes it.
     *
     * @return array{label: string, ok: bool, detail: string}
     */
    private function preview(AutomationAction $action, AutomationContext $context, Organization $organization): array
    {
        $type = A::from($action->getAttributes()['type'] instanceof A ? $action->getAttributes()['type']->value : (string) $action->getAttributes()['type']);
        $config = $action->configuration ?? [];
        $label = ActionRegistry::get($type)->label;
        $render = fn (string $key) => $context->render((string) ($config[$key] ?? ''));

        try {
            return ['label' => $label, ...match ($type) {
                A::SendEmail => $this->previewEmail($action, $config, $context, $organization, $render),
                A::ScheduleFollowUp => ($config['kind'] ?? 'email') === 'reminder'
                    ? ['ok' => true, 'detail' => 'Would remind your team on '.$this->dueDate($organization, $config).': “'.$render('title').'”']
                    : $this->previewFollowUpEmail($config, $context, $organization, $render),
                A::CreateTask => ['ok' => true, 'detail' => 'Would create the task “'.$render('title').'” ('.ucfirst((string) ($config['priority'] ?? 'medium')).' priority'
                    .(isset($config['due_in_hours']) ? ', due '.$organization->localTime(now()->addHours((int) $config['due_in_hours']))->format('M j, g:i A') : '')
                    .(($config['assign_to'] ?? null) === 'owner' ? ', assigned to the automation owner' : (is_int($config['assign_to'] ?? null) ? ', assigned to '.User::find($config['assign_to'])?->name : '')).').'],
                A::NotifyUser => ['ok' => true, 'detail' => 'Would notify '.$this->recipientCount($organization, $config['recipients'] ?? 'admins').': “'.$render('message').'”'],
                A::AddCustomerTag => ['ok' => $context->customer() !== null, 'detail' => 'Would tag '.($context->customer()?->name ?? 'the customer').' “'.($config['tag'] ?? '').'”.'],
                A::RemoveCustomerTag => ['ok' => $context->customer() !== null, 'detail' => 'Would remove the tag “'.($config['tag'] ?? '').'” from '.($context->customer()?->name ?? 'the customer').'.'],
                A::UpdateConversationStatus => ['ok' => $context->conversation() !== null, 'detail' => 'Would set the conversation to “'.(ConversationStatus::tryFrom((string) ($config['status'] ?? ''))?->label() ?? '').'”.'],
                A::CompleteFollowUp, A::CancelFollowUp => $this->previewClose($type, $context),
            }];
        } catch (InvalidEmailTemplateException $e) {
            return ['label' => $label, 'ok' => false, 'detail' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, detail: string}
     */
    private function previewEmail(AutomationAction $action, array $config, AutomationContext $context, Organization $organization, \Closure $render): array
    {
        $subject = $render('subject');
        $render('body');

        if (($config['recipient'] ?? 'customer') === 'owner') {
            return ['ok' => true, 'detail' => 'Would email you (the automation owner): “'.$subject.'”'];
        }

        $blocked = $this->emailPolicy->checkDelivery($organization, $context->conversation(), $context->customer(), null);
        $note = match (true) {
            $blocked !== null => 'Would not be sent: '.$blocked->message,
            ! $organization->automatic_email_enabled => 'Would not be sent: automatic emails are turned off (Automations → Email safety).',
            $organization->require_approval_for_email || $action->requires_approval => 'Would not be sent: customer emails require approval (Automations → Email safety).',
            default => null,
        };

        return ['ok' => $note === null, 'detail' => $note ?? 'Would email '.$context->customer()?->name.' (test mode: nothing is sent): “'.$subject.'”'];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{ok: bool, detail: string}
     */
    private function previewFollowUpEmail(array $config, AutomationContext $context, Organization $organization, \Closure $render): array
    {
        $subject = $render('subject');
        $render('body');

        return $context->conversation() === null
            ? ['ok' => false, 'detail' => 'A follow-up email needs a conversation.']
            : ['ok' => true, 'detail' => 'Would schedule a follow-up email to '.$context->customer()?->name.' for '.$this->dueDate($organization, $config).': “'.$subject.'” (a reply from the customer cancels it).'];
    }

    /**
     * @return array{ok: bool, detail: string}
     */
    private function previewClose(A $type, AutomationContext $context): array
    {
        $open = FollowUp::query()->forOrganization($context->organizationId)->whereIn('status', [FollowUpStatus::Pending, FollowUpStatus::Due]);
        $count = match (true) {
            $context->followUpId !== null => $open->whereKey($context->followUpId)->count(),
            $context->estimateId !== null => $open->where('estimate_id', $context->estimateId)->count(),
            $context->conversationId !== null => $open->where('conversation_id', $context->conversationId)->count(),
            default => 0,
        };
        $verb = $type === A::CompleteFollowUp ? 'complete' : 'cancel';

        return ['ok' => true, 'detail' => $count === 0 ? "No open follow-up to {$verb}." : "Would {$verb} {$count} open ".Str::plural('follow-up', $count).'.'];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function dueDate(Organization $organization, array $config): string
    {
        return $organization->localTime(now()->addDays((int) ($config['delay_days'] ?? 1)))->format('M j');
    }

    private function recipientCount(Organization $organization, mixed $recipients): string
    {
        $query = User::query()->where('organization_id', $organization->id);
        $count = match (true) {
            $recipients === 'members' => $query->count(),
            is_int($recipients) => $query->whereKey($recipients)->count(),
            default => $query->where('role', OrganizationRole::Admin)->count(),
        };

        return $count.' '.Str::plural('person', $count);
    }
}
