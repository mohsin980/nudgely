<?php

namespace Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Automated tests must never reach a real email provider.
        Http::preventStrayRequests();
    }

    /**
     * actingAs($user) with no guard signs in to both the app and the admin panel, as if the person had used
     * both sign-in pages. Tests of the separate admin sign-in pass a guard or sign in through the forms.
     */
    public function be(Authenticatable $user, $guard = null)
    {
        if ($guard === null) {
            parent::be($user, 'admin');
            $guard = 'web';
        }

        return parent::be($user, $guard);
    }
}
