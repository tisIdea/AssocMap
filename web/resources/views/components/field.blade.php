@props([
    'field',
    'prefix',
    'value' => '',
    'inputName' => null,
    'filter' => false,
])

@php
    $id = $prefix . '-' . $field['name'];
    $name = $inputName ?? $field['name'];
    $type = $field['type'] ?? 'text';
    $required = ($field['required'] ?? false) && ! $filter;
    $choices = $field['choices'] ?? [];
    $help = $field['help'] ?? '';
@endphp

<div
    class="field {{ $type === 'textarea' || $type === 'checkboxes' ? 'field-wide' : '' }}"
    data-field="{{ $field['name'] }}"
>
    @if ($type === 'checkboxes')
        <fieldset>
            <legend>{{ $field['label'] }}</legend>

            <div class="checkbox-options">
                @foreach ($choices as $choice)
                    @php
                        $choiceValue = is_array($choice)
                            ? ($choice['value'] ?? '')
                            : $choice;

                        $choiceLabel = is_array($choice)
                            ? ($choice['label'] ?? $choiceValue)
                            : $choice;
                    @endphp

                    <label>
                        <input
                            type="checkbox"
                            name="{{ $name }}[]"
                            value="{{ $choiceValue }}"
                        >
                        {{ $choiceLabel }}
                    </label>
                @endforeach
            </div>
        </fieldset>

    @elseif ($type === 'checkbox')
        <label
            class="check-label"
            for="{{ $id }}"
        >
            <input
                id="{{ $id }}"
                name="{{ $name }}"
                type="checkbox"
                value="1"
                @checked($value === true || $value === 'Yes' || $value === 1 || $value === '1')
            >

            {{ $field['label'] }}
        </label>

    @else
        <label for="{{ $id }}">
            {{ $field['label'] }}

            <span class="field-hint">
                {{ $required ? '*' : 'Optional' }}
            </span>
        </label>

        @if ($type === 'select')
            <select
                id="{{ $id }}"
                name="{{ $name }}"
                @required($required)
                aria-describedby="{{ $id }}-help {{ $id }}-error"
            >
                <option value="">
                    {{ $field['empty_label'] ?? ($filter ? 'All' : 'Select…') }}
                </option>

                @foreach ($choices as $choice)
                    @php
                        $choiceValue = is_array($choice)
                            ? ($choice['value'] ?? '')
                            : $choice;

                        $choiceLabel = is_array($choice)
                            ? ($choice['label'] ?? $choiceValue)
                            : $choice;
                    @endphp

                    <option
                        value="{{ $choiceValue }}"
                        @selected((string) $value === (string) $choiceValue)
                    >
                        {{ $choiceLabel }}
                    </option>
                @endforeach
            </select>

        @elseif ($type === 'search-select')
            <input
                id="{{ $id }}"
                name="{{ $name }}"
                type="search"
                list="{{ $id }}-options"
                value="{{ $value }}"
                placeholder="Search or select…"
                autocomplete="off"
                @required($required)
                aria-describedby="{{ $id }}-help {{ $id }}-error"
            >

            <datalist id="{{ $id }}-options">
                @foreach ($choices as $choice)
                    @php
                        $choiceValue = is_array($choice)
                            ? ($choice['value'] ?? '')
                            : $choice;

                        $choiceLabel = is_array($choice)
                            ? ($choice['label'] ?? $choiceValue)
                            : $choice;
                    @endphp

                    <option
                        value="{{ $choiceValue }}"
                        label="{{ $choiceLabel }}"
                    >
                @endforeach
            </datalist>

        @elseif ($type === 'textarea')
            <textarea
                id="{{ $id }}"
                name="{{ $name }}"
                rows="3"
                placeholder="Enter {{ strtolower($field['label']) }}"
                @required($required)
                aria-describedby="{{ $id }}-help {{ $id }}-error"
            >{{ $value }}</textarea>

        @elseif ($type === 'display')
            <input
                id="{{ $id }}"
                value="{{ $value ?: 'Select a project material' }}"
                readonly
                aria-describedby="{{ $id }}-help {{ $id }}-error"
            >

        @else
            <input
                id="{{ $id }}"
                name="{{ $name }}"
                type="{{ $type }}"
                value="{{ $value }}"
                placeholder="{{ in_array($type, ['text', 'email', 'tel']) ? 'Enter ' . strtolower($field['label']) : '' }}"
                @required($required)
                @if ($type === 'number')
                    min="0"
                    step="{{ in_array($field['name'], ['year', 'program_year']) ? '1' : '0.01' }}"
                @endif
                aria-describedby="{{ $id }}-help {{ $id }}-error"
            >
        @endif

        <small
            id="{{ $id }}-help"
            class="field-help"
        >
            {{ $help }}
        </small>

        <span
            id="{{ $id }}-error"
            class="field-error"
            aria-live="polite"
        ></span>
    @endif
</div>