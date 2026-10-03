<?php

use App\Exceptions\Automation\InvalidEmailTemplateException;
use App\Models\Customer;
use App\Models\Organization;
use App\Services\Automation\EmailTemplateRenderer;

test('follow-up email templates fill only controlled variables', function () {
    $renderer = app(EmailTemplateRenderer::class);
    $organization = new Organization(['name' => 'Dallas HVAC']);

    expect($renderer->render('Hi {{customer.first_name}} ({{ customer.last_name }}, {{customer.email}}) from {{business.name}}',
        new Customer(['name' => 'Mary  Ann Smith', 'email' => 'Mary@Example.com']), $organization))
        ->toBe('Hi Mary (Ann Smith, mary@example.com) from Dallas HVAC')
        // Values are inserted once, never re-parsed.
        ->and($renderer->render('Hi {{customer.first_name}}', new Customer(['name' => '{{business.name}}', 'email' => 'x@example.com']), $organization))
        ->toBe('Hi {{business.name}}');
});

test('unsupported template variables are rejected', function (string $template, string $error) {
    expect(fn () => app(EmailTemplateRenderer::class)->validate($template))->toThrow(InvalidEmailTemplateException::class, $error);
})->with([
    ['{{php_code}}', 'Unsupported variable {{php_code}}.'],
    ['{{ system("ls") }}', 'Unsupported variable {{system("ls")}}.'],
    ['{{business.phone}}', '{{business.phone}} cannot be used yet'],
    ['{{estimate.total}}', '{{estimate.total}} cannot be used yet'],
    ['Hi {{customer.first_name}', 'The template has unmatched {{ or }} braces.'],
]);
