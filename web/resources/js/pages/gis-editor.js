import { L, createGisMap, fitLayer } from './gis-map.js';
import { debounce, fillOptions, gisRequest, queryUrl, showStatus } from '../core/gis-api.js';

function validateGeometry(geometry, boundary) {
    if (!geometry || typeof geometry !== 'object' || Array.isArray(geometry)
        || Object.keys(geometry).some(key => !['type', 'coordinates'].includes(key))) throw new Error('Provide a GeoJSON geometry object.');
    if (new TextEncoder().encode(JSON.stringify(geometry)).length > 131072) throw new Error('Geometry exceeds the supported size.');
    let positions = 0;
    const position = value => {
        if (!Array.isArray(value) || value.length !== 2 || !value.every(Number.isFinite)
            || value[0] < -180 || value[0] > 180 || value[1] < -90 || value[1] > 90) throw new Error('Use bounded longitude, latitude coordinates.');
        if (++positions > 2000) throw new Error('Geometry exceeds the 2,000-position limit.');
    };
    const polygon = rings => {
        if (!Array.isArray(rings) || !rings.length || rings.length > 50) throw new Error('Provide valid boundary rings.');
        rings.forEach(ring => {
            if (!Array.isArray(ring) || ring.length < 4) throw new Error('Each ring requires at least four positions, including its closing position.');
            ring.forEach(position);
            const first = ring[0], last = ring.at(-1);
            if (first[0] !== last[0] || first[1] !== last[1] || new Set(ring.map(value => JSON.stringify(value))).size < 3) throw new Error('Close each boundary ring with at least three distinct positions.');
        });
    };
    if (!boundary) {
        if (geometry.type !== 'Point') throw new Error('This location requires a Point.');
        position(geometry.coordinates);
    } else if (geometry.type === 'Polygon') polygon(geometry.coordinates);
    else if (geometry.type === 'MultiPolygon') {
        if (!Array.isArray(geometry.coordinates) || !geometry.coordinates.length || geometry.coordinates.length > 30) throw new Error('Provide valid polygon parts.');
        geometry.coordinates.forEach(polygon);
    } else throw new Error('A boundary requires Polygon or MultiPolygon geometry.');
    return geometry;
}

export async function initGis(root) {
    window.L = L;
    await import('@geoman-io/leaflet-geoman-free');
    await import('@geoman-io/leaflet-geoman-free/dist/leaflet-geoman.css');
    const base = root.dataset.gisBase.replace(/\/$/, '');
    const form = root.querySelector('[data-gis-editor-form]');
    const status = root.querySelector('[data-gis-editor-status]');
    const save = root.querySelector('[data-gis-save]');
    const type = form.elements.location_type;
    const association = root.querySelector('[data-gis-editor-association]');
    const project = form.elements.project_id;
    const parentSearch = root.querySelector('[data-gis-option-search]');
    const parentNote = root.querySelector('[data-gis-option-note]');
    const associationRetry = root.querySelector('[data-gis-association-retry]');
    const projectStatus = root.querySelector('[data-gis-project-status]');
    const projectRetry = root.querySelector('[data-gis-project-retry]');
    const address = form.elements.address;
    const registeredArea = root.querySelector('[data-gis-registered-area]');
    const registeredStatus = root.querySelector('[data-gis-registered-area-status]');
    const json = form.elements.geometry;
    const longitude = root.querySelector('[data-gis-longitude]');
    const latitude = root.querySelector('[data-gis-latitude]');
    const seed = JSON.parse(root.querySelector('[data-gis-seed]').textContent);
    const map = createGisMap(root.querySelector('[data-gis-editor-map]'), root.querySelector('[data-gis-tile-notice]'));
    const editable = L.featureGroup().addTo(map);
    let forceMulti = false, syncing = false, dirty = false, saving = false;
    let associationController, projectController, associationReady = false, projectState = 'idle', projectKey = null;
    let selectedAssociation = seed?.properties.parent_association_id || '';
    let selectedProject = seed?.properties.project_id || '';
    let associationState = 'idle';
    let associationAddresses = new Map();
    map.pm.setGlobalOptions({ layerGroup: editable, allowSelfIntersection: false, snappable: false });

    function boundary() { return type.value === 'project_boundary'; }

    function synchronizeBoundary() {
        if (syncing || !boundary()) return;
        const polygons = [];
        editable.eachLayer(layer => {
            const geometry = layer.toGeoJSON(false).geometry;
            if (geometry.type === 'Polygon') polygons.push(geometry.coordinates);
            if (geometry.type === 'MultiPolygon') polygons.push(...geometry.coordinates);
        });
        const geometry = polygons.length ? (polygons.length > 1 || forceMulti
            ? { type: 'MultiPolygon', coordinates: polygons } : { type: 'Polygon', coordinates: polygons[0] }) : null;
        json.value = geometry ? JSON.stringify(geometry, null, 2) : '';
        dirty = true;
    }

    function trackLayer(layer) {
        layer.on('pm:edit', synchronizeBoundary);
        layer.on('pm:remove', () => { editable.removeLayer(layer); synchronizeBoundary(); });
    }

    function renderGeometry(geometry, fit = false) {
        geometry = validateGeometry(geometry, boundary());
        syncing = true; editable.clearLayers();
        forceMulti = geometry.type === 'MultiPolygon';
        if (boundary()) {
            const parts = forceMulti ? geometry.coordinates.map(coordinates => ({ type: 'Polygon', coordinates })) : [geometry];
            parts.forEach(part => L.geoJSON(part, { style: { color: '#7444a7', fillOpacity: .18 }, pmIgnore: false })
                .eachLayer(layer => { layer.options.pmIgnore = false; editable.addLayer(layer); trackLayer(layer); }));
        } else {
            L.circleMarker([geometry.coordinates[1], geometry.coordinates[0]], {
                radius: 8, color: '#228855', fillOpacity: .9, pmIgnore: true,
            }).addTo(editable);
            longitude.value = String(geometry.coordinates[0]); latitude.value = String(geometry.coordinates[1]);
        }
        syncing = false;
        json.value = JSON.stringify(geometry, null, 2);
        if (fit) fitLayer(map, editable);
    }

    function stopGeometryModes() {
        if (map.pm.globalDrawModeEnabled()) map.pm.disableDraw();
        if (map.pm.globalEditModeEnabled()) map.pm.disableGlobalEditMode();
        if (map.pm.globalRemovalModeEnabled()) map.pm.disableGlobalRemovalMode();
    }

    function updateMode() {
        stopGeometryModes();
        if (!boundary() && map.pm.controlsVisible()) map.pm.removeControls();
        root.querySelector('[data-gis-coordinate-fields]').hidden = boundary();
        longitude.disabled = boundary(); latitude.disabled = boundary();
        project.required = true;
        project.disabled = projectState !== 'ready';
        form.elements.parent_association_id.value = association.value;
        if (boundary() && !map.pm.controlsVisible()) {
            map.pm.addControls({ position: 'topleft', drawMarker: false, drawCircleMarker: false, drawPolyline: false,
                drawRectangle: false, drawCircle: false, drawText: false, drawPolygon: true,
                editMode: true, dragMode: false, cutPolygon: false, removalMode: true, rotateMode: false });
        }
        root.querySelector('[data-gis-geometry-help]').textContent = boundary()
            ? 'Draw polygon parts and use vertex editing to adjust them. Multiple parts form a multipolygon. Existing holes are preserved; GeoJSON can also define holes.'
            : 'Click the map or enter both coordinates to choose the point. No starting coordinate is supplied.';
        if (json.value) {
            try { renderGeometry(JSON.parse(json.value)); }
            catch { syncing = true; editable.clearLayers(); syncing = false; showStatus(status, 'The geometry does not match the selected type. Replace or clear it before saving.', true); }
        }
    }

    function updateSaveState() {
        save.disabled = saving || !associationReady || projectState !== 'ready';
    }

    function updateRegisteredArea() {
        registeredArea.value = '';
        registeredArea.setAttribute('aria-busy', String(associationState === 'loading'));
        if (associationState === 'loading') showStatus(registeredStatus, 'Loading Registered Area...');
        else if (associationState === 'error') showStatus(registeredStatus, 'Registered area information unavailable. Retry associations to load it.', true);
        else if (!association.value) showStatus(registeredStatus, 'Select an association to load its Registered Area.');
        else {
            const info = associationAddresses.get(association.value);
            if (!info) showStatus(registeredStatus, 'Registered area information unavailable.');
            else {
                registeredArea.value = [info.address, info.context].filter(Boolean).join('\n');
                showStatus(registeredStatus, `${info.message || ''} This is association-area information, not a verified project site. It is not saved as Project Site Address.`.trim());
            }
        }
    }

    function setProjectState(state, placeholder, message = '') {
        projectState = state;
        project.replaceChildren(new Option(placeholder, ''));
        project.disabled = true;
        project.setAttribute('aria-busy', String(state === 'loading'));
        showStatus(projectStatus, message, state === 'error');
        projectRetry.hidden = state !== 'error';
        updateSaveState();
    }

    async function loadAssociations() {
        if (saving) return;
        associationController?.abort(); associationController = new AbortController();
        const controller = associationController;
        const requestedAssociation = selectedAssociation;
        const q = parentSearch.value;
        associationState = 'loading';
        updateRegisteredArea();
        association.setAttribute('aria-busy', 'true');
        association.disabled = !associationReady;
        associationRetry.hidden = true;
        parentNote.textContent = 'Loading associations...';
        try {
            const associations = await gisRequest(queryUrl(`${base}/parents`, { kind: 'association', q, selected: requestedAssociation }), { signal: controller.signal });
            if (controller !== associationController || controller.signal.aborted
                || requestedAssociation !== selectedAssociation || q !== parentSearch.value) return;
            if (!Array.isArray(associations.options)) throw new Error('Invalid association lookup response.');
            associationAddresses = new Map(associations.options.map(option => [option.value, option.address_info]));
            fillOptions(association, associations, requestedAssociation);
            associationState = 'ready';
            associationReady = true;
            association.disabled = false;
            form.elements.parent_association_id.value = association.value;
            parentNote.textContent = associations.truncated ? 'More associations are available. Refine the search.' : 'Only eligible associations within your current scope are available.';
            if (association.value !== selectedAssociation) {
                selectedAssociation = association.value;
                selectedProject = '';
                loadProjects();
            }
            updateRegisteredArea();
        } catch (error) {
            if (error.name !== 'AbortError' && controller === associationController) {
                parentNote.textContent = 'Associations could not be loaded. Retry the association lookup.';
                associationRetry.hidden = false;
                associationState = 'error';
                updateRegisteredArea();
            }
        } finally {
            if (controller === associationController) {
                association.setAttribute('aria-busy', 'false');
                updateSaveState();
            }
        }
    }

    async function loadProjects(force = false) {
        if (saving) return;
        const requestedAssociation = selectedAssociation;
        const q = parentSearch.value;
        if (!requestedAssociation) {
            projectController?.abort(); projectKey = null;
            setProjectState('idle', 'Select an association first', 'Select an association to load its eligible projects.');
            return;
        }
        const key = JSON.stringify([requestedAssociation, q]);
        if (!force && key === projectKey && ['loading', 'ready', 'empty'].includes(projectState)) return;
        projectController?.abort(); projectController = new AbortController();
        const controller = projectController;
        projectKey = key;
        const requestedProject = project.value || selectedProject;
        selectedProject = requestedProject;
        setProjectState('loading', 'Loading projects...', 'Loading projects...');
        const isCurrent = () => controller === projectController && !controller.signal.aborted
            && requestedAssociation === selectedAssociation && q === parentSearch.value;
        try {
            const projects = await gisRequest(queryUrl(`${base}/parents`, {
                kind: 'project', q, association_id: requestedAssociation, selected: requestedProject,
            }), { signal: controller.signal });
            if (!isCurrent()) return;
            if (!Array.isArray(projects.options)) throw new Error('Invalid project lookup response.');
            if (!projects.options.length) {
                selectedProject = '';
                setProjectState('empty', 'No eligible projects', q ? 'No eligible projects match this search.' : 'No eligible projects for this association.');
                return;
            }
            setProjectState('ready', 'Select a project');
            fillOptions(project, projects, requestedProject);
            selectedProject = project.value;
            project.disabled = false;
            updateSaveState();
            showStatus(projectStatus, projects.truncated ? 'More eligible projects are available. Refine the search.' : 'Eligible projects for the selected association are loaded.');
        } catch (error) {
            if (error.name !== 'AbortError' && isCurrent()) {
                setProjectState('error', 'Projects unavailable — retry', 'Projects could not be loaded. Retry to load projects for the selected association.');
            }
        }
    }

    function loadParents() { return Promise.all([loadAssociations(), loadProjects()]); }

    function setPoint(lng, lat) {
        try { renderGeometry({ type: 'Point', coordinates: [lng, lat] }); dirty = true; showStatus(status, 'Point selected. Save the form to store this location.'); }
        catch (error) { showStatus(status, error.message, true); }
    }

    map.on('click', event => { if (!boundary()) setPoint(event.latlng.lng, event.latlng.lat); });
    map.on('pm:create', event => { if (boundary()) { editable.addLayer(event.layer); trackLayer(event.layer); synchronizeBoundary(); } });
    [longitude, latitude].forEach(input => input.addEventListener('change', () => {
        if (longitude.value !== '' && latitude.value !== '') setPoint(Number(longitude.value), Number(latitude.value));
        else {
            syncing = true; editable.clearLayers(); syncing = false; json.value = ''; dirty = true;
            showStatus(status, 'Both coordinates are required to select a point. Supply coordinates or valid GeoJSON before saving.', true);
        }
    }));
    type.addEventListener('change', () => {
        updateMode(); loadProjects(); updateSaveState(); dirty = true;
    });
    association.addEventListener('change', () => {
        associationController?.abort();
        association.setAttribute('aria-busy', 'false');
        parentNote.textContent = 'Only eligible associations within your current scope are available.';
        selectedAssociation = association.value;
        selectedProject = ''; project.value = '';
        associationState = 'ready';
        address.value = '';
        updateRegisteredArea();
        form.elements.parent_association_id.value = selectedAssociation;
        loadProjects(); dirty = true;
    });
    project.addEventListener('change', () => { selectedProject = project.value; address.value = ''; dirty = true; });
    parentSearch.addEventListener('input', () => {
        associationController?.abort(); projectController?.abort(); projectKey = null;
        associationState = 'loading';
        updateRegisteredArea();
        if (project.value) selectedProject = project.value;
        if (selectedAssociation) setProjectState('loading', 'Loading projects...', 'Loading projects...');
    });
    parentSearch.addEventListener('input', debounce(() => loadParents()));
    associationRetry.addEventListener('click', () => loadAssociations());
    projectRetry.addEventListener('click', () => loadProjects(true));
    root.querySelector('[data-gis-preview]').addEventListener('click', () => {
        try { renderGeometry(JSON.parse(json.value), true); dirty = true; showStatus(status, 'Geometry preview updated. Server validation runs when saving.'); }
        catch (error) { showStatus(status, error instanceof SyntaxError ? 'Provide valid GeoJSON text.' : error.message, true); }
    });
    root.querySelector('[data-gis-fit]').addEventListener('click', () => fitLayer(map, editable));
    root.querySelector('[data-gis-clear]').addEventListener('click', () => {
        stopGeometryModes();
        syncing = true; editable.clearLayers(); syncing = false; forceMulti = false; json.value = ''; longitude.value = ''; latitude.value = ''; dirty = true;
        showStatus(status, 'Geometry cleared from this form. Supply replacement geometry before saving.');
    });
    form.addEventListener('input', event => { if (!event.target.matches('[data-gis-option-search]')) dirty = true; });
    window.addEventListener('beforeunload', event => { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (saving || !associationReady || projectState !== 'ready' || !form.reportValidity()) return;
        form.querySelectorAll('[aria-invalid]').forEach(input => input.removeAttribute('aria-invalid'));
        try {
            const geometry = validateGeometry(JSON.parse(json.value), boundary());
            const payload = Object.fromEntries(new FormData(form));
            payload.geometry = geometry;
            payload.parent_association_id = association.value;
            payload.project_id = project.value;
            delete payload._token; delete payload._method;
            associationController?.abort(); projectController?.abort();
            stopGeometryModes();
            saving = true; save.disabled = true; form.inert = true; showStatus(status, 'Saving GIS location…');
            await gisRequest(form.action, { method: seed ? 'PATCH' : 'POST', body: payload, csrf: form.elements._token.value });
            dirty = false; window.location.assign(base);
        } catch (error) {
            Object.keys(error.fields || {}).forEach(field => form.elements.namedItem(field)?.setAttribute('aria-invalid', 'true'));
            showStatus(status, error instanceof SyntaxError ? 'Provide valid GeoJSON text.'
                : (error instanceof TypeError && !error.status ? 'Save could not be confirmed. Retry without reloading this form.'
                    : (error.message || 'Save could not be confirmed. Retry without reloading this form.')), true);
        } finally { saving = false; updateSaveState(); form.inert = false; }
    });

    if (seed) { type.value = seed.properties.location_type; json.value = seed.geometry ? JSON.stringify(seed.geometry, null, 2) : ''; }
    updateMode();
    if (seed?.geometry) {
        try { renderGeometry(seed.geometry, true); showStatus(status, 'Saved geometry loaded. Changes require saving.'); }
        catch { showStatus(status, 'Saved geometry exceeds editor limits. Clear or replace the GeoJSON with supported geometry before saving.', true); }
    }
    else showStatus(status, seed ? 'Saved geometry is unavailable. Supply valid replacement geometry before saving.'
        : 'Choose a parent and supply actual geometry. No location is saved yet.');
    dirty = false;
    await loadParents();
}
