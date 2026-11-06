@props(['stage'])

<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset '.$stage->tone()]) }} data-lead-stage="{{ $stage->value }}">{{ $stage->label() }}</span>
