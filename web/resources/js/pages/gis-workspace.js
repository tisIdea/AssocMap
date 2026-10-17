import { L, createGisMap, featureLayer, fitLayer, locationLabels } from './gis-map.js';
import { addDetail, debounce, fillOptions, gisRequest, queryUrl, showStatus } from '../core/gis-api.js';
import { showFeedback, showOperationError } from '../core/feedback.js';

export async function initGis(root) {
    if (root.dataset.gisInitialized === 'true') return;
    root.dataset.gisInitialized = 'true';
    const base = root.dataset.gisBase.replace(/\/$/, '');
    const publicView = root.dataset.gisPublic === 'true';
    const manager = root.dataset.gisManager === 'true';
    const status = root.querySelector('[data-gis-status]');
    const form = root.querySelector('[data-gis-filters]');
    const map = createGisMap(root.querySelector('[data-gis-map]'), root.querySelector('[data-gis-tile-notice]'));
    const features = L.featureGroup().addTo(map);
    const selection = L.featureGroup().addTo(map);
    const dialog = root.querySelector('[data-gis-confirm]');
    const retry = root.querySelector('[data-gis-retry]');
    let view = 'map', page = 1, lastPage = 1, selected = null, requestController, optionController;
    let pendingAction = null, opener = null, mutating = false, fitting = false, retryFit = false;
    let viewportTimer, searchTimer;


    function actorName(name, recorded = true) {
        if (!recorded) return null;
        return typeof name === 'string' && name.trim() ? name.trim() : 'User unavailable';
    }

    function clearSelection() {
        selected = null; selection.clearLayers();
        root.querySelector('[data-gis-details]').replaceChildren();
        root.querySelector('[data-gis-record-actions]').replaceChildren();
        root.querySelector('[data-gis-selection-note]').textContent = 'Select a map feature or a location from the list.';
    }

    function selectFeature(feature) {
        selected = feature;
        const details = root.querySelector('[data-gis-details]');
        const actions = root.querySelector('[data-gis-record-actions]');
        details.replaceChildren(); actions.replaceChildren();
        const p = feature.properties;
        root.querySelector('[data-gis-selection-note]').textContent = feature.geometry ? '' : 'Geometry is unavailable or exceeds the supported limit.';
        addDetail(details, 'Location', p.title);
        addDetail(details, 'Type', locationLabels[p.location_type]);
        addDetail(details, publicView ? 'Public address' : 'Project Site Address', p.address);
        if (!publicView) {
            [['Association', p.association], ['Project', p.project], ['Municipality', p.municipality], ['Barangay', p.barangay],
                ['Registered Area reference', p.area], ['Program year', p.program_year]].forEach(([label, value]) => addDetail(details, label, value));
            addDetail(details, 'Address context',
                'Registered Area is association context. Saved site-address verification is not recorded; legacy values may be area fallbacks and require review.');
        }
        addDetail(details, 'Public description', p.description);
        if (feature.geometry?.type === 'Point') {
            addDetail(details, 'Longitude', feature.geometry.coordinates[0]); addDetail(details, 'Latitude', feature.geometry.coordinates[1]);
        }
        if (manager) {
            [['Publication', p.is_published ? 'Published' : 'Unpublished'], ['Public title', p.public_title], ['Public address', p.public_address],
                ['Created', p.created_at], ['Updated', p.updated_at], ['Published', p.published_at], ['Archived', p.archived_at],
                ['Created by', actorName(p.created_by_name)], ['Updated by', actorName(p.updated_by_name)],
                ['Published by', actorName(p.published_by_name, Boolean(p.published_at || p.published_by))],
                ['Archived by', actorName(p.archived_by_name, Boolean(p.archived_at || p.archived_by))]]
                .forEach(([label, value]) => addDetail(details, label, value));
            if (p.project_finalized) {
                addDetail(details, 'Project status', p.project_status === 'terminated' ? 'Terminated'
                    : (p.project_status === 'completed' ? 'Completed' : p.project_status));
                addDetail(details, 'Lifecycle', 'Operational history is read-only. GIS details and publication may be maintained; ownership and deletion are protected.');
            }
            if (!p.archived_at) {
                const edit = document.createElement('a'); edit.className = 'button'; edit.textContent = 'Edit location';
                edit.href = `${base}/${encodeURIComponent(feature.id)}/edit`; actions.append(edit);
                const lifecycle = [p.is_published ? 'unpublish' : 'publish', 'archive'];
                lifecycle.forEach(action => {
                    const button = document.createElement('button'); button.type = 'button'; button.className = 'button';
                    button.textContent = action === 'publish' ? 'Publish' : (action === 'unpublish' ? 'Unpublish' : 'Archive');
                    button.addEventListener('click', () => confirmAction(action, feature, button)); actions.append(button);
                });
            }
            if (!p.project_finalized) {
                const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button';
                remove.textContent = 'Delete permanently';
                remove.addEventListener('click', () => confirmAction('delete', feature, remove)); actions.append(remove);
            }
        }
    }

    function filters() {
        const values = Object.fromEntries(new FormData(form));
        Object.keys(values).forEach(key => { if (!values[key]) delete values[key]; });
        return values;
    }

    function dataUrl() {
        const url = queryUrl(`${base}/data`, { ...filters(), mode: view, ...(view === 'list' ? { page } : {}) });
        if (view === 'map') {
            const bounds = map.getBounds();
            [Math.max(-180, bounds.getWest()), Math.max(-90, bounds.getSouth()), Math.min(180, bounds.getEast()), Math.min(90, bounds.getNorth())]
                .forEach((value, index) => url.searchParams.set(`bbox[${index}]`, String(value)));
        }
        return url;
    }

    function emptyMessage(reason) {
        const messages = {
            no_records: publicView ? 'No eligible published GIS locations are available.'
                : 'No GIS locations are available within your current eligible association scope.',
            filtered: manager ? 'No GIS locations match these filters and lifecycle. Reset filters or check Archived records.'
                : 'No GIS locations match these filters. Reset filters to see available locations.',
            outside_view: 'Locations match these filters outside the current view. Use Refresh Records to fit matching locations or browse the list.',
            geometry_unavailable: 'Matching records have no displayable geometry. Use the list to review them; managers can supply valid geometry.',
            display_limit: 'Matching geometry exceeds the map response limit. Narrow the filters or use the register.',
            empty_page: 'No locations remain on this page. Refresh the list.',
        };
        return messages[reason] || 'No displayable locations were returned. Refresh or review the location list.';
    }

    function applyBounds(bounds) {
        if (!Array.isArray(bounds) || bounds.length !== 4 || !bounds.every(Number.isFinite)
            || bounds[0] < -180 || bounds[2] > 180 || bounds[1] < -90 || bounds[3] > 90
            || bounds[0] > bounds[2] || bounds[1] > bounds[3]) throw new Error('GIS extent is unavailable. Retry the request.');
        fitting = true;
        try { map.fitBounds([[bounds[1], bounds[0]], [bounds[3], bounds[2]]], { maxZoom: 16, padding: [24, 24], animate: false }); }
        finally { fitting = false; }
    }

    function showList(data) {
        const list = root.querySelector('[data-gis-list]');
        list.replaceChildren();
        data.features.forEach(feature => {
            const card = document.createElement('article'); card.className = 'gis-record-card';
            const heading = document.createElement('h3'); heading.textContent = feature.properties.title || 'Location';
            const type = document.createElement('p'); type.textContent = locationLabels[feature.properties.location_type];
            const address = document.createElement('p'); address.textContent = feature.properties.address || 'No address supplied';
            const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'View location';
            button.addEventListener('click', () => {
                setView('map', false); selectFeature(feature); selection.clearLayers();
                if (feature.geometry) { const layer = featureLayer(feature, selectFeature).addTo(selection); fitLayer(map, layer); }
                else showStatus(status, 'This record has no displayable geometry. Managers can supply replacement geometry.', true);
            });
            card.append(heading, type, address, button); list.append(card);
        });
        if (!data.features.length) { const text = document.createElement('p'); text.textContent = emptyMessage(data.meta.empty_reason); list.append(text); }
        page = data.meta.page; lastPage = data.meta.last_page;
        root.querySelector('[data-gis-page-label]').textContent = `Page ${page} of ${lastPage} · ${data.meta.total} locations`;
        root.querySelector('[data-gis-page="previous"]').disabled = page <= 1;
        root.querySelector('[data-gis-page="next"]').disabled = page >= lastPage;
    }

    async function load({ fit = false, notifyFailure = false, returnFocus } = {}) {
        if (mutating) return false;
        clearTimeout(viewportTimer);
        clearTimeout(searchTimer);
        requestController?.abort(); requestController = new AbortController();
        const controller = requestController;
        const requestedView = view, requestedFilters = JSON.stringify(filters());
        const isCurrent = () => controller === requestController && !controller.signal.aborted
            && requestedView === view && requestedFilters === JSON.stringify(filters());
        retryFit = fit; retry.hidden = true;
        showStatus(status, 'Loading GIS records…'); root.setAttribute('aria-busy', 'true');
        try {
            if (fit && view === 'map') {
                const overview = await gisRequest(queryUrl(`${base}/data`, { ...filters(), mode: 'overview' }), { signal: controller.signal });
                if (!isCurrent()) return false;
                if (overview.type !== 'FeatureCollection' || !overview.meta || !Object.hasOwn(overview.meta, 'bounds')) throw new Error('GIS extent is unavailable.');
                if (overview.meta.bounds === null) {
                    features.clearLayers(); clearSelection();
                    showStatus(status, emptyMessage(overview.meta.empty_reason));
                    return true;
                }
                applyBounds(overview.meta.bounds);
            }
            const requestedUrl = dataUrl().toString();
            const data = await gisRequest(requestedUrl, { signal: controller.signal });
            if (!isCurrent() || requestedUrl !== dataUrl().toString()) return false;
            if (!Array.isArray(data.features) || data.type !== 'FeatureCollection' || !data.meta) throw new Error('GIS data is unavailable.');
            if (view === 'list' && data.meta.page > data.meta.last_page) {
                page = Math.max(1, data.meta.last_page); return await load({ notifyFailure, returnFocus });
            }
            features.clearLayers(); selection.clearLayers();
            if (view === 'map') {
                data.features.filter(feature => feature.geometry).forEach(feature => featureLayer(feature, selectFeature).addTo(features));
            } else showList(data);
            if (selected) {
                const fresh = data.features.find(feature => feature.id === selected.id);
                fresh ? selectFeature(fresh) : clearSelection();
            }
            const message = data.features.length ? `${data.features.length} locations loaded.` : emptyMessage(data.meta.empty_reason);
            showStatus(status, message + (data.meta.truncated ? ' The map limit was reached. Zoom, filter, or use the register for additional records.' : ''));
            return true;
        } catch (error) {
            if (error.name === 'AbortError' || !isCurrent()) return false;
            features.clearLayers(); selection.clearLayers(); clearSelection(); root.querySelector('[data-gis-list]').replaceChildren();
            retry.hidden = false;
            showStatus(status, `${error.message || 'GIS records could not be loaded.'} Use Retry GIS records.`, true);
            if (notifyFailure) {
                void showOperationError(error, { returnFocus });
                status.dataset.feedbackFallback = '';
            }
            return false;
        } finally {
            if (requestController === controller) root.removeAttribute('aria-busy');
        }
    }

    function setView(next, reload = true) {
        requestController?.abort(); view = next;
        root.querySelector('[data-gis-map-panel]').hidden = view !== 'map';
        root.querySelector('[data-gis-list-panel]').hidden = view !== 'list';
        root.querySelectorAll('[data-gis-view]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.gisView === view)));
        if (view === 'map') {
            fitting = true;
            try { map.invalidateSize({ animate: false }); }
            finally { fitting = false; }
        }
        if (reload) { clearSelection(); load({ fit: view === 'map' }); }
    }

    async function loadOptions() {
        optionController?.abort(); optionController = new AbortController();
        const controller = optionController;
        const q = root.querySelector('[data-gis-option-search]').value;
        const values = filters();
        const isCurrent = () => controller === optionController && !controller.signal.aborted
            && q === root.querySelector('[data-gis-option-search]').value;
        const selects = [...root.querySelectorAll('[data-gis-parent]')];
        let reconciled = false;
        try {
            const results = await Promise.all(selects.map(async select => {
                const kind = select.dataset.gisParent;
                const parameters = { kind, q, selected: select.value };
                if (!publicView) {
                    if (kind !== 'municipality') parameters.municipality_id = values.municipality_id;
                    if (['area', 'association', 'project'].includes(kind)) parameters.barangay_id = values.barangay_id;
                    if (['association', 'project'].includes(kind)) parameters.area_id = values.area_id;
                    if (kind === 'project') parameters.association_id = values.association_id;
                }
                const data = await gisRequest(queryUrl(publicView ? `${base}/associations` : `${base}/parents`, parameters), { signal: controller.signal });
                if (!isCurrent()) return false;
                if (!Array.isArray(data.options)) throw new Error('Invalid GIS filter lookup response.');
                const previous = select.value; fillOptions(select, data);
                reconciled ||= previous !== select.value; return data.truncated;
            }));
            if (!isCurrent()) return;
            root.querySelector('[data-gis-option-note]').textContent = results.some(Boolean)
                ? 'More options are available. Refine the parent search.' : 'Options reflect the current eligible scope.';
            if (reconciled) { page = 1; clearSelection(); await load({ fit: true }); }
        } catch (error) {
            if (error.name !== 'AbortError' && isCurrent()) root.querySelector('[data-gis-option-note]').textContent
                = 'GIS filter options could not be loaded. Use Refresh records to retry.';
        }
    }

    function confirmAction(action, feature, button) {
        if (dialog.querySelector('[data-gis-confirm-submit]').disabled) return;
        pendingAction = { action, feature }; opener = button;
        const message = action === 'publish' ? 'Publish this geometry and the approved public details below?'
            : (action === 'unpublish' ? 'Remove this location from the public GIS viewer?'
                : (action === 'delete' ? 'Permanently delete this GIS location? This cannot be undone. The association or project will remain. Existing audit history will remain, and the deletion audit contains no geometry.'
                    : 'Archive this location and remove it from the public viewer? Its history will be preserved.'));
        dialog.querySelector('[data-gis-confirm-title]').textContent = action === 'delete' ? 'Permanent GIS deletion' : 'Review GIS action';
        dialog.querySelector('[data-gis-confirm-submit]').textContent = action === 'delete' ? 'Delete permanently' : 'Confirm';
        dialog.querySelector('[data-gis-confirm-message]').textContent = message;
        const preview = dialog.querySelector('[data-gis-confirm-preview]'); preview.replaceChildren();
        if (action === 'publish') {
            [['Public title', feature.properties.public_title], ['Public address', feature.properties.public_address], ['Public description', feature.properties.public_description]]
                .forEach(([label, value]) => addDetail(preview, label, value));
        }
        if (action === 'delete') { addDetail(preview, 'Location', feature.properties.title); addDetail(preview, 'Record ID', feature.id); }
        dialog.showModal(); dialog.querySelector('[data-gis-confirm-cancel]').focus();
    }

    if (dialog) {
        dialog.addEventListener('cancel', event => { if (mutating) event.preventDefault(); });
        dialog.querySelector('[data-gis-confirm-cancel]').addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => (opener?.isConnected ? opener : root.querySelector('[data-gis-reload]')).focus());
        dialog.querySelector('[data-gis-confirm-submit]').addEventListener('click', async event => {
            if (!pendingAction || event.currentTarget.disabled) return;
            const button = event.currentTarget; button.disabled = true;
            dialog.querySelector('[data-gis-confirm-cancel]').disabled = true;
            mutating = true; form.inert = true; requestController?.abort(); optionController?.abort();
            const { action, feature } = pendingAction;
            showStatus(status, action === 'delete' ? 'Deleting GIS location permanently…' : 'Updating GIS location…');
            let operationConfirmed = false;
            const outcome = { publish: 'published', unpublish: 'unpublished', archive: 'archived', delete: 'permanently deleted' }[action];
            try {
                const deleting = action === 'delete';
                const result = await gisRequest(`${base}/${encodeURIComponent(feature.id)}${deleting ? '' : `/${action}`}`, { method: deleting ? 'DELETE' : 'PATCH',
                    body: { version: feature.properties.version, ...(deleting ? { confirmation: true } : {}) }, csrf: root.querySelector('[data-gis-csrf]').value });
                if (action === 'publish') {
                    if (result?.type !== 'Feature' || result.id !== feature.id || result.properties?.is_published !== true
                        || result.properties.archived_at !== null) {
                        throw new Error('Publication could not be confirmed. Refresh records before retrying.');
                    }
                }
                if (action !== 'publish' && (result?.id !== feature.id || (action !== 'delete' && result?.type !== 'Feature'))) {
                    const error = new Error('Action could not be confirmed. Refresh records before retrying.');
                    error.status = 503; throw error;
                }
                operationConfirmed = true;
                dialog.close();
                clearSelection(); features.clearLayers(); root.querySelector('[data-gis-list]').replaceChildren();
                mutating = false;
                const refreshed = await load();
                showStatus(status, operationConfirmed && !refreshed
                    ? `Location ${outcome}, but records could not be refreshed. Use Retry GIS records or Refresh Records.`
                    : `Location ${outcome}.` + (refreshed ? '' : ' Records could not be refreshed. Use Refresh records.'), !refreshed);
                void showFeedback({ type: 'success', title: 'GIS Location Updated',
                    message: `Location ${outcome}.` + (refreshed ? '' : ' The register could not refresh. Use Refresh Records before another action.'), returnFocus: opener });
                status.dataset.feedbackFallback = '';
            } catch (error) {
                dialog.close(); clearSelection(); features.clearLayers(); root.querySelector('[data-gis-list]').replaceChildren();
                showStatus(status, operationConfirmed
                    ? `Location ${outcome}, but the GIS view could not be refreshed. Use Refresh Records.`
                    : (error.status ? error.message : 'Action could not be confirmed. Refresh records before retrying.'), true);
                if (operationConfirmed) void showFeedback({ type: 'success', title: 'GIS Change Confirmed',
                    message: `Location ${outcome}, but the register could not refresh. Use Refresh Records.`, returnFocus: opener });
                else void showOperationError(error, { returnFocus: opener });
                status.dataset.feedbackFallback = '';
            } finally {
                mutating = false; form.inert = false; button.disabled = false; pendingAction = null;
                dialog.querySelector('[data-gis-confirm-cancel]').disabled = false;
            }
        });
    }

    root.querySelectorAll('[data-gis-view]').forEach(button => button.addEventListener('click', () => setView(button.dataset.gisView)));
    root.querySelector('[data-gis-reload]').addEventListener('click', event => { clearSelection(); loadOptions(); load({ fit: true, notifyFailure: true, returnFocus: event.currentTarget }); });
    retry.addEventListener('click', () => load({ fit: retryFit, notifyFailure: true, returnFocus: retry }));
    form.addEventListener('submit', event => { event.preventDefault(); page = 1; clearSelection(); load({ fit: true }); });
    form.addEventListener('change', event => {
        if (event.target === form.elements.q || event.target.matches('[data-gis-option-search]')) return;
        const children = { municipality: ['barangay', 'area', 'association', 'project'], barangay: ['area', 'association', 'project'],
            area: ['association', 'project'], association: ['project'] }[event.target.dataset.gisParent] || [];
        children.forEach(kind => { const child = root.querySelector(`[data-gis-parent="${kind}"]`); if (child) child.value = ''; });
        page = 1; clearSelection(); loadOptions(); load({ fit: true });
    });
    form.addEventListener('reset', () => {
        requestController?.abort(); optionController?.abort();
        setTimeout(() => { page = 1; clearSelection(); loadOptions(); load({ fit: true }); }, 0);
    });
    form.elements.q.addEventListener('input', () => {
        requestController?.abort(); clearTimeout(searchTimer);
        searchTimer = setTimeout(() => { page = 1; clearSelection(); load({ fit: true }); }, 450);
    });
    const optionSearch = root.querySelector('[data-gis-option-search]');
    optionSearch.addEventListener('input', () => optionController?.abort());
    optionSearch.addEventListener('input', debounce(loadOptions));
    root.querySelectorAll('[data-gis-page]').forEach(button => button.addEventListener('click', () => { page += button.dataset.gisPage === 'next' ? 1 : -1; clearSelection(); load(); }));
    map.on('moveend', () => {
        if (fitting || view !== 'map' || mutating) return;
        requestController?.abort();
        clearTimeout(viewportTimer);
        viewportTimer = setTimeout(() => { if (view === 'map') load(); }, 450);
    });
    await Promise.all([loadOptions(), load({ fit: true })]);
}
