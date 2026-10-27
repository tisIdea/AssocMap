@if(in_array($monitoringPrefix, ['admin.monitoring', 'field-officer.monitoring'], true))
@php($photoEndpoint = route($monitoringPrefix.'.photos.index', [$record, $photoKind, $photoParent->id]))
<details class="monitoring-photos" data-monitoring-photos data-endpoint="{{ $photoEndpoint }}" data-manage="{{ $canManage ? 'true' : 'false' }}">
    <summary>Photos <span data-photo-count>up to 5</span></summary>
    <div class="monitoring-photo-body">
        <p class="field-hint">Private Monitoring evidence. JPEG or PNG, at most 5 MiB each (8192 pixels per edge; 12 million pixels). Pending uploads also reserve a slot.</p>
        <p role="status" aria-live="polite" data-photo-status>Open the gallery to load photos.</p>
        <div class="monitoring-photo-grid" data-photo-grid></div>
        <ul class="monitoring-photo-pending" data-photo-pending></ul>
        @if($canManage)
            <form data-photo-upload>
                <label for="photo-{{ $photoParent->id }}">Add a photo</label>
                <input id="photo-{{ $photoParent->id }}" name="photo" type="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required aria-describedby="photo-help-{{ $photoParent->id }}">
                <p id="photo-help-{{ $photoParent->id }}" class="field-hint">Save a new Visit Report or Material observation before adding photos. Photos save independently from Monitoring forms.</p>
                <img class="monitoring-photo-preview" data-photo-preview alt="Selected photo preview" hidden>
                <button class="button button-primary" type="submit">Upload photo</button>
            </form>
        @else
            <p class="field-hint">This record is read-only.</p>
        @endif
        <button class="button" type="button" data-photo-refresh>Refresh gallery</button>
        <dialog class="monitoring-photo-dialog" data-photo-viewer aria-label="Monitoring photo">
            <button class="button" type="button" data-photo-close>Close photo</button>
            <p role="status" data-photo-image-status></p>
            <img data-photo-display alt="Monitoring evidence">
        </dialog>
        <dialog class="monitoring-photo-dialog" data-photo-confirm aria-labelledby="photo-delete-{{ $photoParent->id }}">
            <h3 id="photo-delete-{{ $photoParent->id }}">Remove this photo?</h3>
            <p>It will stop appearing immediately. Storage cleanup may finish later.</p>
            <div class="monitoring-actions"><button class="button" type="button" data-photo-cancel>Keep photo</button><button class="button button-danger" type="button" data-photo-remove>Remove photo</button></div>
        </dialog>
        <noscript><p class="notice">Enable JavaScript to view and manage private Monitoring photos.</p></noscript>
    </div>
</details>
@endif
