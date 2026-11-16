<?php

use App\Enums\Platform\PlatformPermission;
use App\Enums\Platform\PlatformRole;
use App\Filament\Pages\Outreach\OutreachCategoriesPage;
use App\Filament\Pages\Outreach\OutreachContactsPage;
use App\Filament\Pages\Outreach\OutreachTemplatesPage;
use App\Models\OutreachCategory;
use App\Models\OutreachContact;
use App\Services\Outreach\OutreachTemplates;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

test('a super admin can open the categories page and create a category', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin);

    Livewire::actingAs($admin)->test(OutreachCategoriesPage::class)
        ->callAction('create', ['name' => 'Cafe', 'slug' => 'cafe', 'description' => 'Coffee shops', 'is_active' => true])
        ->assertHasNoActionErrors();

    expect(OutreachCategory::query()->where('slug', 'cafe')->exists())->toBeTrue();
});

test('a person with view access only can see the categories but cannot change them', function () {
    $viewer = platformAdmin(PlatformRole::SupportAdmin);
    $viewer->givePermissionTo(PlatformPermission::ViewOutreach->value);
    OutreachCategory::query()->create(['name' => 'HVAC', 'slug' => 'hvac', 'is_active' => true]);

    Livewire::actingAs($viewer)->test(OutreachCategoriesPage::class)
        ->assertSee('HVAC')
        ->assertActionHidden('create');
});

test('a template is submitted by one admin and approved by a super admin from the page', function () {
    $author = platformAdmin(PlatformRole::SupportAdmin);
    $author->givePermissionTo([PlatformPermission::ViewOutreach->value, PlatformPermission::ManageOutreachTemplates->value]);
    $superAdmin = platformAdmin(PlatformRole::SuperAdmin);
    $category = OutreachCategory::query()->create(['name' => 'HVAC', 'slug' => 'hvac', 'is_active' => true]);

    $template = app(OutreachTemplates::class)->create($author, [
        'outreach_category_id' => $category->id, 'channel' => 'email', 'name' => 'Intro', 'subject' => 'Hi', 'body' => 'We help HVAC firms.',
    ]);

    Livewire::actingAs($author)->test(OutreachTemplatesPage::class)
        ->callTableAction('submit', $template)
        ->assertHasNoTableActionErrors();
    expect($template->fresh()->status->value)->toBe('pending');

    Livewire::actingAs($superAdmin)->test(OutreachTemplatesPage::class)
        ->callTableAction('approve', $template);
    expect($template->fresh()->status->value)->toBe('approved');
});

test('the CSV import adds contacts and reports rows that need fixing', function () {
    $admin = platformAdmin(PlatformRole::SuperAdmin);
    $category = OutreachCategory::query()->create(['name' => 'Cafe', 'slug' => 'cafe', 'is_active' => true]);
    Storage::fake('local');
    Storage::disk('local')->put('outreach-imports/contacts.csv',
        "Name,Email,Phone,Consent source,Consent date\n"
        ."Ana,ana@example.com,+923001234567,Website signup,2026-11-01\n"
        ."Bo,bo@example.com,,,\n");

    Livewire::actingAs($admin)->test(OutreachContactsPage::class)
        ->callAction('import', ['outreach_category_id' => $category->id, 'file' => ['outreach-imports/contacts.csv']]);

    expect(OutreachContact::query()->where('email', 'ana@example.com')->exists())->toBeTrue()
        ->and(OutreachContact::query()->where('email', 'bo@example.com')->exists())->toBeFalse();
});

test('the stats and logs pages open for someone who can view outreach', function () {
    $viewer = platformAdmin(PlatformRole::SupportAdmin);
    $viewer->givePermissionTo(PlatformPermission::ViewOutreach->value);

    $this->actingAs($viewer)->get('/admin/outreach/stats')->assertOk();
    $this->actingAs($viewer)->get('/admin/outreach/logs')->assertOk();
});

test('the access page is only open to super admins', function () {
    $support = platformAdmin(PlatformRole::SupportAdmin);
    $superAdmin = platformAdmin(PlatformRole::SuperAdmin);

    $this->actingAs($support)->get('/admin/outreach/access')->assertForbidden();
    $this->actingAs($superAdmin)->get('/admin/outreach/access')->assertOk();
});
