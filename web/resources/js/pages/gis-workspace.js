import { L, createGisMap, featureLayer, fitLayer, locationLabels, setFeatureSelection } from './gis-map.js';
import { addDetail, debounce, fillOptions, gisRequest, queryUrl, showStatus } from '../core/gis-api.js';
import { showFeedback, showOperationError } from '../core/feedback.js';
import { createGisPhotos } from './gis-photos.js';
import { siteSymbol } from './gis-site-symbols.js';

export async function initGis(root) {
    if (root.dataset.gisInitialized === 'true') return;
    root.dataset.gisInitialized = 'true';
    root.querySelectorAll('[data-gis-site-symbol]').forEach(symbol => { symbol.innerHTML = siteSymbol(symbol.dataset.gisSiteSymbol); });
    const base = root.dataset.gisBase.replace(/\/$/, '');
    const publicView = root.dataset.gisPublic === 'true';
    const manager = root.dataset.gisManager === 'true';
    const gallery = root.dataset.gisPhotos === 'true' ? createGisPhotos(root.querySelector('[data-gis-photos]'), {
        base: manager ? base : '/map', manager, csrf: root.querySelector('[data-gis-csrf]')?.value || '',
    }) : null;
    const status = root.querySelector('[data-gis-status]');
    const form = root.querySelector('[data-gis-filters]');
    const map = createGisMap(root.querySelector('[data-gis-map]'), root.querySelector('[data-gis-tile-notice]'));
    const features = L.featureGroup().addTo(map);
    const selection = L.featureGroup().addTo(map);
    const dialog = root.querySelector('[data-gis-confirm]');
    const retry = root.querySelector('[data-gis-retry]');
    const detailsPanel = root.querySelector('#gis-location-panel');
    const detailsDrawer = root.querySelector('[data-gis-details-drawer]');
    const detailsHome = detailsPanel.parentElement;
    const compactView = window.matchMedia('(max-width: 1100px)');
    let view = 'map', page = 1, lastPage = 1, selected = null, requestController, optionController;
    let pendingAction = null, opener = null, mutating = false, fitting = false, retryFit = false;
    let viewportTimer, searchTimer;

    function openDetails() {
        if (compactView.matches && !detailsDrawer.open) {
            detailsDrawer.showModal();
            document.documentElement.classList.add('gis-details-drawer-open');
        }
        root.querySelector('[data-gis-selection-title]').focus();
    }

    function placeDetails() {
        detailsPanel.querySelectorAll('dialog[open]').forEach(dialog => dialog.close());
        if (detailsDrawer.open) detailsDrawer.close();
        (compactView.matches ? root.querySelector('[data-gis-drawer-content]') : detailsHome).append(detailsPanel);
        const trigger = root.querySelector('[data-gis-open-details]');
        trigger.setAttribute('aria-controls', compactView.matches ? 'gis-details-drawer' : 'gis-location-panel');
        if (compactView.matches) trigger.setAttribute('aria-haspopup', 'dialog');
        else trigger.removeAttribute('aria-haspopup');
        fitting = true;
        try { map.invalidateSize({ animate: false }); }
        finally { fitting = false; }
    }

    detailsDrawer.addEventListener('close', () => {
        document.documentElement.classList.remove('gis-details-drawer-open');
        if (compactView.matches && selected) root.querySelector('[data-gis-open-details]').focus({ preventScroll: true });
    });
    detailsDrawer.addEventListener('click', event => { if (event.target === detailsDrawer) detailsDrawer.close(); });
    root.querySelector('[data-gis-close-details]').addEventListener('click', () => detailsDrawer.close());
    compactView.addEventListener('change', placeDetails);

    function actorName(name, recorded = true) {
        if (!recorded) return null;
        return typeof name === 'string' && name.trim() ? name.trim() : 'User unavailable';
    }

    function clearSelection() {
        if (detailsDrawer.open) detailsDrawer.close();
        detailsPanel.classList.remove('has-selection');
        gallery?.clear();
        selected = null; selection.clearLayers();
        setFeatureSelection(features, null);
        map.closePopup();
        root.querySelector('[data-gis-details]').replaceChildren();
        root.querySelector('[data-gis-record-actions]').replaceChildren();
        root.querySelector('[data-gis-selection-title]').textContent = 'A closer look starts here';
        root.querySelector('[data-gis-selection-eyebrow]').textContent = 'Explore the map';
        root.querySelector('[data-gis-selection-badges]').hidden = true;
        root.querySelector('[data-gis-selection-symbol]').innerHTML = siteSymbol(null);
        root.querySelector('[data-gis-dismiss]').disabled = true;
        root.querySelector('[data-gis-open-details]').disabled = true;
        const history = root.querySelector('[data-gis-history]');
        if (history) { history.hidden = true; history.open = false; root.querySelector('[data-gis-history-details]').replaceChildren(); }
        root.querySelector('[data-gis-selection-note]').textContent = 'Choose a marker or a list result to explore its details and photos.';
    }

    function selectFeature(feature, reveal = true) {
        const changed = selected?.id !== feature.id;
        gallery?.select(feature.id, feature.properties.version);
        selected = feature;
        detailsPanel.classList.add('has-selection');
        setFeatureSelection(features, feature.id);
        setFeatureSelection(selection, feature.id);
        const details = root.querySelector('[data-gis-details]');
        const actions = root.querySelector('[data-gis-record-actions]');
        details.replaceChildren(); actions.replaceChildren();
        const p = feature.properties;
        root.querySelector('[data-gis-selection-title]').textContent = p.title || 'Location';
        root.querySelector('[data-gis-selection-eyebrow]').textContent = 'Selected location';
        root.querySelector('[data-gis-selection-symbol]').innerHTML = siteSymbol(p.site_type_code);
        root.querySelector('[data-gis-selection-badges]').hidden = false;
        root.querySelector('[data-gis-classification]').textContent = p.site_type_name || 'Unclassified';
        root.querySelector('[data-gis-geometry]').textContent = locationLabels[p.location_type];
        root.querySelector('[data-gis-dismiss]').disabled = false;
        root.querySelector('[data-gis-open-details]').disabled = false;
        root.querySelector('[data-gis-selection-note]').textContent = feature.geometry
            ? (publicView ? 'Published location' : 'Project site information')
            : 'Geometry is unavailable or exceeds the supported limit.';

        const section = (title, className = '') => {
            const container = document.createElement('section'); container.className = `gis-detail-section ${className}`.trim();
            const heading = document.createElement('h4'); heading.textContent = title;
            const list = document.createElement('dl'); container.append(heading, list); details.append(container);
            return list;
        };
        const note = (list, text) => {
            const paragraph = document.createElement('p'); paragraph.className = 'gis-detail-note'; paragraph.textContent = text;
            list.parentElement.append(paragraph);
        };
        const location = section('About this location');
        addDetail(location, publicView ? 'Public address' : 'Project site address', p.address || 'Address not supplied');
        addDetail(location, 'Description', p.description || 'No description has been provided.');
        if (!publicView) {
            const project = section('Project & association');
            [['Project', p.project], ['Association', p.association], ['Program year', p.program_year]]
                .forEach(([label, value]) => addDetail(project, label, value));
            const geography = section('Registered geography');
            [['Municipality', p.municipality], ['Barangay', p.barangay], ['Specific area', p.area]]
                .forEach(([label, value]) => addDetail(geography, label, value));
            note(geography, "Association registration geography does not verify site containment. Site-address verification is not recorded; legacy area fallbacks may require review.");
        }
        const position = section('Map position', 'gis-position-section');
        const representative = publicView && p.location_type === 'project_boundary';
        const point = representative ? p.display_point : feature.geometry;
        if (point?.type === 'Point' && Array.isArray(point.coordinates) && point.coordinates.length === 2
            && point.coordinates.every(Number.isFinite)) {
            const format = (value, positive, negative) => `${Math.abs(value).toFixed(6)}° ${value < 0 ? negative : positive}`;
            addDetail(position, 'Latitude', format(point.coordinates[1], 'N', 'S'));
            addDetail(position, 'Longitude', format(point.coordinates[0], 'E', 'W'));
            [...position.querySelectorAll('dd')].forEach((value, index) => {
                value.title = String(point.coordinates[index === 0 ? 1 : 0]);
            });
            note(position, representative ? 'Calculated representative position within the boundary, not a surveyed site coordinate.' : 'WGS 84 · decimal degrees');
        } else {
            addDetail(position, 'Geometry', feature.geometry?.type || 'Unavailable');
        }
        if (p.location_type === 'project_boundary') {
            note(position, 'Actual project-site area; not barangay, municipality or association coverage.');
        }
        if (manager) {
            const lifecycle = section('Record status');
            if (p.association_archived_at) addDetail(lifecycle, 'Association lifecycle', 'Archived; operational records are read-only.');
            if (p.project_archived_at) addDetail(lifecycle, 'Project lifecycle', 'Archived');
            addDetail(lifecycle, 'Publication', p.is_published ? 'Published' : 'Unpublished');
            addDetail(lifecycle, 'GIS lifecycle', p.archived_at ? 'Archived; regular editing is unavailable.' : 'Active');
            const history = root.querySelector('[data-gis-history]');
            const historyDetails = root.querySelector('[data-gis-history-details]');
            history.hidden = false;
            historyDetails.replaceChildren();
            [['Public title', p.public_title], ['Public address', p.public_address],
                ['Created', p.created_at], ['Updated', p.updated_at], ['Published', p.published_at], ['Archived', p.archived_at],
                ['Created by', actorName(p.created_by_name)], ['Updated by', actorName(p.updated_by_name)],
                ['Published by', actorName(p.published_by_name, Boolean(p.published_at || p.published_by))],
                ['Archived by', actorName(p.archived_by_name, Boolean(p.archived_at || p.archived_by))]]
                .forEach(([label, value]) => addDetail(historyDetails, label, value));
            if (p.project_finalized && !p.can_correct_geometry) {
                addDetail(lifecycle, 'Project status', p.project_status === 'terminated' ? 'Terminated'
                    : (p.project_status === 'completed' ? 'Completed' : p.project_status));
                note(lifecycle, 'Operational history is read-only. GIS details and publication may be maintained; ownership and deletion are protected.');
            }
            renderRecordActions(feature, actions);
        }
        if (changed) {
            root.querySelector('.gis-location-content').scrollTop = 0;
        }
        if (compactView.matches && reveal) openDetails();
    }

    function renderRecordActions(feature, actions) {
        const p = feature.properties;
        if (p.can_correct_geometry) {
            const correct = document.createElement('a'); correct.className = 'button'; correct.textContent = 'Correct geometry';
            correct.href = `${base}/${encodeURIComponent(feature.id)}/coordinates`; actions.append(correct);
        }
        if (!p.archived_at && !p.association_archived_at && !p.project_archived_at) {
            const edit = document.createElement('a'); edit.className = 'button button-primary'; edit.textContent = 'Edit location';
            edit.href = `${base}/${encodeURIComponent(feature.id)}/edit`; actions.append(edit);
            const lifecycle = [p.is_published ? 'unpublish' : 'publish', 'archive'];
            lifecycle.forEach(action => {
                const button = document.createElement('button'); button.type = 'button'; button.className = 'button';
                button.textContent = action === 'publish' ? 'Publish' : (action === 'unpublish' ? 'Unpublish' : 'Archive');
                button.addEventListener('click', () => confirmAction(action, feature, button)); actions.append(button);
            });
        }
        if (!p.project_finalized && !p.association_archived_at && !p.project_archived_at) {
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'button button-danger';
            remove.textContent = 'Delete permanently';
            remove.addEventListener('click', () => confirmAction('delete', feature, remove)); actions.append(remove);
        }
    }

    function updateFilterSummary() {
        const count = Object.entries(filters()).filter(([key, value]) => key !== 'q' && !(key === 'lifecycle' && value === 'active')).length;
        root.querySelector('[data-gis-filter-summary]').textContent = count ? `${count} active ${count === 1 ? 'filter' : 'filters'}` : 'No additional filters';
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
            const site = document.createElement('p'); site.textContent = feature.properties.site_type_name || 'Unclassified site';
            const address = document.createElement('p'); address.textContent = feature.properties.address || 'No address supplied';
            const button = document.createElement('button'); button.type = 'button'; button.className = 'button'; button.textContent = 'View location';
            button.addEventListener('click', () => {
                setView('map', false); selectFeature(feature); selection.clearLayers();
                if (feature.geometry) { const layer = featureLayer(feature, selectFeature, publicView).addTo(selection); fitLayer(map, layer); }
                else showStatus(status, 'This record has no displayable geometry. Managers can supply replacement geometry.', true);
                setFeatureSelection(selection, feature.id);
                root.querySelector('[data-gis-selection-title]').focus();
            });
            card.append(heading, type, site, address, button); list.append(card);
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
        updateFilterSummary();
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
                data.features.filter(feature => feature.geometry).forEach(feature => featureLayer(feature, selectFeature, publicView).addTo(features));
            } else showList(data);
            if (selected) {
                const fresh = data.features.find(feature => feature.id === selected.id);
                fresh ? selectFeature(fresh, false) : clearSelection();
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
        if (next === view && reload) return;
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
                if (!Array.isArray(data.options)) throw new Error('Invalid GIS filter lookup response.');
                return { select, data };
            }));
            if (!isCurrent()) return;
            results.forEach(({ select, data }) => {
                const previous = select.value; fillOptions(select, data);
                reconciled ||= previous !== select.value;
            });
            root.querySelector('[data-gis-option-note]').textContent = results.some(({ data }) => data.truncated)
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
            [['Public title', feature.properties.public_title], ['Public address', feature.properties.public_address], ['Public description', feature.properties.public_description],
                ['Site classification', feature.properties.site_type_name || 'Unclassified']]
                .forEach(([label, value]) => addDetail(preview, label, value));
            if (feature.properties.location_type === 'project_boundary') addDetail(preview, 'Published boundary', 'Complete boundary coordinates are public, even when the map displays a representative marker.');
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
    root.querySelector('[data-gis-dismiss]').addEventListener('click', () => { clearSelection(); root.querySelector(`[data-gis-view="${view}"]`).focus(); });
    root.querySelector('[data-gis-open-details]').addEventListener('click', openDetails);
    root.querySelector('[data-gis-reload]').addEventListener('click', event => { clearSelection(); loadOptions(); load({ fit: true, notifyFailure: true, returnFocus: event.currentTarget }); });
    retry.addEventListener('click', () => load({ fit: retryFit, notifyFailure: true, returnFocus: retry }));
    form.addEventListener('submit', event => { event.preventDefault(); page = 1; clearSelection(); load({ fit: true }); });
    form.addEventListener('change', event => {
        if (event.target === form.elements.q || event.target.matches('[data-gis-option-search]')) return;
        const children = { municipality: ['barangay', 'area', 'association', 'project'], barangay: ['area', 'association', 'project'],
            area: ['association', 'project'], association: ['project'] }[event.target.dataset.gisParent] || [];
        children.forEach(kind => { const child = root.querySelector(`[data-gis-parent="${kind}"]`); if (child) child.value = ''; });
        page = 1; clearSelection();
        if (event.target.dataset.gisParent) loadOptions();
        load({ fit: true });
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
    placeDetails();
    await Promise.all([loadOptions(), load({ fit: true })]);
}
