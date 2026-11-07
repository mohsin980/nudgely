<?php

namespace App\Livewire\Settings;

use App\Enums\Billing\LimitKey;
use App\Livewire\Settings\Concerns\SettingsPage;
use App\Models\AssistantProfile;
use App\Services\Assistant\AssistantKnowledgeService;
use App\Services\Assistant\CatalogImportService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\UsageService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → AI Assistant: what the WhatsApp assistant knows about the business (profile, common questions
 * and catalog). Changing it is limited to people who manage the business profile; the route and the
 * service check that too.
 */
#[Layout('components.layouts.app')]
#[Title('AI Assistant')]
class AiAssistant extends Component
{
    use SettingsPage;
    use WithFileUploads;

    public const TABS = ['profile', 'faqs', 'catalog'];

    #[Url]
    public string $tab = 'profile';

    // On/off
    public bool $enabled = false;

    public string $ownerWhatsapp = '';

    // Business profile
    public string $assistantName = 'Assistant';

    /** @var list<string> */
    public array $languages = ['en', 'ur'];

    public string $about = '';

    public string $serviceArea = '';

    public string $hours = '';

    public string $qualifyingQuestions = '';

    public string $handoverRules = '';

    // Questions & answers
    public ?int $editingFaq = null;

    public string $question = '';

    public string $answer = '';

    // Catalog
    #[Url(as: 'q')]
    public string $search = '';

    public ?int $editingItem = null;

    public bool $itemFormOpen = false;

    public string $itemName = '';

    public string $itemCategory = '';

    public string $itemPrice = '';

    public string $itemDetails = '';

    public bool $itemAvailable = true;

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /** @var array{created: int, updated: int, failed: list<array{row: int, message: string}>}|null */
    public ?array $importResult = null;

    public function mount(AssistantKnowledgeService $knowledge): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'profile';
        $profile = $knowledge->profile($this->organization());

        $this->assistantName = (string) $profile->assistant_name;
        $this->languages = $profile->languageList();
        $this->about = (string) $profile->about;
        $this->serviceArea = (string) $profile->service_area;
        $this->hours = (string) $profile->hours;
        $this->qualifyingQuestions = (string) $profile->qualifying_questions;
        $this->handoverRules = (string) $profile->handover_rules;
        $this->enabled = (bool) $profile->enabled;
        $this->ownerWhatsapp = (string) $profile->owner_whatsapp;
    }

    public function saveSwitch(AssistantKnowledgeService $knowledge): void
    {
        $this->attempt(function () use ($knowledge) {
            $profile = $knowledge->saveSwitch($this->user(), ['enabled' => $this->enabled, 'owner_whatsapp' => $this->ownerWhatsapp]);
            $this->ownerWhatsapp = (string) $profile->owner_whatsapp;
            $this->saved($profile->enabled ? 'The AI assistant is on. It answers new WhatsApp chats from now on.' : 'The AI assistant is off.');
        }, ['owner_whatsapp' => 'ownerWhatsapp']);

        if ($this->getErrorBag()->has('enabled')) {
            $this->enabled = false;
        }
    }

    public function updatedTab(): void
    {
        $this->tab = in_array($this->tab, self::TABS, true) ? $this->tab : 'profile';
        $this->statusMessage = null;
    }

    public function saveProfile(AssistantKnowledgeService $knowledge): void
    {
        $this->attempt(function () use ($knowledge) {
            $knowledge->saveProfile($this->user(), [
                'assistant_name' => $this->assistantName,
                'languages' => $this->languages,
                'about' => $this->about,
                'service_area' => $this->serviceArea,
                'hours' => $this->hours,
                'qualifying_questions' => $this->qualifyingQuestions,
                'handover_rules' => $this->handoverRules,
            ]);
            $this->saved('Assistant profile saved.');
        }, ['assistant_name' => 'assistantName', 'service_area' => 'serviceArea', 'qualifying_questions' => 'qualifyingQuestions', 'handover_rules' => 'handoverRules']);
    }

    public function editFaq(int $id): void
    {
        $faq = $this->organization()->assistantFaqs()->findOrFail($id);
        [$this->editingFaq, $this->question, $this->answer] = [$faq->id, $faq->question, $faq->answer];
        $this->resetErrorBag();
    }

    public function cancelFaq(): void
    {
        $this->reset('editingFaq', 'question', 'answer');
        $this->resetErrorBag();
    }

    public function saveFaq(AssistantKnowledgeService $knowledge): void
    {
        $this->attempt(function () use ($knowledge) {
            $knowledge->saveFaq($this->user(), ['question' => $this->question, 'answer' => $this->answer], $this->editingFaq);
            $this->saved($this->editingFaq ? 'Question updated.' : 'Question added.');
            $this->reset('editingFaq', 'question', 'answer');
        });
    }

    public function deleteFaq(AssistantKnowledgeService $knowledge, int $id): void
    {
        $this->attempt(function () use ($knowledge, $id) {
            $knowledge->deleteFaq($this->user(), $id);
            $this->cancelFaq();
            $this->saved('Question removed.');
        });
    }

    public function newItem(): void
    {
        $this->reset('editingItem', 'itemName', 'itemCategory', 'itemPrice', 'itemDetails', 'itemAvailable');
        $this->itemFormOpen = true;
        $this->resetErrorBag();
    }

    public function editItem(int $id): void
    {
        $item = $this->organization()->catalogItems()->findOrFail($id);
        $this->editingItem = $item->id;
        $this->itemName = $item->name;
        $this->itemCategory = (string) $item->category;
        $this->itemPrice = (string) $item->price;
        $this->itemDetails = (string) $item->details;
        $this->itemAvailable = $item->is_available;
        $this->itemFormOpen = true;
        $this->resetErrorBag();
    }

    public function cancelItem(): void
    {
        $this->reset('editingItem', 'itemFormOpen', 'itemName', 'itemCategory', 'itemPrice', 'itemDetails', 'itemAvailable');
        $this->resetErrorBag();
    }

    public function saveItem(AssistantKnowledgeService $knowledge): void
    {
        $this->attempt(function () use ($knowledge) {
            $knowledge->saveCatalogItem($this->user(), [
                'name' => $this->itemName, 'category' => $this->itemCategory, 'price' => $this->itemPrice,
                'details' => $this->itemDetails, 'is_available' => $this->itemAvailable,
            ], $this->editingItem);
            $this->saved($this->editingItem ? 'Item updated.' : 'Item added to your catalog.');
            $this->cancelItem();
        }, ['name' => 'itemName', 'category' => 'itemCategory', 'price' => 'itemPrice', 'details' => 'itemDetails']);
    }

    public function deleteItem(AssistantKnowledgeService $knowledge, int $id): void
    {
        $this->attempt(function () use ($knowledge, $id) {
            $knowledge->deleteCatalogItem($this->user(), $id);
            $this->cancelItem();
            $this->saved('Item removed from your catalog.');
        });
    }

    public function importCatalog(CatalogImportService $importer): void
    {
        $this->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:'.intdiv(CatalogImportService::MAX_BYTES, 1024)]], [], ['file' => 'CSV file']);

        try {
            $this->importResult = $importer->import($this->user(), $this->file->getRealPath());
            $this->saved('Catalog imported.');
        } catch (RuntimeException $e) {
            $this->addError('file', $e->getMessage());
        } catch (AuthorizationException $e) {
            $this->failed($e->getMessage());
        } finally {
            $this->file?->delete();
            $this->file = null;
        }
    }

    public function downloadSample(): StreamedResponse
    {
        return response()->streamDownload(fn () => print (CatalogImportService::sample()), 'catalog-sample.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function render(AssistantKnowledgeService $knowledge): View
    {
        $organization = $this->organization();
        $search = trim($this->search);

        $entitlements = app(EntitlementService::class);

        return view('livewire.settings.ai-assistant', [
            'readiness' => $knowledge->readiness($organization),
            'planIncludesAssistant' => $entitlements->hasFeature($organization, 'ai_assistant'),
            'aiLimit' => $entitlements->limit($organization, LimitKey::AiConversations),
            'aiUsed' => app(UsageService::class)->usage($organization, LimitKey::AiConversations),
            'isOn' => (bool) $organization->assistantProfile()->value('enabled'),
            'faqs' => $this->tab === 'faqs' ? $organization->assistantFaqs()->orderBy('id')->get() : collect(),
            'faqCount' => $organization->assistantFaqs()->count(),
            'items' => $this->tab === 'catalog'
                ? $organization->catalogItems()
                    ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->where('name', 'like', '%'.addcslashes($search, '%_\\').'%')->orWhere('category', 'like', '%'.addcslashes($search, '%_\\').'%')))
                    ->orderBy('category')->orderBy('name')->limit(200)->get()
                : collect(),
            'itemCount' => $organization->catalogItems()->count(),
            'languageOptions' => AssistantProfile::LANGUAGES,
        ]);
    }

    /**
     * Run a change and show its outcome: field errors next to the fields, a refusal as the page message.
     *
     * @param  array<string, string>  $map  service field => component property
     */
    private function attempt(Closure $change, array $map = []): void
    {
        try {
            $change();
        } catch (ValidationException $e) {
            $this->showErrors($e, $map);
        } catch (AuthorizationException $e) {
            $this->failed($e->getMessage());
        }
    }
}
