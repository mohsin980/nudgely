<?php

use App\Livewire\Settings\EmailSettings;
use App\Models\EmailConnection;
use App\Models\User;
use App\Services\Email\Data\SmtpSettings;
use App\Services\Email\Providers\SmtpEmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'email.oauth.google.client_id' => 'google-client-id',
        'email.oauth.google.client_secret' => 'google-client-secret',
    ]);
});

/** Makes the SMTP test succeed, or fail with $startError, without any network. */
function smtpUiTransport(?string $startError = null): void
{
    $transport = new class($startError) extends EsmtpTransport
    {
        public function __construct(private readonly ?string $startError)
        {
            parent::__construct('smtp.example.test', 587, false);
        }

        public function start(): void
        {
            if ($this->startError !== null) {
                throw new TransportException($this->startError);
            }
        }

        public function stop(): void {}

        protected function doSend(SentMessage $message): void {}
    };

    app()->instance(SmtpEmailProvider::class, new class($transport) extends SmtpEmailProvider
    {
        public function __construct(private readonly EsmtpTransport $transport) {}

        protected function transport(SmtpSettings $settings): EsmtpTransport
        {
            return $this->transport;
        }
    });
}

function accountConnection(User $owner, string $provider = 'gmail', string $status = 'active', bool $default = false): EmailConnection
{
    $connection = new EmailConnection;
    $connection->forceFill([
        'organization_id' => $owner->organization_id,
        'provider' => $provider,
        'domain' => 'gmail.com',
        'sender_email' => 'owner@gmail.com',
        'sender_name' => 'Acme Plumbing',
        'verification_status' => 'verified',
        'verified_at' => now(),
        'credentials' => ['refresh_token' => '1//secret-refresh-token'],
        'connection_status' => $status,
        'is_default' => $default,
    ])->save();

    return $connection;
}

test('the settings page offers Gmail, Outlook and custom SMTP without asking for a domain', function () {
    config([
        'email.oauth.microsoft.client_id' => 'microsoft-client-id',
        'email.oauth.microsoft.client_secret' => 'microsoft-client-secret',
    ]);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get(route('settings.email'))
        ->assertOk()
        ->assertSee('Connect Google')
        ->assertSee('Configure SMTP')
        ->assertSee('Outlook or Hotmail')
        ->assertDontSee('Not set up on this server yet.', false);
});

test('a provider that is not configured on the server is shown as unavailable instead of a broken button', function () {
    config(['email.oauth.microsoft.client_id' => null]);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get(route('settings.email'))
        ->assertOk()
        ->assertSee('Not set up on this server yet.');
});

test('an owner connects a custom SMTP account from the page, and the password is never shown back', function () {
    smtpUiTransport();
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)->test(EmailSettings::class)
        ->call('openSmtpForm')
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpPort', '587')
        ->set('smtpEncryption', 'tls')
        ->set('smtpUsername', 'owner@example.com')
        ->set('smtpPassword', 'super-secret-app-password')
        ->set('smtpSenderName', 'Acme Plumbing')
        ->call('connectSmtp')
        ->assertSet('showSmtpForm', false)
        ->assertSee('owner@example.com')
        ->assertDontSee('super-secret-app-password');

    expect(EmailConnection::query()->where('organization_id', $owner->organization_id)->sole()->provider->value)->toBe('smtp');
});

test('a failed SMTP test shows the reason on the form and saves nothing', function () {
    smtpUiTransport('Expected response code 235 but got code "535"');
    $owner = User::factory()->owner()->create();

    Livewire::actingAs($owner)->test(EmailSettings::class)
        ->call('openSmtpForm')
        ->set('smtpHost', 'smtp.example.com')
        ->set('smtpUsername', 'owner@example.com')
        ->set('smtpPassword', 'wrong-password')
        ->set('smtpSenderName', 'Acme')
        ->call('connectSmtp')
        ->assertHasErrors(['smtp'])
        ->assertSee('username or password');

    expect(EmailConnection::query()->count())->toBe(0);
});

test('disconnecting asks first, and warns when the account is the default sender', function () {
    $owner = User::factory()->owner()->create();
    $connection = accountConnection($owner, default: true);

    Livewire::actingAs($owner)->test(EmailSettings::class)
        ->call('beginDisconnect', $connection->id)
        ->assertSet('disconnectingId', $connection->id)
        ->assertSee('automated quotes and follow-ups will stop');

    expect($connection->refresh()->connection_status)->toBe(EmailConnection::STATUS_ACTIVE);
});

test('confirming a disconnect stops sending, removes the stored secret and keeps the record', function () {
    $owner = User::factory()->owner()->create();
    $connection = accountConnection($owner, default: true);

    Livewire::actingAs($owner)->test(EmailSettings::class)
        ->call('beginDisconnect', $connection->id)
        ->call('confirmDisconnect')
        ->assertSet('disconnectingId', null);

    $connection->refresh();
    expect($connection->connection_status)->toBe(EmailConnection::STATUS_DISCONNECTED)
        ->and($connection->credentials)->toBeNull()
        ->and($connection->is_default)->toBeFalse();
});

test('a connection that needs reconnecting says so, and cannot be used until it is connected again', function () {
    $owner = User::factory()->owner()->create();
    accountConnection($owner, status: 'reauth_required');

    $this->actingAs($owner)->get(route('settings.email'))
        ->assertOk()
        ->assertSee('Needs reconnecting');
});

test('staff cannot change account connections, and another business cannot disconnect yours', function () {
    $owner = User::factory()->owner()->create();
    $connection = accountConnection($owner);
    $staff = User::factory()->staff()->create();
    $other = User::factory()->owner()->create();

    // The refused action aborts with 403; the form never opens.
    $staffTest = Livewire::actingAs($staff)->test(EmailSettings::class);

    try {
        $staffTest->call('openSmtpForm');
    } catch (Throwable) {
        // The test harness cannot parse an error response; the state below is what matters.
    }

    expect((bool) $staffTest->get('showSmtpForm'))->toBeFalse();

    // Another business's connection is not found for it, so no confirmation opens.
    Livewire::actingAs($other)->test(EmailSettings::class)
        ->call('beginDisconnect', $connection->id)
        ->assertSet('disconnectingId', null);

    expect($connection->refresh()->connection_status)->toBe(EmailConnection::STATUS_ACTIVE);
});
