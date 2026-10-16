<script type="module">
    const records = {{ Illuminate\Support\Js::from($rows->values()) }};
    const quarters = {{ Illuminate\Support\Js::from($quarters->map(fn ($quarter) => ['id' => $quarter->id, 'quarter_number' => $quarter->quarter_number, 'months' => app(\App\Services\ProductionMonitoringService::class)->monthsForQuarter($quarter->quarter_number)])->values()) }};
    const monthNames = {{ Illuminate\Support\Js::from($months) }};
    const projects = {{ Illuminate\Support\Js::from($projectRows) }};
    const occupiedPeriods = {{ Illuminate\Support\Js::from($occupiedPeriods ?? collect()) }};
    const initial = {{ Illuminate\Support\Js::from($restore ? old() : ($monitoringRows->first() ?? [])) }};
    const activeSection = {{ Illuminate\Support\Js::from($section) }};
    const errors = {{ Illuminate\Support\Js::from($restore ? $errors->messages() : []) }};
    const restore = {{ Illuminate\Support\Js::from($restore) }};
    const createUrl = {{ Illuminate\Support\Js::from(route($monitoringPrefix.'.store')) }};
    const fieldOfficer = {{ Illuminate\Support\Js::from($monitoringPrefix === 'field-officer.monitoring') }};
    if (fieldOfficer) {
        document.title = 'Monitoring · AssocMap';
        document.querySelector('.topbar-context > span').textContent = 'Monitoring';
    }
    const form = document.getElementById('monitoring-form');
    const removalDialog = document.getElementById('monitoring-removal-modal');
    const removalConfirm = removalDialog.querySelector('[data-removal-confirm]');
    let pendingRemoval = null;
    removalDialog.setAttribute('aria-describedby', 'monitoring-removal-message');
    function confirmRemoval(title, message, opener, action) {
        if (pendingRemoval || removalDialog.open) return;
        pendingRemoval = {opener, action};
        document.getElementById('monitoring-removal-modal-title').textContent = title;
        document.getElementById('monitoring-removal-message').textContent = message;
        removalDialog.returnValue = '';
        removalConfirm.disabled = false;
        removalDialog.showModal();
        removalDialog.querySelector('[data-removal-cancel]').focus();
    }
    removalDialog.querySelector('[data-removal-cancel]').addEventListener('click', () => removalDialog.close('cancel'));
    removalConfirm.addEventListener('click', () => {
        if (!pendingRemoval || removalConfirm.disabled) return;
        removalConfirm.disabled = true;
        removalDialog.close('remove');
    });
    removalDialog.addEventListener('close', () => {
        const pending = pendingRemoval;
        pendingRemoval = null;
        if (!pending) return;
        if (removalDialog.returnValue === 'remove') pending.action();
        else if (pending.opener.isConnected) pending.opener.focus();
    });
    const projectSelect = form.elements.project_id;
    const incomeProduct = document.getElementById('monitoring-income-product');
    const notice = document.getElementById('monitoring-choice-notice');
    const containers = {production: document.getElementById('monitoring-production'), materials: document.getElementById('monitoring-materials')};
    const counters = {production: 0, materials: 0};
    let editingRecord = null;
    let selectedProjectValue = '';
    let periodValues = {year: '', quarter_id: ''};
    const ownInputs = row => Array.from(row.querySelectorAll('[data-key]')).filter(input => input.closest('[data-kind]') === row);
    const rootRows = kind => Array.from(containers[kind].children);
    const incomeRows = row => Array.from(row.incomeGroup.querySelector('[data-income-container]').children);
    const monthlyRows = row => Array.from(row.querySelector('[data-monthly-container]').children);
    function showIncomeProduct() { rootRows('production').forEach(row => { if (row.incomeGroup) row.incomeGroup.hidden = row.dataset.prefix !== incomeProduct.value; }); }
    function refreshIncomeProducts() {
        const selected = incomeProduct.value;
        incomeProduct.replaceChildren(new Option('Select a Production product', ''));
        rootRows('production').filter(row => row.incomeGroup?.isConnected).forEach(row => {
            const isNew = row.querySelector('[data-resource-mode]').value === 'new';
            const name = isNew ? row.querySelector('[data-resource-key="product_name"]').value.trim() : choices('production').find(product => String(product.id) === row.querySelector('[data-key="project_product_id"]').value)?.product_name;
            const option = new Option(name || 'Choose a product in Production', row.dataset.prefix); option.disabled = !name; incomeProduct.add(option);
        });
        incomeProduct.disabled = incomeProduct.options.length === 1;
        incomeProduct.value = Array.from(incomeProduct.options).some(option => option.value === selected) && selected ? selected : Array.from(incomeProduct.options).find(option => option.value && !option.disabled)?.value ?? '';
        showIncomeProduct();
    }
    function revealIncome(input) { const group = input.closest('[data-production-income]'); if (group) { incomeProduct.value = group.productionRow.dataset.prefix; showIncomeProduct(); } }
    incomeProduct.addEventListener('change', showIncomeProduct);
    function quarterBounds(record = null) {
        const quarter = quarters.find(item => String(item.id) === String(record?.quarter_id ?? form.elements.quarter_id.value));
        const year = Number(record?.year ?? form.elements.year.value);
        if (!quarter || year < 2000 || year > 2100) return null;
        const first = quarter.months[0];
        const last = first + 2;
        return {start: `${year}-${String(first).padStart(2, '0')}-01`, end: `${year}-${String(last).padStart(2, '0')}-${new Date(year, last, 0).getDate()}`};
    }
    function fillConductor(target, project, report = null) {
        const select = target.elements.conducted_by_user_id;
        select.replaceChildren(new Option('Select assigned officer', ''));
        if (project?.assigned_officer) select.add(new Option(project.assigned_officer.name, project.assigned_officer.id));
        if (report?.conducted_by_user_id && report.conducted_by_user_id !== project?.assigned_officer?.id) select.add(new Option((report.conducted_by_user?.display_name ?? 'Previous officer') + ' (historical selection)', report.conducted_by_user_id));
        select.value = report?.conducted_by_user_id ?? '';
        target.elements.conducted_by.value = report?.conducted_by ?? '';
    }
    function refreshMonthly(production) {
        const output = production.querySelector('[data-quarterly-output]');
        const notice = production.querySelector('[data-monthly-notice]');
        if (production.dataset.legacyProduction) {
            output.textContent = production.savedProduction.actual_output + ' ' + production.savedProduction.unit;
            monthlyRows(production).forEach(row => { row.querySelector('[data-month-unit]').textContent = production.savedProduction.unit; });
            notice.textContent = 'Legacy quarterly-only or unreconciled record: saved output and monthly details are read-only. No monthly values are inferred.';
            return;
        }
        const rows = monthlyRows(production), allowed = reportingMonths();
        let cents = 0n;
        let complete = allowed.length === 3 && rows.length === 3;
        rows.forEach(row => {
            const month = Number(row.querySelector('[data-key="month"]').value);
            row.querySelector('[data-month-name]').textContent = [monthNames[month], form.elements.year.value].filter(Boolean).join(' ');
            const quantity = row.querySelector('[data-key="total_quantity"]');
            const count = row.querySelector('[data-key="activity_count"]');
            row.querySelector('[data-month-unit]').textContent = production.querySelector('[data-key="unit"]').value || 'Use the product measurement unit';
            const validQuantity = /^\d{1,12}(\.\d{1,2})?$/.test(quantity.value);
            quantity.setCustomValidity(!allowed.includes(month) ? 'Select a monitoring quarter before entering Production.'
                : quantity.value && !validQuantity ? 'Enter a nonnegative quantity with up to 12 whole digits and 2 decimal places.' : '');
            const valid = validQuantity && count.value !== '' && count.validity.valid;
            complete &&= valid && allowed.includes(month);
            if (valid) {
                const [whole, fraction = ''] = quantity.value.split('.');
                cents += BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
            }
        });
        const overflow = cents > 99999999999999n;
        if (overflow && rows.length) rows[rows.length - 1].querySelector('[data-key="total_quantity"]').setCustomValidity('Quarterly Actual Output exceeds NUMERIC(14,2).');
        output.textContent = complete && !overflow ? `${cents / 100n}.${String(cents % 100n).padStart(2, '0')} ${production.querySelector('[data-key="unit"]').value}` : 'Enter all three monthly quantities and activity counts';
        notice.textContent = 'Enter an activity count and actual production quantity for each month. Enter 0 explicitly for confirmed zero; blank means unrecorded. The server calculates and saves the quarterly sum.';
    }
    function addMonthly(production, values = {}, index = 0) {
        const row = document.getElementById('monitoring-monthly-template').content.firstElementChild.cloneNode(true);
        ownInputs(row).forEach(input => {
            input.name = `${production.dataset.prefix}[monthly_details][${index}][${input.dataset.key}]`;
            input.id = 'monitoring-' + input.name.replace(/[^a-zA-Z0-9_-]/g, '-');
            row.querySelector(`[data-label="${input.dataset.key}"]`)?.setAttribute('for', input.id);
            input.value = values[input.dataset.key] ?? '';
            if (input.dataset.key === 'id') input.disabled = !values.id;
        });
        const month = Number(values.month);
        row.querySelector('[data-month-name]').textContent = [monthNames[month], form.elements.year.value].filter(Boolean).join(' ');
        const quantity = row.querySelector('[data-key="total_quantity"]');
        quantity.setAttribute('aria-describedby', quantity.id + '-unit');
        const unit = row.querySelector('[data-month-unit]'); unit.id = quantity.id + '-unit';
        unit.textContent = production.querySelector('[data-key="unit"]').value || 'Use the product measurement unit';
        production.querySelector('[data-monthly-container]').append(row);
        return row;
    }
    function fillQuarterMonths(production, details = []) {
        production.querySelector('[data-monthly-container]').replaceChildren();
        const entries = Object.values(details ?? {});
        const rows = production.dataset.legacyProduction ? entries : reportingMonths().map(month => entries.find(detail => Number(detail.month) === month) ?? {month});
        rows.forEach((detail, index) => addMonthly(production, detail, index));
        refreshMonthly(production);
    }
    form.addEventListener('input', event => { if (event.target.name === 'conducted_by' && event.target.value.trim()) form.elements.conducted_by_user_id.value = ''; });
    form.addEventListener('change', event => { if (event.target.name === 'conducted_by_user_id' && event.target.value) form.elements.conducted_by.value = ''; });
    function activateTab(workspace, name, focus = false) {
        workspace.querySelectorAll('[data-monitoring-tab]').forEach(tab => {
            const active = tab.dataset.monitoringTab === name;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            if (active && focus) tab.focus();
        });
        workspace.querySelectorAll('[data-monitoring-panel]').forEach(panel => { panel.hidden = panel.dataset.monitoringPanel !== name; });
    }
    document.querySelectorAll('[data-monitoring-workspace]').forEach(workspace => {
        workspace.addEventListener('click', event => {
            const tab = event.target.closest('[data-monitoring-tab]');
            if (tab) activateTab(workspace, tab.dataset.monitoringTab);
            if (event.target.closest('[data-go-production]')) activateTab(workspace, 'production', true);
        });
        workspace.addEventListener('keydown', event => {
            const tab = event.target.closest('[data-monitoring-tab]');
            if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const tabs = Array.from(workspace.querySelectorAll('[data-monitoring-tab]'));
            const current = tabs.indexOf(tab);
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (current + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            activateTab(workspace, tabs[next].dataset.monitoringTab, true);
        });
    });
    function updateMaterialHeading(row) {
        const select = row.querySelector('[data-key="project_material_id"]');
        if (row.querySelector('[data-resource-mode]').value === 'new') {
            row.querySelector('[data-row-heading]').textContent = 'New Material — ' + (row.querySelector('[data-resource-key="item_name"]').value || 'Enter material details');
            return;
        }
        row.querySelector('[data-row-heading]').textContent = choices('materials').find(material => String(material.id) === select.value)?.item_name ?? (select.value ? row.dataset.originalLabel || 'Unavailable material' : 'Select a material');
    }
    function updateProductHeading(row) {
        const select = row.querySelector('[data-key="project_product_id"]');
        if (row.querySelector('[data-resource-mode]').value === 'new') {
            const name = row.querySelector('[data-resource-key="product_name"]').value || 'Enter product details';
            row.querySelector('[data-row-heading]').textContent = 'New Product — ' + name;
            row.querySelector('[data-product-type]').hidden = true;
            if (row.incomeGroup) row.incomeGroup.querySelector('[data-income-heading]').textContent = 'Income for New Product — ' + name;
            refreshIncomeProducts();
            return;
        }
        const product = choices('production').find(product => String(product.id) === select.value);
        row.querySelector('[data-row-heading]').textContent = product?.product_name ?? (select.value ? row.dataset.originalLabel || 'Unavailable product' : 'Select a product');
        const type = row.querySelector('[data-product-type]');
        type.textContent = product?.product_type ? `Product Type: ${product.product_type.charAt(0).toUpperCase()}${product.product_type.slice(1)}` : '';
        type.hidden = !product?.product_type;
        row.incomeGroup.querySelector('[data-income-heading]').textContent = 'Income for ' + (product?.product_name ?? row.dataset.originalLabel ?? 'product not selected');
        refreshIncomeProducts();
    }
    function refreshQuarterAvailability() {
        const status = document.getElementById('monitoring-quarter-availability');
        if (editingRecord) { status.hidden = true; return true; }
        const select = form.elements.quarter_id;
        const year = Number(form.elements.year.value);
        const project = selectedProject();
        const ready = Boolean(project) && /^\d{4}$/.test(form.elements.year.value) && year >= 2000 && year <= 2100;
        const used = new Set(occupiedPeriods.filter(period => ready && String(period.project_id) === String(project.id)
            && Number(period.year) === year).map(period => String(period.quarter_id)));
        const options = Array.from(select.options).filter(option => option.value);
        options.forEach(option => {
            option.dataset.availableLabel ??= option.textContent;
            option.disabled = !ready || used.has(option.value);
            option.textContent = option.dataset.availableLabel + (ready && used.has(option.value) ? ' — already recorded' : '');
        });
        if (select.value && !options.some(option => option.value === select.value && !option.disabled)) {
            select.value = '';
            periodValues.quarter_id = '';
        }
        const available = options.filter(option => !option.disabled);
        const canCreate = ready && available.length > 0;
        select.disabled = !canCreate;
        select.options[0].textContent = !ready ? 'Select project and year first…' : !available.length ? 'No quarters available' : 'Select a quarter…';
        select.setCustomValidity(canCreate ? '' : 'Select a project and year with an available monitoring quarter.');
        form.querySelector('button[type="submit"]').disabled = !canCreate;
        status.hidden = false;
        status.textContent = !ready ? 'Select a project and monitoring year to see available quarters.'
            : !available.length ? 'No quarters remain available for this project and year. View or edit its existing records from the Monitoring dashboard.'
            : used.size ? 'Already recorded quarters cannot be selected. Choose an available quarter, or view existing records from the Monitoring dashboard.'
            : 'All quarters are available for this project and year.';
        return canCreate;
    }
    function updateContext() {
        refreshQuarterAvailability();
        const project = selectedProject();
        const set = (key, value) => { form.querySelector(`[data-context-${key}]`).textContent = value; };
        set('association', project?.association_name ?? 'Select a project to see its association');
        set('project', project?.title ?? 'No project selected');
        set('program-year', project?.program_year ?? '—');
        const quarter = form.elements.quarter_id;
        set('period', [quarter.value ? quarter.selectedOptions[0].textContent : 'Quarter not selected', form.elements.year.value || 'Year not entered', form.elements.monitoring_date.value || 'Date not entered'].join(' · '));
        const status = form.elements.project_status;
        const badge = document.createElement('span');
        badge.className = `badge badge-${status.value === 'completed' ? 'success' : ['cancelled', 'suspended'].includes(status.value) ? 'danger' : 'warning'}`;
        badge.textContent = status.value ? status.selectedOptions[0].textContent : 'Status not selected';
        form.querySelector('[data-context-status]').replaceChildren(badge);
        set('validated', form.elements.site_validated.value === '' ? 'Not selected' : form.elements.site_validated.value === '1' ? 'Yes' : 'No');
        set('officer', form.dataset.monitoredBy);
        const bounds = !editingRecord && quarterBounds();
        form.elements.monitoring_date.min = bounds?.start ?? '';
        form.elements.monitoring_date.max = bounds?.end ?? '';
    }
    const contextFields = ['project_id', 'year', 'quarter_id', 'monitoring_date', 'site_validated', 'project_status'];
    ['input', 'change'].forEach(type => form.addEventListener(type, event => {
        if (contextFields.includes(event.target.name)) updateContext();
    }));
    form.addEventListener('invalid', event => {
        revealIncome(event.target);
        const panel = event.target.closest('[data-monitoring-panel]');
        if (panel) activateTab(form, panel.dataset.monitoringPanel);
    }, true);
    function selectedProject() { return projects.find(project => String(project.id) === projectSelect.value); }
    function choices(kind) { return selectedProject()?.[kind === 'production' ? 'products' : 'materials'] ?? []; }
    function fillChoices(select, kind, selected = '') {
        select.replaceChildren(new Option('Select…', ''));
        choices(kind).forEach(choice => select.add(new Option(choice[kind === 'production' ? 'product_name' : 'item_name'], choice.id)));
        select.value = String(selected ?? '');
        const unavailable = Boolean(selected && select.value !== String(selected));
        if (unavailable) {
            const retained = new Option('Unavailable item — choose an eligible replacement', String(selected));
            retained.disabled = true;
            select.add(retained);
            select.value = String(selected);
        }
        return unavailable;
    }
    function refreshUnique(kind) {
        const key = kind === 'production' ? 'project_product_id' : 'project_material_id';
        const existingRows = rootRows(kind).filter(row => row.querySelector('[data-resource-mode]').value === 'existing');
        const selects = existingRows.map(row => row.querySelector('[data-key="' + key + '"]'));
        const selected = selects.map(select => select.value).filter(Boolean);
        const eligible = choices(kind).map(item => String(item.id));
        selects.forEach(select => {
            Array.from(select.options).forEach(option => { option.disabled = Boolean(option.value && (!eligible.includes(option.value) || (option.value !== select.value && selected.includes(option.value)))); });
            select.setCustomValidity(select.value && !eligible.includes(select.value) ? 'Choose an eligible item belonging to the selected project.' : select.value && selected.filter(value => value === select.value).length > 1 ? 'Select each item only once.' : '');
        });
        if (kind === 'production') { refreshProductNames(); refreshIncomeProducts(); }
        form.querySelector('[data-add-root="' + kind + '"]').disabled = !projectSelect.value || (Boolean(editingRecord) && rootRows(kind).length >= choices(kind).length);
        document.getElementById('monitoring-' + kind + '-empty').hidden = rootRows(kind).length > 0;
        document.getElementById('monitoring-income-empty').hidden = rootRows('production').length > 0;
    }
    function refreshProductNames() {
        const rows = rootRows('production').filter(row => row.querySelector('[data-resource-mode]').value === 'new');
        const names = rows.map(row => row.querySelector('[data-resource-key="product_name"]').value.trim().toLocaleLowerCase());
        const existing = choices('production').map(product => product.product_name.trim().toLocaleLowerCase());
        rows.forEach((row, index) => {
            const input = row.querySelector('[data-resource-key="product_name"]');
            const value = names[index];
            input.setCustomValidity(value && existing.includes(value) ? 'This product already exists. Select the existing project product.' : value && names.filter(name => name === value).length > 1 ? 'Register each new product name only once.' : '');
        });
    }
    function syncResourceMode(row) {
        const kind = row.dataset.kind;
        const mode = row.querySelector('[data-resource-mode]');
        const registration = row.querySelector('[data-registration-panel]');
        const newResource = !editingRecord && mode.value === 'new';
        if (editingRecord) mode.value = 'existing';
        mode.disabled = Boolean(editingRecord);
        mode.querySelector('option[value="new"]').disabled = Boolean(editingRecord);
        registration.hidden = !newResource;
        registration.disabled = !newResource;
        const existing = row.querySelector('[data-existing-resource]');
        existing.hidden = newResource;
        existing.querySelector('select').disabled = newResource;
        row.querySelectorAll('[data-resource-key]').forEach(input => { input.disabled = !newResource; });
        if (kind === 'production') updateProductHeading(row);
        else updateMaterialHeading(row);
        refreshUnique(kind);
    }
    function reportingMonths() {
        const quarter = quarters.find(quarter => String(quarter.id) === form.elements.quarter_id.value);
        if (!quarter) return [];
        return quarter.months;
    }
    function refreshIncome(production) {
        const rows = incomeRows(production);
        const frequency = production.incomeGroup.querySelector('[data-income-frequency]');
        const allowed = reportingMonths();
        const used = rows.map(row => row.querySelector('[data-key="month"]').value).filter(Boolean);
        let conflict = false;
        rows.forEach(row => {
            const period = row.querySelector('[data-key="period_type"]');
            const month = row.querySelector('[data-key="month"]');
            const selected = month.value;
            const monthly = period.value === 'monthly';
            conflict ||= period.value !== frequency.value;
            month.closest('.field').hidden = !monthly;
            month.disabled = !monthly;
            month.required = monthly;
            month.replaceChildren(new Option('Select a month…', ''));
            allowed.forEach(value => {
                const option = new Option([monthNames[value], form.elements.year.value].filter(Boolean).join(' '), String(value));
                option.disabled = String(value) !== selected && used.includes(String(value));
                month.add(option);
            });
            if (monthly && selected && !allowed.includes(Number(selected))) {
                const retained = new Option('Previous month is outside this quarter — choose a month', selected);
                retained.disabled = true;
                month.add(retained);
            }
            month.value = selected;
            month.setCustomValidity(monthly && selected && !allowed.includes(Number(selected)) ? 'Choose a month in the selected monitoring quarter.' : monthly && selected && used.filter(value => value === selected).length > 1 ? 'Select each month only once.' : '');
            row.querySelector('[data-row-heading]').textContent = monthly ? (selected && allowed.includes(Number(selected)) ? month.selectedOptions[0].textContent + ' Income' : 'Monthly Income') : 'Quarterly Income';
            if (!monthly && rows.filter(item => item.querySelector('[data-key="period_type"]').value === 'quarterly').length > 1) conflict = true;
        });
        frequency.setCustomValidity(conflict ? 'Use one income frequency per production entry. Remove conflicting entries before saving.' : '');
        frequency.disabled = Boolean(production.persistedFrequency);
        const add = production.incomeGroup.querySelector('[data-add-income]');
        add.disabled = !allowed.length || !frequency.value || conflict || (frequency.value === 'quarterly' ? rows.length >= 1 : rows.length >= 3);
        add.textContent = frequency.value === 'quarterly' ? 'Add Quarterly Income' : 'Add Monthly Income';
        const message = production.incomeGroup.querySelector('[data-income-notice]');
        message.textContent = !allowed.length ? 'Select a monitoring quarter before adding income.' : !frequency.value ? 'Choose monthly or quarterly reporting for this production entry.' : 'No income entries yet. Add an entry to record gross income.';
        message.hidden = rows.length > 0 && allowed.length > 0;
        production.incomeGroup.querySelector('[data-income-empty]').disabled = rows.length > 0;
    }
    function changeIncomeFrequency(production, frequency) {
        const previous = production.incomeFrequency;
        if (production.persistedFrequency && frequency.value !== production.persistedFrequency) {
            frequency.value = previous;
            return;
        }
        const rows = incomeRows(production);
        if (rows.length && !window.confirm('Switch income reporting frequency? Current entries will be kept as an unsaved draft in this dialog. Only entries for the selected frequency will be saved.')) {
            frequency.value = previous;
            return;
        }
        production.incomeDrafts[previous] = rows;
        rows.forEach(row => row.remove());
        production.incomeFrequency = frequency.value;
        const container = production.incomeGroup.querySelector('[data-income-container]');
        (production.incomeDrafts[frequency.value] ?? []).forEach(row => container.append(row));
        if (!incomeRows(production).length && frequency.value && reportingMonths().length) {
            addRow('income', {period_type: frequency.value}, null, production);
        }
        refreshIncome(production);
    }
    function addRow(kind, values = {}, index = null, parent = null) {
        if (index === null) index = kind === 'income' ? Number(parent.dataset.nextIncome || 0) : counters[kind];
        if (kind === 'income') parent.dataset.nextIncome = String(Math.max(Number(parent.dataset.nextIncome || 0), Number(index) + 1));
        else counters[kind] = Math.max(counters[kind], Number(index) + 1);
        const row = document.getElementById(`monitoring-${kind}-template`).content.firstElementChild.cloneNode(true);
        const prefix = kind === 'income' ? `${parent.dataset.prefix}[income][${index}]` : `${kind}[${index}]`;
        row.dataset.prefix = prefix;
        row.dataset.originalLabel = values.product_name ?? values.item_name ?? '';
        row.querySelector('[data-row-heading]').textContent = `${kind === 'materials' ? 'Material' : kind === 'production' ? 'Production' : 'Income'} ${Number(index) + 1}`;
        ownInputs(row).forEach(input => {
            const key = input.dataset.key;
            input.name = `${prefix}[${key}]`;
            input.id = `monitoring-${input.name.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
            row.querySelector(`[data-label="${key}"]`)?.setAttribute('for', input.id);
            input.value = values[key] ?? '';
            if (key === 'id') input.disabled = !values.id;
        });
        if (kind !== 'income') {
            const payload = kind === 'production' ? 'new_product' : 'new_material';
            const mode = row.querySelector('[data-resource-mode]');
            mode.id = 'monitoring-resource-mode-' + kind + '-' + index;
            row.querySelector('[data-resource-mode-label]').htmlFor = mode.id;
            mode.value = !editingRecord && (values[payload] || !choices(kind).length) ? 'new' : 'existing';
            row.querySelectorAll('[data-resource-key]').forEach(input => {
                const key = input.dataset.resourceKey;
                input.name = prefix + '[' + payload + '][' + key + ']';
                input.id = 'monitoring-' + input.name.replace(/[^a-zA-Z0-9_-]/g, '-');
                row.querySelector('[data-resource-label="' + key + '"]').htmlFor = input.id;
                input.value = values[payload]?.[key] ?? '';
            });
            const key = kind === 'production' ? 'project_product_id' : 'project_material_id';
            if (fillChoices(row.querySelector(`[data-key="${key}"]`), kind, values[key])) {
                notice.textContent = 'An original product or material is unavailable. Its entered values have been retained; choose an eligible item before saving.';
                notice.hidden = false;
            }
            containers[kind].append(row);
            if (kind === 'materials') updateMaterialHeading(row);
            if (kind === 'production') {
                const group = document.createElement('section');
                group.className = 'monitoring-income-group monitoring-card';
                group.dataset.productionIncome = '';
                group.productionRow = row;
                row.incomeGroup = group;
                const heading = document.createElement('h3');
                heading.dataset.incomeHeading = '';
                group.append(heading);
                const field = document.createElement('div');
                field.className = 'field monitoring-frequency';
                const label = document.createElement('label');
                const frequency = document.createElement('select');
                frequency.id = 'monitoring-frequency-' + index;
                frequency.dataset.incomeFrequency = '';
                label.htmlFor = frequency.id;
                label.textContent = 'Income Reporting Frequency';
                frequency.add(new Option('Choose reporting frequency…', ''));
                frequency.add(new Option('Monthly', 'monthly'));
                frequency.add(new Option('Quarterly', 'quarterly'));
                const persisted = editingRecord?.production?.find(item => String(item.id) === String(values.id));
                const savedIncome = Object.values(persisted?.income ?? values.income ?? {}).filter(item => item.id);
                row.persistedFrequency = savedIncome[0]?.period_type ?? '';
                row.incomeFrequency = Object.values(values.income ?? {})[0]?.period_type ?? row.persistedFrequency;
                row.incomeDrafts = {};
                frequency.value = row.incomeFrequency;
                const help = document.createElement('p');
                help.id = frequency.id + '-help';
                help.className = 'field-hint';
                help.textContent = row.persistedFrequency ? 'Saved income keeps its current frequency. Changing it needs a separate approved backend fix. Removing income entries takes effect only when you save.' : 'One frequency per production entry. Switching keeps unsaved drafts in this dialog; only the selected frequency is saved.';
                frequency.setAttribute('aria-describedby', help.id);
                field.append(label, frequency, help);
                group.append(field);
                ['[data-income-empty]', '[data-income-title]', '[data-income-container]', '[data-income-notice]', '[data-add-income]'].forEach(selector => group.append(row.querySelector(selector)));
                document.getElementById('monitoring-income-groups').append(group);
                updateProductHeading(row);
                row.incomeGroup.querySelector('[data-income-empty]').name = `${prefix}[income]`;
                Object.entries(values.income ?? {}).forEach(([incomeIndex, income]) => addRow('income', income, incomeIndex, row));
                refreshIncome(row);
            }
            syncResourceMode(row);
            if (kind === 'production') {
                const output = row.querySelector('[data-quarterly-output]');
                output.id = 'monitoring-quarterly-output-' + index;
                row.querySelector('[data-quarterly-label]').htmlFor = output.id;
                const saved = editingRecord?.production?.find(item => item.id === values.id);
                row.savedProduction = saved;
                row.dataset.legacyProduction = saved && !saved.can_edit_monthly ? '1' : '';
                fillQuarterMonths(row, row.dataset.legacyProduction ? saved.monthly_details : values.monthly_details);
                if (saved) {
                    row.querySelector('[data-monitoring-remove-row]').disabled = true;
                    const protectedKeys = row.dataset.legacyProduction
                        ? ['project_product_id', 'unit', 'target_output', 'remarks'] : ['project_product_id', 'unit'];
                    protectedKeys.forEach(key => {
                        const input = row.querySelector(`[data-key="${key}"]`);
                        input.value = saved[key] ?? '';
                        input.disabled = true;
                        if (key === 'project_product_id' || !row.dataset.legacyProduction) {
                            const proxy = document.createElement('input'); proxy.type = 'hidden';
                            proxy.name = input.name; proxy.value = input.value; proxy.dataset.key = key; row.append(proxy);
                        }
                    });
                    if (row.dataset.legacyProduction) row.querySelectorAll('[data-kind="monthly"] input, [data-kind="monthly"] textarea').forEach(input => { input.disabled = true; });
                }
                refreshMonthly(row);
            }
        } else {
            parent.incomeGroup.querySelector('[data-income-container]').append(row);
            refreshIncome(parent);
        }
        return row;
    }
    function populate(values, record = null) {
        editingRecord = record;
        form.querySelectorAll('[data-identity-payload]').forEach(input => input.remove());
        form.reset();
        form.querySelectorAll('[data-field-error]').forEach(error => error.remove());
        form.querySelectorAll('[aria-invalid]').forEach(input => { input.removeAttribute('aria-invalid'); if (input.id === 'monitoring-project_id') input.setAttribute('aria-describedby', 'monitoring-project-help'); else input.removeAttribute('aria-describedby'); });
        form.action = record?.update_url ?? createUrl;
        form.elements._method.disabled = !record;
        form.elements.monitoring_record_id.value = record?.id ?? '';
        form.dataset.monitoredBy = record?.monitored_by ?? 'Recorded by the signed-in officer on save';
        document.getElementById('monitoring-editor-title').textContent = record ? 'Edit Monitoring Record' : 'Add Monitoring Record';
        notice.hidden = true;
        document.getElementById('monitoring-income-groups').replaceChildren();
        activateTab(form, 'overview');
        for (const key of ['project_id', 'year', 'quarter_id', 'monitoring_date', 'site_validated', 'project_status', 'issues_encountered', 'actions_taken', 'overall_remarks']) {
            let value = (record && key !== 'project_status' ? record[key] : values[key]) ?? '';
            if (key === 'site_validated' && typeof value === 'boolean') value = value ? '1' : '0';
            form.elements[key].value = value;
        }
        ['project_id', 'year', 'quarter_id'].forEach(key => {
            const input = form.elements[key]; input.disabled = Boolean(record);
        });
        ['monitoring_date', 'site_validated', 'issues_encountered', 'actions_taken', 'overall_remarks'].forEach(key => { form.elements[key].closest('.field').hidden = Boolean(record); });
        form.querySelector('[data-initial-conductor]').hidden = Boolean(record);
        form.querySelector('[data-initial-conductor-help]').hidden = Boolean(record);
        fillConductor(form, selectedProject(), record ? null : values);
        form.elements.conducted_by_user_id.disabled = Boolean(record); form.elements.conducted_by.disabled = Boolean(record);
        if (values.project_id && !projectSelect.value) { notice.textContent = 'The original project is unavailable. Select an available project before saving.'; notice.hidden = false; }
        for (const kind of ['production', 'materials']) {
            containers[kind].replaceChildren();
            counters[kind] = 0;
            Object.entries(values[kind] ?? {}).forEach(([index, row]) => addRow(kind, row, index));
            if (kind === 'production' && record) record.production.filter(row => !Object.values(values.production ?? {}).some(item => item.id === row.id)).forEach(row => addRow(kind, row));
            refreshUnique(kind);
        }
        selectedProjectValue = projectSelect.value;
        periodValues = {year: form.elements.year.value, quarter_id: form.elements.quarter_id.value};
        updateContext();
    }
    projectSelect.addEventListener('change', () => {
        if (editingRecord) { projectSelect.value = editingRecord.project_id; return; }
        if (projectSelect.value === selectedProjectValue) return;
        const hasDetails = rootRows('production').length || rootRows('materials').length;
        const nextProjectValue = projectSelect.value;
        const applyProjectChange = () => {
            projectSelect.value = nextProjectValue;
            if (hasDetails) {
                document.getElementById('monitoring-income-groups').replaceChildren();
                containers.production.replaceChildren();
                containers.materials.replaceChildren();
                notice.textContent = 'Project changed. Add details for the new project before saving. Previous detail cards were removed from this dialog.';
                notice.hidden = false;
            }
            selectedProjectValue = projectSelect.value;
            fillConductor(form, selectedProject(), {conducted_by: form.elements.conducted_by.value});
            refreshUnique('production');
            refreshUnique('materials');
            updateContext();
            projectSelect.focus();
        };
        if (hasDetails) {
            projectSelect.value = selectedProjectValue;
            confirmRemoval('Remove entries and change project?', 'All Production, Income and Material entries in this unsaved Monitoring form will be discarded. Cancel keeps the current project and entries.', projectSelect, applyProjectChange);
            return;
        }
        applyProjectChange();
    });
    ['year', 'quarter_id'].forEach(name => form.elements[name].addEventListener('change', () => {
        const input = form.elements[name];
        if (editingRecord) { input.value = editingRecord[name]; return; }
        if (input.value === periodValues[name]) return;
        const savedIncome = rootRows('production').some(row => row.persistedFrequency);
        if (name === 'quarter_id' && rootRows('production').some(row => monthlyRows(row).some(month =>
            ['total_quantity', 'activity_count'].some(key => month.querySelector(`[data-key="${key}"]`).value !== '')))
            && !window.confirm('Change the quarter and clear unsaved monthly Production entries? Each month in the new quarter must be recorded explicitly.')) {
            input.value = periodValues[name];
            updateContext();
            return;
        }
        if (savedIncome && !window.confirm('Change the reporting period of saved income? Existing amounts will remain, and monthly entries must be reviewed against the new quarter. Cancel preserves the current period.')) {
            input.value = periodValues[name];
        }
        periodValues[name] = input.value;
        rootRows('production').forEach(refreshIncome);
        rootRows('production').forEach(row => name === 'quarter_id' ? fillQuarterMonths(row) : refreshMonthly(row));
        updateContext();
    }));
    form.addEventListener('click', event => {

        const root = event.target.closest('[data-add-root]');
        if (root) { const row = addRow(root.dataset.addRoot); Array.from(row.querySelectorAll('input, select, textarea')).find(input => input.type !== 'hidden' && !input.disabled && !input.closest('[hidden]'))?.focus(); }
        const income = event.target.closest('[data-add-income]');
        if (income) {
            const parent = income.closest('[data-production-income]').productionRow;
            const period = parent.incomeFrequency;
            const row = addRow('income', {period_type: period}, null, parent);
            row.querySelector(period === 'monthly' ? '[data-key="month"]' : '[data-key="gross_income"]').focus();
        }
        const remove = event.target.closest('[data-monitoring-remove-row]');
        if (remove) {
            const row = remove.closest('[data-kind]');
            const kind = row.dataset.kind;
            if (kind === 'production' && row.querySelector('[data-key="id"]').value) return;
            const label = {production: 'Production', income: 'Income', materials: 'Material Observation'}[kind];
            const saved = Boolean(row.querySelector('[data-key="id"]')?.value);
            const warning = saved
                ? (kind === 'production' ? 'This Production entry and its linked Income will be deleted only when you save Production.' : `This ${label.toLowerCase()} entry will be deleted only when you save its section.`)
                : (kind === 'production' ? 'This unsaved Production entry and its linked Income drafts will be discarded. No saved entry is deleted now.' : `This unsaved ${label.toLowerCase()} entry will be discarded. No saved entry is deleted now.`);
            confirmRemoval(`Remove ${label}?`, warning + ' Cancel keeps the entry unchanged.', remove, () => {
                if (!row.isConnected || (kind === 'production' && row.querySelector('[data-key="id"]').value)) return;
                const parent = kind === 'income' ? row.closest('[data-production-income]').productionRow : null;
                if (kind === 'production') row.incomeGroup.remove();
                row.remove();
                if (parent) { refreshIncome(parent); parent.incomeGroup.querySelector('[data-add-income]').focus(); }
                else { refreshUnique(kind); form.querySelector(`[data-add-root="${kind}"]`).focus(); }
            });
        }
    });
    form.addEventListener('change', event => {
        const input = event.target;
        const monthly = input.closest('[data-kind="monthly"]');
        if (monthly) { refreshMonthly(monthly.closest('[data-kind="production"]')); return; }
        if (input.matches('[data-resource-mode]')) {
            syncResourceMode(input.closest('[data-kind]'));
            return;
        }
        if (input.matches('[data-resource-key]')) {
            const card = input.closest('[data-kind]');
            if (card.dataset.kind === 'production') updateProductHeading(card);
            else updateMaterialHeading(card);
            refreshUnique(card.dataset.kind);
            return;
        }
        if (input.matches('[data-income-frequency]')) {
            changeIncomeFrequency(input.closest('[data-production-income]').productionRow, input);
            return;
        }
        const row = input.closest('[data-kind]');
        if (!row) return;
        if (input.dataset.key === 'project_product_id') {
            row.querySelector('[data-key="unit"]').value = choices('production').find(product => String(product.id) === input.value)?.default_unit ?? '';
            refreshMonthly(row);
            refreshUnique('production');
            updateProductHeading(row);
        }
        if (input.dataset.key === 'project_material_id') {
            refreshUnique('materials');
            updateMaterialHeading(row);
        }
        if (['period_type', 'month'].includes(input.dataset.key)) refreshIncome(row.closest('[data-production-income]').productionRow);
    });
    form.addEventListener('input', event => {
        const production = event.target.closest('[data-kind="production"]');
        if (production && (event.target.closest('[data-kind="monthly"]') || event.target.dataset.key === 'unit')) refreshMonthly(production);
        if (!event.target.matches('[data-resource-key]')) return;
        const row = event.target.closest('[data-kind]');
        if (row.dataset.kind === 'production') { updateProductHeading(row); refreshProductNames(); }
        else updateMaterialHeading(row);
    });
    let submitting = false;
    form.addEventListener('submit', event => {
        if (submitting) { event.preventDefault(); return; }
        if (!refreshQuarterAvailability()) {
            event.preventDefault();
            document.getElementById('monitoring-quarter-availability').focus();
            return;
        }
        refreshUnique('production'); refreshUnique('materials');
        rootRows('production').forEach(refreshIncome);
        rootRows('production').forEach(refreshMonthly);
        isolateSection();
        const invalid = Array.from(form.elements).find(input => input.willValidate && !input.validity.valid);
        if (invalid) {
            event.preventDefault();
            revealIncome(invalid);
            const panel = invalid.closest('[data-monitoring-panel]');
            if (panel && !activeSection) activateTab(form, panel.dataset.monitoringPanel);
            invalid.reportValidity();
            return;
        }
        form.querySelectorAll('[data-identity-payload]').forEach(input => input.remove());
        if (editingRecord) ['project_id', 'year', 'quarter_id'].forEach(key => { const proxy = document.createElement('input'); proxy.type = 'hidden'; proxy.name = key; proxy.value = editingRecord[key]; proxy.dataset.identityPayload = ''; form.append(proxy); });
        submitting = true;
        form.querySelector('[type="submit"]').disabled = true;
        form.setAttribute('aria-busy', 'true');
        form.querySelector('[data-monitoring-submit-status]').textContent = 'Saving Monitoring. Please wait.';
    });
    window.addEventListener('pageshow', () => {
        submitting = false; form.removeAttribute('aria-busy');
        form.querySelector('[data-monitoring-submit-status]').textContent = '';
        form.querySelector('[type="submit"]').disabled = false;
        refreshQuarterAvailability();
    });
    const editing = records[0] ?? null;
    populate(initial, editing);
    function isolateSection() {
        if (!activeSection) return;
        form.querySelectorAll('[data-monitoring-panel]').forEach(panel => {
            const active = panel.dataset.monitoringPanel === activeSection;
            panel.querySelectorAll('input, select, textarea').forEach(input => {
                // Income submits its saved parent identity without changing Production.
                const parentValue = activeSection === 'income' && panel.dataset.monitoringPanel === 'production'
                    && ['id', 'project_product_id'].includes(input.dataset.key);
                if (!active && !parentValue) input.disabled = true;
            });
        });
        form.querySelectorAll('input[name="materials"], input[name="production"]').forEach(input => {
            input.disabled = input.name === 'materials' ? activeSection !== 'materials' : activeSection === 'materials';
        });
    }
    if (activeSection) {
        form.querySelectorAll('[data-monitoring-tab]').forEach(tab => { tab.hidden = true; });
        form.querySelector('[aria-label="Editor sections"]').hidden = true;
        document.getElementById('monitoring-editor-title').textContent = 'Manage ' + activeSection;
        const statusField = form.elements.project_status.closest('.field');
        if (activeSection === 'production') document.getElementById('editor-panel-production').prepend(statusField);
        document.getElementById('monitoring-period-heading').closest('section').hidden = true;
        form.querySelector('[type="submit"]').textContent = 'Save ' + activeSection;
        activateTab(form, activeSection);
        isolateSection();
    }
    if (restore) {
        Object.entries(errors).forEach(([key, messages]) => {
            const parts = key.split('.');
            const name = parts[0] + parts.slice(1).map(part => `[${part}]`).join('');
            let input = Array.from(form.querySelectorAll('input, select, textarea')).find(input => input.name === name && input.type !== 'hidden');
            if (!input && ['production', 'materials'].includes(parts[0])) {
                const resourceRow = rootRows(parts[0]).find(row => row.dataset.prefix === `${parts[0]}[${parts[1]}]`);
                if (parts.includes('new_product') || parts.includes('new_material')) input = resourceRow?.querySelector('[data-resource-mode]');
                if (parts.length === 1) input = rootRows(parts[0])[0]?.querySelector('[data-resource-mode]');
            }
            if (!input && parts[0] === 'production' && parts.includes('income')) {
                const production = rootRows('production').find(row => row.dataset.prefix === `production[${parts[1]}]`);
                input = production?.incomeGroup.querySelector('[data-income-frequency]');
            }
            if (!input) return;
            const error = document.createElement('p');
            error.className = 'field-error'; error.dataset.fieldError = ''; error.id = `${input.id}-error-${key.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
            error.textContent = messages.join(' ');
            input.setAttribute('aria-invalid', 'true'); input.setAttribute('aria-describedby', [input.getAttribute('aria-describedby'), error.id].filter(Boolean).join(' '));
            input.closest('.field').append(error);
        });
        const firstError = Array.from(form.querySelectorAll('[aria-invalid="true"]')).find(input => !input.disabled && !input.closest('.field[hidden]'));
        if (!activeSection && !firstError && Object.keys(errors).some(key => key.startsWith('production') || key.startsWith('materials'))) activateTab(form, Object.keys(errors).some(key => key.startsWith('production')) ? 'production' : 'materials');
        if (firstError) {
            revealIncome(firstError);
            const panel = firstError.closest('[data-monitoring-panel]');
            if (panel && !activeSection) activateTab(form, panel.dataset.monitoringPanel);
        }
        document.getElementById('monitoring-editor-title').focus();
        if (activeSection) activateTab(form, activeSection);
        (firstError ?? form.querySelector('[data-monitoring-errors]'))?.focus();
    }
</script>
