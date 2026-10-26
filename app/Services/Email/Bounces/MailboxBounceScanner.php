<?php

namespace App\Services\Email\Bounces;

use App\Enums\EmailProvider;
use App\Enums\MessageDirection;
use App\Exceptions\Email\EmailProviderException;
use App\Models\EmailConnection;
use App\Models\MailboxBounceNotice;
use App\Models\Message;
use App\Services\Email\AccountEmailSender;
use App\Services\Email\OAuth\OAuthMailClient;

/**
 * Finds bounce notices in a connected Gmail or Outlook mailbox and applies them to the messages they name.
 *
 * Only a notice that carries a valid token for one of this connection's own outbound messages is applied, and the
 * message's organization comes from that message. Each mailbox message is examined once.
 */
class MailboxBounceScanner
{
    private const LOOKBACK_DAYS = 3;

    private const MAX_PER_RUN = 25;

    private const MAX_NOTICE_BYTES = 512 * 1024;

    public function __construct(
        private readonly OAuthMailClient $client,
        private readonly AccountEmailSender $accounts,
        private readonly BounceNoticeParser $parser,
        private readonly BounceRecorder $recorder,
    ) {}

    /**
     * @return int How many mailbox messages were examined.
     *
     * @throws EmailProviderException
     */
    public function scan(EmailConnection $connection): int
    {
        if (! $connection->bounce_scan_enabled || $connection->connection_status !== EmailConnection::STATUS_ACTIVE
            || ! in_array($connection->provider, [EmailProvider::Gmail, EmailProvider::Microsoft], true)) {
            return 0;
        }

        try {
            $token = $this->accounts->accessToken($connection);
            $candidates = $this->client->listBounceCandidates($connection->provider, $token, self::LOOKBACK_DAYS);
        } catch (EmailProviderException $e) {
            if ($e->reason === EmailProviderException::REAUTH_REQUIRED) {
                $this->accounts->markReauthRequired($connection);
            }

            throw $e;
        }

        $examined = MailboxBounceNotice::query()
            ->where('email_connection_id', $connection->id)
            ->whereIn('provider_message_id', $candidates)
            ->pluck('provider_message_id')
            ->all();

        $new = array_slice(array_values(array_diff($candidates, $examined)), 0, self::MAX_PER_RUN);

        foreach ($new as $providerMessageId) {
            [$outcome, $messageId] = $this->examine($connection, $token, $providerMessageId);

            MailboxBounceNotice::query()->create([
                'organization_id' => $connection->organization_id,
                'email_connection_id' => $connection->id,
                'message_id' => $messageId,
                'provider_message_id' => $providerMessageId,
                'outcome' => $outcome,
            ]);
        }

        return count($new);
    }

    /**
     * @return array{0: string, 1: ?int} The outcome, and the message the notice was about (if any).
     *
     * @throws EmailProviderException
     */
    private function examine(EmailConnection $connection, string $token, string $providerMessageId): array
    {
        $raw = $this->client->fetchRaw($connection->provider, $token, $providerMessageId);

        if (strlen($raw) > self::MAX_NOTICE_BYTES) {
            return ['too_large', null];
        }

        $messageId = $this->parser->messageId($raw);

        // Only this connection's own outbound messages: a notice in this mailbox cannot touch another account's mail.
        $message = $messageId === null ? null : Message::query()
            ->where('organization_id', $connection->organization_id)
            ->where('email_connection_id', $connection->id)
            ->where('direction', MessageDirection::Outbound)
            ->whereKey($messageId)
            ->first();

        if ($message === null) {
            return ['not_ours', null];
        }

        $status = $this->parser->statusCode($raw);

        if ($status === null) {
            return ['no_status', $message->id];
        }

        if (! $this->parser->isPermanent($status)) {
            return ['temporary', $message->id];
        }

        return [$this->recorder->recordPermanentBounce($message, 'mailbox'), $message->id];
    }
}
