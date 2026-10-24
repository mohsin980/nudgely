<?php

use App\Enums\EmailProvider;
use App\Enums\MessageStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\EmailConnection;
use App\Models\MailboxBounceNotice;
use App\Models\Message;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Email\Bounces\BounceNoticeParser;
use App\Services\Email\Bounces\BounceTokens;
use App\Services\Email\Bounces\MailboxBounceScanner;
use App\Services\Email\Data\SmtpSettings;
use App\Services\Email\EmailService;
use App\Services\Email\InboundEmailProcessor;
use App\Services\Email\OAuth\OAuthConnectionService;
use App\Services\Email\Providers\SmtpEmailProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, PostmarkInboundPayloads::class);

beforeEach(function () {
    Queue::fake();
    $this->configureInboundWebhook();
    config([
        'email.providers.postmark.server_token' => 'server-token',
        'email.oauth.google.client_id' => 'google-client-id',
        'email.oauth.google.client_secret' => 'google-client-secret',
        'email.oauth.microsoft.client_id' => 'microsoft-client-id',
        'email.oauth.microsoft.client_secret' => 'microsoft-client-secret',
    ]);

    $this->owner = User::factory()->owner()->create();
    $this->organization = $this->owner->organization;
    $this->customer = Customer::factory()->create(['organization_id' => $this->organization->id, 'email' => 'jane@customer.test']);
    $this->conversation = Conversation::factory()->create(['organization_id' => $this->organization->id, 'customer_id' => $this->customer->id]);
});

/** Records what the SMTP transport is given, without touching the network. */
function recordingSmtpTransport(): EsmtpTransport
{
    $transport = new class extends EsmtpTransport
    {
        public array $envelopes = [];

        public function __construct()
        {
            parent::__construct('smtp.example.test', 587, false);
        }

        public function start(): void {}

        public function stop(): void {}

        protected function doSend(SentMessage $message): void
        {
            $this->envelopes[] = $message->getEnvelope();
        }
    };

    app()->instance(SmtpEmailProvider::class, new class($transport) extends SmtpEmailProvider
    {
        public function __construct(private readonly EsmtpTransport $transport) {}

        protected function transport(SmtpSettings $settings): EsmtpTransport
        {
            return $this->transport;
        }
    });

    return $transport;
}

function sentMessageFor(Conversation $conversation, string $text = 'Your estimate is ready.'): Message
{
    return app(EmailService::class)->sendToConversation($conversation, 'Your estimate', null, $text);
}

function base64Url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/** A bounce notice as a mailbox returns it: our returned headers, and a machine-readable status. */
function bounceNotice(string $status, ?string $token, string $recipient = 'jane@customer.test'): string
{
    $returned = $token === null ? '' : "X-QuoteFlow-Message: {$token}\r\n";

    return "From: Mail Delivery Subsystem <mailer-daemon@googlemail.com>\r\n"
        ."Subject: Delivery Status Notification (Failure)\r\n"
        ."Content-Type: text/plain\r\n\r\n"
        ."Status: {$status}\r\n"
        ."Final-Recipient: rfc822; {$recipient}\r\n\r\n"
        ."--- Original message ---\r\n"
        ."Subject: Your estimate\r\n"
        .$returned;
}

test('a bounce token names only the message it was made for', function () {
    expect(BounceTokens::messageIdFrom(BounceTokens::forMessage(42)))->toBe(42)
        ->and(BounceTokens::messageIdFrom('42.'.str_repeat('0', 16)))->toBeNull()
        ->and(BounceTokens::messageIdFrom('43.'.substr(BounceTokens::forMessage(42), 3)))->toBeNull()
        ->and(BounceTokens::messageIdFrom('not-a-token'))->toBeNull();
});

test('the parser reads permanent and temporary status codes and the returned token', function () {
    $parser = app(BounceNoticeParser::class);
    $token = BounceTokens::forMessage(7);

    expect($parser->statusCode("Status: 5.1.1\nFinal-Recipient: x"))->toBe('5.1.1')
        ->and($parser->isPermanent('5.1.1'))->toBeTrue()
        ->and($parser->isPermanent('4.4.7'))->toBeFalse()
        ->and($parser->statusCode('550 5.1.1 user unknown'))->toBe('5.1.1')
        ->and($parser->statusCode('no code here'))->toBeNull()
        ->and($parser->messageId(bounceNotice('5.1.1', $token)))->toBe(7)
        ->and($parser->messageId(bounceNotice('5.1.1', null)))->toBeNull();
});

test('SMTP mail is sent with a bounce address as its envelope sender, and the From header unchanged', function () {
    $transport = recordingSmtpTransport();
    EmailConnection::factory()->for($this->organization)->verified()->default()->create([
        'provider' => EmailProvider::Smtp,
        'domain' => 'example.com',
        'sender_email' => 'sales@example.com',
        'credentials' => ['host' => 'smtp.example.com', 'port' => 587, 'username' => 'sales@example.com', 'password' => 'app-password', 'encryption' => 'tls'],
    ]);

    $message = sentMessageFor($this->conversation);
    app(EmailService::class)->deliver($message);

    expect($message->refresh()->status)->toBe(MessageStatus::Sent, (string) $message->failure_reason);
    $envelope = $transport->envelopes[0];
    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($envelope->getSender()->getAddress())->toStartWith('bounce+')
        ->and($envelope->getSender()->getAddress())->toEndWith('@inbound.quoteflow.ai')
        ->and($envelope->getSender()->getAddress())->toContain(BounceTokens::forMessage($message->id))
        ->and($envelope->getRecipients()[0]->getAddress())->toBe('jane@customer.test');
});

test('a permanent bounce notice at the bounce address marks the message bounced and stops emailing that customer', function () {
    EmailConnection::factory()->for($this->organization)->verified()->default()->create(['provider' => EmailProvider::Smtp, 'domain' => 'example.com', 'sender_email' => 'sales@example.com', 'credentials' => ['host' => 'smtp.example.com', 'port' => 587, 'username' => 'u', 'password' => 'p', 'encryption' => 'tls']]);
    $message = sentMessageFor($this->conversation);
    $message->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now(), 'provider' => EmailProvider::Smtp])->save();

    $address = 'bounce+'.BounceTokens::forMessage($message->id).'@inbound.quoteflow.ai';
    $this->postJson(route('webhooks.email.inbound', 'postmark'), $this->postmarkInbound($address, [
        'TextBody' => "550 5.1.1 <jane@customer.test>: Recipient address rejected: user unknown\n",
    ]), $this->webhookAuth())->assertOk();

    app(InboundEmailProcessor::class)->process(WebhookEvent::query()->sole());

    expect($message->refresh()->status)->toBe(MessageStatus::Bounced)
        ->and($this->customer->refresh()->isEmailSuppressed())->toBeTrue();
});

test('a temporary bounce notice does not stop emailing the customer', function () {
    EmailConnection::factory()->for($this->organization)->verified()->default()->create(['provider' => EmailProvider::Smtp, 'domain' => 'example.com', 'sender_email' => 'sales@example.com', 'credentials' => ['host' => 'smtp.example.com', 'port' => 587, 'username' => 'u', 'password' => 'p', 'encryption' => 'tls']]);
    $message = sentMessageFor($this->conversation);
    $message->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now(), 'provider' => EmailProvider::Smtp])->save();

    $address = 'bounce+'.BounceTokens::forMessage($message->id).'@inbound.quoteflow.ai';
    $this->postJson(route('webhooks.email.inbound', 'postmark'), $this->postmarkInbound($address, [
        'TextBody' => "421 4.4.2 mailbox busy, will retry\n",
    ]), $this->webhookAuth())->assertOk();

    app(InboundEmailProcessor::class)->process(WebhookEvent::query()->sole());

    expect($message->refresh()->status)->toBe(MessageStatus::Sent)
        ->and($this->customer->refresh()->isEmailSuppressed())->toBeFalse();
});

test('a Gmail mailbox bounce notice for our message is applied once, and unrelated notices are ignored', function () {
    EmailConnection::factory()->for($this->organization)->verified()->default()->create(['provider' => EmailProvider::Gmail, 'domain' => 'gmail.com', 'sender_email' => 'owner@gmail.com', 'bounce_scan_enabled' => true, 'credentials' => ['refresh_token' => '1//refresh']]);
    $connection = EmailConnection::query()->sole();
    $message = sentMessageFor($this->conversation);
    $message->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now(), 'provider' => EmailProvider::Gmail, 'email_connection_id' => $connection->id])->save();

    $ours = bounceNotice('5.1.1', BounceTokens::forMessage($message->id));
    $unrelated = bounceNotice('5.1.1', null, 'someone@else.test');

    Http::fake(function ($request) use ($ours, $unrelated) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_contains($request->url(), 'oauth2.googleapis.com') => Http::response(['access_token' => 'ya29.token', 'expires_in' => 3600]),
            $path === '/gmail/v1/users/me/messages' => Http::response(['messages' => [['id' => 'g-ours'], ['id' => 'g-other']]]),
            str_ends_with($path, '/messages/g-ours') => Http::response(['raw' => base64Url($ours)]),
            str_ends_with($path, '/messages/g-other') => Http::response(['raw' => base64Url($unrelated)]),
            default => Http::response([], 404),
        };
    });

    $scanner = app(MailboxBounceScanner::class);
    expect($scanner->scan($connection))->toBe(2)
        ->and($message->refresh()->status)->toBe(MessageStatus::Bounced)
        ->and($this->customer->refresh()->isEmailSuppressed())->toBeTrue()
        ->and(MailboxBounceNotice::query()->pluck('outcome', 'provider_message_id')->all())->toBe(['g-ours' => 'recorded', 'g-other' => 'not_ours'])
        ->and($scanner->scan($connection))->toBe(0);
});

test('an Outlook bounce is found by its sender or subject, fetched once, and applied', function () {
    EmailConnection::factory()->for($this->organization)->verified()->default()->create(['provider' => EmailProvider::Microsoft, 'domain' => 'outlook.com', 'sender_email' => 'owner@outlook.com', 'bounce_scan_enabled' => true, 'credentials' => ['refresh_token' => 'refresh']]);
    $connection = EmailConnection::query()->sole();
    $message = sentMessageFor($this->conversation);
    $message->forceFill(['status' => MessageStatus::Sent, 'sent_at' => now(), 'provider' => EmailProvider::Microsoft, 'email_connection_id' => $connection->id])->save();
    $notice = bounceNotice('5.4.1', BounceTokens::forMessage($message->id));

    Http::fake(function ($request) use ($notice) {
        $path = parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            str_contains($request->url(), 'login.microsoftonline.com') => Http::response(['access_token' => 'eyJ.token', 'expires_in' => 3600]),
            $path === '/v1.0/me/messages' => Http::response(['value' => [
                ['id' => 'm-bounce', 'from' => ['emailAddress' => ['address' => 'MAILER-DAEMON@outlook.com']], 'subject' => 'Undeliverable: Your estimate'],
                ['id' => 'm-friend', 'from' => ['emailAddress' => ['address' => 'bob@friend.test']], 'subject' => 'Lunch?'],
            ]]),
            str_contains($path, '/me/messages/m-bounce/') => Http::response($notice, 200, ['Content-Type' => 'message/rfc822']),
            default => Http::response([], 404),
        };
    });

    expect(app(MailboxBounceScanner::class)->scan($connection))->toBe(1)
        ->and($message->refresh()->status)->toBe(MessageStatus::Bounced);

    Http::assertSentCount(3); // token, list, and the one candidate
});

test('a mailbox connection that was not granted read access is never scanned', function () {
    EmailConnection::factory()->for($this->organization)->verified()->default()->create(['provider' => EmailProvider::Gmail, 'domain' => 'gmail.com', 'sender_email' => 'owner@gmail.com', 'bounce_scan_enabled' => false, 'credentials' => ['refresh_token' => '1//refresh']]);
    Http::fake();

    expect(app(MailboxBounceScanner::class)->scan(EmailConnection::query()->sole()))->toBe(0);
    Http::assertNothingSent();
});

test('connecting Gmail records whether the business granted read access to its mailbox', function (string $scope, bool $expected) {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.token', 'refresh_token' => '1//refresh', 'expires_in' => 3600, 'scope' => $scope]),
        'openidconnect.googleapis.com/*' => Http::response(['email' => 'owner@gmail.com', 'email_verified' => true]),
    ]);

    app(OAuthConnectionService::class)->connect($this->owner, $this->organization, EmailProvider::Gmail, 'code', 'verifier', 'https://app.test/callback');

    expect(EmailConnection::query()->sole()->bounce_scan_enabled)->toBe($expected);
})->with([
    'with read access' => ['openid email https://www.googleapis.com/auth/gmail.send https://www.googleapis.com/auth/gmail.readonly', true],
    'without read access' => ['openid email https://www.googleapis.com/auth/gmail.send', false],
]);

test('Outlook sends carry our message token as an x- header, and no unsubscribe headers', function () {
    EmailConnection::factory()->for($this->organization)->verified()->default()->create(['provider' => EmailProvider::Microsoft, 'domain' => 'outlook.com', 'sender_email' => 'owner@outlook.com', 'credentials' => ['refresh_token' => 'refresh']]);
    Http::fake(['login.microsoftonline.com/*' => Http::response(['access_token' => 'eyJ.token', 'expires_in' => 3600]), 'graph.microsoft.com/*' => Http::response(null, 202)]);

    $message = sentMessageFor($this->conversation);
    app(EmailService::class)->deliver($message);

    Http::assertSent(function ($request) use ($message) {
        if (! str_contains($request->url(), 'sendMail')) {
            return false;
        }

        $headers = collect($request['message']['internetMessageHeaders'] ?? []);

        return $headers->contains(fn ($h) => $h['name'] === 'X-QuoteFlow-Message' && $h['value'] === BounceTokens::forMessage($message->id))
            && ! $headers->contains(fn ($h) => strtolower($h['name']) === 'list-unsubscribe');
    });
});
