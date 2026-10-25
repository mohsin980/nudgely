@php($card = 'rounded-lg border border-slate-200 bg-white p-5 shadow-sm sm:p-6')
@php($input = 'mt-1 block w-full rounded-lg border-0 px-3 py-2 text-sm text-slate-900 ring-1 ring-slate-300 ring-inset focus:ring-2 focus:ring-violet-600')
@php($primary = 'inline-flex items-center justify-center rounded-lg bg-violet-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-violet-700 disabled:opacity-50')
@php($secondary = 'inline-flex items-center justify-center rounded-lg bg-white px-4 py-2.5 text-sm font-medium text-slate-700 ring-1 ring-slate-300 ring-inset hover:bg-slate-50')

<div class="mx-auto max-w-4xl space-y-6">
    <div class="space-y-2">
        <a href="{{ route('estimates.index') }}" wire:navigate class="text-sm font-medium text-violet-700 hover:underline">&larr; Estimates</a>
        <h1 class="text-2xl font-semibold tracking-tight text-slate-900">Import estimates from a CSV</h1>
        <p class="text-sm text-slate-600">Each row becomes a draft estimate. <strong class="font-semibold text-slate-900">Nothing is emailed</strong>, and no follow-ups start, until you activate them.</p>
    </div>

    @if (! $import)
        <section class="{{ $card }}" aria-labelledby="upload-heading">
            <h2 id="upload-heading" class="text-base font-semibold text-slate-900">1. Upload your file</h2>
            <p class="mt-1 text-sm text-slate-600">Export your estimates from your current software as a CSV (UTF-8). Columns can be in any order; you match them in the next step.</p>
            <form wire:submit="upload" class="mt-4 space-y-4">
                <div>
                    <label for="estimate-file" class="block text-sm font-medium text-slate-700">CSV file (up to 5 MB and 5,000 rows)</label>
                    <input id="estimate-file" type="file" wire:model="file" accept=".csv,text/csv" class="mt-1 block w-full text-sm text-slate-700 file:mr-3 file:rounded-lg file:border-0 file:bg-violet-50 file:px-3 file:py-2 file:font-medium file:text-violet-700">
                    @error('file') <p class="mt-1 text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="submit" wire:loading.attr="disabled" wire:target="upload" class="{{ $primary }}"><span wire:loading.remove wire:target="upload">Upload CSV</span><span wire:loading wire:target="upload">Uploading…</span></button>
                    <a href="{{ route('estimates.import.sample') }}" class="text-sm font-medium text-violet-700 hover:underline">Download a sample file</a>
                </div>
            </form>
        </section>

    @elseif ($import->status === \App\Models\EstimateImport::STATUS_UPLOADED)
        <section class="{{ $card }}" aria-labelledby="map-heading">
            <h2 id="map-heading" class="text-base font-semibold text-slate-900">2. Match your columns</h2>
            <p class="mt-1 text-sm text-slate-600">We guessed the matches from your column names. Check each one. Customer name and amount are required.</p>
            @error('map') <p class="mt-3 rounded-lg bg-red-50 p-3 text-sm text-red-800" role="alert">{{ $message }}</p> @enderror
            <form wire:submit="saveMapping" class="mt-4 space-y-3">
                @foreach ($columns as $field => $definition)
                    <div class="grid gap-2 sm:grid-cols-[14rem_1fr] sm:items-center">
                        <label for="map-{{ $field }}" class="text-sm font-medium text-slate-700">
                            {{ $definition['label'] }}@if (in_array($field, $required, true)) <span class="text-red-700" aria-hidden="true">*</span>@endif
                        </label>
                        <select id="map-{{ $field }}" wire:model="map.{{ $field }}" class="{{ $input }}" data-map="{{ $field }}">
                            <option value="">{{ in_array($field, $required, true) ? 'Choose a column' : 'Not in my file' }}</option>
                            @foreach ($headers as $index => $header)
                                <option value="{{ $index }}">{{ $header }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <div class="flex flex-wrap gap-3 pt-2">
                    <button type="submit" wire:loading.attr="disabled" wire:target="saveMapping" class="{{ $primary }}"><span wire:loading.remove wire:target="saveMapping">Check my estimates</span><span wire:loading wire:target="saveMapping">Checking every row…</span></button>
                    <button type="button" wire:click="startOver" class="{{ $secondary }}">Start over with another file</button>
                </div>
            </form>
        </section>

    @elseif ($import->status === \App\Models\EstimateImport::STATUS_READY || $import->status === \App\Models\EstimateImport::STATUS_FAILED)
        @if ($import->status === \App\Models\EstimateImport::STATUS_FAILED)
            <p role="alert" class="rounded-lg bg-red-50 p-4 text-sm text-red-800" data-import-failed>{{ $import->error_message }} Rows already imported are kept.</p>
        @endif

        <section class="{{ $card }}" aria-labelledby="review-heading">
            <h2 id="review-heading" class="text-base font-semibold text-slate-900">3. Review before importing</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-3" data-counts>
                <div class="rounded-lg bg-green-50 p-4"><dt class="text-xs font-medium text-green-800">Will be imported</dt><dd class="mt-1 text-2xl font-semibold text-green-900" data-count="valid">{{ $counts['pending'] }}</dd></div>
                <div class="rounded-lg bg-red-50 p-4"><dt class="text-xs font-medium text-red-800">Need fixing (not imported)</dt><dd class="mt-1 text-2xl font-semibold text-red-900" data-count="invalid">{{ $counts['rejected'] }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-4"><dt class="text-xs font-medium text-slate-600">Rows in file</dt><dd class="mt-1 text-2xl font-semibold text-slate-900">{{ array_sum($counts) }}</dd></div>
            </dl>

            @if ($counts['rejected'] > 0)
                <p class="mt-4 text-sm text-slate-700">Rows that need fixing are not imported. <a href="{{ route('estimates.import.problems', $import->id) }}" class="font-medium text-violet-700 hover:underline">Download the list of problems</a>, fix them in your spreadsheet, and upload the file again.</p>
            @endif

            <h3 class="mt-6 text-sm font-semibold text-slate-900">First rows that will be imported</h3>
            <div class="mt-2 overflow-x-auto rounded-lg ring-1 ring-slate-200">
                <table class="min-w-full divide-y divide-slate-200 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-medium text-slate-500">
                        <tr><th class="px-3 py-2">Line</th><th class="px-3 py-2">Customer</th><th class="px-3 py-2">Email</th><th class="px-3 py-2 text-right">Amount</th><th class="px-3 py-2">Expires</th><th class="px-3 py-2">Notes</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($preview as $row)
                            <tr>
                                <td class="px-3 py-2 text-slate-500">{{ $row->row_number }}</td>
                                <td class="px-3 py-2 text-slate-900">{{ $row->values[\App\Services\Estimates\Import\ImportColumns::CUSTOMER_NAME] ?? '' }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ ($row->values[\App\Services\Estimates\Import\ImportColumns::CUSTOMER_EMAIL] ?? '') ?: '—' }}</td>
                                <td class="px-3 py-2 text-right text-slate-900">{{ $row->values[\App\Services\Estimates\Import\ImportColumns::AMOUNT] ?? '' }}</td>
                                <td class="px-3 py-2 text-slate-700">{{ ($row->values[\App\Services\Estimates\Import\ImportColumns::EXPIRATION_DATE] ?? '') ?: '—' }}</td>
                                <td class="px-3 py-2 text-xs text-slate-600">
                                    @foreach ($row->warnings ?? [] as $warning)<p>{{ $warning }}</p>@endforeach
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="px-3 py-4 text-center text-slate-500">No rows can be imported. Download the problems list and fix them.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($counts['pending'] > 0)
                <form wire:submit="start" class="mt-6 space-y-4 border-t border-slate-200 pt-5">
                    <label class="flex items-start gap-3 text-sm text-slate-800">
                        <input type="checkbox" wire:model="confirmed" class="mt-1 rounded border-slate-300 text-violet-600 focus:ring-violet-600" data-confirm-import>
                        <span>Import {{ $counts['pending'] }} {{ \Illuminate\Support\Str::plural('estimate', $counts['pending']) }} as drafts. I understand that nothing will be emailed, and no follow-ups start until I activate them.</span>
                    </label>
                    @error('start') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                    <div class="flex flex-wrap gap-3">
                        <button type="submit" wire:loading.attr="disabled" wire:target="start" class="{{ $primary }}" data-start-import><span wire:loading.remove wire:target="start">Import {{ $counts['pending'] }} as drafts</span><span wire:loading wire:target="start">Starting…</span></button>
                        <button type="button" wire:click="startOver" class="{{ $secondary }}">Start over</button>
                    </div>
                </form>
            @endif
        </section>

    @elseif (in_array($import->status, [\App\Models\EstimateImport::STATUS_QUEUED, \App\Models\EstimateImport::STATUS_PROCESSING], true))
        <section class="{{ $card }}" aria-labelledby="progress-heading" wire:poll.2s>
            <h2 id="progress-heading" class="text-base font-semibold text-slate-900">Importing your estimates</h2>
            <p class="mt-1 text-sm text-slate-600">You can leave this page. Nothing is emailed while the import runs.</p>
            <p class="mt-4 text-sm text-slate-800" data-progress>{{ $counts['imported'] + $counts['skipped'] + $counts['rejected'] }} of {{ $counts['imported'] + $counts['skipped'] + $counts['rejected'] + $counts['pending'] }} rows processed</p>
        </section>

    @else
        <section class="{{ $card }}" aria-labelledby="summary-heading" data-import-summary>
            <h2 id="summary-heading" class="text-base font-semibold text-slate-900">Import complete</h2>
            <dl class="mt-4 grid gap-3 sm:grid-cols-3">
                <div class="rounded-lg bg-green-50 p-4"><dt class="text-xs font-medium text-green-800">Imported as drafts</dt><dd class="mt-1 text-2xl font-semibold text-green-900" data-count="imported">{{ $counts['imported'] }}</dd></div>
                <div class="rounded-lg bg-slate-50 p-4"><dt class="text-xs font-medium text-slate-600">Already imported (skipped)</dt><dd class="mt-1 text-2xl font-semibold text-slate-900" data-count="skipped">{{ $counts['skipped'] }}</dd></div>
                <div class="rounded-lg bg-red-50 p-4"><dt class="text-xs font-medium text-red-800">Not imported</dt><dd class="mt-1 text-2xl font-semibold text-red-900" data-count="rejected">{{ $counts['rejected'] }}</dd></div>
            </dl>
            <p class="mt-4 text-sm text-slate-700">Nothing was emailed. The estimates are drafts until you send them or start their follow-ups below.</p>
            <div class="mt-5 flex flex-wrap gap-3">
                <a href="{{ route('estimates.index') }}" wire:navigate class="{{ $primary }}">View estimates</a>
                @if ($counts['rejected'] > 0)
                    <a href="{{ route('estimates.import.problems', $import->id) }}" class="{{ $secondary }}">Download problems</a>
                @endif
                <button type="button" wire:click="startOver" class="{{ $secondary }}">Import another file</button>
            </div>
        </section>

        @if ($followUps)
            <section class="{{ $card }} mt-6" aria-labelledby="followups-heading" data-follow-ups>
                <h2 id="followups-heading" class="text-base font-semibold text-slate-900">Start follow-ups for estimates already sent</h2>
                @if ($followUpStatus)
                    <p role="status" class="mt-3 rounded-lg bg-green-50 p-3 text-sm text-green-800" data-follow-up-status>{{ $followUpStatus }}</p>
                @endif
                <p class="mt-1 text-sm text-slate-600">Only estimates your file marks as sent or viewed can start follow-ups. Activating schedules the messages; <strong class="font-semibold text-slate-900">it does not send the estimate</strong>, and each follow-up is sent on its date.</p>

                <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg bg-green-50 p-4"><dt class="text-xs font-medium text-green-800">Will start follow-ups</dt><dd class="mt-1 text-2xl font-semibold text-green-900" data-follow-up-count="eligible">{{ $followUps['eligible']->count() }}</dd></div>
                    <div class="rounded-lg bg-slate-50 p-4"><dt class="text-xs font-medium text-slate-600">Left out</dt><dd class="mt-1 text-2xl font-semibold text-slate-900">{{ array_sum($followUps['excluded']) }}</dd></div>
                </dl>

                @if (! empty($followUps['excluded']))
                    <ul class="mt-3 space-y-1 text-sm text-slate-700" data-excluded>
                        @foreach ($followUps['excluded'] as $reason => $count)
                            <li>{{ $count }} · {{ $reasons[$reason] ?? $reason }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($followUps['blocked'])
                    <p class="mt-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-900" role="status" data-follow-up-blocked>{{ $followUps['blocked'] }}</p>
                @elseif ($followUps['eligible']->isNotEmpty())
                    <p class="mt-4 text-sm text-slate-700">Sequence from <span class="font-medium text-slate-900">{{ $followUps['automation']->name }}</span>:
                        {{ collect($followUps['steps'])->map(fn ($step) => 'after '.$step->configuration['delay_days'].' '.\Illuminate\Support\Str::plural('day', $step->configuration['delay_days']))->implode(', ') }}.
                    </p>
                    <form wire:submit="activateFollowUps" class="mt-5 space-y-4 border-t border-slate-200 pt-5">
                        <label class="flex items-start gap-3 text-sm text-slate-800">
                            <input type="checkbox" wire:model="followUpsConfirmed" class="mt-1 rounded border-slate-300 text-violet-600 focus:ring-violet-600" data-confirm-follow-ups>
                            <span>These customers were already sent their estimate outside QuoteFlow, and they should receive the follow-up messages from now on.</span>
                        </label>
                        @error('followUps') <p class="text-sm text-red-700" role="alert">{{ $message }}</p> @enderror
                        <button type="submit" wire:loading.attr="disabled" wire:target="activateFollowUps" class="{{ $primary }}" data-activate-follow-ups><span wire:loading.remove wire:target="activateFollowUps">Start follow-ups for {{ $followUps['eligible']->count() }} {{ \Illuminate\Support\Str::plural('estimate', $followUps['eligible']->count()) }}</span><span wire:loading wire:target="activateFollowUps">Scheduling…</span></button>
                    </form>
                @endif
            </section>
        @endif
    @endif
</div>
