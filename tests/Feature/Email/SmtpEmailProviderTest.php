<?php

uses(TestCase::class);

use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\Data\OutboundEmail;
use App\Services\Email\Data\SmtpSettings;
use App\Services\Email\Providers\SmtpEmailProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\TestCase;

/**
 * A transport that never touches the network: start() can be made to fail, and sent messages are recorded.
 */
function fakeSmtpProvider(?string $startError = null, ?array &$sent = null): SmtpEmailProvider
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
            // A real send opens the connection inside the send call, so a connection failure surfaces here too.
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

    $sent = &$transport->sent;

    return $provider;
}

function smtpSettings(string $encryption = 'tls'): SmtpSettings
{
    return new SmtpSettings('smtp.example.com', 587, 'owner@example.com', 'app-password-123', $encryption);
}

function outboundEmail(): OutboundEmail
{
    return new OutboundEmail(
        organizationId: 1,
        fromEmail: 'owner@example.com',
        fromName: 'Acme Plumbing',
        toEmail: 'customer@example.org',
        toName: 'Sam',
        subject: 'Your estimate',
        textBody: 'Hello',
        htmlBody: '<p>Hello</p>',
    );
}

test('a successful test connects and disconnects without sending anything', function () {
    fakeSmtpProvider()->test(smtpSettings());

    expect(true)->toBeTrue();
});

test('a send returns the message id from the server side of the transport', function () {
    $provider = fakeSmtpProvider();

    $result = $provider->send(smtpSettings(), outboundEmail());

    expect($result->successful())->toBeTrue()
        ->and($result->providerMessageId)->not->toBeNull()->and($result->providerMessageId)->not->toBe('');
});

test('authentication failures are refused, with a message that does not repeat the server reply', function () {
    $provider = fakeSmtpProvider('Expected response code 235 but got code "535", with message "535 5.7.8 Username and Password not accepted"');

    try {
        $provider->test(smtpSettings());
        $this->fail('Authentication failure must be reported.');
    } catch (EmailProviderException $e) {
        expect($e->reason)->toBe(EmailProviderException::REJECTED)
            ->and($e->isTransient())->toBeFalse()
            ->and($e->userMessage())->toContain('username or password')
            ->and($e->userMessage())->not->toContain('535')
            ->and($e->getMessage())->not->toContain('app-password-123');
    }
});

test('encryption failures are reported as a configuration problem, not retried', function () {
    $provider = fakeSmtpProvider('TLS required but neither TLS or STARTTLS are in use.');

    try {
        $provider->test(smtpSettings());
        $this->fail('Encryption failure must be reported.');
    } catch (EmailProviderException $e) {
        expect($e->reason)->toBe(EmailProviderException::REJECTED)->and($e->isTransient())->toBeFalse();
    }
});

test('an unreachable server is transient so the job can retry', function () {
    $provider = fakeSmtpProvider('Connection could not be established with host "smtp.example.com:587".');

    try {
        $provider->send(smtpSettings(), outboundEmail());
        $this->fail('Unreachable server must be reported.');
    } catch (EmailProviderException $e) {
        expect($e->reason)->toBe(EmailProviderException::UNAVAILABLE)->and($e->isTransient())->toBeTrue();
    }
});

test('private and loopback hosts are refused before any connection is attempted', function (string $host) {
    $provider = new SmtpEmailProvider;

    expect(fn () => $provider->test(new SmtpSettings($host, 587, 'owner@example.com', 'app-password-123', 'tls')))
        ->toThrow(EmailProviderException::class, 'private');
})->with(['127.0.0.1', '10.0.0.5', '192.168.1.20']);

test('unencrypted SMTP is refused unless a private relay is explicitly allowed', function () {
    $provider = new SmtpEmailProvider;

    expect(fn () => $provider->test(new SmtpSettings('10.0.0.5', 25, 'owner@example.com', 'app-password-123', 'none')))
        ->toThrow(EmailProviderException::class, 'Unencrypted SMTP is disabled');
});
