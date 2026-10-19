<div class="program-choice-search" data-program-lookup="{{ $kind }}" data-lookup-target="{{ $target }}" data-lookup-url="{{ route($lookupRoute) }}" @isset($emptyLabel) data-lookup-empty-label="{{ $emptyLabel }}" @endisset>
    <label for="lookup-{{ $kind }}">{{ $label }}</label>
    <div class="program-choice-controls"><input id="lookup-{{ $kind }}" type="search" maxlength="120" placeholder="{{ $placeholder ?? 'Enter a name to find more choices' }}" data-lookup-term><button type="button" class="button" data-lookup-search><x-icon name="search" />Find</button><button type="button" class="button" data-lookup-previous hidden>Previous</button><button type="button" class="button" data-lookup-next hidden>More</button></div>
    <p class="field-help" role="status" aria-live="polite" data-lookup-status>Up to 25 choices are shown at a time. Search to find another record.</p>
</div>
