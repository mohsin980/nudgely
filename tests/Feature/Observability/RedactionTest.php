<?php

use App\Enums\MessageStatus;
use App\Logging\RedactSensitiveLogs;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Log\Logger as LaravelLogger;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as MonologLogger;

/**
 * Run a value through the redactor and return it as JSON, so tests can assert on what could be written.
 */
function redacted(mixed $value): string
{
    return json_encode(SensitiveDataRedactor::redact($value), JSON_UNESCAPED_SLASHES);
}

test('secret-named keys are removed at any depth', function () {
    $clean = redacted([
        'password' => 'hunter2',
        'current_password' => 'old',
        'api_key' => 'sk_live_xyz',
        'webhook_secret' => 'whsec_abc',
        'access_token' => 'tok',
        'nested' => ['deeper' => ['session_id' => 'sess-1', 'status' => 'ok']],
        'list' => [['cookie' => 'laravel_session=abc']],
    ]);

    expect($clean)->not->toContain('hunter2')->not->toContain('old')->not->toContain('sk_live_xyz')
        ->not->toContain('whsec_abc')->not->toContain('"tok"')->not->toContain('sess-1')->not->toContain('laravel_session')
        ->toContain('"status":"ok"');
});

test('token and hash fields are removed, while identifiers stay', function () {
    $clean = redacted([
        'reply_token' => 'a'.str_repeat('b', 39),
        'token_hash' => str_repeat('c', 64),
        'invitation_token' => 'inv',
        'public_token_hash' => 'h',
        'message_id' => 42,
        'provider_message_id' => 'pm-42-1',
        'correlation_id' => '3f2b8c1e-aaaa-bbbb-cccc-111122223333',
    ]);

    expect($clean)->not->toContain(str_repeat('c', 64))->not->toContain('"inv"')
        ->toContain('"message_id":42')->toContain('pm-42-1')->toContain('3f2b8c1e-aaaa-bbbb-cccc-111122223333');
});

test('email and message content is never written', function () {
    $clean = redacted([
        'body_text' => 'Hi Pat, here is your estimate for $4,200',
        'body_html' => '<p>Hi</p>',
        'html' => '<p>x</p>',
        'attachments' => [['Name' => 'quote.pdf', 'Content' => 'JVBERi0=']],
        'payload' => ['Subject' => 'secret subject'],
        'headers' => ['X-Internal-Secret' => 'value'],
    ]);

    expect($clean)->not->toContain('Pat')->not->toContain('4,200')->not->toContain('<p>')
        ->not->toContain('JVBERi0=')->not->toContain('secret subject')->not->toContain('X-Internal-Secret');
});

test('email addresses inside strings are masked to a first letter and the domain', function () {
    expect(SensitiveDataRedactor::sanitizeMessage('Sent to jane.doe@acme-hvac.com today'))
        ->toBe('Sent to j***@acme-hvac.com today');
});

test('bearer and basic credentials, URL user info and query strings are removed', function () {
    expect(SensitiveDataRedactor::sanitizeMessage('Authorization: Bearer abc.def.ghi'))->not->toContain('abc.def.ghi');
    expect(SensitiveDataRedactor::sanitizeMessage('Basic cG9zdG1hcms6c2VjcmV0'))->not->toContain('cG9zdG1hcms6c2VjcmV0');
    expect(SensitiveDataRedactor::sanitizeMessage('https://user:pass@example.test/hook'))->not->toContain('user:pass');
    expect(SensitiveDataRedactor::sanitizeMessage('GET https://example.test/x?token=abc123&email=a@b.co'))->not->toContain('abc123')->not->toContain('a@b.co');
});

test('stripe and webhook signing keys are removed from text', function () {
    $text = redacted('key sk_live_51Hxyz and whsec_test_123 in error');

    expect($text)->not->toContain('sk_live_51Hxyz')->not->toContain('whsec_test_123');
});

test('internal addresses and long opaque tokens are removed from text', function () {
    expect(SensitiveDataRedactor::sanitizeMessage('connection refused to 10.0.0.5:5432'))->not->toContain('10.0.0.5');
    expect(SensitiveDataRedactor::sanitizeMessage('invalid '.str_repeat('x', 64)))->not->toContain(str_repeat('x', 64));
});

test('an exception is described by class, scrubbed message and origin, never by its trace or arguments', function () {
    $exception = new RuntimeException('Provider rejected jane@acme.com with password=hunter2', 500);

    $described = SensitiveDataRedactor::describe($exception);

    expect($described)->toHaveKeys(['exception', 'message', 'code', 'location'])
        ->and($described['exception'])->toBe(RuntimeException::class)
        ->and($described['message'])->not->toContain('jane@acme.com')
        ->and($described['location'])->toMatch('/\.php:\d+$/')
        ->and(json_encode($described))->not->toContain('#0')->not->toContain('trace');
});

test('an object in context is replaced by its class name, never dumped', function () {
    $clean = redacted(['model' => new stdClass, 'status' => MessageStatus::Sent]);

    expect($clean)->toContain('[object stdClass]')->toContain('"status":"sent"');
});

test('the tap redacts every record written through a channel', function () {
    $handler = new TestHandler;
    $monolog = new MonologLogger('test', [$handler]);
    (new RedactSensitiveLogs)(new LaravelLogger($monolog));

    $monolog->warning('Probe', ['password' => 'hunter2', 'body_text' => 'private', 'organization_id' => 9, 'email' => 'jane@acme.com']);

    $record = $handler->getRecords()[0];
    expect($record->level)->toBe(Level::Warning)
        ->and($record->context['password'])->toBe('[redacted]')
        ->and($record->context['body_text'])->toBe('[redacted]')
        ->and($record->context['organization_id'])->toBe(9)
        ->and($record->context['email'])->toBe('j***@acme.com');
});

test('the redacted output keeps what an operator needs to find the event', function () {
    $clean = redacted([
        'event' => 'email.failed', 'organization_id' => 3, 'message_id' => 88, 'provider' => 'postmark',
        'reason' => 'rejected', 'attempt' => 2, 'duration_ms' => 41.2, 'correlation_id' => 'req-abc12345',
    ]);

    expect($clean)->toContain('"event":"email.failed"')->toContain('"organization_id":3')->toContain('"reason":"rejected"')
        ->toContain('"duration_ms":41.2')->toContain('req-abc12345');
});
