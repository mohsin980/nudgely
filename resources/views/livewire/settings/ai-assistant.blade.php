@php
    $card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6';
    $badge = 'grid size-12 shrink-0 place-items-center rounded-2xl';
    $field = 'mt-1.5 block w-full rounded-xl border-0 bg-white px-3.5 py-2.5 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset placeholder:text-slate-400 focus:ring-2 focus:ring-violet-600 focus:ring-inset';
    $label = 'block text-[15px] font-semibold text-slate-800';
    $help = 'mt-1 text-xs text-slate-500';
    $error = 'mt-1 text-sm text-red-700';
    $primaryButton = 'inline-flex items-center justify-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-2.5 text-sm font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 disabled:opacity-50';
    $secondaryButton = 'inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50 disabled:opacity-50';
    $iconButton = 'grid size-9 place-items-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-800';
    $done = collect($readiness)->where('done', true)->count();
@endphp
<x-settings.shell title="AI Assistant" description="Teach your WhatsApp assistant about your business. It answers new leads, asks your questions and brings you in when needed.">
    @include('livewire.settings.partials.status')

    {{-- What the assistant still needs --}}
    <section class="{{ $card }}" aria-labelledby="ready-heading" data-assistant-readiness>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex items-center gap-4">
                <span class="{{ $badge }} bg-violet-100 text-violet-600"><x-ui-icon name="sparkles" class="size-6" /></span>
                <div>
                    <h2 id="ready-heading" class="text-lg font-bold text-slate-900">Get your assistant ready</h2>
                    <p class="text-sm text-slate-500">It introduces itself as an AI assistant and only answers from what you add here.</p>
                </div>
            </div>
            <span class="rounded-full bg-violet-50 px-3 py-1 text-sm font-semibold text-violet-700 ring-1 ring-violet-600/20 ring-inset">{{ $done }} of {{ count($readiness) }} done</span>
        </div>
        <ol class="mt-5 grid gap-3 sm:grid-cols-3">
            @foreach ($readiness as $step)
                <li @class(['flex items-start gap-3 rounded-xl p-3.5 ring-1 ring-inset', 'bg-emerald-50/60 ring-emerald-100' => $step['done'], 'bg-slate-50 ring-slate-200/70' => ! $step['done']]) data-ready-step="{{ $step['done'] ? 'done' : 'todo' }}">
                    @if ($step['done'])
                        <x-ui-icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-emerald-600" />
                    @else
                        <span class="mt-0.5 size-5 shrink-0 rounded-full ring-2 ring-slate-300 ring-inset" aria-hidden="true"></span>
                    @endif
                    <span>
                        <span class="block text-sm font-semibold text-slate-900">{{ $step['label'] }}<span class="sr-only">{{ $step['done'] ? ' (done)' : ' (to do)' }}</span></span>
                        <span class="block text-xs text-slate-500">{{ $step['hint'] }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
        <p class="mt-4 text-xs text-slate-500">Switching the assistant on for WhatsApp comes in the next update. Everything you add here is used from then on.</p>
    </section>

    {{-- Tabs --}}
    <nav aria-label="Assistant sections" class="flex gap-1 overflow-x-auto rounded-2xl bg-white p-1.5 ring-1 ring-slate-200/70">
        @foreach (['profile' => ['building', 'Business profile', 'Profile', null], 'faqs' => ['chat', 'Questions & answers', 'Q&A', $faqCount], 'catalog' => ['cube', 'Catalog', 'Catalog', $itemCount]] as $value => [$icon, $text, $short, $count])
            <button type="button" wire:click="$set('tab', '{{ $value }}')" @if ($tab === $value) aria-current="page" @endif data-tab="{{ $value }}"
                @class(['inline-flex flex-1 items-center justify-center gap-1.5 whitespace-nowrap rounded-xl px-2.5 py-2.5 sm:gap-2 sm:px-4 text-sm font-semibold transition', 'bg-violet-600 text-white shadow-[0_8px_18px_-8px_rgba(109,40,217,0.8)]' => $tab === $value, 'text-slate-600 hover:bg-slate-50' => $tab !== $value])>
                <x-ui-icon :name="$icon" class="hidden size-4 sm:block" /><span class="sm:hidden">{{ $short }}</span><span class="hidden sm:inline">{{ $text }}</span>
                @if ($count !== null)
                    <span @class(['rounded-full px-2 py-0.5 text-xs', 'bg-white/20' => $tab === $value, 'bg-slate-100 text-slate-600' => $tab !== $value])>{{ number_format($count) }}</span>
                @endif
            </button>
        @endforeach
    </nav>

    {{-- 1. Business profile --}}
    @if ($tab === 'profile')
        <form wire:submit="saveProfile" class="{{ $card }} space-y-5" aria-label="Business profile">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="assistant-name" class="{{ $label }}">Assistant name</label>
                    <input id="assistant-name" type="text" wire:model="assistantName" maxlength="60" class="{{ $field }}" placeholder="e.g. Sara">
                    <p class="{{ $help }}">"Hi, I'm Sara, the AI assistant for your business."</p>
                    @error('assistantName') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <fieldset>
                    <legend class="{{ $label }}">Languages</legend>
                    <div class="mt-2.5 flex flex-wrap gap-2">
                        @foreach ($languageOptions as $code => $name)
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-xl px-3.5 py-2 text-sm font-semibold ring-1 ring-slate-200 ring-inset has-checked:bg-violet-50 has-checked:text-violet-700 has-checked:ring-violet-300">
                                <input type="checkbox" wire:model="languages" value="{{ $code }}" class="size-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500">{{ $name }}
                            </label>
                        @endforeach
                    </div>
                    <p class="{{ $help }}">It replies in the customer's language when it's one of these.</p>
                    @error('languages') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                </fieldset>
            </div>
            <div>
                <label for="assistant-about" class="{{ $label }}">About your business</label>
                <textarea id="assistant-about" wire:model="about" rows="4" maxlength="3000" class="{{ $field }}" placeholder="e.g. We are a real estate agency in Lahore selling plots and houses in DHA and Bahria Town. We help buyers find the right property within their budget."></textarea>
                @error('about') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <label for="assistant-area" class="{{ $label }}">Where you work <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="assistant-area" type="text" wire:model="serviceArea" maxlength="500" class="{{ $field }}" placeholder="e.g. Lahore: DHA, Bahria Town, Gulberg">
                    @error('serviceArea') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="assistant-hours" class="{{ $label }}">Opening hours <span class="font-normal text-slate-400">(optional)</span></label>
                    <input id="assistant-hours" type="text" wire:model="hours" maxlength="500" class="{{ $field }}" placeholder="e.g. Mon–Sat 10am–7pm">
                    @error('hours') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
            </div>
            <div>
                <label for="assistant-questions" class="{{ $label }}">What to ask new leads <span class="font-normal text-slate-400">(optional)</span></label>
                <textarea id="assistant-questions" wire:model="qualifyingQuestions" rows="3" maxlength="2000" class="{{ $field }}" placeholder="e.g. Budget, preferred area, plot size, buying for living or investment, when they want to buy."></textarea>
                <p class="{{ $help }}">The assistant asks these one at a time, so you get serious leads with the details you need.</p>
                @error('qualifyingQuestions') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <div>
                <label for="assistant-handover" class="{{ $label }}">When to bring you in <span class="font-normal text-slate-400">(optional)</span></label>
                <textarea id="assistant-handover" wire:model="handoverRules" rows="3" maxlength="2000" class="{{ $field }}" placeholder="e.g. When they want a site visit, ask for a discount, or are ready to pay a token."></textarea>
                <p class="{{ $help }}">The assistant also hands over whenever a customer asks for a person.</p>
                @error('handoverRules') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
            </div>
            <div class="flex justify-end"><button type="submit" class="{{ $primaryButton }}">Save profile</button></div>
        </form>
    @endif

    {{-- 2. Questions & answers --}}
    @if ($tab === 'faqs')
        <section class="{{ $card }}" aria-labelledby="faq-heading">
            <div class="flex items-center gap-4">
                <span class="{{ $badge }} bg-sky-100 text-sky-600"><x-ui-icon name="chat" class="size-6" /></span>
                <div>
                    <h2 id="faq-heading" class="text-lg font-bold text-slate-900">Questions customers ask</h2>
                    <p class="text-sm text-slate-500">Add the answers you'd give. The assistant uses them in its own words.</p>
                </div>
            </div>

            <form wire:submit="saveFaq" class="mt-5 space-y-4 rounded-2xl bg-slate-50/70 p-4 ring-1 ring-slate-200/70" aria-label="{{ $editingFaq ? 'Edit question' : 'Add a question' }}">
                <div>
                    <label for="faq-question" class="{{ $label }}">Question</label>
                    <input id="faq-question" type="text" wire:model="question" maxlength="300" class="{{ $field }}" placeholder="e.g. Do you offer installments?">
                    @error('question') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="faq-answer" class="{{ $label }}">Answer</label>
                    <textarea id="faq-answer" wire:model="answer" rows="3" maxlength="2000" class="{{ $field }}" placeholder="e.g. Yes, on most plots: 25% down payment, the rest over 2 years."></textarea>
                    @error('answer') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    @if ($editingFaq)
                        <button type="button" wire:click="cancelFaq" class="{{ $secondaryButton }}">Cancel</button>
                    @endif
                    <button type="submit" class="{{ $primaryButton }}"><x-ui-icon :name="$editingFaq ? 'check' : 'plus'" class="size-4" />{{ $editingFaq ? 'Save changes' : 'Add question' }}</button>
                </div>
            </form>

            @if ($faqs->isEmpty())
                <p class="mt-5 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">No questions yet. Start with the three customers ask most: prices, payment options, and where you are.</p>
            @else
                <ul role="list" class="mt-5 divide-y divide-slate-100">
                    @foreach ($faqs as $faq)
                        <li wire:key="faq-{{ $faq->id }}" class="flex items-start gap-3 py-4" data-faq>
                            <div class="min-w-0 flex-1">
                                <p class="font-semibold text-slate-900">{{ $faq->question }}</p>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ $faq->answer }}</p>
                            </div>
                            <button type="button" wire:click="editFaq({{ $faq->id }})" class="{{ $iconButton }}" aria-label="Edit {{ $faq->question }}"><x-ui-icon name="pencil" class="size-4" /></button>
                            <button type="button" wire:click="deleteFaq({{ $faq->id }})" wire:confirm="Remove this question?" class="{{ $iconButton }} hover:text-rose-600" aria-label="Remove {{ $faq->question }}"><x-ui-icon name="trash" class="size-4" /></button>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    {{-- 3. Catalog --}}
    @if ($tab === 'catalog')
        <section class="{{ $card }}" aria-labelledby="catalog-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <span class="{{ $badge }} bg-amber-100 text-amber-600"><x-ui-icon name="cube" class="size-6" /></span>
                    <div>
                        <h2 id="catalog-heading" class="text-lg font-bold text-slate-900">Your catalog</h2>
                        <p class="text-sm text-slate-500">Products, services or properties, with prices. The assistant suggests what fits each lead.</p>
                    </div>
                </div>
                <button type="button" wire:click="newItem" class="{{ $primaryButton }} shrink-0 self-start sm:self-auto"><x-ui-icon name="plus" class="size-4" />Add item</button>
            </div>

            @if ($itemFormOpen)
                <form wire:submit="saveItem" class="mt-5 grid gap-4 rounded-2xl bg-slate-50/70 p-4 ring-1 ring-slate-200/70 sm:grid-cols-2" aria-label="{{ $editingItem ? 'Edit item' : 'Add an item' }}">
                    <div class="sm:col-span-2">
                        <label for="item-name" class="{{ $label }}">Name</label>
                        <input id="item-name" type="text" wire:model="itemName" maxlength="200" class="{{ $field }}" placeholder="e.g. 5 Marla plot, DHA Phase 5">
                        @error('itemName') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="item-category" class="{{ $label }}">Category <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="item-category" type="text" wire:model="itemCategory" maxlength="100" class="{{ $field }}" placeholder="e.g. Plots">
                        @error('itemCategory') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="item-price" class="{{ $label }}">Price <span class="font-normal text-slate-400">(optional)</span></label>
                        <input id="item-price" type="text" wire:model="itemPrice" maxlength="100" class="{{ $field }}" placeholder="e.g. 1.5 Cr, or $120 per visit">
                        @error('itemPrice') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="item-details" class="{{ $label }}">Details <span class="font-normal text-slate-400">(optional)</span></label>
                        <textarea id="item-details" wire:model="itemDetails" rows="2" maxlength="2000" class="{{ $field }}" placeholder="e.g. Corner plot near the park. Possession ready."></textarea>
                        @error('itemDetails') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                    </div>
                    <label class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 sm:col-span-2">
                        <input type="checkbox" wire:model="itemAvailable" class="size-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500"> Available now
                    </label>
                    <div class="flex justify-end gap-2 sm:col-span-2">
                        <button type="button" wire:click="cancelItem" class="{{ $secondaryButton }}">Cancel</button>
                        <button type="submit" class="{{ $primaryButton }}">{{ $editingItem ? 'Save changes' : 'Add item' }}</button>
                    </div>
                </form>
            @endif

            @if ($itemCount > 0)
                <div class="mt-5">
                    <label for="catalog-search" class="sr-only">Search the catalog</label>
                    <div class="relative sm:max-w-sm">
                        <x-ui-icon name="search" class="pointer-events-none absolute top-1/2 left-3.5 size-4 -translate-y-1/2 text-slate-400" />
                        <input id="catalog-search" type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name or category" class="{{ str_replace('mt-1.5 ', '', $field) }} pl-10">
                    </div>
                </div>
            @endif

            @if ($items->isEmpty())
                <p class="mt-5 rounded-xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                    {{ $itemCount > 0 ? 'Nothing matches your search.' : 'Your catalog is empty. Add items one by one, or import a CSV file below.' }}
                </p>
            @else
                <div class="mt-4 overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs font-semibold tracking-wide text-slate-500 uppercase">
                            <tr><th scope="col" class="py-2 pr-3">Item</th><th scope="col" class="hidden px-3 py-2 sm:table-cell">Category</th><th scope="col" class="px-3 py-2">Price</th><th scope="col" class="hidden px-3 py-2 sm:table-cell">Status</th><th scope="col" class="py-2 pl-3"><span class="sr-only">Actions</span></th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($items as $item)
                                <tr wire:key="item-{{ $item->id }}" data-catalog-item>
                                    <td class="py-3 pr-3">
                                        <p class="font-semibold text-slate-900">{{ $item->name }}</p>
                                        @if ($item->details)<p class="line-clamp-1 text-xs text-slate-500">{{ $item->details }}</p>@endif
                                        <p class="mt-1 text-xs text-slate-500 sm:hidden">{{ $item->category ?? 'No category' }} · <span @class(['font-semibold', 'text-emerald-700' => $item->is_available, 'text-slate-500' => ! $item->is_available])>{{ $item->is_available ? 'Available' : 'Unavailable' }}</span></p>
                                    </td>
                                    <td class="hidden px-3 py-3 text-slate-600 sm:table-cell">{{ $item->category ?? '—' }}</td>
                                    <td class="px-3 py-3 font-semibold whitespace-nowrap text-slate-900">{{ $item->price ?? '—' }}</td>
                                    <td class="hidden px-3 py-3 sm:table-cell">
                                        <span @class(['inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset', 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' => $item->is_available, 'bg-slate-100 text-slate-600 ring-slate-500/20' => ! $item->is_available])>{{ $item->is_available ? 'Available' : 'Unavailable' }}</span>
                                    </td>
                                    <td class="py-3 pl-1 sm:pl-3">
                                        <div class="flex justify-end">
                                            <button type="button" wire:click="editItem({{ $item->id }})" class="{{ $iconButton }}" aria-label="Edit {{ $item->name }}"><x-ui-icon name="pencil" class="size-4" /></button>
                                            <button type="button" wire:click="deleteItem({{ $item->id }})" wire:confirm="Remove this item from your catalog?" class="{{ $iconButton }} hover:text-rose-600" aria-label="Remove {{ $item->name }}"><x-ui-icon name="trash" class="size-4" /></button>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ($items->count() >= 200)
                    <p class="mt-2 text-xs text-slate-500">Showing the first 200 items. Search to find others.</p>
                @endif
            @endif
        </section>

        <form wire:submit="importCatalog" class="{{ $card }}" aria-labelledby="catalog-import-heading">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="flex min-w-0 items-center gap-4">
                    <span class="{{ $badge }} bg-emerald-100 text-emerald-600"><x-ui-icon name="download" class="size-6" /></span>
                    <div>
                        <h2 id="catalog-import-heading" class="text-lg font-bold text-slate-900">Import from a CSV file</h2>
                        <p class="text-sm text-slate-500">Columns: Name, Category, Price, Details, Available. Items with the same name are updated.</p>
                    </div>
                </div>
                <button type="button" wire:click="downloadSample" class="{{ $secondaryButton }} shrink-0 self-start sm:self-auto"><x-ui-icon name="download" class="size-4" />Sample file</button>
            </div>
            <div class="mt-5 flex flex-wrap items-end gap-3">
                <div class="min-w-0 flex-1">
                    <label for="catalog-file" class="{{ $label }}">CSV file</label>
                    <input id="catalog-file" type="file" wire:model="file" accept=".csv,text/csv" class="mt-1.5 block w-full text-sm text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-violet-50 file:px-3.5 file:py-2 file:text-sm file:font-semibold file:text-violet-700 hover:file:bg-violet-100">
                    @error('file') <p role="alert" class="{{ $error }}">{{ $message }}</p> @enderror
                </div>
                <button type="submit" class="{{ $primaryButton }}" wire:loading.attr="disabled" wire:target="file,importCatalog">Import</button>
            </div>
            @if ($importResult)
                <div class="mt-4 rounded-xl bg-slate-50 p-4 text-sm ring-1 ring-slate-200/70" data-import-result>
                    <p class="font-semibold text-slate-900">{{ trans_choice(':count item added|:count items added', $importResult['created']) }}, {{ trans_choice(':count updated|:count updated', $importResult['updated']) }}@if ($importResult['failed']), {{ trans_choice(':count row skipped|:count rows skipped', count($importResult['failed'])) }}@endif.</p>
                    @if ($importResult['failed'])
                        <ul role="list" class="mt-2 space-y-1 text-rose-700">
                            @foreach (array_slice($importResult['failed'], 0, 10) as $failure)
                                <li>Row {{ $failure['row'] }}: {{ $failure['message'] }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </form>
    @endif
</x-settings.shell>
