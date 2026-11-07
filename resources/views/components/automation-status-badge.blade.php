@props(['status'])

@php
    // Works for automation, run, action-run and follow-up statuses; labels come from the enum.
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $label = method_exists($status, 'label') ? $status->label() : ucfirst($value);
    $classes = match ($value) {
        'active', 'completed' => 'bg-green-50 text-green-700 ring-green-600/20',
        'paused', 'skipped', 'due' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
        'running', 'pending', 'processing', 'waiting' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
        default => 'bg-slate-50 text-slate-700 ring-gray-500/20',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1 ring-inset', $classes]) }}><span aria-hidden="true" class="size-1.5 rounded-full bg-current"></span>{{ $label }}</span>
