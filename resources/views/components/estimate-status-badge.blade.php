@props(['status', 'icon' => false])

@if ($icon)
    {{-- Pill with an icon, used in the estimates list. --}}
    @php($glyph = match ($status) {
        \App\Enums\EstimateStatus::Draft => 'pencil',
        \App\Enums\EstimateStatus::Sent => 'paper-airplane',
        \App\Enums\EstimateStatus::Viewed => 'eye',
        \App\Enums\EstimateStatus::Accepted => 'check',
        \App\Enums\EstimateStatus::Expired => 'clock',
        default => 'close',
    })
    <span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full py-1 pr-3 pl-1.5 text-xs font-semibold ring-1 ring-inset', $status->badgeClasses()]) }} data-estimate-status="{{ $status->value }}">
        <span class="grid size-4.5 place-items-center rounded-full bg-current/15"><x-ui-icon :name="$glyph" class="size-3 stroke-[2.5]" /></span>{{ $status->label() }}
    </span>
@else
    <span {{ $attributes->class(['inline-flex items-center rounded-lg px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $status->badgeClasses()]) }} data-estimate-status="{{ $status->value }}">{{ $status->label() }}</span>
@endif
