<style>
    #monitoring-materials-conflict-modal { width: min(580px, calc(100% - 30px)); }
    #monitoring-materials-conflict-modal .modal-actions { flex-wrap: wrap; }
    #monitoring-materials-conflict-modal [hidden] { display: none; }
    @media (max-width: 520px) {
        #monitoring-materials-conflict-modal .modal-actions > * { width: 100%; text-align: center; }
    }
</style>
<x-modal id="monitoring-materials-conflict-modal" title="Materials Record Updated">
    <p id="monitoring-materials-conflict-message" data-materials-conflict-message>Another authorized user has saved newer changes to this Materials record. Your changes were not saved. You can compare the latest saved information with your current entries before refreshing.</p>
    <p>Your entries and removal selections remain in the form. Refreshing will discard this unsaved draft.</p>
    <div data-materials-refresh-confirmation hidden>
        <p class="notice notice-error" id="monitoring-materials-refresh-warning">Refresh and discard your unsaved Materials entries and removal selections? Copy anything you need before continuing.</p>
        <div class="modal-actions">
            <button type="button" class="button" data-materials-refresh-cancel>Keep Draft</button>
            <button type="button" class="button button-danger" data-materials-refresh-confirm aria-describedby="monitoring-materials-refresh-warning">Discard Draft and Refresh</button>
        </div>
    </div>
    <div class="modal-actions" data-materials-conflict-actions>
        <a class="button" data-materials-latest target="_blank" rel="noopener noreferrer" hidden>Open Latest Saved Materials</a>
        <button type="button" class="button button-primary" data-materials-refresh>Refresh Page</button>
        <button type="button" class="button" data-materials-conflict-cancel autofocus>Cancel / Keep Editing</button>
    </div>
</x-modal>
