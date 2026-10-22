<?php

use App\Enums\EmailProvider;
use App\Exceptions\Email\EmailProviderException;
use App\Models\EmailConnection;
use App\Models\User;
use App\Services\Email\AccountEmailSender;
use App\Services\Email\Data\OutboundEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    config([
        'email.oauth.google.client_id' => 'google-client-id',
        'email.oauth.google.client_secret' => 'google-client-secret',
        'email.oauth.microsoft.client_id' => 'microsoft-client-id',
        'email.oauth.microsoft.client_secret' => 'microsoft-client-secret',
    ]);
});

/** Starts the connect flow as the given user and returns the state and verifier the server stored. */
function startOAuth(User $user, string $provider): array
{
    test()->actingAs($user)->get(route('settings.email.oauth.redirect', ['provider' => $provider]))->assertRedirect();

    $pending = session('email_oauth_pending');

    return ['state' => $pending['state'], 'verifier' => $pending['verifier']];
}

function callbackFor(User $user, string $provider, string $state, string $code = 'auth-code-123')
{
    return test()->actingAs($user)->get(route('settings.email.oauth.callback', ['provider' => $provider]).'?'.http_build_query(['code' => $code, 'state' => $state]));
}

function fakeGoogle(bool $verified = true, string $email = 'Owner@Gmail.com'): void
{
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29.access', 'refresh_token' => '1//refresh-secret', 'expires_in' => 3600]),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response(['email' => $email, 'email_verified' => $verified]),
        'gmail.googleapis.com/*' => Http::response(['id' => 'gmail-message-1', 'threadId' => 't1']),
    ]);
}

test('Connect Google redirects with PKCE, a one-time state and only the scopes it needs', function () {
    $owner = User::factory()->owner()->create();

    $response = $this->actingAs($owner)->get(route('settings.email.oauth.redirect', ['provider' => 'gmail']));

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['access_type'])->toBe('offline')
        ->and($query['scope'])->toBe('openid email https://www.googleapis.com/auth/gmail.send')
        ->and($query['state'])->toBe(session('email_oauth_pending')['state'])
        ->and($query['code_challenge'])->not->toBe(session('email_oauth_pending')['verifier']);
});

test('a successful Google approval connects the verified account and stores its refresh token encrypted', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');

    callbackFor($owner, 'gmail', $flow['state'])->assertRedirect(route('settings.email'))->assertSessionHas('status');

    $connection = EmailConnection::query()->where('organization_id', $owner->organization_id)->sole();
    expect($connection->provider)->toBe(EmailProvider::Gmail)
        ->and($connection->sender_email)->toBe('owner@gmail.com')
        ->and($connection->isVerified())->toBeTrue()
        ->and($connection->connection_status)->toBe(EmailConnection::STATUS_ACTIVE)
        ->and($connection->credentials['refresh_token'])->toBe('1//refresh-secret');

    $raw = DB::table('email_connections')->where('id', $connection->id)->value('credentials');
    expect($raw)->not->toContain('1//refresh-secret')->and(json_encode($connection))->not->toContain('refresh-secret');
});

test('the code exchange sends the PKCE verifier and the client secret, never to the browser', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');

    callbackFor($owner, 'gmail', $flow['state']);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'oauth2.googleapis.com/token')
        && $request['code_verifier'] === $flow['verifier']
        && $request['client_secret'] === 'google-client-secret'
        && $request['grant_type'] === 'authorization_code');
});

test('a denied approval saves nothing and contacts no token endpoint', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');

    $this->actingAs($owner)->get(route('settings.email.oauth.callback', ['provider' => 'gmail']).'?error=access_denied&state='.$flow['state'])
        ->assertRedirect(route('settings.email'))
        ->assertSessionHas('error', 'Connection cancelled. Nothing was changed.');

    expect(EmailConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a forged state is refused before any token request', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    startOAuth($owner, 'gmail');

    callbackFor($owner, 'gmail', 'forged-state-value')->assertSessionHas('error');

    expect(EmailConnection::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('a callback can be used only once, so a replayed link does nothing', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');

    callbackFor($owner, 'gmail', $flow['state'])->assertSessionHas('status');
    callbackFor($owner, 'gmail', $flow['state'])->assertSessionHas('error');

    expect(EmailConnection::query()->count())->toBe(1);
});

test('a flow started by one account cannot be completed by another, even in the same browser session', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    $other = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');

    callbackFor($other, 'gmail', $flow['state'])->assertSessionHas('error');

    expect(EmailConnection::query()->count())->toBe(0);
});

test('a Google account whose address is not verified is refused', function () {
    fakeGoogle(verified: false);
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');

    callbackFor($owner, 'gmail', $flow['state'])->assertSessionHas('error');

    expect(EmailConnection::query()->count())->toBe(0);
});

test('staff cannot start or complete a connection, and unknown providers are not found', function () {
    $staff = User::factory()->staff()->create();

    $this->actingAs($staff)->get(route('settings.email.oauth.redirect', ['provider' => 'gmail']))->assertForbidden();
    $this->actingAs(User::factory()->owner()->create())->get('/settings/email/yahoo/connect')->assertNotFound();
});

test('a Gmail send uses a fresh access token and returns the Gmail message ID', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');
    callbackFor($owner, 'gmail', $flow['state']);
    $connection = EmailConnection::query()->sole();

    $result = app(AccountEmailSender::class)->send($connection, new OutboundEmail(
        organizationId: $owner->organization_id,
        fromEmail: $connection->sender_email,
        fromName: 'Acme Plumbing',
        toEmail: 'customer@example.org',
        toName: null,
        subject: 'Your estimate',
        textBody: 'Hello',
        htmlBody: null,
    ));

    expect($result->providerMessageId)->toBe('gmail-message-1');
    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), 'gmail.googleapis.com')) {
            return false;
        }

        $mime = base64_decode(strtr($request['raw'], '-_', '+/'));

        return $request->hasHeader('Authorization', 'Bearer ya29.access')
            && str_contains($mime, 'Subject: Your estimate')
            && str_contains($mime, 'customer@example.org');
    });
});

test('a revoked Google grant is reported as reconnect-required, not retried, and the business is told', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');
    callbackFor($owner, 'gmail', $flow['state']);
    $connection = EmailConnection::query()->sole();

    // A fresh HTTP fake: the earlier stubs would otherwise keep answering the token request.
    Http::swap(new Factory);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    try {
        app(AccountEmailSender::class)->send($connection, new OutboundEmail(1, $connection->sender_email, 'Acme', 'c@example.org', null, 'Hi', 'Hi', null));
        $this->fail('A revoked grant must not send.');
    } catch (EmailProviderException $e) {
        expect($e->reason)->toBe(EmailProviderException::REAUTH_REQUIRED)->and($e->isTransient())->toBeFalse();
    }

    expect($connection->refresh()->connection_status)->toBe(EmailConnection::STATUS_REAUTH_REQUIRED);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'gmail.googleapis.com'));
});

test('a Microsoft connection and send use Graph, and a rotated refresh token is stored', function () {
    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'eyJ.access', 'refresh_token' => 'M.rotated-refresh', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/me*' => Http::response(['mail' => 'Owner@Outlook.com', 'userPrincipalName' => 'owner@outlook.com']),
        'graph.microsoft.com/v1.0/me/sendMail' => Http::response(null, 202),
    ]);
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'microsoft');

    callbackFor($owner, 'microsoft', $flow['state'])->assertSessionHas('status');
    $connection = EmailConnection::query()->sole();
    expect($connection->provider)->toBe(EmailProvider::Microsoft)->and($connection->sender_email)->toBe('owner@outlook.com');

    Http::swap(new Factory);
    Http::fake([
        'login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response(['access_token' => 'eyJ.access-2', 'refresh_token' => 'M.rotated-again', 'expires_in' => 3600]),
        'graph.microsoft.com/v1.0/me/sendMail' => Http::response(null, 202),
    ]);

    $result = app(AccountEmailSender::class)->send($connection, new OutboundEmail(1, $connection->sender_email, 'Acme', 'c@example.org', null, 'Estimate', 'Hello', '<p>Hello</p>'));

    expect($result->successful())->toBeTrue()->and($connection->refresh()->credentials['refresh_token'])->toBe('M.rotated-again');
    Http::assertSent(fn ($request) => str_contains($request->url(), '/me/sendMail')
        && $request['message']['subject'] === 'Estimate'
        && $request['message']['toRecipients'][0]['emailAddress']['address'] === 'c@example.org'
        && $request['message']['body']['contentType'] === 'HTML');
});

test('a missing OAuth client on the server says so, and starts no flow', function () {
    config(['email.oauth.google.client_id' => null]);
    $owner = User::factory()->owner()->create();

    $this->actingAs($owner)->get(route('settings.email.oauth.redirect', ['provider' => 'gmail']))
        ->assertRedirect(route('settings.email'))
        ->assertSessionHas('error');
});

test('a revoked account tells the owner once, even when several queued messages fail after it', function () {
    fakeGoogle();
    $owner = User::factory()->owner()->create();
    $flow = startOAuth($owner, 'gmail');
    callbackFor($owner, 'gmail', $flow['state']);
    $connection = EmailConnection::query()->sole();

    Http::swap(new Factory);
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);

    foreach (range(1, 3) as $attempt) {
        try {
            app(AccountEmailSender::class)->send($connection->refresh(), new OutboundEmail(1, $connection->sender_email, 'Acme', "c{$attempt}@example.org", null, 'Hi', 'Hi', null));
        } catch (EmailProviderException) {
            // Each message fails; only the first failure should notify.
        }
    }

    $notices = $owner->notifications()->get()->filter(fn ($n) => ($n->data['kind'] ?? null) === 'email_account_needs_reconnect');
    expect($notices)->toHaveCount(1)
        ->and($notices->first()->data['message'])->toContain('owner@gmail.com')
        ->and($connection->refresh()->connection_status)->toBe(EmailConnection::STATUS_REAUTH_REQUIRED);
});
