// Behavioral checks run inside an isolated rendered preview, without Laravel's backend.
(async () => {
    const frame = document.getElementById('preview');
    const results = { checks: [], errors: [], requests: [] };
    const check = (label, pass, detail = '') => results.checks.push({ label, pass: Boolean(pass), detail });
    const tick = () => new Promise(resolve => setTimeout(resolve, 25));
    frame.addEventListener('load', async () => {
        const win = frame.contentWindow;
        const doc = frame.contentDocument;
        const $ = selector => doc.querySelector(selector);
        const $$ = selector => [...doc.querySelectorAll(selector)];
        win.addEventListener('error', event => results.errors.push(event.message));
        win.addEventListener('unhandledrejection', event => results.errors.push(String(event.reason)));
        const change = (selector, value) => { const input = $(selector); input.value = value; input.dispatchEvent(new win.Event('change', { bubbles: true })); };
        const input = (selector, value) => { const field = $(selector); field.value = value; field.dispatchEvent(new win.Event('input', { bubbles: true })); };
        const go = async key => { win.location.hash = key; await tick(); };
        try {
            check('JavaScript initialized the dashboard', $('[data-page="dashboard"]').hidden === false && $('[data-page="members"]').hidden);
            const keys = $$('[data-page]').map(page => page.dataset.page);
            for (const width of [1440, 768, 390, 320]) {
                frame.style.width = `${width}px`;
                await tick();
                const overflow = [];
                for (const key of keys) {
                    await go(key);
                    if (doc.documentElement.scrollWidth > win.innerWidth + 1) overflow.push(key);
                }
                check(`All 31 pages fit a ${width}px viewport`, !overflow.length, overflow.join(', '));
                await go('areas');
                $('[data-area-add]').click();
                for (const areaType of ['Municipality', 'Barangay']) {
                    change('#area-editor-area_type', areaType);
                    const modal = $('#area-editor');
                    const bounds = modal.getBoundingClientRect();
                    check(`${areaType} modal fits a ${width}px viewport`, bounds.left >= 0 && bounds.right <= win.innerWidth && bounds.height <= win.innerHeight && modal.scrollWidth <= modal.clientWidth);
                    if (width <= 390) {
                        const fields = areaType === 'Barangay' ? ['municipality', 'barangay_name'] : ['province', 'region'];
                        const first = $(`#area-editor-${fields[0]}`).getBoundingClientRect();
                        const second = $(`#area-editor-${fields[1]}`).getBoundingClientRect();
                        check(`${areaType} fields stack at ${width}px`, second.top > first.bottom);
                    }
                }
                $('#area-editor [data-close-dialog]').click();
            }
            frame.style.width = '1440px'; await tick();
            await go('areas');
            const areaRows = () => $$('[data-list="areas"] [data-record]');
            const visibleAreas = () => areaRows().filter(row => !row.hidden);
            const areaSnapshot = areaRows().map(row => row.dataset.record).sort().join('');
            const municipalityRow = areaRows().find(row => JSON.parse(row.dataset.record).area_type === 'Municipality');
            const barangayRow = areaRows().find(row => JSON.parse(row.dataset.record).area_type === 'Barangay');
            check('Area table has all seven requested columns', $$('[data-list="areas"] th').map(th => th.textContent).join('|') === 'Area Name|Type|Municipality|PSGC Code|Source|Status|Actions');
            check('Both area types have View, Edit, and Deactivate without Delete', areaRows().length === 2 && areaRows().every(row => [...row.querySelectorAll('button')].map(button => button.textContent).join('|') === 'View|Edit|Deactivate'));
            municipalityRow.querySelector('[data-area-view]').click();
            check('Municipality details identify the selected row', $('[data-area-detail=name]').textContent === 'Demo Municipality' && $('[data-area-detail=municipality]').textContent === '—' && !$('[data-area-province]').hidden);
            $('#area-modal [data-close-dialog]').click();
            barangayRow.querySelector('[data-area-view]').click();
            check('Barangay details show its parent municipality', $('[data-area-detail=name]').textContent === 'Sample Barangay' && $('[data-area-detail=municipality]').textContent === 'Demo Municipality' && $('[data-area-province]').hidden);
            $('#area-modal [data-close-dialog]').click();
            $('[data-area-add]').click();
            check('New areas default to Municipality, Cebu, Region VII, Active, and Manual', $('#area-editor-area_type').value === 'Municipality' && $('#area-editor-province').value === 'Cebu' && $('#area-editor-region').value === 'Region VII' && $('#area-editor-status').value === 'Active' && $('#area-editor-source').value === 'Manual');
            check('Municipality form hides and disables barangay inputs', !$('#area-editor-municipality_name').disabled && $('#area-editor-municipality').disabled && $('#area-editor-barangay_name').disabled);
            change('#area-editor-area_type', 'Barangay');
            check('Barangay form requires a parent and hides municipality-only fields', !$('#area-editor-municipality').disabled && $('#area-editor-municipality').required && !$('#area-editor-barangay_name').disabled && $('#area-editor-province').disabled && $('#area-editor-region').disabled && $('#area-editor-municipality_name').disabled);
            input('#area-editor-municipality', 'Unknown Municipality');
            check('Searchable parent selector validates its fictional options', !$('#area-editor-municipality').checkValidity());
            input('#area-editor-municipality', 'Demo Municipality');
            input('#area-editor-barangay_name', 'Demo New Barangay');
            $('[data-area-form]').requestSubmit();
            check('Add Area submission closes with preview feedback only', !$('#area-editor').open && $('#ui-toast').textContent.includes('Nothing was saved'));
            municipalityRow.querySelector('[data-area-edit]').click();
            check('Edit Municipality fills name, PSGC, province, region, status, and source', $('#area-editor-title').textContent === 'Edit Area' && $('#area-editor-municipality_name').value === 'Demo Municipality' && $('#area-editor-psgc_code').value === 'DEMO-MUN-001' && $('#area-editor-province').value === 'Cebu' && $('#area-editor-source').value === 'Manual');
            $('#area-editor [data-close-dialog]').click();
            barangayRow.querySelector('[data-area-edit]').click();
            check('Edit Barangay fills the correct name and parent', $('#area-editor-area_type').value === 'Barangay' && $('#area-editor-barangay_name').value === 'Sample Barangay' && $('#area-editor-municipality').value === 'Demo Municipality' && $('#area-editor-psgc_code').value === 'DEMO-BRGY-001');
            input('#area-editor-barangay_name', 'Demo Edited Name');
            $('[data-area-form]').requestSubmit();
            check('Area forms contain no association fields', !$$('[data-area-form] [name]').some(field => /association/i.test(field.name)));
            municipalityRow.querySelector('[data-confirm]').click();
            check('Deactivate reuses confirmation with preservation text', $('#confirmation-modal-title').textContent === 'Deactivate Area' && $('[data-confirm-action]').textContent === 'Deactivate' && $('[data-confirm-message]').textContent.includes('remain preserved'));
            $('#confirmation-modal [data-close-dialog]').click();
            barangayRow.querySelector('[data-confirm]').click();
            $('[data-confirm-action]').click();
            check('Add, Edit, Cancel, and Deactivate leave sample records unchanged', areaRows().map(row => row.dataset.record).sort().join('') === areaSnapshot);
            $('[data-area-add]').click();
            check('Reopening Add Area clears previous edits and restores defaults', $('#area-editor-municipality_name').value === '' && $('#area-editor-barangay_name').value === '' && $('#area-editor-area_type').value === 'Municipality');
            $('#area-editor [data-close-dialog]').click();
            input('#areas-search', 'Sample Barangay');
            check('Area search finds a barangay by name', visibleAreas().length === 1 && visibleAreas()[0] === barangayRow);
            input('#areas-search', '');
            $('[data-disclosure="areas-filters"]').click();
            for (const [field, value, expected] of [['area_type', 'Municipality', 1], ['municipality', 'Demo Municipality', 2], ['source', 'PSGC', 0], ['status', 'Inactive', 0]]) {
                $('#areas-filters').reset(); await tick();
                change(`#areas-filters-${field}`, value); $('#areas-filters').requestSubmit();
                check(`Area ${field} filter works`, visibleAreas().length === expected);
            }
            $('#areas-filters').reset(); await tick();
            input('#area-municipality-search', 'Demo Municipality');
            check('Municipality quick search includes the municipality and its barangay', visibleAreas().length === 2);
            input('#area-municipality-search', '');
            for (const [sort, expected] of [['az', municipalityRow], ['za', barangayRow], ['newest', barangayRow], ['oldest', municipalityRow]]) {
                change('#areas-sort', sort);
                check(`Area ${sort} sorting works`, areaRows()[0] === expected);
            }
            await go('members');
            input('#members-search', 'not-present');
            check('Member search shows an empty state', !$('[data-list="members"] [data-empty]').hidden);
            input('#members-search', 'Demo');
            check('Clearing search restores the sample member', !$('[data-list="members"] [data-record]').hidden);
            $('[data-disclosure="members-filters"]').click();
            const classification = $('#members-filters input[value="Youth"]'); classification.checked = true;
            $('#members-filters').requestSubmit();
            check('Multiple member classifications are filterable', !$('[data-list="members"] [data-record]').hidden);
            classification.checked = false; $('#members-filters input[value="PWD"]').checked = true;
            $('#members-filters').requestSubmit();
            check('Unmatched classification excludes the member', $('[data-list="members"] [data-record]').hidden);
            $('#members-filters').reset(); await tick();
            check('Clearing filters restores the member', !$('[data-list="members"] [data-record]').hidden);
            check('Member actions are read-only', !$$('[data-page^="members"] a').some(a => /create|edit/.test(a.hash)) && !$$('[data-page^="members"] button').some(b => /add member|edit|delete|approve|reject/i.test(b.textContent)));
            await go('associations/create');
            change('#associations-create-municipality', 'Demo Municipality');
            check('Barangay options follow the municipality', $('#associations-create-barangay').options.length === 2 && !$('#associations-create-barangay').disabled);
            const registrations = '[data-page="associations/create"] [data-repeater]';
            $(`${registrations} [data-add-row]`).click();
            $(`${registrations} [data-remove-row]`).click();
            $(`${registrations} [data-add-row]`).click();
            const ids = $$('[id]').map(element => element.id);
            check('Adding and removing rows preserves unique IDs', ids.length === new Set(ids).size);
            check('Registration rows can be added and removed', $$(`${registrations} [data-repeat-row]`).length === 2);
            const primaries = $$(`${registrations} input[type="checkbox"]`); primaries[0].click(); primaries[1].click();
            check('Only one primary registration is selected', primaries.filter(field => field.checked).length === 1);
            await go('users/create');
            change('#users-create-role', 'Association Member Account');
            check('Member account form uses display name and association', !$('#users-create-display_name').disabled && !$('#users-create-association').disabled && $('#users-create-full_name').disabled);
            change('#users-create-role', 'Field Officer');
            check('Officer form uses full name and contact number', !$('#users-create-full_name').disabled && !$('#users-create-contact_number').disabled && $('#users-create-association').disabled);
            check('No password fields exist', !$$('input[type="password"]').length);
            await go('projects/edit');
            check('Project classification is populated correctly', $('#projects-edit-sub_component').value === 'Livelihood Project' && $('#projects-edit-activity').value === 'Livelihood Project Delivery');
            await go('trainings/create');
            input('#create-participant', 'Demo Member'); $('[data-page="trainings/create"] [data-add-participant]').click();
            input('#create-participant', 'Demo Member'); $('[data-page="trainings/create"] [data-add-participant]').click();
            check('Participant selector prevents duplicate entries', $$('[data-page="trainings/create"] [data-participant]').length === 1);
            $('[data-page="trainings/create"] [data-remove-participant]').click();
            check('Participant removal restores empty state', !$('[data-page="trainings/create"] .participant-empty').hidden);
            await go('reports');
            $('[name="scope"][value="Association"]').click();
            check('Association report scope shows its selector', !$('#report-association').disabled && $('#report-municipality').disabled);
            $('[name="scope"][value="Municipality"]').click();
            check('Municipality report scope shows its selector', $('#report-association').disabled && !$('#report-municipality').disabled);
            $('[name="scope"][value="Overall"]').click();
            check('Overall report hides both scope selectors', $('#report-association').disabled && $('#report-municipality').disabled);
            $('[data-report-form]').requestSubmit();
            check('Report generation is preview only', $('[data-report-status]').textContent.includes('No file was generated.'));
            await go('users/show');
            $('[data-page="users/show"] [data-confirm]').click();
            check('Account confirmation opens a modal', $('#confirmation-modal').open);
            check('Other confirmations retain their original heading and action', $('#confirmation-modal-title').textContent === 'Review action' && $('[data-confirm-action]').textContent === 'Confirm preview');
            $('[data-confirm-action]').click();
            check('Confirmation closes without changing the account', !$('#confirmation-modal').open && $('[data-list="users"] [data-record]').dataset.record.includes('Active'));
            await go('gis');
            $('[data-location="project"]').click();
            check('Map marker updates the location card', $('[data-location-title]').textContent === 'Demo Aquaculture Project');
            frame.style.width = '390px'; await tick();
            $('[data-open-sidebar]').click();
            check('Mobile drawer opens and isolates the content', doc.body.classList.contains('drawer-open') && $('.main-shell').inert);
            doc.dispatchEvent(new win.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
            check('Escape closes the mobile drawer', !doc.body.classList.contains('drawer-open') && !$('.main-shell').inert);
            results.requests = win.performance.getEntriesByType('resource').map(entry => entry.name);
        } catch (error) { results.errors.push(error.stack); }
        document.getElementById('results').textContent = JSON.stringify(results);
    });
    frame.srcdoc = previewSource;
})();
