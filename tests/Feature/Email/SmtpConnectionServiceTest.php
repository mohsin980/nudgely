<?php

use App\Enums\EmailProvider;
use App\Exceptions\Email\EmailProviderException;
use App\Exceptions\Email\EmailSendingNotAllowedException;
use App\Models\EmailConnection;
use App\Models\User;
use App\Services\Email\AccountEmailSender;
use App\Services\Email\Data\OutboundEmail;
use App\Services\Email\Data\SmtpSettings;
use App\Services\Email\EmailService;
use App\Services\Email\Providers\SmtpEmailProvider;
use App\Services\Email\SmtpConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** Bind a provider whose transport never touches the network. Failing ones raise $startError. */
function bindSmtpTransport(?string $startError = null): object
{
    $transport = new class($startError) extends EsmtpTransport
    {
        public array $sent = [];

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

        protected function doSend(SentMessage $message): void
        {
            if ($this->startError !== null) {
                throw new TransportException($this->startError);
            }

            $this->sent[] = $message;
        }
    };

    $provider = new class($transport) extends SmtpEmailProvider
    {
        public function __construct(private readonly EsmtpTransport $transport) {}

        protected function transport(SmtpSettings $settings): EsmtpTransport
        {
            return $this->transport;
        }
    };

    app()->instance(SmtpEmailProvider::class, $provider);

    return $transport;
}

function smtpInput(array $overrides = []): array
{
    return array_merge([
        'host' => 'smtp.example.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => 'Owner@Example.com',
        'password' => 'app-password-123',
        'sender_name' => 'Acme Plumbing',
    ], $overrides);
}

test('an owner connects an SMTP account: the sender is the login address and the password is encrypted at rest', function () {
    bindSmtpTransport();
    $owner = User::factory()->owner()->create();

    $connection = app(SmtpConnectionService::class)->connect($owner, $owner->organization, smtpInput());

    expect($connection->provider)->toBe(EmailProvider::Smtp)
        ->and($connection->sender_email)->toBe('owner@example.com')
        ->and($connection->domain)->toBe('example.com')
        ->and($connection->isVerified())->toBeTrue()
        ->and($connection->connection_status)->toBe(EmailConnection::STATUS_ACTIVE)
        ->and($connection->credentials['password'])->toBe('app-password-123');

    $raw = DB::table('email_connections')->where('id', $connection->id)->value('credentials');
    expect($raw)->not->toContain('app-password-123')->and($raw)->not->toContain('smtp.example.com');
});

test('connected credentials are never serialized into responses', function () {
    bindSmtpTransport();
    $owner = User::factory()->owner()->create();
    $connection = app(SmtpConnectionService::class)->connect($owner, $owner->organization, smtpInput());

    expect($connection->toArray())->not->toHaveKey('credentials')
        ->and(json_encode($connection))->not->toContain('app-password-123');
});

test('only an owner can connect an account, so staff and other organizations are refused', function () {
    $transport = bindSmtpTransport();
    $staff = User::factory()->staff()->create();

    expect(fn () => app(SmtpConnectionService::class)->connect($staff, $staff->organization, smtpInput()))
        ->toThrow(ValidationException::class);

    $owner = User::factory()->owner()->create();
    expect(fn () => app(SmtpConnectionService::class)->connect($owner, User::factory()->owner()->create()->organization, smtpInput()))
        ->toThrow(ValidationException::class);

    $this->assertDatabaseCount('email_connections', 0);
    expect($transport->sent)->toBeEmpty();
});

test('a failed test saves nothing and gives a safe, specific message', function () {
    bindSmtpTransport('Expected response code 235 but got code "535", with message "535 Username and Password not accepted"');
    $owner = User::factory()->owner()->create();

    try {
        app(SmtpConnectionService::class)->connect($owner, $owner->organization, smtpInput());
        $this->fail('A failed test must stop the connection.');
    } catch (EmailProviderException $e) {
        expect($e->userMessage())->toContain('username or password')->and($e->userMessage())->not->toContain('app-password-123');
    }

    $this->assertDatabaseCount('email_connections', 0);
});

test('reconnecting the same address updates the connection instead of adding another', function () {
    bindSmtpTransport();
    $owner = User::factory()->owner()->create();
    $service = app(SmtpConnectionService::class);

    $first = $service->connect($owner, $owner->organization, smtpInput());
    $second = $service->connect($owner, $owner->organization, smtpInput(['password' => 'rotated-password-456', 'sender_name' => 'Acme Ltd']));

    expect($second->id)->toBe($first->id)
        ->and($second->credentials['password'])->toBe('rotated-password-456')
        ->and($second->sender_name)->toBe('Acme Ltd');
    $this->assertDatabaseCount('email_connections', 1);
});

test('the sender comes from the stored credentials and the message goes out through that account', function () {
    $transport = bindSmtpTransport();
    $owner = User::factory()->owner()->create();
    $connection = app(SmtpConnectionService::class)->connect($owner, $owner->organization, smtpInput());

    $result = app(AccountEmailSender::class)->send($connection, new OutboundEmail(
        organizationId: $owner->organization_id,
        fromEmail: $connection->sender_email,
        fromName: $connection->sender_name,
        toEmail: 'customer@example.org',
        toName: null,
        subject: 'Your estimate',
        textBody: 'Hello',
        htmlBody: null,
    ));

    expect($result->successful())->toBeTrue()->and($transport->sent)->toHaveCount(1);
});

test('a disconnected account cannot send', function () {
    bindSmtpTransport();
    $owner = User::factory()->owner()->create();
    $connection = app(SmtpConnectionService::class)->connect($owner, $owner->organization, smtpInput());
    $connection->forceFill(['connection_status' => EmailConnection::STATUS_DISCONNECTED])->save();

    expect(fn () => app(EmailService::class)->assertCanSendFrom($connection->refresh(), $owner->organization_id))
        ->toThrow(EmailSendingNotAllowedException::class, 'disconnected');
});

test('an SMTP password that the provider rejects flags the account and tells the owner, once', function () {
    $owner = User::factory()->owner()->create();
    bindSmtpTransport();
    $connection = app(SmtpConnectionService::class)->connect($owner, $owner->organization, smtpInput());

    bindSmtpTransport('Expected response code 235 but got code "535", with message "535 Username and Password not accepted"');

    foreach (range(1, 2) as $attempt) {
        try {
            app(AccountEmailSender::class)->send($connection->refresh(), new OutboundEmail(1, $connection->sender_email, 'Acme', 'c@example.org', null, 'Hi', 'Hi', null));
        } catch (EmailProviderException) {
            // Expected: the provider refuses the password.
        }
    }

    expect($connection->refresh()->connection_status)->toBe(EmailConnection::STATUS_REAUTH_REQUIRED)
        ->and($owner->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === 'email_account_needs_reconnect'))->toHaveCount(1);
});
