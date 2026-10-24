<?php

/*
|--------------------------------------------------------------------------
| Super Admin panel navigation (/admin)
|--------------------------------------------------------------------------
*/

test('the sidebar groups pages under meaningful headings', function () {
    $this->actingAs(platformAdmin())->get('/admin')
        ->assertOk()
        ->assertSee('Customers')
        ->assertSee('Billing')
        ->assertSee('Security & Activity')
        ->assertSee('Audit Events');
});

test('every link in the admin sidebar opens for a super admin', function () {
    $admin = platformAdmin();
    $html = $this->actingAs($admin)->get('/admin')->assertOk()->getContent();

    preg_match_all('#href="([^"]*/admin[^"]*)"#', $html, $matches);

    $paths = collect($matches[1])
        ->map(fn (string $url) => parse_url(html_entity_decode($url), PHP_URL_PATH))
        ->filter(fn (?string $path) => $path !== null && ! str_contains($path, 'logout'))
        ->unique()
        ->values();

    expect($paths)->not->toBeEmpty()
        ->and($paths)->toContain('/admin/organizations', '/admin/users', '/admin/usage', '/admin/plans', '/admin/subscriptions', '/admin/audit-logs');

    foreach ($paths as $path) {
        $this->actingAs($admin)->get($path)->assertOk();
    }
});
