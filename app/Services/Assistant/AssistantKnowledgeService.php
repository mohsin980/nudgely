<?php

namespace App\Services\Assistant;

use App\Enums\Team\Permission;
use App\Models\AssistantFaq;
use App\Models\AssistantProfile;
use App\Models\CatalogItem;
use App\Models\Organization;
use App\Models\OrganizationActivity;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What the AI WhatsApp assistant knows about a business: its profile, common questions, and catalog.
 * Only people who manage the business profile may change it. Everything is plain text the assistant reads;
 * none of it is ever run or shown as HTML.
 */
class AssistantKnowledgeService
{
    public const MAX_FAQS = 50;

    public const MAX_CATALOG_ITEMS = 2000;

    /**
     * The business's assistant profile, or an unsaved one with the defaults.
     */
    public function profile(Organization $organization): AssistantProfile
    {
        return $organization->assistantProfile()->first()
            ?? (new AssistantProfile)->forceFill(['organization_id' => $organization->id, 'assistant_name' => 'Assistant', 'languages' => 'en,ur']);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function saveProfile(User $actor, array $input): AssistantProfile
    {
        $organization = $this->organizationOf($actor);

        $data = Validator::make($input, [
            'assistant_name' => ['required', 'string', 'max:60'],
            'languages' => ['required', 'array', 'min:1'],
            'languages.*' => [Rule::in(array_keys(AssistantProfile::LANGUAGES))],
            'about' => ['required', 'string', 'max:3000'],
            'service_area' => ['nullable', 'string', 'max:500'],
            'hours' => ['nullable', 'string', 'max:500'],
            'qualifying_questions' => ['nullable', 'string', 'max:2000'],
            'handover_rules' => ['nullable', 'string', 'max:2000'],
        ], [
            'about.required' => 'Describe your business in a few sentences. The assistant uses this to answer customers.',
            'languages.required' => 'Choose at least one language.',
            'languages.min' => 'Choose at least one language.',
        ], [
            'assistant_name' => 'assistant name',
        ])->validate();

        $profile = $this->profile($organization);
        $profile->forceFill([
            'assistant_name' => trim($data['assistant_name']),
            // Kept in a fixed order, whatever order they were ticked in.
            'languages' => implode(',', array_values(array_intersect(array_keys(AssistantProfile::LANGUAGES), $data['languages']))),
            'about' => trim($data['about']),
            'service_area' => $this->blankToNull($data['service_area'] ?? null),
            'hours' => $this->blankToNull($data['hours'] ?? null),
            'qualifying_questions' => $this->blankToNull($data['qualifying_questions'] ?? null),
            'handover_rules' => $this->blankToNull($data['handover_rules'] ?? null),
        ])->save();

        OrganizationActivity::record($organization, 'assistant_profile_updated', $actor);

        return $profile;
    }

    /**
     * Add a question and answer, or change one ($id).
     *
     * @param  array{question?: string, answer?: string}  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function saveFaq(User $actor, array $input, ?int $id = null): AssistantFaq
    {
        $organization = $this->organizationOf($actor);
        $faq = $id === null ? new AssistantFaq : $organization->assistantFaqs()->findOrFail($id);

        $data = Validator::make($input, [
            'question' => ['required', 'string', 'max:300'],
            'answer' => ['required', 'string', 'max:2000'],
        ])->validate();

        if ($id === null && $organization->assistantFaqs()->count() >= self::MAX_FAQS) {
            throw ValidationException::withMessages(['question' => 'You can add up to '.self::MAX_FAQS.' questions. Remove one you no longer need first.']);
        }

        $faq->forceFill(['organization_id' => $organization->id, 'question' => trim($data['question']), 'answer' => trim($data['answer'])])->save();

        return $faq;
    }

    /**
     * @throws AuthorizationException
     */
    public function deleteFaq(User $actor, int $id): void
    {
        $this->organizationOf($actor)->assistantFaqs()->findOrFail($id)->delete();
    }

    /**
     * Add an item, or change one ($id).
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorizationException|ValidationException
     */
    public function saveCatalogItem(User $actor, array $input, ?int $id = null): CatalogItem
    {
        $organization = $this->organizationOf($actor);
        $item = $id === null ? new CatalogItem : $organization->catalogItems()->findOrFail($id);

        if ($id === null && $organization->catalogItems()->count() >= self::MAX_CATALOG_ITEMS) {
            throw ValidationException::withMessages(['name' => 'Your catalog can hold up to '.number_format(self::MAX_CATALOG_ITEMS).' items.']);
        }

        $data = $this->validateCatalogItem($input);

        // Names are unique in a catalog: the CSV import updates items by name, and the assistant names them to customers.
        if ($organization->catalogItems()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->when($id !== null, fn ($query) => $query->whereKeyNot($id))->exists()) {
            throw ValidationException::withMessages(['name' => 'Your catalog already has an item with this name.']);
        }

        $this->fillCatalogItem($item, $organization, $data);

        return $item;
    }

    /**
     * @throws AuthorizationException
     */
    public function deleteCatalogItem(User $actor, int $id): void
    {
        $this->organizationOf($actor)->catalogItems()->findOrFail($id)->delete();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, category: ?string, price: ?string, details: ?string, is_available: bool}
     *
     * @throws ValidationException
     */
    public function validateCatalogItem(array $input): array
    {
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:100'],
            'price' => ['nullable', 'string', 'max:100'],
            'details' => ['nullable', 'string', 'max:2000'],
            'is_available' => ['boolean'],
        ])->validate();

        return [
            'name' => trim($data['name']),
            'category' => $this->blankToNull($data['category'] ?? null),
            'price' => $this->blankToNull($data['price'] ?? null),
            'details' => $this->blankToNull($data['details'] ?? null),
            'is_available' => (bool) ($data['is_available'] ?? true),
        ];
    }

    /**
     * @param  array{name: string, category: ?string, price: ?string, details: ?string, is_available: bool}  $data
     */
    public function fillCatalogItem(CatalogItem $item, Organization $organization, array $data): void
    {
        $item->forceFill(['organization_id' => $organization->id] + $data)->save();
    }

    /**
     * What the assistant still needs before it can answer customers well.
     *
     * @return list<array{label: string, done: bool, hint: string}>
     */
    public function readiness(Organization $organization): array
    {
        return [
            ['label' => 'Describe your business', 'done' => filled($organization->assistantProfile()->value('about')), 'hint' => 'In the Business profile tab.'],
            ['label' => 'Add questions or your catalog', 'done' => $organization->assistantFaqs()->exists() || $organization->catalogItems()->exists(), 'hint' => 'At least a few common questions, or what you sell.'],
            ['label' => 'Connect WhatsApp', 'done' => (bool) $organization->whatsappConnection()->first()?->isActive(), 'hint' => 'In Settings → WhatsApp.'],
        ];
    }

    /**
     * @throws AuthorizationException
     */
    public function organizationOf(User $actor): Organization
    {
        if (! $actor->hasPermission(Permission::ManageBusinessProfile) || $actor->organization === null) {
            throw new AuthorizationException('Only the owner or an admin can change what the assistant knows.');
        }

        return $actor->organization;
    }

    private function blankToNull(?string $value): ?string
    {
        return trim((string) $value) === '' ? null : trim($value);
    }
}
