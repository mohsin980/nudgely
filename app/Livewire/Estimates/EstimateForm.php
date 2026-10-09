<?php

namespace App\Livewire\Estimates;

use App\Enums\DiscountType;
use App\Exceptions\Estimates\EstimateException;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\Organization;
use App\Services\Estimates\EstimateCalculator;
use App\Services\Estimates\EstimateService;
use App\Services\Estimates\EstimateTotals;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Create an estimate, or edit a draft: customer → details → items → totals → save or send.
 *
 * Only form values live here. Totals shown while typing come from EstimateService::preview();
 * on save the service validates everything and recalculates the amounts itself.
 */
#[Layout('components.layouts.app')]
class EstimateForm extends Component
{
    #[Locked]
    public ?int $estimateId = null;

    public string $customerId = '';

    #[Locked]
    public string $conversationId = '';

    public string $customerSearch = '';

    public string $title = '';

    public string $notes = '';

    public string $validUntil = '';

    public string $discountType = '';

    public string $discountValue = '';

    public string $taxRate = '';

    /** @var list<array{description: string, quantity: string, unit_price: string}> */
    public array $items = [];

    public function mount(?int $estimateId = null): void
    {
        $organization = $this->organization();

        if ($estimateId !== null) {
            $estimate = $organization->estimates()->whereKey($estimateId)->first() ?? abort(404);
            $this->authorize('update', $estimate);

            if (! $estimate->isDraft()) {
                session()->flash('estimate-status', 'A sent estimate can\'t be changed. Create a revision to make changes.');
                $this->redirectRoute('estimates.show', $estimate->id, navigate: true);

                return;
            }

            $this->estimateId = $estimate->id;
            $this->fillFrom($estimate);

            return;
        }

        $this->authorize('create', Estimate::class);
        // Business defaults (Settings → Estimates) pre-fill a new estimate; anything typed replaces them.
        $defaults = $organization->businessSettings();
        $this->validUntil = $organization->localNow()->addDays($defaults->estimateValidDays())->toDateString();
        $this->notes = $defaults->estimateNotes() ?? '';
        $this->taxRate = $defaults->estimateTaxRate() ?? '';
        $this->items = [$this->blankItem()];

        // Pre-filled from a customer or conversation page; both looked up in this organization.
        if (ctype_digit((string) request()->query('conversation'))) {
            $conversation = $organization->conversations()->find((int) request()->query('conversation'));

            if ($conversation !== null) {
                $this->conversationId = (string) $conversation->id;
                $this->customerId = (string) $conversation->customer_id;
            }
        } elseif (ctype_digit((string) request()->query('customer')) && $organization->customers()->whereKey((int) request()->query('customer'))->exists()) {
            $this->customerId = (string) request()->query('customer');
        }
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return ctype_digit($this->customerId) ? $this->organization()->customers()->find((int) $this->customerId) : null;
    }

    #[Computed]
    public function conversation(): ?Conversation
    {
        return ctype_digit($this->conversationId) ? $this->organization()->conversations()->find((int) $this->conversationId) : null;
    }

    /**
     * Customers matching the search box (existing customers only: this form never creates one).
     *
     * @return Collection<int, Customer>
     */
    #[Computed]
    public function customerMatches(): Collection
    {
        $search = mb_strtolower(trim($this->customerSearch));
        $query = $this->organization()->customers()->select(['id', 'name', 'email'])->orderBy('name')->limit(8);

        if ($search !== '') {
            $query->whereRaw('search_text like ?', ['%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%']);
        }

        return $query->get();
    }

    #[Computed]
    public function totals(): EstimateTotals
    {
        return app(EstimateService::class)->preview($this->input());
    }

    /**
     * Each row's amount for display, or null while the row is incomplete.
     */
    public function lineAmount(int $index): ?string
    {
        $quantity = Money::tryParse($this->items[$index]['quantity'] ?? null);
        $price = Money::tryParse($this->items[$index]['unit_price'] ?? null);

        if (! $quantity || $price === null || $quantity > EstimateCalculator::MAX_QUANTITY || $price > EstimateCalculator::MAX_UNIT_PRICE) {
            return null;
        }

        return Money::format(EstimateCalculator::lineAmount($quantity, $price), $this->currency());
    }

    public function money(int $cents): string
    {
        return Money::format($cents, $this->currency());
    }

    public function selectCustomer(int $customerId): void
    {
        $customer = $this->organization()->customers()->find($customerId) ?? abort(404);
        $this->customerId = (string) $customer->id;
        $this->customerSearch = '';
        // A conversation belongs to one customer; changing the customer unlinks it.
        $this->conversationId = $this->conversation?->customer_id === $customer->id ? $this->conversationId : '';
        unset($this->customer, $this->conversation);
        $this->resetErrorBag('customerId');
    }

    public function clearCustomer(): void
    {
        $this->customerId = '';
        $this->conversationId = '';
        unset($this->customer, $this->conversation);
    }

    public function addItem(): void
    {
        if (count($this->items) < (int) config('estimates.max_items')) {
            $this->items[] = $this->blankItem();
        }
    }

    public function removeItem(int $index): void
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
        $this->resetErrorBag();

        if ($this->items === []) {
            $this->items = [$this->blankItem()];
        }
    }

    public function save(EstimateService $estimates): void
    {
        $estimate = $this->persist($estimates);

        if ($estimate !== null) {
            session()->flash('estimate-status', 'Draft saved.');
            $this->redirectRoute('estimates.show', $estimate->id, navigate: true);
        }
    }

    public function saveAndSend(EstimateService $estimates): void
    {
        $estimate = $this->persist($estimates);

        if ($estimate === null) {
            return;
        }

        try {
            $estimates->send(Auth::user(), $estimate);
            session()->flash('estimate-status', 'Sending estimate…');
        } catch (EstimateException $e) {
            // The draft is saved; the estimate page shows why it wasn't sent.
            session()->flash('estimate-error', $e->getMessage());
        }

        $this->redirectRoute('estimates.show', $estimate->id, navigate: true);
    }

    public function render()
    {
        return view('livewire.estimates.estimate-form', [
            'discountTypes' => DiscountType::cases(),
            'currency' => $this->currency(),
        ])->title($this->estimateId ? 'Edit estimate' : 'New estimate');
    }

    private function persist(EstimateService $estimates): ?Estimate
    {
        $this->resetErrorBag();
        $actor = Auth::user();

        try {
            if ($this->estimateId === null) {
                $this->authorize('create', Estimate::class);

                return $estimates->create($actor, $this->input());
            }

            $estimate = $this->organization()->estimates()->whereKey($this->estimateId)->first() ?? abort(404);
            $this->authorize('update', $estimate);

            return $estimates->update($actor, $estimate, $this->input());
        } catch (EstimateException $e) {
            $fields = ['customer_id' => 'customerId', 'conversation_id' => 'customerId', 'valid_until' => 'validUntil', 'discount_value' => 'discountValue', 'tax_rate' => 'taxRate'];

            if ($e->errors === []) {
                $this->addError('form', $e->getMessage());
            }

            foreach ($e->errors as $field => $message) {
                $this->addError($fields[$field] ?? $field, $message);
            }

            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function input(): array
    {
        return [
            'customer_id' => $this->customerId,
            'conversation_id' => $this->conversationId,
            'title' => $this->title,
            'notes' => $this->notes,
            'valid_until' => $this->validUntil,
            'discount_type' => $this->discountType,
            'discount_value' => $this->discountValue,
            'tax_rate' => $this->taxRate,
            'items' => $this->items,
        ];
    }

    private function fillFrom(Estimate $estimate): void
    {
        $this->customerId = (string) $estimate->customer_id;
        $this->conversationId = (string) ($estimate->conversation_id ?? '');
        $this->title = $estimate->title;
        $this->notes = (string) $estimate->notes;
        $this->validUntil = (string) $estimate->valid_until?->toDateString();
        $this->discountType = $estimate->discount_type?->value ?? '';
        $this->discountValue = $estimate->discount_type === null ? '' : (string) $estimate->discount_value;
        $this->taxRate = $estimate->tax_rate === null ? '' : rtrim(rtrim((string) $estimate->tax_rate, '0'), '.');
        $this->items = $estimate->items()->get()->map(fn ($item) => [
            'description' => $item->description,
            'quantity' => $item->displayQuantity(),
            'unit_price' => (string) $item->unit_price,
        ])->all() ?: [$this->blankItem()];
    }

    /**
     * @return array{description: string, quantity: string, unit_price: string}
     */
    private function blankItem(): array
    {
        return ['description' => '', 'quantity' => '1', 'unit_price' => ''];
    }

    private function currency(): string
    {
        return $this->estimateId ? ($this->organization()->estimates()->whereKey($this->estimateId)->value('currency') ?? 'USD') : $this->organization()->currencyCode();
    }

    private function organization(): Organization
    {
        return Auth::user()->organization ?? abort(403);
    }
}
