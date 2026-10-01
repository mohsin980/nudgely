<?php

namespace Tests\Unit\Email;

use App\Contracts\Email\EmailProviderInterface;
use App\Enums\EmailProvider;
use App\Exceptions\Email\EmailProviderException;
use App\Services\Email\EmailProviderManager;
use App\Services\Email\Providers\PostmarkEmailProvider;
use Tests\TestCase;

class EmailProviderManagerTest extends TestCase
{
    public function test_it_resolves_the_configured_provider(): void
    {
        config(['email.provider' => 'postmark']);

        $this->assertInstanceOf(PostmarkEmailProvider::class, app(EmailProviderManager::class)->driver());
        $this->assertInstanceOf(PostmarkEmailProvider::class, app(EmailProviderInterface::class));
    }

    public function test_it_resolves_a_connections_stored_provider(): void
    {
        $this->assertInstanceOf(PostmarkEmailProvider::class, app(EmailProviderManager::class)->for(EmailProvider::Postmark));
    }

    public function test_missing_provider_configuration_fails_safely(): void
    {
        config(['email.provider' => null]);

        $this->expectExceptionObject(EmailProviderException::notConfigured('QUOTE_FLOW_EMAIL_PROVIDER is not configured.'));

        app(EmailProviderManager::class)->driver();
    }

    public function test_unsupported_provider_fails_safely(): void
    {
        try {
            app(EmailProviderManager::class)->for(EmailProvider::Resend);
            $this->fail('Expected exception.');
        } catch (EmailProviderException $e) {
            $this->assertSame(EmailProviderException::NOT_CONFIGURED, $e->reason);
            $this->assertSame('Domain verification isn\'t available right now. Please contact support.', $e->userMessage());
        }
    }

    public function test_new_connections_default_to_the_configured_provider(): void
    {
        config(['email.provider' => 'resend']);
        $this->assertSame(EmailProvider::Resend, EmailProvider::default());

        config(['email.provider' => 'unknown']);
        $this->assertSame(EmailProvider::Postmark, EmailProvider::default());
    }
}
