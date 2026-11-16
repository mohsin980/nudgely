<?php

use App\Enums\Outreach\OutreachCampaignStatus;
use App\Enums\Outreach\OutreachMessageStatus;
use App\Enums\Outreach\OutreachTemplateStatus;
use App\Enums\Platform\PlatformPermission;
use App\Enums\Platform\PlatformRole;
use App\Filament\Widgets\Outreach\OutreachVolumeChart;
use App\Models\OutreachCampaign;
use App\Models\OutreachCategory;
use App\Models\OutreachContact;
use App\Models\OutreachMessage;
use App\Models\OutreachTemplate;
use App\Models\User;
use App\Services\Outreach\OutreachAccess;
use App\Services\Outreach\OutreachCampaigns;
use App\Services\Outreach\OutreachContactImporter;
use App\Services\Outreach\OutreachTemplates;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    config([
        'outreach.email.postmark_token' => 'postmark-test-token',
        'outreach.email.from_email' => 'team@quoteflow.test',
        'outreach.whatsapp.token' => 'wa-test-token',
        'outreach.whatsapp.phone_number_id' => '1234567890',
        'outreach.max_per_minute' => 1000,
    ]);
    $this->superAdmin = platformAdmin(PlatformRole::SuperAdmin);
    $this->hvac = OutreachCategory::query()->create(['name' => 'HVAC', 'slug' => 'hvac', 'is_active' => true]);
});

/** An approved email template in the given category, written by one admin and approved by another. */
function approvedEmailTemplate(OutreachCategory $category, User $approver): OutreachTemplate
{
    $author = platformAdmin(PlatformRole::SuperAdmin);
    $service = app(OutreachTemplates::class);
    $template = $service->create($author, ['outreach_category_id' => $category->id, 'channel' => 'email', 'name' => 'Intro', 'subject' => 'Hi {{first_name}}', 'body' => 'We can help.']);
    $service->submit($author, $template);

    return $service->approve($approver, $template);
}

function contact(OutreachCategory $category, array $attributes = []): OutreachContact
{
    return OutreachContact::query()->create($attributes + [
        'outreach_category_id' => $category->id,
        'name' => 'Ana Khan',
        'email' => 'ana'.str()->random(4).'@example.com',
        'consent_source' => 'Asked for details at a trade show',
        'consent_at' => now(),
    ]);
}

test('an import row needs a consent source and a valid address', function () {
    $rows = [
        ['name' => 'No Consent', 'email' => 'a@example.com', 'phone' => null, 'consent_source' => null, 'consent_date' => null],
        ['name' => 'Bad Phone', 'email' => null, 'phone' => '0300-1234', 'consent_source' => 'Form', 'consent_date' => null],
        ['name' => 'Nothing', 'email' => null, 'phone' => null, 'consent_source' => 'Form', 'consent_date' => null],
        ['name' => 'Good', 'email' => 'good@example.com', 'phone' => '+923001234567', 'consent_source' => 'Website signup', 'consent_date' => '2026-11-01'],
    ];

    $result = app(OutreachContactImporter::class)->import($this->superAdmin, $this->hvac, $rows);

    expect($result['created'])->toBe(1)
        ->and($result['errors'])->toHaveCount(3)
        ->and(OutreachContact::query()->where('email', 'good@example.com')->value('consent_source'))->toBe('Website signup');
});

test('duplicates are skipped, not merged', function () {
    contact($this->hvac, ['email' => 'dup@example.com']);

    $result = app(OutreachContactImporter::class)->import($this->superAdmin, $this->hvac, [
        ['name' => 'Again', 'email' => 'dup@example.com', 'phone' => null, 'consent_source' => 'Form', 'consent_date' => null],
    ]);

    expect($result)->toMatchArray(['created' => 0, 'skipped' => 1])
        ->and(OutreachContact::query()->where('email', 'dup@example.com')->count())->toBe(1);
});

test('a template must be approved by someone else before it can be sent', function () {
    $author = platformAdmin(PlatformRole::SuperAdmin);
    $service = app(OutreachTemplates::class);
    $template = $service->create($author, ['outreach_category_id' => $this->hvac->id, 'channel' => 'email', 'name' => 'Intro', 'subject' => 'Hi', 'body' => 'Hello']);
    $service->submit($author, $template);

    expect(fn () => $service->approve($author, $template))->toThrow(ValidationException::class, 'Someone else must review this template.');
    expect(fn () => app(OutreachCampaigns::class)->start($this->superAdmin, $template->fresh(), $this->hvac))
        ->toThrow(ValidationException::class, 'Only an approved template can be sent.');
});

test('editing an approved template sends it back for approval', function () {
    $template = approvedEmailTemplate($this->hvac, $this->superAdmin);

    app(OutreachTemplates::class)->update($this->superAdmin, $template, [
        'outreach_category_id' => $this->hvac->id, 'channel' => 'email', 'name' => 'Intro', 'subject' => 'Hi', 'body' => 'A new offer',
    ]);

    expect($template->fresh()->status)->toBe(OutreachTemplateStatus::Draft);
});

test('a campaign sends only to eligible contacts and logs every decision', function () {
    Http::fake(['api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-1'], 200)]);
    $template = approvedEmailTemplate($this->hvac, $this->superAdmin);

    $eligible = contact($this->hvac);
    $noConsent = contact($this->hvac, ['consent_at' => null]);
    $optedOut = contact($this->hvac, ['email_opted_out_at' => now()]);

    $campaign = app(OutreachCampaigns::class)->start($this->superAdmin, $template, $this->hvac);

    $campaign->refresh();
    expect($campaign->status)->toBe(OutreachCampaignStatus::Completed)
        ->and($campaign->sent_count)->toBe(1)
        ->and($campaign->queued_count)->toBe(1);

    expect(OutreachMessage::query()->where('outreach_contact_id', $eligible->id)->value('status'))->toBe(OutreachMessageStatus::Sent)
        ->and(OutreachMessage::query()->where('outreach_contact_id', $noConsent->id)->value('skip_reason'))->toBe('no_consent')
        ->and(OutreachMessage::query()->where('outreach_contact_id', $optedOut->id)->value('skip_reason'))->toBe('opted_out')
        ->and(OutreachMessage::query()->where('outreach_contact_id', $eligible->id)->value('provider_message_id'))->toBe('pm-1');

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['To'] === $eligible->email && str_contains($request['TextBody'], 'To stop these emails'));
});

test('a contact messaged recently on the same channel is not messaged again', function () {
    Http::fake(['api.postmarkapp.com/email' => Http::response(['MessageID' => 'pm-2'], 200)]);
    $template = approvedEmailTemplate($this->hvac, $this->superAdmin);
    $person = contact($this->hvac);

    OutreachMessage::query()->create([
        'outreach_campaign_id' => OutreachCampaign::query()->create(['outreach_template_id' => $template->id, 'outreach_category_id' => $this->hvac->id, 'channel' => 'email', 'status' => 'completed', 'created_by' => $this->superAdmin->id])->id,
        'outreach_contact_id' => $person->id, 'channel' => 'email', 'recipient' => $person->email, 'status' => 'sent', 'sent_at' => now()->subDay(),
    ]);

    app(OutreachCampaigns::class)->start($this->superAdmin, $template, $this->hvac);

    expect(OutreachMessage::query()->where('outreach_contact_id', $person->id)->latest('id')->value('skip_reason'))->toBe('recently_contacted');
    Http::assertNothingSent();
});

test('a provider rejection is logged as failed and not retried', function () {
    Http::fake(['api.postmarkapp.com/email' => Http::response(['Message' => 'bad address'], 422)]);
    $template = approvedEmailTemplate($this->hvac, $this->superAdmin);
    $person = contact($this->hvac);

    $campaign = app(OutreachCampaigns::class)->start($this->superAdmin, $template, $this->hvac);

    expect(OutreachMessage::query()->where('outreach_contact_id', $person->id)->value('status'))->toBe(OutreachMessageStatus::Failed)
        ->and($campaign->fresh()->failed_count)->toBe(1);
    Http::assertSentCount(1);
});

test('a person without the send permission cannot start a campaign', function () {
    $template = approvedEmailTemplate($this->hvac, $this->superAdmin);
    $support = platformAdmin(PlatformRole::SupportAdmin);

    expect(fn () => app(OutreachCampaigns::class)->start($support, $template, $this->hvac))
        ->toThrow(AuthorizationException::class);
});

test('a super admin can give outreach access to another administrator, and take it back', function () {
    $teammate = platformAdmin(PlatformRole::SupportAdmin);
    $template = approvedEmailTemplate($this->hvac, $this->superAdmin);
    $access = app(OutreachAccess::class);

    $access->set($this->superAdmin, $teammate, PlatformPermission::SendOutreach, true);
    expect($teammate->fresh()->hasPlatformPermission(PlatformPermission::SendOutreach))->toBeTrue();

    $access->set($this->superAdmin, $teammate, PlatformPermission::SendOutreach, false);
    expect($teammate->fresh()->hasPlatformPermission(PlatformPermission::SendOutreach))->toBeFalse();

    expect(fn () => $access->set($teammate, $this->superAdmin, PlatformPermission::SendOutreach, true))->toThrow(AuthorizationException::class);
});

test('access can only be granted for outreach permissions', function () {
    $teammate = platformAdmin(PlatformRole::SupportAdmin);

    expect(fn () => app(OutreachAccess::class)->set($this->superAdmin, $teammate, PlatformPermission::ManagePlans, true))
        ->toThrow(ValidationException::class);
});

test('the unsubscribe link works only when signed, and stops email', function () {
    $person = contact($this->hvac);

    $this->get(route('outreach.unsubscribe', ['contact' => $person->id]))->assertForbidden();

    $this->get(URL::signedRoute('outreach.unsubscribe', ['contact' => $person->id]))->assertOk()->assertSee('You are unsubscribed');

    expect($person->fresh()->email_opted_out_at)->not->toBeNull();
});

test('a WhatsApp campaign sends only the approved Meta template, to a consenting number', function () {
    Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]], 200)]);
    $author = platformAdmin(PlatformRole::SuperAdmin);
    $service = app(OutreachTemplates::class);
    $template = $service->create($author, [
        'outreach_category_id' => $this->hvac->id, 'channel' => 'whatsapp', 'name' => 'Intro WA', 'body' => 'Hi {{first_name}}',
        'whatsapp_template_name' => 'hvac_intro', 'whatsapp_language' => 'en',
    ]);
    $service->submit($author, $template);
    $service->approve($this->superAdmin, $template);

    $person = contact($this->hvac, ['email' => null, 'phone' => '+923001234567']);
    $optedOut = contact($this->hvac, ['email' => null, 'phone' => '+923007654321', 'whatsapp_opted_out_at' => now()]);

    app(OutreachCampaigns::class)->start($this->superAdmin, $template, $this->hvac);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['template']['name'] === 'hvac_intro'
        && $request['to'] === '923001234567'
        && $request['messaging_product'] === 'whatsapp');
    expect(OutreachMessage::query()->where('outreach_contact_id', $person->id)->value('provider_message_id'))->toBe('wamid.1')
        ->and(OutreachMessage::query()->where('outreach_contact_id', $optedOut->id)->value('skip_reason'))->toBe('opted_out');
});

test('the volume chart counts only sent messages per day and channel', function () {
    $template = approvedEmailTemplate($this->hvac, $this->superAdmin);
    $campaign = OutreachCampaign::query()->create(['outreach_template_id' => $template->id, 'outreach_category_id' => $this->hvac->id, 'channel' => 'email', 'status' => 'completed', 'created_by' => $this->superAdmin->id]);

    foreach (['sent', 'sent', 'failed'] as $status) {
        OutreachMessage::query()->create(['outreach_campaign_id' => $campaign->id, 'channel' => 'email', 'recipient' => 'x@example.com', 'status' => $status, 'sent_at' => now()]);
    }

    $chart = new OutreachVolumeChart;
    $method = new ReflectionMethod($chart, 'getData');
    $method->setAccessible(true);
    $data = $method->invoke($chart);

    $email = collect($data['datasets'])->firstWhere('label', 'Email')['data'];
    expect(array_sum($email))->toBe(2);
});
