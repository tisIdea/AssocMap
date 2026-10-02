import { $, $$, setFieldVisibility } from '../../core/helpers.js';

function syncForm(form) {
    if (form.matches('[data-association-form]')) {
        const records = JSON.parse(form.dataset.areaRecords);
        const municipality = $('[data-association-municipality]', form);
        const barangay = $('[data-association-barangay]', form);
        const area = $('[name=area_id]', form);
        const populate = (select, choices, label) => {
            const selected = select.value;
            select.replaceChildren(new Option(choices.length ? label : 'No choices available', ''));
            choices.forEach(choice => select.add(new Option(choice.label, choice.value)));
            select.value = choices.some(choice => choice.value === selected) ? selected : '';
        };
        const matching = records.filter(record => municipality.value && record.municipality_id === municipality.value);
        const barangays = new Map(matching.filter(record => record.barangay_id)
            .map(record => [record.barangay_id, { value: record.barangay_id, label: record.barangay }]));
        populate(barangay, Array.from(barangays.values()), 'Select barangay');
        populate(area, matching.filter(record => barangay.value && record.barangay_id === barangay.value), 'Select specific area');
    }
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
        setFieldVisibility($('[data-area-reference-fields]', form), type !== 'Specific Area');
        if (type === 'Specific Area') {
            const municipality = $('[data-specific-municipality]', form);
            const barangay = $('[name=barangay_id]', form);
            const selected = barangay.value;
            const choices = JSON.parse(form.dataset.barangayRecords)
                .filter(choice => choice.municipality_id === municipality.value);
            barangay.replaceChildren(new Option(choices.length ? 'Select…' : 'No barangays available.', ''));
            choices.forEach(choice => barangay.add(new Option(choice.label, choice.value)));
            barangay.value = choices.some(choice => choice.value === selected) ? selected : '';
        }
        form.action = editing ? form.dataset.updateAction
            : (type === 'Specific Area' ? form.dataset.specificAreaAction
                : (type === 'Barangay' ? form.dataset.barangayAction : form.dataset.municipalityAction));
        $('[data-area-submit]', form).textContent = editing ? 'Save Changes'
            : (type === 'Specific Area' ? 'Save Area' : (type === 'Barangay' ? 'Save Barangay' : 'Save Municipality'));
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
    const fields = { area_type: 'areaType', municipality: 'municipality', barangay: 'barangayId', source: 'source', status: 'status' };

    function update() {
        const specific = listType === 'Specific Area';
        $$('[data-area-column]', list).forEach(cell => {
            const column = cell.dataset.areaColumn;
            cell.hidden = (column === 'municipality' && listType === 'Municipality')
                || (column === 'barangay' && !specific) || (column === 'area_type' && specific);
        });
        $('th[data-area-column=name]', list).textContent = specific ? 'Area / Address' : 'Area Name';
        $('[data-field=barangay]', filterForm).hidden = !specific;
        $$('[data-specific-sort]', sort).forEach(option => { option.hidden = !specific; });
        if (!specific && sort.value === 'barangay') sort.value = 'az';
        $('option[value=az]', sort).textContent = specific ? 'Area / Address A–Z' : 'Name A–Z';
        $('option[value=za]', sort).textContent = specific ? 'Area / Address Z–A' : 'Name Z–A';
        const query = normalize(search.value);
        const municipalityQuery = normalize(municipality.value);
        rows.forEach(row => {
            const typeMatches = listType !== 'Municipality'
                ? row.dataset.areaType === listType
                : ['Municipality', 'City'].includes(row.dataset.areaType);
            const text = normalize(`${row.dataset.name} ${row.dataset.areaType} ${row.dataset.barangay} ${row.dataset.municipality} ${row.dataset.status}`);
            const filtersMatch = Object.entries(filters).every(([name, value]) =>
                !value || (name === 'barangay' && !specific) || normalize(row.dataset[fields[name]]) === normalize(value));
            row.hidden = !(typeMatches && filtersMatch && text.includes(query)
                && normalize(row.dataset.municipality).includes(municipalityQuery));
        });
        rows.sort((a, b) => {
            if (['newest', 'oldest'].includes(sort.value)) {
                const difference = (Date.parse(a.dataset.createdAt) || 0) - (Date.parse(b.dataset.createdAt) || 0);
                if (difference) return sort.value === 'newest' ? -difference : difference;
            }
            const key = ['barangay', 'municipality', 'status'].includes(sort.value) ? sort.value : 'name';
            const difference = a.dataset[key].localeCompare(b.dataset[key], undefined, { sensitivity: 'base', numeric: true });
            return sort.value === 'za' ? -difference : difference;
        }).forEach(row => tbody.append(row));
        const count = rows.filter(row => !row.hidden).length;
        $('[data-empty]', list).hidden = count > 0;
        $('[data-result-count]', list).textContent = `${count} ${count === 1 ? 'result' : 'results'}`;
        const filterCount = Object.entries(filters).filter(([name, value]) => value && (name !== 'barangay' || specific)).length;
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

function initRecordList(moduleKey) {
    const memberList = moduleKey === 'members';
    const prefix = memberList ? 'member' : 'association';
    const nameKey = memberList ? 'full_name' : 'name';
    const dateKey = memberList ? 'date_registered' : 'joined_at';
    const statusKey = memberList ? 'member_status' : 'status_value';
    const searchKeys = memberList
        ? ['full_name', 'association', 'municipality', 'barangay', 'area', 'sex', 'position', 'fishr_registration_number', 'date_registered', 'status']
        : ['name', 'acronym', 'municipality', 'barangay', 'area', 'field_officer', 'joined_at', 'status'];
    const list = $(`[data-list="${moduleKey}"]`);
    if (!list) return;
    const indexUrl = list.dataset[`${prefix}IndexUrl`];
    const navigation = $(`[data-nav="${moduleKey}"]`);
    if (navigation) navigation.href = indexUrl;
    const tbody = $('.data-table tbody', list);
    const rows = $$('tr', tbody);
    const records = new Map(rows.map(row => [row, JSON.parse($(`[data-${prefix}-record]`, row).dataset[`${prefix}Record`])]));
    const filterForm = $('[data-filter-form]', list);
    const loadedStatus = list.dataset[`${prefix}StatusFilter`] || '';
    const parameters = new URLSearchParams(location.search);
    $$('select', filterForm).forEach(select => {
        select.value = select.name === 'status' ? loadedStatus : (parameters.get(select.name) || '');
    });
    const search = $('[data-search]', list);
    const sort = $('[data-sort]', list);
    let filters = Object.fromEntries(new FormData(filterForm));
    const normalize = value => String(value ?? '').trim().toLocaleLowerCase();

    function update() {
        const query = normalize(search.value);
        rows.forEach(row => {
            const record = records.get(row);
            row.hidden = !Object.entries(filters).every(([key, value]) => !value
                || (key === 'status' ? (value === 'archived' ? loadedStatus === 'archived' : record[statusKey] === value) : record[key] === value))
                || !searchKeys.some(key => normalize(record[key]).includes(query));
        });
        rows.sort((a, b) => {
            const left = records.get(a);
            const right = records.get(b);
            if (['newest', 'oldest'].includes(sort.value)) {
                if (!left[dateKey] || !right[dateKey]) {
                    if (left[dateKey] !== right[dateKey]) return left[dateKey] ? -1 : 1;
                } else {
                    const difference = left[dateKey].localeCompare(right[dateKey]);
                    if (difference) return sort.value === 'newest' ? -difference : difference;
                }
            }
            const sortKeys = memberList ? ['association', 'municipality', 'position', 'status'] : ['municipality', 'barangay', 'field_officer', 'status'];
            const key = sortKeys.includes(sort.value) ? sort.value : nameKey;
            const difference = String(left[key] ?? '').localeCompare(String(right[key] ?? ''), undefined, { sensitivity: 'base', numeric: true });
            return sort.value === 'za' ? -difference : difference;
        }).forEach(row => tbody.append(row));
        const count = rows.filter(row => !row.hidden).length;
        $('[data-empty]', list).hidden = count > 0;
        $('[data-result-count]', list).textContent = `${count} ${count === 1 ? 'result' : 'results'}`;
        const filterCount = Object.values(filters).filter(Boolean).length;
        const badge = $('[data-filter-count]', list);
        badge.hidden = filterCount === 0;
        badge.textContent = filterCount;
    }

    $('[data-filter-apply]', filterForm).addEventListener('click', () => {
        filters = Object.fromEntries(new FormData(filterForm));
        if (filters.status !== loadedStatus) {
            const url = new URL(indexUrl, location.origin);
            Object.entries(filters).forEach(([key, value]) => { if (value) url.searchParams.set(key, value); });
            url.hash = moduleKey;
            location.assign(url.href);
            return;
        }
        update();
    });
    filterForm.addEventListener('reset', () => {
        if (loadedStatus) {
            location.assign(`${indexUrl}#${moduleKey}`);
            return;
        }
        filters = {};
        update();
    });
    search.addEventListener('input', update);
    sort.addEventListener('change', update);
    list.addEventListener('click', event => {
        const editorButton = event.target.closest('[data-association-add], [data-association-edit]');
        if (editorButton) {
            const form = $('[data-association-form]');
            const record = editorButton.hasAttribute('data-association-edit')
                ? records.get(editorButton.closest('tr')) : null;
            if (record?.archived_at) return;
            form.reset();
            form.action = record ? record.update_url : form.dataset.createAction;
            $('[data-association-method]', form).disabled = !record;
            const recordId = $('[name=association_record_id]', form);
            recordId.disabled = !record;
            recordId.value = record?.id || '';
            $('#association-editor-title').textContent = record ? 'Edit Association' : 'Add Association';
            $('[data-association-submit]', form).textContent = record ? 'Save Changes' : 'Save Association';
            $('[data-association-validation]', form)?.setAttribute('hidden', '');
            $('[name=name]', form).value = record?.name || '';
            $('[name=acronym]', form).value = record?.acronym === '—' ? '' : (record?.acronym || '');
            $('[name=saad_phase2_join_date]', form).value = record?.joined_at || '';
            $('[name=primary_field_officer_id]', form).value = record?.primary_field_officer_id || '';
            $('[name=status]', form).value = record?.status_value || 'active';
            $('[data-association-municipality]', form).value = record?.municipality_id || '';
            syncForm(form);
            $('[data-association-barangay]', form).value = record?.barangay_id || '';
            syncForm(form);
            $('[name=area_id]', form).value = record?.area_id || '';
            $('[data-association-unavailable]', form).hidden = !record
                || (Boolean($('[name=area_id]', form).value) && Boolean($('[name=primary_field_officer_id]', form).value));
        }
        const button = event.target.closest(`[data-${prefix}-view]`);
        if (!button) return;
        const record = JSON.parse(button.dataset[`${prefix}Record`]);
        const modal = $(`#${prefix}-modal`);
        if (memberList) {
            $$('[data-member-optional]', modal).forEach(element => {
                element.hidden = !record[element.dataset.memberOptional];
            });
        } else $('[data-association-archived-date]', modal).hidden = !record.archived_at;
        $$(`[data-${prefix}-detail]`, modal).forEach(element => {
            element.textContent = record[element.dataset[`${prefix}Detail`]] || '—';
        });
    });
    update();
}

export function initForms() {
    initAreaList();
    initRecordList('associations');
    initRecordList('members');
    $$('[data-form]').forEach(form => {
        syncForm(form);
        form.addEventListener('submit', event => {
            if (form.matches('[data-area-form]')) {
                syncForm(form);
                const typeField = $('select[name=area_type]', form);
                if (typeField.value === 'Specific Area') typeField.disabled = true;
            }
            else if (!form.matches('[data-association-form]')) event.preventDefault();
        });
        form.addEventListener('change', () => syncForm(form));
        form.addEventListener('reset', () => setTimeout(() => syncForm(form), 0));
        if (form.matches('[data-area-form], [data-association-form]') && form.dataset.hasErrors === 'true') {
            if (form.matches('[data-area-form]') && ($('[data-association-form]') || $('[data-member-validation]'))) return;
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
