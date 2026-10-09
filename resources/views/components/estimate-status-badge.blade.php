@props(['status'])
<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $status->badgeClasses()]) }} data-estimate-status="{{ $status->value }}">{{ $status->label() }}</span>
