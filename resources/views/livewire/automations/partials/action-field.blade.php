{{-- One input of an action card. Expects $field (ActionField), $i (action index), $users, $fieldClass. --}}
@php($id = "action-{$i}-{$field->name}")
@php($model = "actions.{$i}.configuration.{$field->name}")
<div @class(['sm:col-span-2' => in_array($field->type, ['template', 'template_body', 'textarea'], true)])>
    <label for="{{ $id }}" class="block text-sm font-medium text-gray-700">{{ $field->label }}@unless ($field->required) <span class="font-normal text-gray-500">(optional)</span>@endunless</label>
    @switch($field->type)
        @case('select')
            <select id="{{ $id }}" wire:model.live="{{ $model }}" class="{{ $fieldClass }}">
                @foreach ($field->options as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            @break
        @case('user')
            <select id="{{ $id }}" wire:model="{{ $model }}" class="{{ $fieldClass }}">
                <option value="">Default task assignee (see Automation defaults)</option>
                <option value="owner">The automation owner</option>
                @foreach ($users as $userId => $userName)
                    <option value="{{ $userId }}">{{ $userName }}</option>
                @endforeach
            </select>
            @break
        @case('template_body')
        @case('textarea')
            <textarea id="{{ $id }}" wire:model.blur="{{ $model }}" rows="5" @if ($field->max) maxlength="{{ $field->max }}" @endif class="{{ $fieldClass }}"></textarea>
            @break
        @default
            <input id="{{ $id }}" type="text" wire:model.blur="{{ $model }}" @if ($field->max) maxlength="{{ $field->max }}" @endif class="{{ $fieldClass }}">
    @endswitch
    @if ($field->help)
        <p class="mt-1 text-xs text-gray-500">{{ $field->help }}</p>
    @endif
</div>
