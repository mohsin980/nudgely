<?php

namespace Tests\Unit\Email;

use App\Enums\EmailProvider;
use App\Exceptions\Email\InvalidInboundEmailException;
use App\Services\Email\Inbound\PostmarkInboundWebhook;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\PostmarkInboundPayloads;
use Tests\TestCase;

class PostmarkInboundWebhookTest extends TestCase
{
    use PostmarkInboundPayloads;

    private function webhook(?string $secret = self::WEBHOOK_SECRET): PostmarkInboundWebhook
    {
        return new PostmarkInboundWebhook(['inbound_webhook_username' => self::WEBHOOK_USER, 'inbound_webhook_secret' => $secret]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers = []): Request
    {
        $server = isset($headers['Authorization']) ? ['HTTP_AUTHORIZATION' => $headers['Authorization']] : [];

        return Request::create('/webhooks/email/inbound/postmark', 'POST', server: $server);
    }

    public function test_valid_basic_auth_is_accepted(): void
    {
        $this->assertTrue($this->webhook()->authenticate($this->request($this->webhookAuth())));
    }

    public function test_wrong_or_missing_credentials_are_rejected(): void
    {
        $this->assertFalse($this->webhook()->authenticate($this->request($this->webhookAuth(secret: 'wrong'))));
        $this->assertFalse($this->webhook()->authenticate($this->request($this->webhookAuth(user: 'someone-else'))));
        $this->assertFalse($this->webhook()->authenticate($this->request()));
    }

    public function test_unconfigured_secret_fails_closed(): void
    {
        $this->assertFalse($this->webhook(null)->authenticate($this->request($this->webhookAuth(secret: ''))));
        $this->assertFalse($this->webhook('')->authenticate($this->request($this->webhookAuth())));
    }

    public function test_payload_is_normalized(): void
    {
        $email = $this->webhook()->parse($this->postmarkInbound(overrides: [
            'CcFull' => [['Email' => 'Office@Example.com', 'Name' => 'Office']],
            'Attachments' => [['Name' => 'photo.jpg', 'Content' => base64_encode('binary'), 'ContentType' => 'image/jpeg', 'ContentLength' => 6]],
        ]));

        $this->assertSame(EmailProvider::Postmark, $email->provider);
        $this->assertSame('22c74902-a0c1-4511-804f-341342852c90', $email->providerMessageId);
        $this->assertSame('<CAF1234@mail.example.com>', $email->messageId);
        $this->assertSame('john@example.com', $email->fromEmail);
        $this->assertSame('John Smith', $email->fromName);
        $this->assertSame('Re: HVAC Estimate', $email->subject);
        $this->assertSame('<p>Can you lower the price?</p>', $email->htmlBody);
        $this->assertStringStartsWith('Can you lower the price?', $email->textBody);
        $this->assertSame('2026-10-02T15:15:00+00:00', $email->receivedAt->toIso8601String());
        $this->assertSame('<outbound-1@pm.mtasv.net>', $email->inReplyTo);
        $this->assertSame(['<outbound-0@pm.mtasv.net>', '<outbound-1@pm.mtasv.net>'], $email->references);
        $this->assertSame(['reply+0000000000000000000000000000000000000000@inbound.quoteflow.ai', 'office@example.com'], $email->recipients);
        $this->assertArrayNotHasKey('x-internal-secret', $email->headers);
        $this->assertSame('pass', $email->headers['received-spf']);
        $this->assertSame([['name' => 'photo.jpg', 'content_type' => 'image/jpeg', 'size' => 6]], $email->attachments);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'no message id' => [['MessageID' => '']],
            'invalid sender' => [['From' => 'nope', 'FromFull' => ['Email' => 'nope']]],
            'no recipients' => [['OriginalRecipient' => '', 'ToFull' => [], 'To' => '']],
            'message id is not a string' => [['MessageID' => ['x']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_malformed_payloads_are_rejected(array $overrides): void
    {
        $this->expectException(InvalidInboundEmailException::class);

        $this->webhook()->parse($this->postmarkInbound(overrides: $overrides));
    }

    public function test_attachment_contents_are_stripped_before_storage(): void
    {
        $sanitized = $this->webhook()->sanitizePayload($this->postmarkInbound(overrides: [
            'Attachments' => [['Name' => 'a.pdf', 'Content' => base64_encode('secret file'), 'ContentType' => 'application/pdf', 'ContentLength' => 11]],
        ]));

        $this->assertSame([['Name' => 'a.pdf', 'ContentType' => 'application/pdf', 'ContentLength' => 11]], $sanitized['Attachments']);
    }
}
