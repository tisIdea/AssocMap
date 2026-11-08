<section class="gis-photos" data-gis-photos hidden aria-label="Location photos">
    <div class="gis-photos-heading"><div><h3>Photo gallery</h3><span data-photo-count></span></div><button type="button" class="button" data-photo-refresh aria-label="Refresh location photos">Refresh</button></div>
    <p class="gis-photo-status" data-photo-status role="status" aria-live="polite"></p>
    @if($gisManager)
        <p class="gis-photo-policy">Private by default. Approve each photo explicitly for a published, eligible location. Finalized project evidence is read-only.</p>
        <form data-photo-upload>
            <label for="gis-photo-file">JPEG or PNG, at most 5 MiB</label>
            <input id="gis-photo-file" type="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png" required>
            <img data-photo-preview hidden alt="Selected photo preview" class="gis-photo-preview">
            <button type="submit" class="button button-primary">Upload photo</button>
        </form>
    @else
        <p class="gis-photo-policy">Approved photos of published locations.</p>
    @endif
    <div class="gis-photo-grid" data-photo-grid></div>
    <ul data-photo-pending></ul>
    <dialog class="modal gis-photo-viewer" data-photo-viewer aria-label="Location photo">
        <p data-photo-image-status role="status"></p><img data-photo-display alt="Location photo">
        <button type="button" class="button" data-photo-close>Close photo</button>
    </dialog>
    @if($gisManager)
        <dialog class="modal" data-photo-confirm aria-labelledby="gis-photo-remove-title">
            <h3 id="gis-photo-remove-title">Remove location photo?</h3>
            <p>The photo will stop being accessible immediately. Storage cleanup may remain pending.</p>
            <div class="modal-actions"><button type="button" class="button" data-photo-cancel>Cancel</button><button type="button" class="button button-danger" data-photo-remove>Remove photo</button></div>
        </dialog>
    @endif
</section>
