<?php

use App\Services\Email\Data\SmtpSettings;
use Tests\TestCase;

uses(TestCase::class);

function validSmtpInput(array $overrides = []): array
{
    return array_merge([
        'host' => 'smtp.example.com',
        'port' => '587',
        'encryption' => 'tls',
        'username' => 'owner@example.com',
        'password' => 'app-password-123',
    ], $overrides);
}

test('valid SMTP input is accepted and normalised', function () {
    $settings = SmtpSettings::fromInput(validSmtpInput(['host' => ' SMTP.Example.COM ', 'port' => 465, 'encryption' => 'SSL']));

    expect($settings->host)->toBe('smtp.example.com')
        ->and($settings->port)->toBe(465)
        ->and($settings->encryption)->toBe('ssl');
});

test('invalid SMTP input is refused with a message the business can act on', function (array $override, string $message) {
    expect(fn () => SmtpSettings::fromInput(validSmtpInput($override)))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'host with a path' => [['host' => 'smtp.example.com/evil'], 'Enter the server name'],
    'host with a space' => [['host' => 'smtp example.com'], 'Enter the server name'],
    'port zero' => [['port' => 0], 'Enter a port number'],
    'port too large' => [['port' => 70000], 'Enter a port number'],
    'unknown encryption' => [['encryption' => 'plain'], 'Choose TLS, SSL or no encryption'],
    'empty username' => [['username' => '  '], 'Enter the username'],
    'empty password' => [['password' => ''], 'Enter the password'],
]);

test('the password never appears in debug output', function () {
    $settings = SmtpSettings::fromInput(validSmtpInput());

    expect(print_r($settings, true))->not->toContain('app-password-123')
        ->and(var_export($settings->toCredentials(), true))->toContain('app-password-123');
});
