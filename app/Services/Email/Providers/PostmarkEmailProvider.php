<?php

namespace App\Services\Email\Providers;

use App\Contracts\Email\EmailProviderInterface;
use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\Data\DnsRecord;
use App\Services\Email\Data\DnsRecordsResult;
use App\Services\Email\Data\DomainVerificationResult;
use App\Services\Email\Data\EmailSendResult;
use App\Services\Email\Data\OutboundEmail;
use App\Services\Email\Data\ProviderDomainResult;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Postmark Domains API (https://postmarkapp.com/developer/api/domains-api) and
 * Email API (https://postmarkapp.com/developer/api/email-api).
 *
 * All Postmark-specific request and response handling lives here.
 */
class PostmarkEmailProvider implements EmailProviderInterface
{
    private const PAGE_SIZE = 500;

    /**
     * @param  array{account_token?: ?string, server_token?: ?string, message_stream?: string, base_url?: string, timeout?: int, return_path_subdomain?: string}  $config
     */
    public function __construct(private readonly array $config) {}

    public function registerDomain(string $domain): ProviderDomainResult
    {
        $payload = [
            'Name' => $domain,
            'ReturnPathDomain' => ($this->config['return_path_subdomain'] ?? 'pm-bounces').'.'.$domain,
        ];

        try {
            $details = $this->request('register domain', 'POST', '/domains', $payload);
        } catch (EmailProviderException $e) {
            // The domain already exists in our Postmark account but is not referenced by any
            // QuoteFollow connection (the caller checks that). Re-create it so fresh DKIM keys
            // are issued and nobody inherits a verification they did not perform.
            $existingId = $e->reason === EmailProviderException::REJECTED ? $this->findDomainId($domain) : null;

            if ($existingId === null) {
                throw $e;
            }

            $this->removeDomain($existingId);
            $details = $this->request('register domain', 'POST', '/domains', $payload);
        }

        return new ProviderDomainResult($this->domainId($details), $this->domainName($details));
    }

    public function getDomainDnsRecords(string $providerDomainId): DnsRecordsResult
    {
        $details = $this->request('get domain', 'GET', '/domains/'.rawurlencode($providerDomainId));

        return new DnsRecordsResult($this->dnsRecords($details));
    }

    public function verifyDomain(string $providerDomainId): DomainVerificationResult
    {
        $path = '/domains/'.rawurlencode($providerDomainId);

        $this->request('verify DKIM', 'PUT', $path.'/verifyDkim');
        $details = $this->request('verify return path', 'PUT', $path.'/verifyReturnPath');

        $this->domainId($details);

        return new DomainVerificationResult(
            verified: ($details['DKIMVerified'] ?? false) === true && ($details['ReturnPathDomainVerified'] ?? false) === true,
            records: $this->dnsRecords($details),
        );
    }

    public function removeDomain(string $providerDomainId): void
    {
        try {
            $this->request('remove domain', 'DELETE', '/domains/'.rawurlencode($providerDomainId));
        } catch (EmailProviderException $e) {
            if ($e->reason !== EmailProviderException::DOMAIN_NOT_FOUND) {
                throw $e;
            }
        }
    }

    public function send(OutboundEmail $email): EmailSendResult
    {
        $client = $this->client(
            'X-Postmark-Server-Token',
            $this->config['server_token'] ?? null,
            fn () => EmailProviderException::sendingNotConfigured('POSTMARK_SERVER_TOKEN is not configured.'),
        );

        $payload = array_filter([
            'From' => $this->formatAddress($email->fromEmail, $email->fromName),
            'To' => $this->formatAddress($email->toEmail, $email->toName),
            'ReplyTo' => $email->replyTo,
            'Subject' => $email->subject,
            'HtmlBody' => $email->htmlBody,
            'TextBody' => $email->textBody,
            'MessageStream' => $this->config['message_stream'] ?? 'outbound',
            'Metadata' => array_map('strval', $email->metadata) ?: null,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = $client->post('/email', $payload);
        } catch (ConnectionException) {
            $this->logFailure('send email', null, null);

            throw EmailProviderException::sendOutcomeUnknown('Postmark send email request timed out or could not connect.');
        }

        $status = $response->status();
        $errorCode = $response->json('ErrorCode');
        $messageId = $response->json('MessageID');

        if ($response->successful() && (int) $errorCode === 0 && is_string($messageId) && $messageId !== '') {
            return EmailSendResult::sent($messageId);
        }

        $this->logFailure('send email', $status, $errorCode);
        $detail = "Postmark send email failed with HTTP {$status} (error code ".(is_scalar($errorCode) ? $errorCode : 'n/a').').';

        throw match (true) {
            $status === 401, $status === 403 => EmailProviderException::authenticationFailed($detail),
            $status === 429, $status >= 500 => EmailProviderException::sendUnavailable($detail),
            $status === 422, $response->successful() && (int) $errorCode !== 0 => EmailProviderException::emailRejected($detail),
            default => EmailProviderException::unexpectedResponse($detail),
        };
    }

    /**
     * "Display Name" <address>, with the name quoted and stripped of header-breaking characters.
     */
    private function formatAddress(string $email, ?string $name): string
    {
        $name = trim(str_replace(["\r", "\n"], ' ', (string) $name));

        if ($name === '') {
            return $email;
        }

        return '"'.addcslashes($name, '"\\').'" <'.$email.'>';
    }

    private function findDomainId(string $domain): ?string
    {
        $offset = 0;

        do {
            $page = $this->request('list domains', 'GET', '/domains', ['count' => self::PAGE_SIZE, 'offset' => $offset]);
            $domains = $page['Domains'] ?? [];

            foreach ($domains as $candidate) {
                if (strcasecmp((string) ($candidate['Name'] ?? ''), $domain) === 0 && isset($candidate['ID'])) {
                    return (string) $candidate['ID'];
                }
            }

            $offset += self::PAGE_SIZE;
        } while ($domains !== [] && $offset < (int) ($page['TotalCount'] ?? 0));

        return null;
    }

    /**
     * @param  array<string, mixed>  $details
     * @return list<DnsRecord>
     */
    private function dnsRecords(array $details): array
    {
        $domain = $this->domainName($details);
        $records = [];

        // During initial setup or key rotation Postmark exposes the key to publish as "pending".
        $pending = filled($details['DKIMPendingHost'] ?? null) && filled($details['DKIMPendingTextValue'] ?? null);
        $dkimHost = $pending ? $details['DKIMPendingHost'] : ($details['DKIMHost'] ?? null);
        $dkimValue = $pending ? $details['DKIMPendingTextValue'] : ($details['DKIMTextValue'] ?? null);

        if (filled($dkimHost) && filled($dkimValue)) {
            $records[] = new DnsRecord(
                type: 'TXT',
                name: $this->fullyQualified((string) $dkimHost, $domain),
                value: (string) $dkimValue,
                purpose: 'DKIM',
                verified: ! $pending && ($details['DKIMVerified'] ?? false) === true,
            );
        }

        if (filled($details['ReturnPathDomain'] ?? null) && filled($details['ReturnPathDomainCNAMEValue'] ?? null)) {
            $records[] = new DnsRecord(
                type: 'CNAME',
                name: $this->fullyQualified((string) $details['ReturnPathDomain'], $domain),
                value: (string) $details['ReturnPathDomainCNAMEValue'],
                purpose: 'Return-Path',
                verified: ($details['ReturnPathDomainVerified'] ?? false) === true,
            );
        }

        return $records;
    }

    private function fullyQualified(string $host, string $domain): string
    {
        $host = rtrim(strtolower($host), '.');

        return $host === $domain || Str::endsWith($host, '.'.$domain) ? $host : $host.'.'.$domain;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function domainId(array $details): string
    {
        if (! isset($details['ID']) || ! is_scalar($details['ID'])) {
            throw EmailProviderException::unexpectedResponse('Postmark domain response is missing an ID.');
        }

        return (string) $details['ID'];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function domainName(array $details): string
    {
        if (! isset($details['Name']) || ! is_string($details['Name'])) {
            throw EmailProviderException::unexpectedResponse('Postmark domain response is missing a name.');
        }

        return strtolower($details['Name']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function request(string $operation, string $method, string $path, array $data = []): array
    {
        try {
            $response = $this->accountClient()->send($method, $path, match (true) {
                $data === [] => [],
                $method === 'GET' => ['query' => $data],
                default => ['json' => $data],
            });
        } catch (ConnectionException) {
            $this->logFailure($operation, null, null);

            throw EmailProviderException::unavailable("Postmark {$operation} request timed out or could not connect.");
        }

        if ($response->successful()) {
            $body = $response->json();

            if (! is_array($body)) {
                throw EmailProviderException::unexpectedResponse("Postmark {$operation} returned a non-JSON body.");
            }

            return $body;
        }

        $status = $response->status();
        $errorCode = $response->json('ErrorCode');
        $this->logFailure($operation, $status, $errorCode);

        $detail = "Postmark {$operation} failed with HTTP {$status} (error code ".(is_scalar($errorCode) ? $errorCode : 'n/a').').';
        $notFound = $status === 404
            || ($status === 422 && Str::contains((string) $response->json('Message'), 'not found', ignoreCase: true));

        throw match (true) {
            $status === 401, $status === 403 => EmailProviderException::notConfigured($detail),
            $notFound => EmailProviderException::domainNotFound($detail),
            $status === 422, $status === 400 => EmailProviderException::rejected($detail),
            $status === 429, $status >= 500 => EmailProviderException::unavailable($detail),
            default => EmailProviderException::unexpectedResponse($detail),
        };
    }

    private function accountClient(): PendingRequest
    {
        return $this->client(
            'X-Postmark-Account-Token',
            $this->config['account_token'] ?? null,
            fn () => EmailProviderException::notConfigured('POSTMARK_ACCOUNT_TOKEN is not configured.'),
        );
    }

    /**
     * @param  Closure(): EmailProviderException  $missingToken
     */
    private function client(string $header, ?string $token, Closure $missingToken): PendingRequest
    {
        if (blank($token)) {
            throw $missingToken();
        }

        return Http::baseUrl($this->config['base_url'] ?? 'https://api.postmarkapp.com')
            ->withHeaders([$header => $token])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout($this->config['timeout'] ?? 15);
    }

    /**
     * Logs only the operation and Postmark's status/error code: never headers, tokens or response bodies.
     */
    private function logFailure(string $operation, ?int $status, mixed $errorCode): void
    {
        Log::warning('Email provider request failed.', [
            'provider' => 'postmark',
            'operation' => $operation,
            'status' => $status,
            'error_code' => is_scalar($errorCode) ? $errorCode : null,
        ]);
    }
}
