<?php

namespace App\Services\Assistant;

use App\Enums\Team\Permission;
use App\Exceptions\WhatsApp\WhatsAppSendException;
use App\Models\AppointmentRequest;
use App\Models\AssistantChat;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Services\Team\ActivityNotifications;
use App\Services\WhatsApp\WhatsAppMessenger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Appointment times picked through the AI assistant. The assistant only ever *requests* a time; a person confirms
 * or declines it here, and the customer hears the answer on WhatsApp.
 */
class AppointmentBooking
{
    public function __construct(
        private readonly AppointmentSlots $slots,
        private readonly ActivityNotifications $notifications,
        private readonly WhatsAppMessenger $whatsapp,
    ) {}

    /**
     * Record the customer's choice of an offered slot. Null when the slot isn't offered (any more).
     */
    public function request(Organization $organization, Customer $customer, Conversation $conversation, ?AssistantChat $chat, string $key): ?AppointmentRequest
    {
        $slot = $this->slots->find($organization, $key);

        if ($slot === null) {
            Log::info('Appointment request ignored: the time was not offered.', ['organization_id' => $organization->id]);

            return null;
        }

        return DB::transaction(function () use ($organization, $customer, $conversation, $chat, $slot) {
            // Lock the business so two chats can't take the same time.
            Organization::query()->whereKey($organization->id)->lockForUpdate()->value('id');

            $taken = AppointmentRequest::query()->where('organization_id', $organization->id)
                ->whereIn('status', AppointmentRequest::HOLDING)->where('starts_at', $slot['at']->utc())->exists();

            if ($taken) {
                return null;
            }

            $request = new AppointmentRequest;
            $request->forceFill([
                'organization_id' => $organization->id,
                'customer_id' => $customer->id,
                'conversation_id' => $conversation->id,
                'assistant_chat_id' => $chat?->id,
                'starts_at' => $slot['at']->utc(),
                'status' => AppointmentRequest::STATUS_REQUESTED,
            ])->save();

            $this->notifications->appointmentRequested($request, $slot['label']);

            return $request;
        });
    }

    /**
     * @throws AuthorizationException|RuntimeException
     */
    public function confirm(User $actor, AppointmentRequest $request): ?string
    {
        return $this->decide($actor, $request, AppointmentRequest::STATUS_CONFIRMED);
    }

    /**
     * @throws AuthorizationException|RuntimeException
     */
    public function decline(User $actor, AppointmentRequest $request): ?string
    {
        return $this->decide($actor, $request, AppointmentRequest::STATUS_DECLINED);
    }

    /**
     * Records the decision and tells the customer on WhatsApp. Returns a warning when the message couldn't be sent
     * (WhatsApp only allows free text within 24 hours of the customer's last message), otherwise null.
     *
     * @throws AuthorizationException|RuntimeException
     */
    private function decide(User $actor, AppointmentRequest $request, string $status): ?string
    {
        if (! $actor->hasPermission(Permission::ManageBusinessProfile) || $actor->organization_id !== $request->organization_id) {
            throw new AuthorizationException('Only the owner or a manager can confirm appointments.');
        }

        if (! $request->isOpen()) {
            throw new RuntimeException('This appointment was already '.$request->status.'.');
        }

        $organization = $request->organization;
        $label = $this->slots->label($organization, $request->starts_at);
        $request->forceFill(['status' => $status, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
        OrganizationActivity::record($organization, 'appointment_'.$status, $actor, ['appointment_request_id' => $request->id, 'starts_at' => $request->starts_at->toIso8601String()]);

        if ($request->conversation === null) {
            return 'Saved, but there is no chat to tell the customer in. Please message them yourself.';
        }

        $text = $status === AppointmentRequest::STATUS_CONFIRMED
            ? "Your appointment with {$organization->name} is confirmed for {$label}. See you then!"
            : "Sorry, we can't make {$label}. Reply with another time and a team member will help you find one.";

        try {
            $this->whatsapp->sendAutomated($request->conversation, $text);
        } catch (WhatsAppSendException $e) {
            return 'Saved, but the customer was not messaged on WhatsApp: '.$e->getMessage().' Please message them yourself.';
        }

        return null;
    }
}
