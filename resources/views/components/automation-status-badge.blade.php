@props(['status'])

@php
    // Works for automation, run, action-run and follow-up statuses; labels come from the enum.
    $value = $status instanceof \BackedEnum ? $status->value : (string) $status;
    $label = method_exists($status, 'label') ? $status->label() : ucfirst($value);
    $classes = match ($value) {
        'active', 'completed' => 'bg-green-50 text-green-700 ring-green-600/20',
        'paused', 'skipped', 'due' => 'bg-amber-50 text-amber-800 ring-amber-600/20',
        'failed' => 'bg-red-50 text-red-700 ring-red-600/20',
        'running', 'pending', 'processing' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
        default => 'bg-gray-50 text-gray-700 ring-gray-500/20',
    };
@endphp

<span {{ $attributes->class(['inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset', $classes]) }}>{{ $label }}</span>
