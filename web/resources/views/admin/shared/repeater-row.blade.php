@php
    /** @var array{title: string, repeater: string, fields: list<array<string, mixed>>} $repeaterSection */
    /** @var string $formPrefix */
    /** @var int|string $rowIndex */
    /** @var array<string, mixed> $rowValues */
@endphp
<div class="repeat-row" data-repeat-row><div class="repeat-row-heading"><strong>{{ rtrim($repeaterSection['title'], 's') }} <span data-row-number>{{ is_numeric($rowIndex) ? $rowIndex + 1 : '' }}</span></strong><button type="button" class="text-button text-danger" data-remove-row><x-icon name="trash" />Remove</button></div><div class="form-grid">
    @foreach($repeaterSection['fields'] as $field)<x-field :field="$field" :prefix="$formPrefix.'-'.$repeaterSection['repeater'].'-'.$rowIndex" :input-name="$repeaterSection['repeater'].'['.$rowIndex.']['.$field['name'].']'" :value="$rowValues[$field['name']] ?? ''" />@endforeach
</div></div>
