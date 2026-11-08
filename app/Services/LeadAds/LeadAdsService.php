<?php

namespace App\Services\LeadAds;

use App\Enums\Billing\LimitKey;
use App\Enums\CustomerStatus;
use App\Enums\MessageChannel;
use App\Enums\Team\Permission;
use App\Events\CustomerCreated;
use App\Exceptions\Billing\PlanLimitException;
use App\Jobs\ImportLeadAdJob;
use App\Models\Customer;
use App\Models\LeadAdsLead;
use App\Models\LeadAdsPage;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Team\ActivityNotifications;
use App\Services\WhatsApp\WhatsAppConnectionService;
use App\Support\PhoneNumber;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Facebook and Instagram Lead Ads, through QuoteFlow's own Meta app.
 *
 * Connecting: the owner signs in with Facebook in the browser; the server checks that sign-in belongs to
 * QuoteFlow's app, lists the Pages it may use, and subscribes the chosen ones to new leads. Page tokens stay on
 * the server (encrypted). Receiving: Meta's webhook says a lead exists (ID only); a job fetches its answers with
 * the Page token and turns it into a customer (or finds the existing one by email or phone), which starts the
 * business's "customer created" automations and notifies the team. A scheduled sync picks up anything a webhook
 * missed. Every lead is stored once by Meta's lead ID.
 */
class LeadAdsService
{
    /** Asked for when no Facebook Login for Business configuration is set. */
    public const SCOPES = ['pages_show_list', 'pages_read_engagement', 'pages_manage_metadata', 'pages_manage_ads', 'leads_retrieval'];

    /** Lead fields with ad details; some Pages only allow the basic set. */
    private const LEAD_FIELDS = 'created_time,field_data,form_id,ad_id,ad_name,platform,is_organic';

    private const BASIC_LEAD_FIELDS = 'created_time,field_data,form_id,platform';

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly ActivityNotifications $notifications,
    ) {}

    public static function enabled(): bool
    {
        return WhatsAppConnectionService::isMetaId(config('services.whatsapp.embedded.app_id')) && filled(config('services.whatsapp.embedded.app_secret'));
    }

    // ─── Connecting Pages ────────────────────────────────────────────────────────────────

    /**
     * Check a Facebook sign-in and list the Pages it can manage. The tokens are kept in the cache for ten minutes
     * under a random key; only the key and the Pages' names and IDs go back to the browser.
     *
     * @return array{key: string, pages: list<array{id: string, name: string, connected_elsewhere: bool}>}
     *
     * @throws AuthorizationException|RuntimeException
     */
    public function pagesFor(User $actor, string $userToken): array
    {
        $organization = $this->organizationOf($actor);
        $this->assertEnabled();

        if ($userToken === '' || strlen($userToken) > 2000 || preg_match('/\s/', $userToken)) {
            throw new RuntimeException('Facebook did not return a valid sign-in. Try again.');
        }

        // The token must have been issued to QuoteFlow's app (not pasted from somewhere else) and still be valid.
        $debug = $this->request(fn () => $this->graph($this->appToken())->get('debug_token', ['input_token' => $userToken]), 'Facebook did not confirm the sign-in');

        if (! $debug->json('data.is_valid') || (string) $debug->json('data.app_id') !== (string) config('services.whatsapp.embedded.app_id')) {
            throw new RuntimeException('That Facebook sign-in is not for QuoteFlow. Try again.');
        }

        // A long-lived user token gives Page tokens that don't expire.
        $longLived = $this->request(fn () => $this->graph(null)->get('oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.whatsapp.embedded.app_id'),
            'client_secret' => config('services.whatsapp.embedded.app_secret'),
            'fb_exchange_token' => $userToken,
        ]), 'Facebook did not extend the sign-in')->json('access_token');

        $accounts = $this->request(fn () => $this->graph(is_string($longLived) && $longLived !== '' ? $longLived : $userToken)
            ->get('me/accounts', ['fields' => 'id,name,access_token', 'limit' => 100]), 'Facebook did not list your Pages');

        $pages = collect((array) $accounts->json('data'))
            ->filter(fn ($page) => is_array($page) && preg_match('/^\d{5,40}$/', (string) ($page['id'] ?? '')) && filled($page['access_token'] ?? null))
            ->map(fn (array $page) => ['id' => (string) $page['id'], 'name' => mb_substr((string) ($page['name'] ?? 'Facebook Page'), 0, 191), 'token' => (string) $page['access_token']])
            ->values();

        if ($pages->isEmpty()) {
            throw new RuntimeException('No Facebook Pages were shared. Connect again and tick the Page that runs your lead ads.');
        }

        $key = Str::random(40);
        Cache::put($this->cacheKey($organization->id, $key), $pages->all(), now()->addMinutes(10));

        $elsewhere = LeadAdsPage::query()->whereIn('page_id', $pages->pluck('id'))->where('organization_id', '!=', $organization->id)->pluck('page_id')->all();

        return ['key' => $key, 'pages' => $pages->map(fn ($page) => ['id' => $page['id'], 'name' => $page['name'], 'connected_elsewhere' => in_array($page['id'], $elsewhere, true)])->all()];
    }

    /**
     * Subscribe the chosen Pages to new leads and save them.
     *
     * @param  list<string>  $pageIds
     * @return list<LeadAdsPage>
     *
     * @throws AuthorizationException|ValidationException|RuntimeException
     */
    public function connect(User $actor, string $key, array $pageIds): array
    {
        $organization = $this->organizationOf($actor);
        $this->assertEnabled();

        $available = collect(Cache::get($this->cacheKey($organization->id, $key), []))->keyBy('id');

        if ($available->isEmpty()) {
            throw new RuntimeException('The Facebook sign-in has expired. Connect again.');
        }

        $chosen = collect($pageIds)->map(fn ($id) => (string) $id)->unique()->filter(fn ($id) => $available->has($id))->values();

        if ($chosen->isEmpty()) {
            throw ValidationException::withMessages(['pages' => 'Choose at least one Page.']);
        }

        $taken = LeadAdsPage::query()->whereIn('page_id', $chosen)->where('organization_id', '!=', $organization->id)->exists();

        if ($taken) {
            throw ValidationException::withMessages(['pages' => 'One of these Pages is already connected to another business.']);
        }

        $connected = [];

        foreach ($chosen as $id) {
            $page = $available[$id];
            $this->request(fn () => $this->graph($page['token'])->post($id.'/subscribed_apps', ['subscribed_fields' => 'leadgen']),
                'Facebook did not let QuoteFlow receive leads for “'.$page['name'].'”');

            $record = LeadAdsPage::query()->where('page_id', $id)->first() ?? new LeadAdsPage;
            $record->forceFill([
                'page_id' => $id,
                'organization_id' => $organization->id,
                'page_name' => $page['name'],
                'page_access_token' => $page['token'],
                'status' => LeadAdsPage::STATUS_ACTIVE,
                'last_error' => null,
                'connected_by' => $actor->id,
                'last_synced_at' => $record->last_synced_at ?? now(),
            ])->save();

            OrganizationActivity::record($organization, 'lead_ads_page_connected', $actor, ['page_id' => $id, 'page_name' => $page['name']]);
            $connected[] = $record;
        }

        Cache::forget($this->cacheKey($organization->id, $key));
        Log::info('Lead Ads Pages connected.', ['organization_id' => $organization->id, 'count' => count($connected)]);

        return $connected;
    }

    /**
     * Who handles new leads from a Page, and whether its form says people will be contacted on WhatsApp.
     *
     * @throws AuthorizationException|ValidationException
     */
    public function updatePage(User $actor, int $pageId, ?int $assignTo, bool $whatsappConsent): LeadAdsPage
    {
        $organization = $this->organizationOf($actor);
        $page = LeadAdsPage::query()->where('organization_id', $organization->id)->findOrFail($pageId);

        if ($assignTo !== null && ! User::query()->activeIn($organization->id)->whereKey($assignTo)->exists()) {
            throw ValidationException::withMessages(['assign_to' => 'Choose an active member of your team.']);
        }

        $page->forceFill(['assign_to' => $assignTo, 'whatsapp_consent' => $whatsappConsent])->save();
        OrganizationActivity::record($organization, 'lead_ads_page_updated', $actor, ['page_id' => $page->page_id, 'assign_to' => $assignTo, 'whatsapp_consent' => $whatsappConsent]);

        return $page;
    }

    /**
     * @throws AuthorizationException
     */
    public function disconnect(User $actor, int $pageId): void
    {
        $organization = $this->organizationOf($actor);
        $page = LeadAdsPage::query()->where('organization_id', $organization->id)->findOrFail($pageId);

        try {
            $this->graph($page->page_access_token)->delete($page->page_id.'/subscribed_apps');
        } catch (ConnectionException) {
            // Meta unreachable: leads for a Page we no longer know are ignored anyway.
        }

        $page->delete();
        OrganizationActivity::record($organization, 'lead_ads_page_disconnected', $actor, ['page_id' => $page->page_id, 'page_name' => $page->page_name]);
    }

    // ─── Receiving leads ────────────────────────────────────────────────────────────────

    /**
     * A lead exists (from the webhook or the sync). Stored once per Meta lead ID, then imported by a job.
     */
    public function receive(string $pageId, string $leadgenId, ?string $formId = null, ?string $adId = null): ?LeadAdsLead
    {
        if (! preg_match('/^\d{5,40}$/', $leadgenId)) {
            return null;
        }

        $page = LeadAdsPage::query()->where('page_id', $pageId)->first();

        if ($page === null) {
            return null;
        }

        if ($existing = LeadAdsLead::query()->where('leadgen_id', $leadgenId)->first()) {
            return $existing;
        }

        $lead = new LeadAdsLead;
        $lead->forceFill([
            'leadgen_id' => $leadgenId,
            'organization_id' => $page->organization_id,
            'lead_ads_page_id' => $page->id,
            'form_id' => $formId && preg_match('/^\d{1,40}$/', $formId) ? $formId : null,
            'ad_id' => $adId && preg_match('/^\d{1,40}$/', $adId) ? $adId : null,
            'status' => LeadAdsLead::STATUS_PENDING,
        ]);

        try {
            $lead->save();
        } catch (UniqueConstraintViolationException) {
            // Delivered twice at the same moment: the other delivery stored it.
            return LeadAdsLead::query()->where('leadgen_id', $leadgenId)->first();
        }

        ImportLeadAdJob::dispatch($lead->id)->afterCommit();

        return $lead;
    }

    /**
     * Fetch a lead's answers and turn it into a customer. Safe to run again: only pending leads are imported.
     */
    public function import(int $leadId): ?LeadAdsLead
    {
        $lead = LeadAdsLead::query()->with('page')->find($leadId);

        if ($lead === null || $lead->status !== LeadAdsLead::STATUS_PENDING) {
            return $lead;
        }

        $organization = Organization::query()->find($lead->organization_id);
        $page = $lead->page;

        // Paused while the business is suspended; the sync picks it up after reactivation.
        if ($organization === null || $page === null || $organization->isSuspended()) {
            return $lead;
        }

        $lead->increment('attempts');

        try {
            $data = $this->fetchLead($page, $lead->leadgen_id);
        } catch (RuntimeException $e) {
            return $this->failed($lead, $e->getMessage(), final: $lead->attempts >= 5);
        }

        $answers = $this->answers((array) ($data['field_data'] ?? []));
        $formName = $lead->form_name ?? $this->formName($page, (string) ($data['form_id'] ?? $lead->form_id));

        $lead->forceFill([
            'form_id' => $lead->form_id ?? (isset($data['form_id']) ? (string) $data['form_id'] : null),
            'form_name' => $formName,
            'ad_id' => $lead->ad_id ?? (isset($data['ad_id']) ? (string) $data['ad_id'] : null),
            'ad_name' => isset($data['ad_name']) ? mb_substr((string) $data['ad_name'], 0, 191) : null,
            'platform' => in_array($data['platform'] ?? null, ['fb', 'ig'], true) ? $data['platform'] : null,
            'submitted_at' => isset($data['created_time']) ? rescue(fn () => now()->parse((string) $data['created_time']), null, false) : null,
        ]);

        try {
            [$customer, $created] = $this->customerFor($organization, $page, $lead, $answers);
        } catch (PlanLimitException) {
            return $this->failed($lead, 'Your plan\'s customer limit is reached, so this lead was not added. Upgrade to add it.', final: true);
        }

        $lead->forceFill([
            'customer_id' => $customer->id,
            'status' => $created ? LeadAdsLead::STATUS_IMPORTED : LeadAdsLead::STATUS_MATCHED,
            'error' => null,
        ])->save();
        $page->forceFill(['last_lead_at' => now()])->save();

        if ($created) {
            CustomerCreated::dispatch($organization->id, $customer->id);
        }

        $this->notifications->adLead($lead, $customer);
        Log::info('Lead ad imported.', ['organization_id' => $organization->id, 'lead_ads_lead_id' => $lead->id, 'customer_id' => $customer->id, 'new' => $created]);

        return $lead;
    }

    /**
     * Safety net for webhooks Meta did not deliver: list recent leads of each connected Page's forms, and
     * retry leads that are still pending.
     */
    public function sync(): int
    {
        $found = 0;

        LeadAdsPage::query()->where('status', LeadAdsPage::STATUS_ACTIVE)
            ->whereNotIn('organization_id', Organization::suspendedIds())
            ->orderBy('id')->each(function (LeadAdsPage $page) use (&$found) {
                $since = ($page->last_synced_at ?? now()->subDay())->copy()->subHour()->max(now()->subDays(2));
                $started = now();

                try {
                    foreach ($this->forms($page) as $form) {
                        $leads = $this->request(fn () => $this->graph($page->page_access_token)->get($form['id'].'/leads', [
                            'fields' => 'id,created_time',
                            'limit' => 100,
                            'filtering' => json_encode([['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since->getTimestamp()]]),
                        ]), 'Facebook did not list the form\'s leads');

                        foreach ((array) $leads->json('data') as $row) {
                            $id = (string) ($row['id'] ?? '');

                            if ($id !== '' && ! LeadAdsLead::query()->where('leadgen_id', $id)->exists() && $this->receive($page->page_id, $id, $form['id']) !== null) {
                                $found++;
                            }
                        }
                    }

                    $page->forceFill(['last_synced_at' => $started, 'status' => LeadAdsPage::STATUS_ACTIVE, 'last_error' => null])->save();
                } catch (RuntimeException $e) {
                    $page->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();
                    Log::warning('Lead Ads sync failed for a Page.', ['organization_id' => $page->organization_id, 'lead_ads_page_id' => $page->id]);
                }
            });

        LeadAdsLead::query()->where('status', LeadAdsLead::STATUS_PENDING)->where('updated_at', '<', now()->subMinutes(10))
            ->whereNotIn('organization_id', Organization::suspendedIds())
            ->orderBy('id')->limit(200)->pluck('id')
            ->each(fn (int $id) => ImportLeadAdJob::dispatch($id));

        return $found;
    }

    // ─── Turning answers into a customer ────────────────────────────────────────────────

    /**
     * Meta's answers as name => first value, with standard question names kept as Meta sends them.
     *
     * @param  array<int, mixed>  $fieldData
     * @return array<string, string>
     */
    public function answers(array $fieldData): array
    {
        $answers = [];

        foreach ($fieldData as $field) {
            $name = is_array($field) ? Str::limit(trim((string) ($field['name'] ?? '')), 100, '') : '';
            $value = is_array($field) ? trim((string) (((array) ($field['values'] ?? []))[0] ?? '')) : '';

            if ($name !== '' && $value !== '') {
                $answers[$name] = Str::limit($value, 1000, '');
            }
        }

        return $answers;
    }

    /**
     * @param  array<string, string>  $answers
     * @return array{0: Customer, 1: bool}
     *
     * @throws PlanLimitException
     */
    private function customerFor(Organization $organization, LeadAdsPage $page, LeadAdsLead $lead, array $answers): array
    {
        $email = Str::lower($answers['email'] ?? $answers['work_email'] ?? '');
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 254 ? $email : null;
        $phone = $answers['phone_number'] ?? $answers['phone'] ?? $answers['work_phone_number'] ?? null;
        $e164 = $phone ? PhoneNumber::toE164($phone, $organization->country ?? 'US') : null;

        $existing = ($email || $e164) ? Customer::query()->where('organization_id', $organization->id)
            ->where(fn ($query) => $query
                ->when($email, fn ($q) => $q->orWhere('email', $email))
                ->when($e164, fn ($q) => $q->orWhere('phone_e164', $e164)))
            ->orderBy('id')->first() : null;

        if ($existing !== null) {
            $existing->forceFill([
                'notes' => $this->appendNotes($existing->notes, $this->noteFor($lead, $answers)),
                'whatsapp_opt_in_at' => $existing->whatsapp_opt_in_at ?? ($page->whatsapp_consent && $existing->phone_e164 ? now() : null),
                'whatsapp_opt_in_source' => $existing->whatsapp_opt_in_source ?? ($page->whatsapp_consent && $existing->phone_e164 ? 'lead_ad' : null),
            ])->save();

            return [$existing, false];
        }

        $customer = $this->entitlements->guard($organization, LimitKey::Customers, function () use ($organization, $page, $lead, $answers, $email, $phone, $e164) {
            [$first, $last] = [$answers['first_name'] ?? null, $answers['last_name'] ?? null];
            $name = $answers['full_name'] ?? trim(($first ?? '').' '.($last ?? ''));
            $consent = $page->whatsapp_consent && $e164 !== null;
            $assignee = $page->assign_to && User::query()->activeIn($organization->id)->whereKey($page->assign_to)->exists() ? $page->assign_to : null;

            $customer = new Customer;
            $customer->forceFill([
                'organization_id' => $organization->id,
                'name' => mb_substr($name !== '' ? $name : ($email ?? $phone ?? 'Lead from '.$lead->platformLabel()), 0, 200),
                'first_name' => $first ? mb_substr($first, 0, 100) : null,
                'last_name' => $last ? mb_substr($last, 0, 100) : null,
                'email' => $email,
                'phone' => $phone ? mb_substr($phone, 0, 32) : null,
                'company' => isset($answers['company_name']) ? mb_substr($answers['company_name'], 0, 255) : null,
                'notes' => $this->noteFor($lead, $answers),
                'status' => CustomerStatus::Active,
                'preferred_channel' => $consent ? MessageChannel::WhatsApp : MessageChannel::Email,
                'whatsapp_opt_in_at' => $consent ? now() : null,
                'whatsapp_opt_in_source' => $consent ? 'lead_ad' : null,
                'assigned_to' => $assignee,
            ])->save();

            return $customer;
        });

        return [$customer, true];
    }

    /**
     * "Lead from Facebook ad “Spring offer” (form “Free quote”), 8 Nov 2026" plus every answer that isn't
     * already a customer field.
     *
     * @param  array<string, string>  $answers
     */
    private function noteFor(LeadAdsLead $lead, array $answers): string
    {
        $standard = ['full_name', 'first_name', 'last_name', 'email', 'work_email', 'phone_number', 'phone', 'work_phone_number', 'company_name'];
        $lines = ['Lead from '.$lead->platformLabel().' ad'.($lead->ad_name ? ' “'.$lead->ad_name.'”' : '').($lead->form_name ? ' (form “'.$lead->form_name.'”)' : '').', '.$lead->organization->formatDate(now()).'.'];

        foreach (array_diff_key($answers, array_flip($standard)) as $question => $answer) {
            $lines[] = Str::headline($question).': '.$answer;
        }

        return implode("\n", $lines);
    }

    private function appendNotes(?string $notes, string $note): string
    {
        return Str::limit(trim(($notes ? $notes."\n\n" : '').$note), 10000, '');
    }

    // ─── Meta ───────────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    private function fetchLead(LeadAdsPage $page, string $leadgenId): array
    {
        try {
            $response = $this->graph($page->page_access_token)->get($leadgenId, ['fields' => self::LEAD_FIELDS]);

            if (! $response->successful()) {
                // Ad details need extra access on some Pages: the answers alone are enough.
                $response = $this->graph($page->page_access_token)->get($leadgenId, ['fields' => self::BASIC_LEAD_FIELDS]);
            }
        } catch (ConnectionException) {
            throw new RuntimeException('Facebook could not be reached. It will be tried again.');
        }

        if (! $response->successful()) {
            $error = 'Facebook did not share this lead: '.$this->metaError($response);

            if (in_array($response->status(), [400, 401, 403], true) && $response->json('error.code') === 190) {
                $page->forceFill(['status' => LeadAdsPage::STATUS_FAILED, 'last_error' => 'Facebook access for this Page has expired. Connect it again.'])->save();
            }

            throw new RuntimeException($error);
        }

        return (array) $response->json();
    }

    /**
     * @return list<array{id: string, name: string}>
     *
     * @throws RuntimeException
     */
    private function forms(LeadAdsPage $page): array
    {
        $response = $this->request(fn () => $this->graph($page->page_access_token)->get($page->page_id.'/leadgen_forms', ['fields' => 'id,name', 'limit' => 100]),
            'Facebook did not list the Page\'s lead forms');

        return collect((array) $response->json('data'))
            ->filter(fn ($form) => is_array($form) && preg_match('/^\d{1,40}$/', (string) ($form['id'] ?? '')))
            ->map(fn (array $form) => ['id' => (string) $form['id'], 'name' => (string) ($form['name'] ?? '')])
            ->values()->all();
    }

    private function formName(LeadAdsPage $page, string $formId): ?string
    {
        if (! preg_match('/^\d{1,40}$/', $formId)) {
            return null;
        }

        return Cache::remember("lead-ads-form:{$formId}", now()->addDay(), function () use ($page, $formId) {
            try {
                $response = $this->graph($page->page_access_token)->get($formId, ['fields' => 'name']);
            } catch (ConnectionException) {
                return null;
            }

            return $response->successful() && is_string($response->json('name')) ? mb_substr($response->json('name'), 0, 191) : null;
        });
    }

    private function failed(LeadAdsLead $lead, string $error, bool $final): LeadAdsLead
    {
        $lead->forceFill(['status' => $final ? LeadAdsLead::STATUS_FAILED : LeadAdsLead::STATUS_PENDING, 'error' => mb_substr($error, 0, 500)])->save();
        Log::warning('Lead ad not imported.', ['organization_id' => $lead->organization_id, 'lead_ads_lead_id' => $lead->id, 'final' => $final]);

        return $lead;
    }

    private function graph(?string $token): PendingRequest
    {
        $request = Http::baseUrl(rtrim((string) config('services.whatsapp.graph_url'), '/').'/'.config('services.whatsapp.graph_version'))
            ->acceptJson()
            ->timeout((int) config('services.whatsapp.timeout', 10));

        return $token === null ? $request : $request->withToken($token);
    }

    private function appToken(): string
    {
        return config('services.whatsapp.embedded.app_id').'|'.config('services.whatsapp.embedded.app_secret');
    }

    /**
     * @param  \Closure(): Response  $call
     *
     * @throws RuntimeException
     */
    private function request(\Closure $call, string $failure): Response
    {
        try {
            $response = $call();
        } catch (ConnectionException) {
            throw new RuntimeException('Facebook could not be reached. Try again in a minute.');
        }

        if (! $response->successful()) {
            throw new RuntimeException($failure.': '.$this->metaError($response));
        }

        return $response;
    }

    private function metaError(Response $response): string
    {
        $message = $response->json('error.error_user_msg') ?: $response->json('error.message');

        return is_string($message) && $message !== '' ? Str::limit($message, 300) : 'error (HTTP '.$response->status().')';
    }

    private function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new RuntimeException('Lead Ads isn\'t set up on this server yet.');
        }
    }

    private function cacheKey(int $organizationId, string $key): string
    {
        return "lead-ads-pages:{$organizationId}:".hash('sha256', $key);
    }

    /**
     * @throws AuthorizationException
     */
    private function organizationOf(User $actor): Organization
    {
        if (! $actor->hasPermission(Permission::ManageEmail) || $actor->organization === null) {
            throw new AuthorizationException('Only the business owner can connect Facebook lead ads.');
        }

        return $actor->organization;
    }
}
