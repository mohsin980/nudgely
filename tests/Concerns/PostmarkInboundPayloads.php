<?php

namespace Tests\Concerns;

trait PostmarkInboundPayloads
{
    protected const WEBHOOK_USER = 'postmark';

    protected const WEBHOOK_SECRET = 'inbound-secret-never-shown';

    protected function configureInboundWebhook(): void
    {
        config([
            'email.providers.postmark.inbound_webhook_username' => self::WEBHOOK_USER,
            'email.providers.postmark.inbound_webhook_secret' => self::WEBHOOK_SECRET,
            'email.inbound.reply_domain' => 'inbound.quoteflow.ai',
        ]);
    }

    /**
     * A realistic Postmark inbound webhook payload.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function postmarkInbound(string $to = 'reply+0000000000000000000000000000000000000000@inbound.quoteflow.ai', array $overrides = []): array
    {
        return array_merge([
            'FromName' => 'John Smith',
            'MessageStream' => 'inbound',
            'From' => 'john@example.com',
            'FromFull' => ['Email' => 'john@example.com', 'Name' => 'John Smith', 'MailboxHash' => ''],
            'To' => $to,
            'ToFull' => [['Email' => $to, 'Name' => '', 'MailboxHash' => '']],
            'Cc' => '',
            'CcFull' => [],
            'Bcc' => '',
            'BccFull' => [],
            'OriginalRecipient' => $to,
            'Subject' => 'Re: HVAC Estimate',
            'MessageID' => '22c74902-a0c1-4511-804f-341342852c90',
            'ReplyTo' => '',
            'MailboxHash' => '',
            'Date' => 'Fri, 2 Oct 2026 10:15:00 -0500',
            'TextBody' => "Can you lower the price?\n\nOn Thu, Dallas Cooling wrote:\n> Hi John",
            'HtmlBody' => '<p>Can you lower the price?</p>',
            'StrippedTextReply' => 'Can you lower the price?',
            'Tag' => '',
            'Headers' => [
                ['Name' => 'Message-ID', 'Value' => '<CAF1234@mail.example.com>'],
                ['Name' => 'In-Reply-To', 'Value' => '<outbound-1@pm.mtasv.net>'],
                ['Name' => 'References', 'Value' => '<outbound-0@pm.mtasv.net> <outbound-1@pm.mtasv.net>'],
                ['Name' => 'Received-SPF', 'Value' => 'pass'],
                ['Name' => 'X-Internal-Secret', 'Value' => 'should not be kept'],
            ],
            'Attachments' => [],
        ], $overrides);
    }

    /**
     * @return array<string, string>
     */
    protected function webhookAuth(string $user = self::WEBHOOK_USER, string $secret = self::WEBHOOK_SECRET): array
    {
        return ['Authorization' => 'Basic '.base64_encode($user.':'.$secret)];
    }
}
