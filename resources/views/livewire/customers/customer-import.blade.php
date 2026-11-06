@php($card = 'rounded-2xl bg-white p-5 shadow-[0_8px_30px_-14px_rgba(76,29,149,0.18)] ring-1 ring-slate-200/70 sm:p-6')
@php($badge = 'grid size-12 shrink-0 place-items-center rounded-2xl bg-violet-100 text-violet-600')
@php($primary = 'inline-flex items-center justify-center gap-2 rounded-xl bg-linear-to-r from-violet-600 to-violet-500 px-5 py-2.5 text-[15px] font-semibold text-white shadow-[0_10px_22px_-8px_rgba(109,40,217,0.65)] transition hover:from-violet-700 hover:to-violet-600 disabled:opacity-50')
@php($secondary = 'inline-flex items-center justify-center gap-2 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 shadow-xs ring-1 ring-slate-200 ring-inset hover:bg-slate-50')
@php($select = 'block w-full appearance-none rounded-xl border-0 bg-white py-2.5 pr-10 pl-3.5 text-[15px] text-slate-900 shadow-xs ring-1 ring-slate-200 ring-inset focus:ring-2 focus:ring-violet-600')
@php($step = $result ? 3 : ($headers ? 2 : 1))

<div class="mx-auto max-w-4xl space-y-6">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <a href="{{ route('customers.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-semibold text-violet-700 hover:text-violet-800"><x-ui-icon name="arrow-left" class="size-4 stroke-2" /> Back to Customers</a>
            <h1 class="mt-2 text-3xl font-extrabold tracking-tight text-slate-900">Import customers</h1>
            <p class="mt-1 text-[15px] text-slate-500">Add many customers at once from a CSV file. Customers you already have are skipped, never changed.</p>
        </div>
    </div>

    <ol class="flex flex-wrap items-center gap-2 text-sm font-semibold" aria-label="Steps">
        @foreach (['Upload file', 'Match columns', 'Done'] as $index => $stepLabel)
            <li @class(['inline-flex items-center gap-2 rounded-full px-3.5 py-1.5', 'bg-violet-600 text-white' => $step === $index + 1, 'bg-violet-100 text-violet-700' => $step > $index + 1, 'bg-white text-slate-500 ring-1 ring-slate-200' => $step < $index + 1])
                @if ($step === $index + 1) aria-current="step" @endif>
                <span class="grid size-5 place-items-center rounded-full bg-white/25 text-xs">{{ $step > $index + 1 ? '✓' : $index + 1 }}</span>{{ $stepLabel }}
            </li>
            @unless ($loop->last)<li aria-hidden="true" class="h-px w-6 bg-slate-300"></li>@endunless
        @endforeach
    </ol>

    @if ($result)
        {{-- 3. Result --}}
        <section class="{{ $card }}" aria-labelledby="result-heading" data-section="import-result">
            <div class="flex items-center gap-4">
                <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-emerald-100 text-emerald-600"><x-ui-icon name="check-circle" class="size-7" /></span>
                <h2 id="result-heading" class="text-lg font-bold text-slate-900">{{ trans_choice(':count customer imported|:count customers imported', $result['created']) }}</h2>
            </div>
            <dl class="mt-5 grid gap-3 sm:grid-cols-3">
                <div class="rounded-xl bg-emerald-50 p-4"><dt class="text-sm font-medium text-emerald-800">Imported</dt><dd class="mt-1 text-2xl font-bold text-emerald-900" data-count="created">{{ $result['created'] }}</dd></div>
                <div class="rounded-xl bg-slate-50 p-4"><dt class="text-sm font-medium text-slate-600">Already a customer (skipped)</dt><dd class="mt-1 text-2xl font-bold text-slate-900" data-count="duplicates">{{ $result['duplicates'] }}</dd></div>
                <div class="rounded-xl bg-rose-50 p-4"><dt class="text-sm font-medium text-rose-800">Not imported</dt><dd class="mt-1 text-2xl font-bold text-rose-900" data-count="failed">{{ collect($result['failed'])->where('row', '>', 0)->count() }}</dd></div>
            </dl>
            @if ($result['failed'])
                <div class="mt-5">
                    <h3 class="text-sm font-bold text-slate-900">Rows that need fixing</h3>
                    <p class="text-sm text-slate-500">Fix these in your spreadsheet and import the file again. Rows already imported will be skipped.</p>
                    <ul role="list" class="mt-2 max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-xl ring-1 ring-slate-200" data-section="import-problems">
                        @foreach (array_slice($result['failed'], 0, 100) as $problem)
                            <li class="flex gap-3 px-4 py-2.5 text-sm">
                                @if ($problem['row'] > 0)<span class="shrink-0 font-semibold text-slate-900">Row {{ $problem['row'] }}</span>@endif
                                <span class="text-slate-600">{{ $problem['message'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="mt-6 flex flex-wrap gap-3">
                <a href="{{ route('customers.index') }}" wire:navigate class="{{ $primary }}">View customers <x-ui-icon name="arrow-right" class="size-4 stroke-2" /></a>
                <button type="button" wire:click="startOver" class="{{ $secondary }}">Import another file</button>
            </div>
        </section>
    @elseif (! $headers)
        {{-- 1. Upload --}}
        <section class="{{ $card }}" aria-labelledby="upload-heading">
            <div class="flex items-center gap-4">
                <span class="{{ $badge }}"><x-ui-icon name="download" class="size-6 rotate-180" /></span>
                <div>
                    <h2 id="upload-heading" class="text-lg font-bold text-slate-900">Upload your file</h2>
                    <p class="text-sm text-slate-500">A CSV (UTF-8) with a header row, up to {{ number_format($maxRows) }} customers and 2 MB.</p>
                </div>
            </div>
            <label for="customer-file" class="mt-5 flex cursor-pointer flex-col items-center rounded-2xl border-2 border-dashed border-violet-200 bg-violet-50/40 px-6 py-10 text-center transition hover:border-violet-300 hover:bg-violet-50">
                <span class="grid size-14 place-items-center rounded-full bg-white text-violet-600 shadow-xs ring-1 ring-violet-100"><x-ui-icon name="document" class="size-7" /></span>
                <span class="mt-3 text-[15px] font-bold text-slate-900"><span wire:loading.remove wire:target="file">Choose a CSV file</span><span wire:loading wire:target="file">Reading your file…</span></span>
                <span class="mt-1 text-sm text-slate-500">Columns can be in any order: you match them in the next step.</span>
                <input id="customer-file" type="file" wire:model="file" accept=".csv,text/csv" class="sr-only">
            </label>
            @error('file') <p class="mt-3 rounded-xl bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ $message }}</p> @enderror
            <div class="mt-5 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-600">
                <span>Not sure how to lay it out? Columns like <strong class="font-semibold text-slate-800">First name, Last name, Email, Phone, Company, Notes</strong> work.</span>
                <a href="{{ route('customers.import.sample') }}" class="inline-flex items-center gap-1.5 font-semibold text-violet-700 hover:text-violet-800"><x-ui-icon name="download" class="size-4 stroke-2" /> Download a sample file</a>
            </div>
        </section>
    @else
        {{-- 2. Match columns and preview --}}
        <form wire:submit="import" class="space-y-6">
            <section class="{{ $card }}" aria-labelledby="map-heading">
                <div class="flex items-center gap-4">
                    <span class="{{ $badge }}"><x-ui-icon name="arrows-right-left" class="size-6" /></span>
                    <div>
                        <h2 id="map-heading" class="text-lg font-bold text-slate-900">Match your columns</h2>
                        <p class="text-sm text-slate-500">{{ trans_choice(':count customer found.|:count customers found.', $rowCount) }} We matched the columns we recognised. Check each one.</p>
                    </div>
                </div>
                @error('map') <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-800" role="alert">{{ $message }}</p> @enderror
                <div class="mt-5 grid gap-x-6 gap-y-4 sm:grid-cols-2">
                    @foreach ($fields as $field => $definition)
                        <div>
                            <label for="map-{{ $field }}" class="block text-[15px] font-semibold text-slate-800">{{ $definition['label'] }}</label>
                            <div class="relative mt-1.5">
                                <select id="map-{{ $field }}" wire:model.live="map.{{ $field }}" class="{{ $select }}" data-map="{{ $field }}">
                                    <option value="">Not in my file</option>
                                    @foreach ($headers as $index => $header)
                                        <option value="{{ $index }}">{{ $header !== '' ? $header : 'Column '.($index + 1) }}</option>
                                    @endforeach
                                </select>
                                <x-ui-icon name="chevron-down" class="pointer-events-none absolute top-1/2 right-3.5 size-4 -translate-y-1/2 text-slate-500" />
                            </div>
                        </div>
                    @endforeach
                </div>
                <p class="mt-4 text-sm text-slate-500">Each customer needs a first and last name (or a full name) and an email address.</p>
            </section>

            <section class="{{ $card }}" aria-labelledby="preview-heading">
                <h2 id="preview-heading" class="text-lg font-bold text-slate-900">Preview</h2>
                <p class="text-sm text-slate-500">The first {{ count($preview) }} {{ count($preview) === 1 ? 'row' : 'rows' }}, as they will be imported.</p>
                <div class="mt-4 overflow-x-auto rounded-xl ring-1 ring-slate-200">
                    <table class="min-w-full text-left text-sm" data-section="preview">
                        <thead class="bg-slate-50 text-xs font-semibold tracking-wide text-slate-500 uppercase">
                            <tr>@foreach (['First name', 'Last name', 'Email', 'Phone', 'Company'] as $column)<th scope="col" class="px-4 py-2.5 whitespace-nowrap">{{ $column }}</th>@endforeach</tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($preview as $row)
                                <tr>
                                    @foreach (['first_name', 'last_name', 'email', 'phone', 'company'] as $key)
                                        <td class="px-4 py-2.5 whitespace-nowrap {{ $row[$key] === '' ? 'text-slate-300' : 'text-slate-800' }}">{{ $row[$key] !== '' ? $row[$key] : '—' }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="{{ $card }}">
                <label class="flex items-start gap-3">
                    <input type="checkbox" wire:model="runAutomations" class="mt-1 size-4 rounded border-slate-300 text-violet-600 focus:ring-violet-500" data-run-automations>
                    <span>
                        <span class="block text-[15px] font-semibold text-slate-900">Start automations for these customers</span>
                        <span class="block text-sm text-slate-500">Off by default. When on, automations that run when a customer is added (such as a new lead follow-up) start for every imported customer, and may email them.</span>
                    </span>
                </label>
                <div class="mt-5 flex flex-wrap items-center gap-3 border-t border-slate-100 pt-5">
                    <button type="submit" wire:loading.attr="disabled" wire:target="import" class="{{ $primary }}">
                        <span wire:loading.remove wire:target="import">{{ trans_choice('Import :count customer|Import :count customers', $rowCount) }}</span>
                        <span wire:loading wire:target="import">Importing…</span>
                    </button>
                    <button type="button" wire:click="startOver" class="{{ $secondary }}">Choose another file</button>
                </div>
            </section>
        </form>
    @endif
</div>
