<section class="card program-information project-cover" data-project-cover data-csrf="{{ csrf_token() }}" data-endpoint="{{ route($prefix.'.projects.cover.index', $project) }}" data-manage="{{ $canManage ? 'true' : 'false' }}" aria-labelledby="project-cover-heading">
    <h2 id="project-cover-heading">Project cover photo</h2>
    <p class="field-hint">One optional representative photo, separate from GIS galleries.</p>
    <p role="status" aria-live="polite" data-cover-status>Loading cover photo...</p>
    <div class="project-cover-image">
        <img data-cover-display alt="Representative cover for {{ $project->title }}" decoding="async" hidden>
        <p class="project-cover-empty" data-cover-empty hidden>No cover photo.</p>
    </div>
    <ul data-cover-pending class="project-cover-pending"></ul>
    @if($canManage)
        <form data-cover-upload>
            <label for="project-cover-file">Upload or replace cover photo</label>
            <input type="file" id="project-cover-file" name="photo" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required aria-describedby="project-cover-help">
            <p id="project-cover-help" class="field-hint">JPEG or PNG, at most 5 MiB (8192 pixels per edge; 12 million pixels). The existing cover remains available until a replacement finishes. Cover changes save independently from Project details.</p>
            <img class="project-cover-preview" data-cover-preview alt="Selected cover preview" hidden>
            <button class="button button-primary" type="submit">Upload cover</button>
        </form>
        <button class="button button-danger-outline" type="button" data-cover-remove hidden>Remove cover</button>
    @else
        <p class="field-hint">This Project is read-only.</p>
    @endif
    <button class="button" type="button" data-cover-refresh>Refresh cover</button>
    <dialog class="project-cover-dialog" data-cover-confirm aria-labelledby="cover-removal-title">
        <h3 id="cover-removal-title">Remove this photo?</h3>
        <p>The selected photo will be withdrawn. Storage cleanup may finish later.</p>
        <div class="modal-actions"><button class="button" type="button" data-cover-cancel>Keep photo</button><button class="button button-danger" type="button" data-cover-confirm-remove>Remove photo</button></div>
    </dialog>
    <noscript><p class="notice">Enable JavaScript to view and manage the private cover photo.</p></noscript>
</section>
