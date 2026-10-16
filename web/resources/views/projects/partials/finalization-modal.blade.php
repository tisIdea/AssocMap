@php
    $terminating = $outcome === 'terminated';
    $dialogId = $terminating ? 'project-termination-modal' : 'project-closure-modal';
    $actionLabel = $terminating ? 'Terminate Project' : 'Complete Project';
    $word = $terminating ? 'TERMINATE' : 'COMPLETED';
    $confirmationField = $terminating ? 'termination_confirmation' : 'completion_confirmation';
    $modalErrors = $terminating ? $terminationErrors : $closureErrors;
@endphp
<x-modal :id="$dialogId" :title="$actionLabel">
    <form method="POST" action="{{ route($prefix.($terminating ? '.projects.terminate' : '.projects.close'), $project) }}" data-project-finalization-form data-confirmation-word="{{ $word }}" data-confirmation-field="{{ $confirmationField }}" data-open-finalization="{{ $modalErrors->any() || (request()->query('finalize') === $outcome && !$closureErrors->any() && !$terminationErrors->any()) ? 'true' : 'false' }}">
        @csrf
        <input type="hidden" name="expected_updated_at" value="{{ $project->updated_at?->format('Y-m-d\TH:i:s.uP') }}">
        <p><strong>{{ $project->title }}</strong></p>
        <p class="notice notice-error" id="{{ $dialogId }}-warning">{{ $terminating ? 'Termination permanently discontinues implementation.' : 'Completion records that implementation successfully finished.' }} This action is irreversible. The project and linked operational records become read-only. No new operational records can be linked. Authorized GIS maintenance and publication remain available with ownership and deletion protected. All historical records will be preserved.</p>
        @if($modalErrors->any())<div class="notice notice-error" role="alert"><ul>@foreach($modalErrors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <div class="field">
            <label for="{{ $dialogId }}-remarks">Final Remarks *</label>
            <textarea id="{{ $dialogId }}-remarks" name="closure_remarks" rows="5" maxlength="2000" required aria-describedby="{{ $dialogId }}-help" @if($modalErrors->has('closure_remarks')) aria-invalid="true" @endif>{{ $modalErrors->any() && is_string(old('closure_remarks')) ? old('closure_remarks') : '' }}</textarea>
            <small id="{{ $dialogId }}-help">Explain the {{ $terminating ? 'termination' : 'completion' }} in up to 2,000 characters. Existing project remarks and implementation dates will be preserved.</small>
        </div>
        <div class="field">
            <label for="{{ $dialogId }}-confirmation">Type {{ $word }} to confirm *</label>
            <input id="{{ $dialogId }}-confirmation" name="{{ $confirmationField }}" required pattern="{{ $word }}" autocomplete="off" spellcheck="false" aria-describedby="{{ $dialogId }}-confirmation-help" @if($modalErrors->has($confirmationField)) aria-invalid="true" @endif>
            <small id="{{ $dialogId }}-confirmation-help">Use exact uppercase text without leading or trailing whitespace.</small>
        </div>
        <p role="status" aria-live="polite" data-project-finalization-status></p>
        <div class="modal-actions"><button type="button" class="button" data-close-dialog>Cancel</button><button type="submit" class="button {{ $terminating ? 'button-danger' : 'button-primary' }}" data-project-finalization-submit disabled>{{ $terminating ? 'Confirm Termination' : 'Confirm Completion' }}</button></div>
        <noscript><p>JavaScript is required to enable finalization confirmation.</p></noscript>
    </form>
</x-modal>
