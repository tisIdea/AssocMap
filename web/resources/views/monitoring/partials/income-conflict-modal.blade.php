<style>
    #monitoring-income-conflict-modal { width: min(580px, calc(100% - 30px)); }
    #monitoring-income-conflict-modal .modal-actions { flex-wrap: wrap; }
    #monitoring-income-conflict-modal [hidden] { display: none; }
    @media (max-width: 520px) {
        #monitoring-income-conflict-modal .modal-actions > * { width: 100%; text-align: center; }
    }
</style>
<x-modal id="monitoring-income-conflict-modal" title="Income Record Updated">
    <p id="monitoring-income-conflict-message" data-income-conflict-message>Another user has saved newer changes to this Income record. Your changes were not saved. You can compare the latest saved information with your current entries before refreshing.</p>
    <p>Your entries and removal selections remain in the form. Refreshing will discard this unsaved draft.</p>
    <div data-income-refresh-confirmation hidden>
        <p class="notice notice-error" id="monitoring-income-refresh-warning">Refresh and discard your unsaved Income entries and removal selections? Copy anything you need before continuing.</p>
        <div class="modal-actions">
            <button type="button" class="button" data-income-refresh-cancel>Keep Draft</button>
            <button type="button" class="button button-danger" data-income-refresh-confirm aria-describedby="monitoring-income-refresh-warning">Discard Draft and Refresh</button>
        </div>
    </div>
    <div class="modal-actions" data-income-conflict-actions>
        <a class="button" data-income-latest target="_blank" rel="noopener noreferrer" hidden>Open Latest Saved Income</a>
        <button type="button" class="button button-primary" data-income-refresh>Refresh Page</button>
        <button type="button" class="button" data-income-conflict-cancel autofocus>Cancel / Keep Editing</button>
    </div>
</x-modal>
