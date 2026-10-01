import { $, $$, setFieldVisibility } from '../../core/helpers.js';

function syncForm(form) {
    const role = $('[name=role]', form)?.value || 'Field Officer';
    if (form.dataset.module === 'users') {
        setFieldVisibility($('[data-field=full_name]', form), role === 'Field Officer');
        setFieldVisibility($('[data-field=contact_number]', form), role === 'Field Officer');
        setFieldVisibility($('[data-field=display_name]', form), role === 'Association Member Account');
        setFieldVisibility($('[data-field=association]', form), role === 'Association Member Account');
    }
    if (form.matches('[data-area-form]')) {
        const typeField = $('select[name=area_type]', form);
        const type = typeField.value;
        const editing = form.dataset.mode === 'edit';
        typeField.disabled = editing;
        $('[data-area-edit-type]', form).disabled = !editing;
        $('[data-area-edit-type]', form).value = type;
        $('[data-area-method]', form).disabled = !editing;
        $('[name=area_record_id]', form).disabled = !editing;
        $$('[data-area-fields]', form).forEach(group => setFieldVisibility(group, group.dataset.areaFields === type));
        form.action = editing ? form.dataset.updateAction
            : (type === 'Barangay' ? form.dataset.barangayAction : form.dataset.municipalityAction);
        $('[data-area-submit]', form).textContent = editing ? 'Save Changes'
            : (type === 'Barangay' ? 'Save Barangay' : 'Save Municipality');
    }
}

function initAreaList() {
    const list = $('[data-list="areas"]');
    if (!list) return;
    const tbody = $('.data-table tbody', list);
    const rows = $$('tr', tbody);
    const filterForm = $('[data-filter-form]', list);
    const search = $('[data-search]', list);
    const municipality = $('[data-area-municipality]', list);
    const sort = $('[data-sort]', list);
    let listType = 'Municipality';
    let filters = {};
    const normalize = value => String(value || '').trim().toLocaleLowerCase();
    const fields = { area_type: 'areaType', municipality: 'municipality', source: 'source', status: 'status' };

    function update() {
        $$('[data-area-parent-column]', list).forEach(cell => { cell.hidden = listType !== 'Barangay'; });
        const query = normalize(search.value);
        const municipalityQuery = normalize(municipality.value);
        rows.forEach(row => {
            const typeMatches = listType === 'Barangay'
                ? row.dataset.areaType === 'Barangay'
                : ['Municipality', 'City'].includes(row.dataset.areaType);
            const text = normalize(`${row.dataset.name} ${row.dataset.areaType} ${row.dataset.municipality} ${row.dataset.status}`);
            const filtersMatch = Object.entries(filters).every(([name, value]) =>
                !value || normalize(row.dataset[fields[name]]) === normalize(value));
            row.hidden = !(typeMatches && filtersMatch && text.includes(query)
                && normalize(row.dataset.municipality).includes(municipalityQuery));
        });
        rows.sort((a, b) => {
            if (['newest', 'oldest'].includes(sort.value)) {
                const difference = (Date.parse(a.dataset.createdAt) || 0) - (Date.parse(b.dataset.createdAt) || 0);
                if (difference) return sort.value === 'newest' ? -difference : difference;
            }
            const difference = a.dataset.name.localeCompare(b.dataset.name, undefined, { sensitivity: 'base', numeric: true });
            return sort.value === 'za' ? -difference : difference;
        }).forEach(row => tbody.append(row));
        const count = rows.filter(row => !row.hidden).length;
        $('[data-empty]', list).hidden = count > 0;
        $('[data-result-count]', list).textContent = `${count} ${count === 1 ? 'result' : 'results'}`;
        const filterCount = Object.values(filters).filter(Boolean).length;
        const badge = $('[data-filter-count]', list);
        badge.hidden = filterCount === 0;
        badge.textContent = filterCount;
        $$('[data-area-list-type]', list).forEach(button => {
            button.setAttribute('aria-pressed', String(button.dataset.areaListType === listType));
        });
    }

    list.addEventListener('click', event => {
        const switchButton = event.target.closest('[data-area-list-type]');
        if (switchButton) { listType = switchButton.dataset.areaListType; update(); }
        if (event.target.closest('[data-filter-apply]')) {
            filters = Object.fromEntries(Object.keys(fields).map(name => [name, $(`[name="${name}"]`, filterForm).value]));
            update();
        }
    });
    filterForm.addEventListener('reset', () => { filters = {}; update(); });
    search.addEventListener('input', update);
    municipality.addEventListener('input', update);
    sort.addEventListener('change', update);
    update();
}

export function initForms() {
    initAreaList();
    $$('[data-form]').forEach(form => {
        syncForm(form);
        form.addEventListener('submit', event => {
            if (form.matches('[data-area-form]')) syncForm(form);
            else event.preventDefault();
        });
        form.addEventListener('change', () => syncForm(form));
        form.addEventListener('reset', () => setTimeout(() => syncForm(form), 0));
        if (form.matches('[data-area-form]') && form.dataset.hasErrors === 'true') {
            form.closest('dialog').showModal();
        }
    });
    $$('[data-repeater]').forEach(repeater => { repeater.dataset.nextIndex = $$('[data-repeat-row]', repeater).length; });
    document.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.matches('[data-add-row]')) {
            const repeater = button.closest('[data-repeater]');
            const index = Number(repeater.dataset.nextIndex || $$('[data-repeat-row]', repeater).length);
            repeater.dataset.nextIndex = index + 1;
            const row = $('[data-row-template]', repeater).content.cloneNode(true);
            $$('[id], [for], [name], [aria-describedby]', row).forEach(element => {
                ['id', 'for', 'name', 'aria-describedby'].forEach(attribute => {
                    if (element.hasAttribute(attribute)) element.setAttribute(attribute, element.getAttribute(attribute).replaceAll('__INDEX__', String(index)));
                });
            });
            $('[data-repeater-rows]', repeater).append(row);
            $$('[data-row-number]', repeater).forEach((number, i) => { number.textContent = i + 1; });
            $('input, select, textarea', $$('[data-repeat-row]', repeater).at(-1))?.focus();
        }
        if (button.matches('[data-remove-row]')) {
            const repeater = button.closest('[data-repeater]');
            button.closest('[data-repeat-row]').remove();
            $$('[data-row-number]', repeater).forEach((number, i) => { number.textContent = i + 1; });
            $('[data-add-row]', repeater).focus();
        }
    });
}
