<?php

namespace Tests\Unit\Email;

use App\Enums\MessageStatus;
use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\Data\OutboundEmail;
use App\Services\Email\Providers\PostmarkEmailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostmarkEmailSendTest extends TestCase
{
    private const SERVER_TOKEN = 'pm-server-token-secret';

    private const ACCOUNT_TOKEN = 'pm-account-token-secret';

    private function provider(?string $serverToken = self::SERVER_TOKEN): PostmarkEmailProvider
    {
        return new PostmarkEmailProvider([
            'account_token' => self::ACCOUNT_TOKEN,
            'server_token' => $serverToken,
            'message_stream' => 'outbound',
            'base_url' => 'https://api.postmarkapp.com',
            'timeout' => 5,
        ]);
    }

    private function email(array $overrides = []): OutboundEmail
    {
        return new OutboundEmail(...array_merge([
            'organizationId' => 7,
            'fromEmail' => 'sales@example.com',
            'fromName' => 'Dallas Cooling',
            'toEmail' => 'customer@gmail.com',
            'toName' => 'Pat Customer',
            'subject' => 'Your estimate',
            'textBody' => 'Plain body',
            'htmlBody' => '<p>HTML body</p>',
            'replyTo' => 'reply+abc123@inbound.quoteflow.ai',
            'metadata' => ['message_id' => '42'],
        ], $overrides));
    }

    private function acceptedResponse(): array
    {
        return ['To' => 'customer@gmail.com', 'SubmittedAt' => '2026-10-04T12:00:00Z', 'MessageID' => 'b7bc2f4a-e38e-4336-af7d-e6c392c2f817', 'ErrorCode' => 0, 'Message' => 'OK'];
    }

    public function test_it_sends_the_expected_payload_with_the_server_token(): void
    {
        Http::fake(['api.postmarkapp.com/email' => Http::response($this->acceptedResponse())]);

        $result = $this->provider()->send($this->email());

        $this->assertSame(MessageStatus::Sent, $result->status);
        $this->assertTrue($result->successful());
        $this->assertSame('b7bc2f4a-e38e-4336-af7d-e6c392c2f817', $result->providerMessageId);

        Http::assertSent(function (Request $request) {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.postmarkapp.com/email'
                && $request->hasHeader('X-Postmark-Server-Token', self::SERVER_TOKEN)
                && ! $request->hasHeader('X-Postmark-Account-Token')
                && $request['From'] === '"Dallas Cooling" <sales@example.com>'
                && $request['To'] === '"Pat Customer" <customer@gmail.com>'
                && $request['Subject'] === 'Your estimate'
                && $request['HtmlBody'] === '<p>HTML body</p>'
                && $request['TextBody'] === 'Plain body'
                && $request['ReplyTo'] === 'reply+abc123@inbound.quoteflow.ai'
                && $request['MessageStream'] === 'outbound'
                && $request['Metadata'] === ['message_id' => '42'];
        });
    }

    public function test_optional_fields_are_omitted_when_absent(): void
    {
        Http::fake(['*' => Http::response($this->acceptedResponse())]);

        $this->provider()->send($this->email(['htmlBody' => null, 'replyTo' => null, 'toName' => null, 'metadata' => []]));

        Http::assertSent(fn (Request $request) => $request['To'] === 'customer@gmail.com'
            && $request['TextBody'] === 'Plain body'
            && ! isset($request['HtmlBody'], $request['ReplyTo'], $request['Metadata']));
    }

    public function test_display_names_cannot_break_the_header(): void
    {
        Http::fake(['*' => Http::response($this->acceptedResponse())]);

        $this->provider()->send($this->email(['fromName' => "Acme \"Best\"\r\nBcc: evil@example.org"]));

        Http::assertSent(fn (Request $request) => $request['From'] === '"Acme \"Best\"  Bcc: evil@example.org" <sales@example.com>');
    }

    public function test_missing_server_token_fails_without_a_request(): void
    {
        Http::fake();

        try {
            $this->provider(null)->send($this->email());
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame(EmailProviderException::NOT_CONFIGURED, $e->reason);
            $this->assertFalse($e->isTransient());
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: int, 1: array<string, mixed>, 2: string, 3: bool, 4: string}>
     */
    public static function failures(): array
    {
        return [
            'invalid token' => [401, ['ErrorCode' => 10, 'Message' => 'Bad or missing Server API token.'], EmailProviderException::NOT_CONFIGURED, false, 'Email provider authentication failed.'],
            'rejected' => [422, ['ErrorCode' => 400, 'Message' => 'Sender signature not defined.'], EmailProviderException::REJECTED, false, 'The email could not be accepted by the provider.'],
            'rate limited' => [429, [], EmailProviderException::UNAVAILABLE, true, 'The email provider did not respond. The message will be retried when appropriate.'],
            'server error' => [503, [], EmailProviderException::UNAVAILABLE, true, 'The email provider did not respond. The message will be retried when appropriate.'],
            'error code in 200' => [200, ['ErrorCode' => 406, 'Message' => 'Inactive recipient.'], EmailProviderException::REJECTED, false, 'The email could not be accepted by the provider.'],
            'missing message id' => [200, ['ErrorCode' => 0], EmailProviderException::UNEXPECTED_RESPONSE, false, 'We couldn\'t connect your domain right now. Please try again.'],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('failures')]
    public function test_provider_failures_are_normalized(int $status, array $body, string $reason, bool $transient, string $userMessage): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        Log::spy();

        try {
            $this->provider()->send($this->email());
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertSame($transient, $e->isTransient());
            $this->assertSame($userMessage, $e->userMessage());
            $this->assertStringNotContainsString(self::SERVER_TOKEN, $e->getMessage());
            $this->assertStringNotContainsString('Sender signature', $e->userMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context), self::SERVER_TOKEN)
            && ! str_contains(json_encode($context), 'customer@gmail.com'));
    }

    /**
     * A timed-out send may already have been accepted, so it is not retried: resending could duplicate the email.
     */
    public function test_send_timeouts_are_not_retried(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            $this->provider()->send($this->email());
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertFalse($e->isTransient());
            $this->assertSame(EmailProviderException::OUTCOME_UNKNOWN, $e->reason);
            $this->assertSame('The email provider did not confirm delivery. It was not resent, to avoid sending it twice.', $e->userMessage());
        }
    }
}
