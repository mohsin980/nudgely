{{-- When / Wait / If / Then, each with its icon. Expects $summary (AutomationSummary). --}}
@php($row = 'flex items-start gap-3')
@php($icon = 'grid size-9 shrink-0 place-items-center rounded-xl bg-violet-50 text-violet-600 ring-1 ring-violet-100')
<dl class="grid gap-5 text-sm sm:grid-cols-2" data-section="summary">
    <div class="space-y-5">
        <div class="{{ $row }}">
            <span class="{{ $icon }}"><x-ui-icon name="calendar" class="size-5" /></span>
            <div><dt class="text-xs font-semibold text-slate-500">When</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $summary['when'] ?? 'Not chosen' }}</dd></div>
        </div>
        @if ($summary['wait'])
            <div class="{{ $row }}">
                <span class="{{ $icon }}"><x-ui-icon name="clock" class="size-5" /></span>
                <div><dt class="text-xs font-semibold text-slate-500">Wait</dt><dd class="mt-0.5 font-medium text-slate-900">{{ $summary['wait'] }}</dd></div>
            </div>
        @endif
    </div>
    <div class="space-y-5">
        <div class="{{ $row }}">
            <span class="{{ $icon }}"><x-ui-icon name="arrows-right-left" class="size-5" /></span>
            <div>
                <dt class="text-xs font-semibold text-slate-500">If {{ count($summary['conditions']) > 1 ? ($summary['match'] === 'any' ? '(any)' : '(all)') : '' }}</dt>
                <dd class="mt-0.5 text-slate-900">@forelse ($summary['conditions'] as $line)<span class="block">{{ $line }}</span>@empty <span class="text-slate-500">No conditions</span>@endforelse</dd>
            </div>
        </div>
        <div class="{{ $row }}">
            <span class="{{ $icon }}"><x-ui-icon name="envelope" class="size-5" /></span>
            <div>
                <dt class="text-xs font-semibold text-slate-500">Then</dt>
                <dd class="mt-0.5 text-slate-900">
                    @if (count($summary['actions']) > 1)
                        <ol class="list-inside list-decimal space-y-0.5">@foreach ($summary['actions'] as $line)<li>{{ $line }}</li>@endforeach</ol>
                    @else
                        @forelse ($summary['actions'] as $line)<span class="block">{{ $line }}</span>@empty <span class="text-slate-500">No actions yet</span>@endforelse
                    @endif
                </dd>
            </div>
        </div>
    </div>
</dl>
