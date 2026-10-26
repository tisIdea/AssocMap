import { applyFormErrors, errorFeedback, showFeedback, showOperationError } from '../../core/feedback.js';
import { initReportDownloads } from '../report-download.js';

function element(tag, text, className) {
    const node = document.createElement(tag);
    if (text !== undefined) node.textContent = text;
    if (className) node.className = className;
    return node;
}

function initReportControls(root, form, readFilters, defaultYear) {
    const toggle = root.querySelector('[data-report-filter-toggle]');
    const panel = root.querySelector('#report-filter-panel');
    const count = root.querySelector('[data-report-filter-count]');
    const indicator = root.querySelector('[data-report-filter-status]');
    const exportMenu = root.querySelector('[data-report-export-menu]');

    function setOpen(open, returnFocus = false) {
        panel.hidden = !open;
        toggle.setAttribute('aria-expanded', String(open));
        if (returnFocus) toggle.focus();
    }

    toggle.hidden = false;
    setOpen(false);
    toggle.addEventListener('click', () => setOpen(panel.hidden));
    panel.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false, true); }
    });
    form.addEventListener('invalid', () => setOpen(true), true);
    exportMenu?.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            event.preventDefault();
            exportMenu.open = false;
            exportMenu.querySelector('summary').focus();
        }
    });

    return function sync() {
        const active = [...readFilters()].filter(([key, value]) => key !== 'report_type'
            && !(key === 'year' && Number(value) === defaultYear)).length;
        count.textContent = String(active);
        count.hidden = active === 0;
        toggle.setAttribute('aria-label', active ? `Filters, ${active} active` : 'Filters');
        indicator.textContent = active ? `${active} active filter(s)` : 'Default filters';
    };
}

export function initReports() {
    const root = document.querySelector('[data-report-workspace]');
    if (!root || root.dataset.reportsInitialized === 'true') return;
    const form = root.querySelector('[data-report-form]');
    const preview = root.querySelector('[data-report-result]');
    const status = root.querySelector('[data-report-status]');
    const exportForm = root.querySelector('[data-report-export]');
    initReportDownloads(exportForm);
    const initial = JSON.parse(root.querySelector('[data-report-initial]').textContent);
    root.dataset.reportsInitialized = 'true';
    const field = name => form.elements.namedItem(name);
    let request;
    let optionsRequest;
    let revision = 0;
    const syncControls = initReportControls(root, form, filters, initial.defaultYear);

    function syncBusy() {
        form.querySelector('[data-report-submit]').disabled = Boolean(request || optionsRequest);
        preview.setAttribute('aria-busy', String(Boolean(request)));
    }

    function exportsEnabled(enabled) {
        if (exportForm) exportForm.dataset.exportReady = String(enabled);
        exportForm?.querySelectorAll('button').forEach(button => { button.disabled = !enabled || exportForm.dataset.downloadPending === 'true'; });
    }

    function showStatus(message, error = false) {
        delete status.dataset.feedbackFallback;
        status.textContent = message;
        status.setAttribute('role', error ? 'alert' : 'status');
        status.classList.toggle('notice-error', error);
    }

    function filters() {
        const type = field('report_type').value;
        const allowed = new Set(['report_type', ...initial.filterMap[type]]);
        return new URLSearchParams([...new FormData(form)].filter(([name, value]) => allowed.has(name) && value !== ''));
    }

    function relevance() {
        const supported = initial.filterMap[field('report_type').value];
        root.querySelectorAll('[data-report-filter]').forEach(container => {
            const select = container.querySelector('select');
            const visible = supported.includes(container.dataset.reportFilter);
            container.hidden = !visible;
            select.disabled = !visible;
            select.required = visible && select.name === 'year';
            if (!visible && select.name !== 'year') select.value = '';
            if (visible && select.name === 'year' && !select.value) {
                select.value = String(initial.defaultYear);
                if (!select.value) select.selectedIndex = 0;
            }
        });
    }

    function associationOptions() {
        const municipality = field('municipality_id')?.value;
        const select = field('association_id');
        if (!select) return;
        for (const option of select.options) {
            const available = !option.value || !municipality || option.dataset.municipality === municipality;
            option.disabled = !available;
            option.hidden = !available;
        }
    }

    async function json(url, signal) {
        let response;
        try {
            response = await fetch(url, { signal, credentials: 'same-origin', redirect: 'error', headers: { Accept: 'application/json' } });
        } catch (error) {
            if (error.name === 'AbortError') throw error;
            throw new Error('Unable to load the report. Check your connection or sign in again, then retry.');
        }
        const contentType = response.headers.get('content-type') || '';
        const failure = new Error(errorFeedback(response.ok ? 503 : response.status, { context: 'Report' }).message);
        failure.status = response.ok ? 503 : response.status;
        if (!contentType.includes('application/json')) throw failure;
        let body;
        try { body = await response.json(); }
        catch { throw failure; }
        if (!response.ok) {
            failure.fields = response.status === 422 ? body?.errors || {} : {};
            throw failure;
        }
        if (!body?.data || typeof body.data !== 'object') throw failure;
        return body.data;
    }

    function fill(name, options, placeholder) {
        const select = field(name);
        if (!select) return;
        const previous = select.value;
        select.replaceChildren();
        if (placeholder) select.append(new Option(placeholder, ''));
        for (const option of options) select.append(new Option(String(option.name), String(option.id)));
        if ([...select.options].some(option => option.value === previous)) select.value = previous;
        else if (name === 'year') {
            select.value = String(initial.defaultYear);
            if (!select.value) select.selectedIndex = 0;
        }
    }

    async function refreshOptions() {
        optionsRequest?.abort();
        optionsRequest = new AbortController();
        const current = optionsRequest;
        const query = new URLSearchParams({ report_type: field('report_type').value });
        for (const key of ['municipality_id', 'association_id', 'project_id']) {
            if (field(key)?.value && !field(key).disabled) query.set(key, field(key).value);
        }
        syncBusy();
        try {
            const options = await json(`${root.dataset.optionsUrl}?${query}`, current.signal);
            if (current !== optionsRequest || current.signal.aborted) return;
            fill('project_id', options.projects, 'All recorded projects');
            fill('product_id', options.products, 'All recorded products');
            fill('component_id', options.components, 'All recorded components');
            fill('program_year', options.program_years.map(year => ({ id: year, name: year })), 'All recorded program years');
            fill('year', options.years.map(year => ({ id: year, name: year })));
            relevance();
            syncControls();
            showStatus('Filters changed. Apply filters to load current results.');
        } catch (error) {
            if (current === optionsRequest && error.name !== 'AbortError') showStatus(error.message, true);
        } finally {
            if (current === optionsRequest) { optionsRequest = undefined; syncBusy(); }
        }
    }

    function table(section, container) {
        if (!section.rows.length) { container.append(element('p', 'No recorded rows for this section.', 'report-empty')); return; }
        const scroll = element('div', undefined, 'report-table-scroll');
        scroll.tabIndex = 0;
        scroll.setAttribute('role', 'region');
        scroll.setAttribute('aria-label', section.title);
        const table = element('table');
        table.append(element('caption', section.title));
        const head = element('thead');
        const tr = element('tr');
        for (const column of section.columns) {
            const th = element('th', column.label);
            th.scope = 'col';
            if (['integer', 'decimal'].includes(column.type)) th.className = 'report-numeric';
            tr.append(th);
        }
        head.append(tr);
        const body = element('tbody');
        table.append(head, body);
        scroll.append(table);
        const controls = element('div', undefined, 'report-pagination');
        controls.setAttribute('role', 'group');
        controls.setAttribute('aria-label', `${section.title} table pages`);
        const previous = element('button', 'Previous', 'button');
        const next = element('button', 'Next', 'button');
        previous.type = next.type = 'button';
        previous.setAttribute('aria-label', `Previous page: ${section.title}`);
        next.setAttribute('aria-label', `Next page: ${section.title}`);
        const context = element('span');
        context.setAttribute('aria-live', 'polite');
        controls.append(previous, context, next);
        let page = 0;
        function renderPage() {
            body.replaceChildren();
            for (const row of section.rows.slice(page * 50, (page + 1) * 50)) {
                const rowNode = element('tr');
                for (const column of section.columns) rowNode.append(element('td', row[column.key] ?? 'Unobserved / N/A',
                    ['integer', 'decimal'].includes(column.type) ? 'report-numeric' : undefined));
                body.append(rowNode);
            }
            previous.disabled = page === 0;
            next.disabled = (page + 1) * 50 >= section.rows.length;
            context.textContent = `Rows ${page * 50 + 1}–${Math.min((page + 1) * 50, section.rows.length)} of ${section.rows.length}`;
        }
        previous.addEventListener('click', () => { page--; renderPage(); });
        next.addEventListener('click', () => { page++; renderPage(); });
        renderPage();
        container.append(scroll, controls);
    }

    function render(result, animateSummaries = false) {
        preview.replaceChildren(element('h2', result.title));
        const scope = element('section', undefined, 'report-context-card');
        scope.id = 'report-scope';
        scope.append(element('h3', 'Reporting scope and period'), element('p', result.meta.scope_basis),
            element('p', `Associations: ${result.meta.scope.join('; ') || 'None in this scope'}`),
            element('p', `Generated ${result.meta.generated_at} · ${result.meta.timezone}`, 'field-help'));
        const snapshot = ['associations', 'members', 'projects'].includes(result.meta.filters.report_type);
        scope.append(element('p', snapshot ? 'Current snapshot. Program year selects a cohort; reporting dates do not reconstruct historical counts.'
            : 'Reporting year/quarter selects training start dates, activity dates and quarterly monitoring periods. Association/member counts and project statuses remain current snapshots.', 'field-help'));
        const context = Object.entries(result.meta.filters).map(([key, value]) => {
            const select = field(key);
            const label = select ? [...select.options].find(option => option.value === String(value))?.textContent : value;
            return `${key.replaceAll('_', ' ')}: ${label || value}`;
        }).join(' · ');
        scope.append(element('p', context, 'field-help'));
        preview.append(scope);
        if (result.empty) preview.append(element('p', 'No recorded results match the selected report and filters.', 'notice'));
        const summary = element('section', undefined, 'report-summary');
        summary.append(element('h3', 'Summary indicators'), element('p', 'Recorded values within the applied scope. Unobserved values are not zero.', 'field-help'));
        for (const section of result.sections.filter(section => section.measures.length)) {
            summary.append(element('h4', section.title));
            const measures = element('dl', undefined, 'report-measures');
            for (const measure of section.measures) {
                const card = element('div');
                const value = element('span', measure.value ?? 'Unobserved');
                if (animateSummaries) value.setAttribute('data-summary-counter', '');
                const definition = element('dd'); definition.append(value);
                card.append(element('dt', measure.label), definition); measures.append(card);
            }
            summary.append(measures);
        }
        preview.append(summary);
        const analytics = element('section', undefined, 'report-analytics');
        analytics.setAttribute('aria-busy', 'true');
        analytics.append(element('h3', 'Visual analytics'), element('p', 'Loading charts. Summary indicators and detailed data remain available.', 'field-help'));
        preview.append(analytics);
        // Load presentation code only on report pages; never request a dataset per chart.
        import('../../components/report-analytics.js').then(({ renderReportAnalytics }) => {
            if (preview.contains(analytics)) renderReportAnalytics(result, analytics);
        }).catch(() => {
            if (!preview.contains(analytics)) return;
            analytics.removeAttribute('aria-busy');
            analytics.replaceChildren(element('h3', 'Visual analytics'), element('p', 'Charts are unavailable. Summary indicators and detailed data tables remain available.', 'report-empty'));
        });
        const quality = element('details', undefined, 'report-quality');
        quality.open = true;
        quality.append(element('summary', 'Data interpretation and quality'));
        const notes = element('ul');
        for (const note of result.notes) notes.append(element('li', note));
        quality.append(notes);
        for (const section of result.sections.filter(section => section.notes.length)) {
            quality.append(element('h4', section.title));
            const sectionNotes = element('ul');
            for (const note of section.notes) sectionNotes.append(element('li', note));
            quality.append(sectionNotes);
        }
        preview.append(quality, element('h3', 'Detailed data tables'));
        result.sections.forEach((section, index) => {
            const container = element('section', undefined, 'report-section report-data-section');
            container.id = `report-table-${section.key || index}`;
            container.tabIndex = -1;
            container.append(element('h4', section.title));
            table(section, container);
            preview.append(container);
        });
        const hidden = exportForm?.querySelector('[data-export-filters]');
        if (hidden) {
            hidden.replaceChildren();
            for (const [key, value] of Object.entries(result.meta.filters)) {
                const input = element('input');
                input.type = 'hidden'; input.name = key; input.value = value;
                hidden.append(input);
            }
        }
        exportsEnabled(true);
    }

    form.addEventListener('change', event => {
        ++revision;
        exportsEnabled(false);
        preview.replaceChildren();
        showStatus(request ? 'Filters changed. Wait for the current request to finish, then apply filters.' : 'Filters changed. Apply filters to load results.');
        relevance();
        const name = event.target.name;
        if (name === 'municipality_id' && field('association_id')) {
            const selected = field('association_id').selectedOptions[0];
            if (selected?.dataset.municipality !== field('municipality_id').value) field('association_id').value = '';
        }
        associationOptions();
        if (['municipality_id', 'association_id'].includes(name)) {
            field('project_id').value = '';
            field('product_id').value = '';
        }
        syncControls();
        if (['municipality_id', 'association_id', 'project_id', 'report_type'].includes(name)) refreshOptions();
    });

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (request || optionsRequest) return;
        relevance();
        if (!form.reportValidity()) return;
        const query = filters();
        request = new AbortController();
        const current = request;
        const started = revision;
        exportsEnabled(false);
        preview.replaceChildren();
        syncBusy();
        showStatus('Loading authorized report…');
        try {
            const result = await json(`${root.dataset.dataUrl}?${query}`, current.signal);
            if (current !== request || started !== revision || current.signal.aborted) return;
            render(result);
            showStatus(result.empty ? 'Report loaded. No matching observations.' : 'Report loaded from authorized records.');
            void showFeedback({ type: 'success', title: 'Report Loaded', message: result.empty
                ? 'The report was loaded. No recorded observations match these filters.' : 'The report was loaded from your authorized records.',
                returnFocus: form.querySelector('[data-report-submit]') });
            status.dataset.feedbackFallback = '';
            history.replaceState(null, '', `${form.action}?${query}`);
        } catch (error) {
            if (current === request && started === revision && error.name !== 'AbortError') {
                showStatus(error.message, true);
                applyFormErrors(form, error.fields);
                void showOperationError(error, { context: 'Report', returnFocus: form.querySelector('[data-report-submit]') });
                status.dataset.feedbackFallback = '';
            }
        } finally {
            if (current === request) {
                request = undefined;
                syncBusy();
                if (started !== revision && !optionsRequest && status.getAttribute('role') !== 'alert') showStatus('Filters changed. Apply filters to load results.');
            }
        }
    });
    relevance();
    associationOptions();
    syncControls();
    if (initial.result) {
        const selected = Object.fromEntries(filters());
        const applied = initial.result.meta.filters;
        const keys = new Set([...Object.keys(selected), ...Object.keys(applied)]);
        if ([...keys].every(key => String(selected[key] ?? '') === String(applied[key] ?? ''))) render(initial.result, true);
        else {
            preview.replaceChildren();
            exportsEnabled(false);
            showStatus('Filters changed. Apply filters to load matching results.');
        }
    }
}
