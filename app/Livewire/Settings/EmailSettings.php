<?php

namespace App\Livewire\Settings;

use App\Enums\EmailProvider;
use App\Enums\MessageStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\EmailConnection;
use App\Models\Organization;
use App\Services\Email\EmailDomainService;
use App\Services\Email\EmailService;
use App\Validation\EmailConnectionRules;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Email Settings')]
class EmailSettings extends Component
{
    public string $domain = '';

    public string $senderName = '';

    public string $senderEmail = '';

    #[Locked]
    public ?int $editingConnectionId = null;

    #[Locked]
    public ?int $confirmingDeletionId = null;

    #[Locked]
    public ?int $showingDnsRecordsFor = null;

    #[Locked]
    public ?int $testEmailConnectionId = null;

    public string $testRecipient = '';

    public bool $showForm = false;

    public const TEST_EMAILS_PER_HOUR = 5;

    public ?string $statusMessage = null;

    public string $statusType = 'success';

    public function mount(): void
    {
        $this->authorize('viewAny', EmailConnection::class);
    }

    /**
     * The tenant is always resolved from the authenticated user, never from browser input.
     */
    #[Computed]
    public function organization(): Organization
    {
        return Auth::user()->organization ?? abort(403);
    }

    /**
     * @return Collection<int, EmailConnection>
     */
    #[Computed]
    public function connections(): Collection
    {
        return $this->organization->emailConnections()
            ->orderByDesc('is_default')
            ->oldest()
            ->oldest('id')
            ->get();
    }

    public function create(): void
    {
        $this->authorize('create', EmailConnection::class);

        $this->resetForm();
        $this->showForm = true;
    }

    public function edit(int $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $this->authorize('update', $connection);

        $this->resetValidation();
        $this->editingConnectionId = $connection->id;
        $this->domain = $connection->domain;
        $this->senderName = $connection->sender_name;
        $this->senderEmail = $connection->sender_email;
        $this->showForm = true;
    }

    public function save(): void
    {
        $connection = $this->editingConnectionId ? $this->findConnection($this->editingConnectionId) : null;
        $this->authorize($connection ? 'update' : 'create', $connection ?? EmailConnection::class);

        $this->domain = EmailConnection::normalizeDomain($this->domain);
        $this->senderName = trim($this->senderName);
        $this->senderEmail = EmailConnection::normalizeEmail($this->senderEmail);

        $validated = $this->validate();

        $attributes = [
            'domain' => $validated['domain'],
            'sender_name' => $validated['senderName'],
            'sender_email' => $validated['senderEmail'],
        ];

        if ($connection) {
            $previousProviderDomainId = $connection->provider_domain_id;

            // The model resets verification when the domain or sender address changes.
            $connection->update($attributes);

            if ($previousProviderDomainId !== null && $connection->provider_domain_id === null) {
                $this->domains()->releaseProviderDomain($connection->provider, $previousProviderDomainId);
            }

            $this->notify('Email connection updated successfully.');
        } else {
            $this->organization->emailConnections()->create([
                ...$attributes,
                'provider' => EmailProvider::default(),
            ]);
            $this->notify('Email connection added successfully.');
        }

        $this->resetForm();
        unset($this->connections);
    }

    public function cancel(): void
    {
        $this->resetForm();
    }

    public function confirmDelete(int $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $this->authorize('delete', $connection);

        $this->confirmingDeletionId = $connection->id;
    }

    public function cancelDelete(): void
    {
        $this->confirmingDeletionId = null;
    }

    public function delete(): void
    {
        $connection = $this->findConnection($this->confirmingDeletionId);
        $this->authorize('delete', $connection);

        // The default is a flag on the row itself, so deleting it simply leaves no default.
        $this->domains()->deleteConnection($connection);

        if ($this->editingConnectionId === $connection->id) {
            $this->resetForm();
        }

        $this->confirmingDeletionId = null;
        unset($this->connections);
        $this->notify('Email connection removed.');
    }

    public function setDefault(int $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $this->authorize('setDefault', $connection);

        if (! $connection->canBecomeDefault()) {
            $this->notify('Only verified email connections can be set as the default sender.', 'error');

            return;
        }

        $connection->markAsDefault();

        unset($this->connections);
        $this->notify('Default sender updated.');
    }

    /**
     * Register the domain with the email provider and show the DNS records to publish.
     */
    public function startVerification(int $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $this->authorize('verify', $connection);

        try {
            $this->domains()->registerDomain($connection);
            $this->showingDnsRecordsFor = $connection->id;
            $this->notify('Add the DNS records below at your DNS provider, then click "Check Verification".', 'info');
        } catch (EmailProviderException $e) {
            $this->notify($e->userMessage(), 'error');
        }

        unset($this->connections);
    }

    public function toggleDnsRecords(int $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $this->authorize('verify', $connection);

        $this->showingDnsRecordsFor = $this->showingDnsRecordsFor === $connection->id ? null : $connection->id;
    }

    public function checkVerification(int $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $this->authorize('verify', $connection);

        try {
            if ($this->domains()->verifyDomain($connection)) {
                $this->showingDnsRecordsFor = null;
                $this->notify('Your domain is verified.');
            } else {
                $this->showingDnsRecordsFor = $connection->id;
                $this->notify('We couldn\'t verify your domain yet. DNS changes can take some time to propagate, so please check again later.', 'info');
            }
        } catch (EmailProviderException $e) {
            $this->notify($e->userMessage(), 'error');
        }

        unset($this->connections);
    }

    public function openTestEmail(int $connectionId): void
    {
        $connection = $this->findConnection($connectionId);
        $this->authorize('sendTestEmail', $connection);

        $this->resetValidation('testRecipient');
        $this->testEmailConnectionId = $connection->id;
        $this->testRecipient = (string) Auth::user()->email;
    }

    public function cancelTestEmail(): void
    {
        $this->reset('testEmailConnectionId', 'testRecipient');
        $this->resetValidation('testRecipient');
    }

    /**
     * Send the fixed test email from the selected connection. The sender is always the
     * connection's verified business address; only the recipient comes from the browser.
     */
    public function sendTestEmail(): void
    {
        $connection = $this->findConnection($this->testEmailConnectionId);
        $this->authorize('sendTestEmail', $connection);

        $this->testRecipient = strtolower(trim($this->testRecipient));
        $this->validateOnly('testRecipient', ['testRecipient' => ['required', 'string', 'max:254', 'email:rfc,strict']]);

        $rateLimitKey = 'send-test-email:'.$connection->organization_id;

        if (RateLimiter::tooManyAttempts($rateLimitKey, self::TEST_EMAILS_PER_HOUR)) {
            $minutes = (int) ceil(RateLimiter::availableIn($rateLimitKey) / 60);
            $this->notify("You've sent several test emails recently. Please try again in {$minutes} minute(s).", 'error');

            return;
        }

        RateLimiter::hit($rateLimitKey, 3600);

        try {
            $message = app(EmailService::class)->sendTestEmail($connection, $this->testRecipient);
        } catch (EmailSendingNotAllowedException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }

        Log::info('Test email requested.', [
            'organization_id' => $connection->organization_id,
            'user_id' => Auth::id(),
            'email_connection_id' => $connection->id,
            'message_id' => $message->id,
            'status' => $message->status->value,
        ]);

        if ($message->status === MessageStatus::Sent) {
            $this->notify("Test email sent to {$message->to_address}.");
            $this->cancelTestEmail();
        } else {
            $this->notify($message->failure_reason ?? 'The test email could not be sent.', 'error');
        }
    }

    public function dismissStatus(): void
    {
        $this->statusMessage = null;
    }

    /**
     * Reuses the Task 1 rules, mapped onto this component's property names.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        $rules = EmailConnectionRules::rules($this->domain);

        return [
            'domain' => $rules['domain'],
            'senderName' => $rules['sender_name'],
            'senderEmail' => $rules['sender_email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'domain' => 'business domain',
            'senderName' => 'sender name',
            'senderEmail' => 'sender email',
            'testRecipient' => 'test email address',
        ];
    }

    /**
     * Look the connection up inside the current organization only, so foreign ids resolve to a 404.
     */
    private function findConnection(?int $connectionId): EmailConnection
    {
        return $this->organization->emailConnections()->whereKey($connectionId)->first() ?? abort(404);
    }

    private function domains(): EmailDomainService
    {
        return app(EmailDomainService::class);
    }

    private function resetForm(): void
    {
        $this->reset('domain', 'senderName', 'senderEmail', 'editingConnectionId', 'showForm');
        $this->resetValidation();
    }

    private function notify(string $message, string $type = 'success'): void
    {
        $this->statusMessage = $message;
        $this->statusType = $type;
    }
}
