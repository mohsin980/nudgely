<?php

namespace App\Livewire\Settings;

use App\Livewire\Settings\Concerns\SettingsPage;
use App\Models\LeadAdsLead;
use App\Models\LeadAdsPage;
use App\Models\User;
use App\Services\LeadAds\LeadAdsService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use RuntimeException;

/**
 * Settings → Lead Ads: connect the Facebook Pages whose lead forms (on Facebook and Instagram) should create
 * customers, choose who handles them, and see the latest leads.
 */
#[Layout('components.layouts.app')]
#[Title('Lead Ads')]
class LeadAdsSettings extends Component
{
    use SettingsPage;

    /** Pages the latest Facebook sign-in can connect: id, name, connected_elsewhere. Tokens never reach the page. */
    #[Locked]
    public array $choices = [];

    #[Locked]
    public string $choiceKey = '';

    /** @var list<string> */
    public array $selected = [];

    /** @var array<int, array{assign_to: string, whatsapp_consent: bool}> */
    public array $pageSettings = [];

    public function mount(): void
    {
        $this->authorize('manage-email');
        $this->fillPageSettings();
    }

    /**
     * Called by the "Connect Facebook Page" button with the short-lived token Facebook's sign-in returned.
     */
    public function loadPages(string $userToken): void
    {
        if (! $this->allowed('load')) {
            return;
        }

        try {
            $result = app(LeadAdsService::class)->pagesFor($this->user(), $userToken);
        } catch (AuthorizationException|RuntimeException $e) {
            $this->failed($e->getMessage());

            return;
        }

        $this->choiceKey = $result['key'];
        $this->choices = $result['pages'];
        $this->selected = collect($result['pages'])->reject(fn ($page) => $page['connected_elsewhere'])->pluck('id')->take(1)->all();
        $this->statusMessage = null;
    }

    public function connectPages(): void
    {
        if (! $this->allowed('connect')) {
            return;
        }

        try {
            $pages = app(LeadAdsService::class)->connect($this->user(), $this->choiceKey, $this->selected);
        } catch (ValidationException $e) {
            $this->failed(collect($e->errors())->flatten()->first());

            return;
        } catch (AuthorizationException|RuntimeException $e) {
            $this->failed($e->getMessage());

            return;
        }

        $this->reset('choices', 'choiceKey', 'selected');
        $this->fillPageSettings();
        $this->saved(count($pages) === 1
            ? '“'.$pages[0]->page_name.'” is connected. New leads from its forms will appear in Customers.'
            : count($pages).' Pages connected. New leads from their forms will appear in Customers.');
    }

    public function cancelChoice(): void
    {
        $this->reset('choices', 'choiceKey', 'selected');
    }

    public function savePage(int $pageId): void
    {
        $settings = $this->pageSettings[$pageId] ?? null;

        if ($settings === null) {
            return;
        }

        try {
            app(LeadAdsService::class)->updatePage($this->user(), $pageId, $settings['assign_to'] !== '' ? (int) $settings['assign_to'] : null, (bool) $settings['whatsapp_consent']);
            $this->saved('Saved.');
        } catch (ValidationException $e) {
            $this->failed(collect($e->errors())->flatten()->first());
        } catch (AuthorizationException $e) {
            $this->failed($e->getMessage());
        }
    }

    public function disconnect(int $pageId): void
    {
        try {
            app(LeadAdsService::class)->disconnect($this->user(), $pageId);
            $this->fillPageSettings();
            $this->saved('Page disconnected. Its leads stop coming in; the customers already added stay.');
        } catch (AuthorizationException $e) {
            $this->failed($e->getMessage());
        }
    }

    public function render(): View
    {
        $organization = $this->organization();

        return view('livewire.settings.lead-ads-settings', [
            'organization' => $organization,
            'pages' => LeadAdsPage::query()->where('organization_id', $organization->id)->withCount('leads')->orderBy('page_name')->get(),
            'leads' => LeadAdsLead::query()->where('organization_id', $organization->id)->with('customer:id,name', 'page:id,page_name')->latest('id')->limit(20)->get(),
            'team' => User::query()->activeIn($organization->id)->orderBy('name')->get(['id', 'name']),
            'facebook' => LeadAdsService::enabled() ? [
                'appId' => (string) config('services.whatsapp.embedded.app_id'),
                'version' => (string) config('services.whatsapp.graph_version'),
                'configId' => (string) config('services.whatsapp.lead_ads_config_id'),
                'scope' => implode(',', LeadAdsService::SCOPES),
            ] : null,
            'webhookUrl' => route('webhooks.meta-leads'),
        ]);
    }

    private function fillPageSettings(): void
    {
        $this->pageSettings = LeadAdsPage::query()->where('organization_id', $this->organization()->id)->get()
            ->mapWithKeys(fn (LeadAdsPage $page) => [$page->id => ['assign_to' => (string) ($page->assign_to ?? ''), 'whatsapp_consent' => $page->whatsapp_consent]])
            ->all();
    }

    private function allowed(string $action): bool
    {
        $key = "lead-ads-{$action}:".$this->organization()->id;

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->failed('Too many attempts. Please wait a minute and try again.');

            return false;
        }

        RateLimiter::hit($key, 60);

        return true;
    }
}
