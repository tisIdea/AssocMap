<div class="area-record-actions" data-area-record="{{ json_encode($editorRecord) }}">
    @if(isset($viewUrl))<a class="text-button" href="{{ $viewUrl }}"><x-icon name="eye" />View</a>@endif
    <button class="text-button" type="button" data-area-edit><x-icon name="pencil" />Edit</button>
    <button class="text-button text-danger" type="button" data-area-deactivate @disabled($editorRecord['status'] === 'Inactive') data-confirm="Deactivate {{ $editorRecord['name'] }}? Existing relationships will be preserved." data-confirm-title="Deactivate Area" data-confirm-label="Deactivate"><x-icon name="archive" />Deactivate</button>
</div>
