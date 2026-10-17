@props(['navigation' => false])
@if($navigation)
    <x-modal id="navigation-confirmation-modal" title="Leave form?">
        <p id="navigation-confirmation-message" data-navigation-message></p>
        <div class="modal-actions">
            <button class="button" type="button" data-close-dialog data-navigation-cancel autofocus>Continue Editing</button>
            <button class="button button-primary" type="button" data-navigation-confirm>Leave</button>
        </div>
    </x-modal>
@else
    <x-modal id="confirmation-modal" title="Review action"><p data-confirm-message></p><div class="notice" data-confirm-placeholder>This action is not connected yet.</div><form method="POST" data-confirm-form>@csrf<input type="hidden" name="_method" value="PATCH"><div class="modal-actions"><button class="button" type="button" data-close-dialog>Cancel</button><button class="button button-danger" type="submit" data-confirm-action disabled>Confirm</button></div></form></x-modal>
@endif
