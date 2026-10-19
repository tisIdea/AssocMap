const requests = new WeakMap();

function validResult(result) {
    return Array.isArray(result?.options) && Number.isInteger(result.page) && typeof result.has_more === 'boolean'
        && result.options.every(choice => typeof choice?.value === 'string' && typeof choice?.label === 'string');
}

export function applyLookupResults(root, result) {
    const form = root.closest('form');
    const select = form.elements[root.dataset.lookupTarget];
    if (select?.tagName === 'SELECT') {
        const selected = select.selectedOptions[0];
        const value = select.value;
        select.replaceChildren(new Option(root.dataset.lookupEmptyLabel ?? (root.dataset.lookupTarget === 'related_project_id' ? 'No related project' : 'Select…'), ''));
        for (const choice of result.options) select.add(new Option(choice.label, choice.value));
        if (value && selected && !result.options.some(choice => choice.value === value)) select.add(selected.cloneNode(true));
        select.value = value;
    }
    root.dataset.lookupPage = String(result.page);
    root.querySelector('[data-lookup-previous]').hidden = result.page <= 1;
    root.querySelector('[data-lookup-next]').hidden = !result.has_more;
    root.querySelector('[data-lookup-status]').textContent = result.options.length
        ? `${result.options.length} matching choices · Page ${result.page}. Select a record below.`
        : 'No matching choices. Try another search.';
    form.dispatchEvent(new CustomEvent('assocmap:lookup-results', { detail: { kind: root.dataset.programLookup, options: result.options } }));
}

export async function loadLookup(root, page = 1) {
    const form = root.closest('form');
    const status = root.querySelector('[data-lookup-status]');
    requests.get(root)?.abort();
    requests.delete(root);
    if (!['associations', 'activities'].includes(root.dataset.programLookup) && !form.elements.association_id.value) {
        root.querySelectorAll('button').forEach(button => { button.disabled = false; });
        root.removeAttribute('aria-busy');
        status.textContent = 'Select an association first.';
        return;
    }
    const controller = new AbortController();
    requests.set(root, controller);
    const buttons = Array.from(root.querySelectorAll('button'));
    buttons.forEach(button => { button.disabled = true; });
    root.setAttribute('aria-busy', 'true');
    status.textContent = 'Loading choices…';
    try {
        const url = new URL(root.dataset.lookupUrl, window.location.origin);
        url.searchParams.set('lookup', root.dataset.programLookup);
        url.searchParams.set('term', root.querySelector('[data-lookup-term]').value.trim());
        url.searchParams.set('lookup_page', String(page));
        if (!['associations', 'activities'].includes(root.dataset.programLookup)) url.searchParams.set('association_id', form.elements.association_id.value);
        if (form.elements.training_record_id?.value) url.searchParams.set('training_id', form.elements.training_record_id.value);
        const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
        if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('unavailable');
        const result = await response.json();
        if (!validResult(result)) throw new Error('invalid');
        if (requests.get(root) === controller) applyLookupResults(root, result);
    } catch (error) {
        if (error.name !== 'AbortError' && requests.get(root) === controller) status.textContent = 'Choices are unavailable. Check your session or retry the search.';
    } finally {
        if (requests.get(root) === controller) {
            buttons.forEach(button => { button.disabled = false; });
            root.removeAttribute('aria-busy');
        }
    }
}

export async function loadTrainingChoices(form) {
    const pickers = Array.from(form.querySelectorAll('[data-program-lookup]')).filter(picker => picker.dataset.programLookup !== 'associations');
    if (!pickers.length) return;
    const controller = new AbortController();
    for (const picker of pickers) {
        requests.get(picker)?.abort();
        requests.set(picker, controller);
        picker.querySelector('[data-lookup-term]').value = '';
        picker.setAttribute('aria-busy', 'true');
        picker.querySelectorAll('button').forEach(button => { button.disabled = true; });
        picker.querySelector('[data-lookup-status]').textContent = 'Loading choices…';
    }
    try {
        const associationId = form.elements.association_id.value;
        let result;
        if (associationId) {
            const url = new URL(pickers[0].dataset.lookupUrl, window.location.origin);
            url.searchParams.set('lookup', 'training_choices');
            url.searchParams.set('association_id', associationId);
            if (form.elements.training_record_id?.value) url.searchParams.set('training_id', form.elements.training_record_id.value);
            const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/json')) throw new Error('unavailable');
            result = await response.json();
        } else result = { projects: { options: [], page: 1, has_more: false }, members: { options: [], page: 1, has_more: false } };
        if (!validResult(result.projects) || !validResult(result.members)) throw new Error('invalid');
        for (const picker of pickers) if (requests.get(picker) === controller) applyLookupResults(picker, result[picker.dataset.programLookup]);
    } catch (error) {
        if (error.name !== 'AbortError') for (const picker of pickers) if (requests.get(picker) === controller) {
            picker.querySelector('[data-lookup-status]').textContent = 'Choices are unavailable. Check your session or retry the search.';
        }
    } finally {
        for (const picker of pickers) if (requests.get(picker) === controller) {
            picker.querySelectorAll('button').forEach(button => { button.disabled = false; });
            picker.removeAttribute('aria-busy');
        }
    }
}

export function initProgramLookups(root = document) {
    const forms = new Set();
    root.querySelectorAll('[data-program-lookup]').forEach(picker => {
        forms.add(picker.closest('form'));
        if (picker.dataset.lookupInitialized === 'true') return;
        picker.dataset.lookupInitialized = 'true';
        picker.querySelector('[data-lookup-search]').addEventListener('click', () => void loadLookup(picker));
        picker.querySelector('[data-lookup-previous]').addEventListener('click', () => void loadLookup(picker, Number(picker.dataset.lookupPage) - 1));
        picker.querySelector('[data-lookup-next]').addEventListener('click', () => void loadLookup(picker, Number(picker.dataset.lookupPage) + 1));
        picker.querySelector('[data-lookup-term]').addEventListener('keydown', event => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            void loadLookup(picker);
        });
    });
    forms.forEach(form => {
        if (form.dataset.programLookupsInitialized === 'true') return;
        form.dataset.programLookupsInitialized = 'true';
        form.elements.association_id.addEventListener('change', () => {
            if (!('programActivityForm' in form.dataset)) return void loadTrainingChoices(form);
            const picker = form.querySelector('[data-program-lookup="projects"]');
            form.elements.related_project_id.replaceChildren(new Option('No related project', ''));
            const notice = form.querySelector('[data-activity-project-notice]');
            if (notice) {
                notice.hidden = false;
                notice.textContent = 'Association changed. The related Project selection was cleared. Select a Project from this association if needed.';
            }
            picker.querySelector('[data-lookup-term]').value = '';
            void loadLookup(picker);
        });
        for (const event of ['input', 'change']) form.addEventListener(event, () => { form.dataset.programDirty = 'true'; });
        root.querySelectorAll('[data-program-page-link]').forEach(link => link.addEventListener('click', event => {
            if (form.dataset.programDirty !== 'true' || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            document.dispatchEvent(new CustomEvent('assocmap:confirm-navigation', { detail: {
                link, title: 'Open another participant page?',
                message: 'Unsaved changes on this page will be discarded. Save Training first to keep your attendance and participant changes.',
                label: 'Leave This Page',
            } }));
        }));
    });
}
