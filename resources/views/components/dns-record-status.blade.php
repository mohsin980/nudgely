@props(['verified'])

@if ($verified === true)
    <span class="text-xs font-medium text-green-700">Verified</span>
@elseif ($verified === false)
    <span class="text-xs font-medium text-amber-700">Not detected yet</span>
@endif
