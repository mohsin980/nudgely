<?php

namespace Tests\Unit\Rules;

use App\Validation\EmailConnectionRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as ValidatorInstance;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailConnectionRulesTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function validate(array $overrides = []): ValidatorInstance
    {
        $data = array_merge([
            'provider' => 'postmark',
            'domain' => 'example.com',
            'sender_email' => 'sales@example.com',
            'sender_name' => 'Example Co',
        ], $overrides);

        return Validator::make($data, EmailConnectionRules::rules($data['domain']));
    }

    public function test_sender_email_matching_the_domain_is_accepted(): void
    {
        $this->assertTrue($this->validate()->passes());
    }

    public function test_domain_and_sender_email_are_compared_case_insensitively(): void
    {
        $this->assertTrue($this->validate(['domain' => 'Example.COM', 'sender_email' => 'Sales@example.com'])->passes());
    }

    public function test_sender_email_from_another_domain_is_rejected(): void
    {
        $validator = $this->validate(['sender_email' => 'sales@otherdomain.com']);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('sender_email', $validator->errors()->toArray());
    }

    public function test_sender_email_on_a_subdomain_or_lookalike_domain_is_rejected(): void
    {
        $this->assertTrue($this->validate(['sender_email' => 'sales@mail.example.com'])->fails());
        $this->assertTrue($this->validate(['sender_email' => 'sales@notexample.com'])->fails());
    }

    public function test_invalid_sender_email_is_rejected(): void
    {
        $this->assertTrue($this->validate(['sender_email' => 'not-an-email'])->fails());
    }

    #[DataProvider('invalidDomains')]
    public function test_invalid_domain_is_rejected(string $domain): void
    {
        $validator = $this->validate(['domain' => $domain]);

        $this->assertArrayHasKey('domain', $validator->errors()->toArray());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDomains(): array
    {
        return [
            'no tld' => ['localhost'],
            'with scheme' => ['https://example.com'],
            'with path' => ['example.com/contact'],
            'with port' => ['example.com:443'],
            'leading hyphen' => ['-example.com'],
            'email address' => ['sales@example.com'],
            'spaces' => ['exa mple.com'],
        ];
    }

    public function test_subdomain_is_a_valid_domain(): void
    {
        $this->assertTrue($this->validate(['domain' => 'mail.example.co.uk', 'sender_email' => 'sales@mail.example.co.uk'])->passes());
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $this->assertTrue($this->validate(['provider' => 'smtp-relay'])->fails());
    }
}
