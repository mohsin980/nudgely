<?php

namespace Tests\Unit\Email;

use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\Providers\PostmarkEmailProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PostmarkEmailProviderTest extends TestCase
{
    private const TOKEN = 'secret-account-token';

    private function provider(?string $token = self::TOKEN): PostmarkEmailProvider
    {
        return new PostmarkEmailProvider([
            'account_token' => $token,
            'base_url' => 'https://api.postmarkapp.com',
            'timeout' => 5,
            'return_path_subdomain' => 'pm-bounces',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function domainDetails(array $overrides = []): array
    {
        return array_merge([
            'ID' => 36735,
            'Name' => 'example.com',
            'SPFVerified' => false,
            'DKIMVerified' => false,
            'WeakDKIM' => false,
            'DKIMHost' => '',
            'DKIMTextValue' => '',
            'DKIMPendingHost' => '20261001120000pm._domainkey',
            'DKIMPendingTextValue' => 'k=rsa;p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC',
            'ReturnPathDomain' => 'pm-bounces.example.com',
            'ReturnPathDomainVerified' => false,
            'ReturnPathDomainCNAMEValue' => 'pm.mtasv.net',
        ], $overrides);
    }

    public function test_register_domain_sends_authenticated_request(): void
    {
        Http::fake(['api.postmarkapp.com/domains' => Http::response(self::domainDetails())]);

        $result = $this->provider()->registerDomain('example.com');

        $this->assertSame('36735', $result->providerDomainId);
        $this->assertSame('example.com', $result->domain);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === 'https://api.postmarkapp.com/domains'
            && $request->hasHeader('X-Postmark-Account-Token', self::TOKEN)
            && $request['Name'] === 'example.com'
            && $request['ReturnPathDomain'] === 'pm-bounces.example.com');
    }

    public function test_registering_a_domain_that_already_exists_recreates_it_instead_of_duplicating(): void
    {
        Http::fake([
            'api.postmarkapp.com/domains?*' => Http::response(['TotalCount' => 1, 'Domains' => [['ID' => 111, 'Name' => 'Example.com']]]),
            'api.postmarkapp.com/domains/111' => Http::response(['ErrorCode' => 0, 'Message' => 'Domain removed.']),
            'api.postmarkapp.com/domains' => Http::sequence()
                ->push(['ErrorCode' => 505, 'Message' => 'This domain already exists.'], 422)
                ->push(self::domainDetails(['ID' => 222])),
        ]);

        $result = $this->provider()->registerDomain('example.com');

        $this->assertSame('222', $result->providerDomainId);
        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/domains/111'));
        Http::assertSentCount(4);
    }

    public function test_invalid_domain_rejection_is_reported_safely(): void
    {
        Http::fake([
            'api.postmarkapp.com/domains?*' => Http::response(['TotalCount' => 0, 'Domains' => []]),
            'api.postmarkapp.com/domains' => Http::response(['ErrorCode' => 300, 'Message' => 'Invalid domain name.'], 422),
        ]);

        try {
            $this->provider()->registerDomain('example.com');
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame(EmailProviderException::REJECTED, $e->reason);
            $this->assertStringNotContainsString('Invalid domain name', $e->userMessage());
        }
    }

    public function test_dns_records_are_normalized(): void
    {
        Http::fake(['api.postmarkapp.com/domains/36735' => Http::response(self::domainDetails())]);

        $records = $this->provider()->getDomainDnsRecords('36735')->records;

        $this->assertCount(2, $records);
        $this->assertSame([
            'type' => 'TXT',
            'name' => '20261001120000pm._domainkey.example.com',
            'value' => 'k=rsa;p=MIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC',
            'purpose' => 'DKIM',
            'priority' => null,
            'verified' => false,
        ], $records[0]->toArray());
        $this->assertSame([
            'type' => 'CNAME',
            'name' => 'pm-bounces.example.com',
            'value' => 'pm.mtasv.net',
            'purpose' => 'Return-Path',
            'priority' => null,
            'verified' => false,
        ], $records[1]->toArray());
    }

    public function test_active_dkim_key_is_used_when_nothing_is_pending(): void
    {
        Http::fake(['api.postmarkapp.com/domains/1' => Http::response(self::domainDetails([
            'DKIMVerified' => true,
            'DKIMHost' => 'abc._domainkey.example.com',
            'DKIMTextValue' => 'k=rsa;p=ACTIVE',
            'DKIMPendingHost' => '',
            'DKIMPendingTextValue' => '',
        ]))]);

        $dkim = $this->provider()->getDomainDnsRecords('1')->records[0];

        $this->assertSame('abc._domainkey.example.com', $dkim->name);
        $this->assertSame('k=rsa;p=ACTIVE', $dkim->value);
        $this->assertTrue($dkim->verified);
    }

    public function test_verify_domain_checks_dkim_and_return_path(): void
    {
        $verified = self::domainDetails([
            'DKIMVerified' => true, 'DKIMHost' => 'abc._domainkey.example.com', 'DKIMTextValue' => 'k=rsa;p=X',
            'DKIMPendingHost' => '', 'DKIMPendingTextValue' => '', 'ReturnPathDomainVerified' => true,
        ]);
        Http::fake([
            'api.postmarkapp.com/domains/36735/verifyDkim' => Http::response($verified),
            'api.postmarkapp.com/domains/36735/verifyReturnPath' => Http::response($verified),
        ]);

        $result = $this->provider()->verifyDomain('36735');

        $this->assertTrue($result->verified);
        $this->assertTrue($result->records[0]->verified);
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/verifyDkim'));
        Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/verifyReturnPath'));
    }

    public function test_verify_domain_is_not_verified_until_both_records_pass(): void
    {
        $partial = self::domainDetails(['DKIMVerified' => true, 'ReturnPathDomainVerified' => false]);
        Http::fake(['api.postmarkapp.com/domains/36735/*' => Http::response($partial)]);

        $this->assertFalse($this->provider()->verifyDomain('36735')->verified);
    }

    public function test_remove_domain_ignores_domains_that_no_longer_exist(): void
    {
        Http::fake(['api.postmarkapp.com/domains/9' => Http::response(['ErrorCode' => 510, 'Message' => 'Domain not found.'], 422)]);

        $this->provider()->removeDomain('9');

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_missing_token_fails_without_any_request(): void
    {
        Http::fake();

        try {
            $this->provider(null)->registerDomain('example.com');
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame(EmailProviderException::NOT_CONFIGURED, $e->reason);
        }

        Http::assertNothingSent();
    }

    /**
     * @return array<string, array{0: int, 1: array<string, mixed>, 2: string}>
     */
    public static function errorResponses(): array
    {
        return [
            'invalid token' => [401, ['ErrorCode' => 10, 'Message' => 'Bad or missing API token.'], EmailProviderException::NOT_CONFIGURED],
            'not found (404)' => [404, [], EmailProviderException::DOMAIN_NOT_FOUND],
            'not found (422)' => [422, ['ErrorCode' => 510, 'Message' => 'Domain not found.'], EmailProviderException::DOMAIN_NOT_FOUND],
            'validation error' => [422, ['ErrorCode' => 300, 'Message' => 'Invalid request.'], EmailProviderException::REJECTED],
            'rate limited' => [429, [], EmailProviderException::UNAVAILABLE],
            'server error' => [500, ['Message' => 'Internal error'], EmailProviderException::UNAVAILABLE],
            'unexpected status' => [418, [], EmailProviderException::UNEXPECTED_RESPONSE],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('errorResponses')]
    public function test_api_errors_are_mapped_to_safe_exceptions(int $status, array $body, string $reason): void
    {
        Http::fake(['*' => Http::response($body, $status)]);
        Log::spy();

        try {
            $this->provider()->getDomainDnsRecords('36735');
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame($reason, $e->reason);
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->userMessage());
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => ! str_contains(json_encode($context), self::TOKEN)
            && $context['status'] === $status);
    }

    public function test_timeouts_are_reported_as_unavailable(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $this->expectExceptionObject(EmailProviderException::unavailable('Postmark get domain request timed out or could not connect.'));

        $this->provider()->getDomainDnsRecords('36735');
    }

    public function test_unexpected_response_shape_is_rejected(): void
    {
        Http::fake(['*' => Http::response(['Unexpected' => true])]);

        try {
            $this->provider()->getDomainDnsRecords('36735');
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame(EmailProviderException::UNEXPECTED_RESPONSE, $e->reason);
        }
    }
}
