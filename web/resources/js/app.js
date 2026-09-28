/* UI preview only: no requests, authentication, or persistence. */
(() => {
    'use strict';
    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));
    const body = document.body;
    const mobile = window.matchMedia('(max-width: 1020px)');
    const pages = new Map($$('[data-page]').map(page => [page.dataset.page, page]));
    let toastTimer;
    let drawerOpener;
    let dialogOpener;

    function notify(message) {
        const toast = $('#ui-toast');
        clearTimeout(toastTimer);
        toast.textContent = message;
        toast.hidden = false;
        toastTimer = setTimeout(() => { toast.hidden = true; }, 6500);
    }

    function setDisclosure(button, expanded) {
        const panel = document.getElementById(button.dataset.disclosure);
        if (!panel) return;
        panel.hidden = !expanded;
        button.setAttribute('aria-expanded', String(expanded));
    }

    function setDrawer(open, restore = true) {
        body.classList.toggle('drawer-open', open);
        $('.sidebar-backdrop').hidden = !open;
        $('[data-open-sidebar]').setAttribute('aria-expanded', String(open));
        $('#admin-sidebar').inert = mobile.matches && !open;
        $('.main-shell').inert = open;
        if (open) {
            drawerOpener = document.activeElement;
            $('a', $('#admin-sidebar')).focus();
        } else if (restore && drawerOpener && mobile.matches) drawerOpener.focus();
    }

    function navigate(focus = true) {
        let key = location.hash.slice(1) || 'dashboard';
        if (key === 'main-content') { $('#main-content').focus(); return; }
        if (!pages.has(key)) { key = 'dashboard'; history.replaceState(null, '', '#dashboard'); }
        pages.forEach((page, pageKey) => { page.hidden = pageKey !== key; });
        const current = pages.get(key);
        const module = key.split('/')[0];
        $$('[data-nav]').forEach(link => {
            const active = link.dataset.nav === module;
            link.classList.toggle('active', active);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
        const operations = ['projects', 'trainings', 'monitoring', 'program-activities'].includes(module);
        $('.program-toggle').classList.toggle('active', operations);
        if (operations) setDisclosure($('.program-toggle'), true);
        $('#current-context').textContent = current.dataset.title;
        document.title = `${current.dataset.title} · AssocMap`;
        $$('details[open]').forEach(menu => { menu.open = false; });
        setDrawer(false, false);
        if (focus) { $('h1', current)?.focus({ preventScroll: true }); window.scrollTo({ top: 0, behavior: 'instant' }); }
    }

    function openDialog(id, trigger) {
        const dialog = document.getElementById(id);
        if (!(dialog instanceof HTMLDialogElement)) return;
        dialogOpener = trigger;
        dialog.showModal();
    }

    function updateList(list) {
        const query = ($('[data-search]', list)?.value || '').trim().toLowerCase();
        const areaQuery = ($('[data-area-municipality]', list)?.value || '').trim().toLowerCase();
        const filters = list.appliedFilters || new FormData();
        const rows = $$('tbody [data-record]', list);
        let count = 0;
        rows.forEach(row => {
            const record = JSON.parse(row.dataset.record);
            const matchesSearch = Object.values(record).flat().join(' ').toLowerCase().includes(query);
            const municipality = list.dataset.list === 'areas' && record.area_type === 'Municipality' ? record.name : record.municipality;
            const matchesArea = String(municipality || '').toLowerCase().includes(areaQuery);
            const matchesFilters = [...new Set(filters.keys())].every(name => {
                const choices = filters.getAll(name).filter(Boolean);
                const key = name.replace(/\[\]$/, '');
                if (!choices.length) return true;
                if (key === 'date_from') return String(record.created_at || '').slice(0, 10) >= choices[0];
                if (key === 'date_to') return String(record.created_at || '').slice(0, 10) <= choices[0];
                const value = key === 'municipality' && list.dataset.list === 'areas' ? municipality : record[key];
                const values = Array.isArray(value) ? value : [String(value ?? '')];
                return choices.some(choice => values.includes(choice));
            });
            row.hidden = !(matchesSearch && matchesArea && matchesFilters);
            if (!row.hidden) count++;
        });
        const sort = $('[data-sort]', list)?.value;
        const sortValue = record => {
            if (sort === 'status') return record.status || '';
            if (sort === 'year') return String(record.program_year || record.year || '');
            if (sort === 'newest' || sort === 'oldest') return record.created_at || record.joined_at || record.implementation_date || record.start_date || record.monitoring_date || record.activity_date || record.registered_at || '';
            return String(record.name || record.title || record.project || record.activity || record.barangay || record.user || '');
        };
        rows.sort((a, b) => sortValue(JSON.parse(a.dataset.record)).localeCompare(sortValue(JSON.parse(b.dataset.record)), undefined, { numeric: true }) * (['za', 'newest', 'year'].includes(sort) ? -1 : 1));
        rows.forEach(row => $('tbody', list).append(row));
        $('[data-empty]', list).hidden = count > 0;
        $('[data-result-count]', list).textContent = `${count} ${count === 1 ? 'result' : 'results'} · fictional preview`;
        const countFilters = [...filters.values()].filter(Boolean).length;
        $('[data-filter-count]', list).textContent = countFilters;
        $('[data-filter-count]', list).hidden = !countFilters;
    }

    function setFieldVisibility(container, visible) {
        container.hidden = !visible;
        $$('input, select, textarea', container).forEach(input => { input.disabled = !visible; });
    }

    function syncAreaForm() {
        const form = $('[data-area-form]');
        const type = $('[name=area_type]', form).value;
        $$('[data-area-fields]', form).forEach(group => setFieldVisibility(group, group.dataset.areaFields === type));
        const municipality = $('[name=municipality]', form);
        const choices = $$('datalist option', form).map(option => option.value);
        const invalid = type === 'Barangay' && municipality.value && !choices.includes(municipality.value);
        municipality.setCustomValidity(invalid ? 'Choose a municipality from the preview list.' : '');
    }

    function openAreaEditor(trigger) {
        const form = $('[data-area-form]');
        form.reset();
        $$('input, select', form).forEach(input => { input.setCustomValidity(''); input.removeAttribute('aria-invalid'); });
        $$('.field-error', form).forEach(error => { error.textContent = ''; });
        const editing = trigger.hasAttribute('data-area-edit');
        if (editing) {
            const record = JSON.parse(trigger.closest('[data-record]').dataset.record);
            ['area_type', 'psgc_code', 'province', 'region', 'status', 'source'].forEach(name => {
                $(`[name="${name}"]`, form).value = record[name] ?? '';
            });
            const nameField = record.area_type === 'Municipality' ? 'municipality_name' : 'barangay_name';
            $(`[name="${nameField}"]`, form).value = record.name;
            $('[name=municipality]', form).value = record.area_type === 'Barangay' ? record.municipality : '';
        }
        $('#area-editor-title').textContent = editing ? 'Edit Area' : 'Add Area';
        $('[data-area-submit]').textContent = editing ? 'Preview Changes' : 'Preview Area';
        syncAreaForm();
        openDialog('area-editor', trigger);
    }

    function openAreaDetails(trigger) {
        const record = JSON.parse(trigger.closest('[data-record]').dataset.record);
        $$('[data-area-detail]').forEach(field => { field.textContent = record[field.dataset.areaDetail] || '—'; });
        $('[data-area-detail=status]').className = `badge badge-${record.status === 'Active' ? 'success' : 'neutral'}`;
        $('[data-area-province]').hidden = record.area_type !== 'Municipality';
        $('[data-area-region]').hidden = record.area_type !== 'Municipality';
        openDialog('area-modal', trigger);
    }

    function syncForm(form) {
        const role = $('[name=role]', form)?.value || 'Field Officer';
        if (form.dataset.module === 'users') {
            setFieldVisibility($('[data-field=full_name]', form), role === 'Field Officer');
            setFieldVisibility($('[data-field=contact_number]', form), role === 'Field Officer');
            setFieldVisibility($('[data-field=display_name]', form), role === 'Association Member Account');
            setFieldVisibility($('[data-field=association]', form), role === 'Association Member Account');
        }
        const municipality = $('[name=municipality]', form);
        const barangay = $('[name=barangay]', form);
        if (municipality && barangay) {
            const selected = barangay.value;
            const choices = municipality.value === 'Demo Municipality' ? ['Sample Barangay'] : municipality.value === 'Sample Municipality' ? ['Demo Barangay'] : [];
            barangay.replaceChildren(new Option('Select a barangay', ''), ...choices.map(value => new Option(value, value)));
            barangay.disabled = !municipality.value;
            if (choices.includes(selected)) barangay.value = selected;
        }
        const component = $('[name=component]', form);
        if (component) {
            const sub = $('[name=sub_component]', form);
            const activity = $('[name=activity]', form);
            const activityOptions = {
                'Social Preparation': ['Beneficiary Needs Assessment', 'Participatory Rural Appraisal', 'Beneficiary Profiling', 'Project Tagging'],
                'Food Production and Livelihood': ['Livelihood Project Delivery'],
                'Marketing Assistance and Enterprise Development': ['Market Scanning', 'Partnerships'],
                'Program Management': ['Policy Formulation', 'Capacity Development'],
            };
            const selectedSub = sub.value;
            const selectedActivity = activity.value;
            const subOptions = component.value ? [component.value === 'Food Production and Livelihood' ? 'Livelihood Project' : 'Demo Sub-component'] : [];
            sub.replaceChildren(new Option('Select a sub-component', ''), ...subOptions.map(value => new Option(value, value)));
            sub.disabled = !component.value;
            if (subOptions.includes(selectedSub)) sub.value = selectedSub;
            const activities = sub.value ? (activityOptions[component.value] || []) : [];
            activity.replaceChildren(new Option('Select an activity', ''), ...activities.map(value => new Option(value, value)));
            activity.disabled = !sub.value;
            if (activities.includes(selectedActivity)) activity.value = selectedActivity;
        }
        $$('[data-field=project_material] select', form).forEach(input => {
            const display = $('[data-field=delivery_status] input', input.closest('[data-repeat-row]'));
            if (display) display.value = input.value ? 'Delivered' : 'Select a project material';
        });
    }

    function syncReport() {
        const form = $('[data-report-form]');
        const scope = $('[name=scope]:checked', form).value;
        $$('[data-report-scope]', form).forEach(container => setFieldVisibility(container, container.dataset.reportScope === scope));
        const type = $('[name=report_type]', form).value;
        const visibility = {
            quarter: ['Comprehensive Report', 'Monitoring Report'].includes(type),
            component: ['Comprehensive Report', 'Project Report', 'Training Report'].includes(type),
            status: ['Comprehensive Report', 'Project Report', 'Monitoring Report'].includes(type),
        };
        $$('[data-report-relevance]', form).forEach(container => setFieldVisibility(container, visibility[container.dataset.reportRelevance]));
    }

    function reindexParticipants(container) {
        const rows = $$('[data-participant]', container);
        rows.forEach((row, index) => {
            $('input', row).name = `participants[${index}][name]`;
            $('select', row).name = `participants[${index}][attendance]`;
        });
        $('.participant-empty', container).hidden = rows.length > 0;
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('button, a');
        if (!button) return;
        if (button.matches('[data-disclosure]')) setDisclosure(button, button.getAttribute('aria-expanded') !== 'true');
        if (button.matches('[data-open-sidebar]')) setDrawer(true);
        if (button.matches('[data-close-sidebar]')) setDrawer(false);
        if (button.matches('[data-collapse-sidebar]')) {
            const collapsed = body.classList.toggle('sidebar-collapsed');
            button.setAttribute('aria-expanded', String(!collapsed));
            button.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        }
        if (button.matches('[data-notice]')) notify(button.dataset.notice);
        if (button.matches('[data-dialog]')) openDialog(button.dataset.dialog, button);
        if (button.matches('[data-area-add], [data-area-edit]')) openAreaEditor(button);
        if (button.matches('[data-area-view]')) openAreaDetails(button);
        if (button.matches('[data-confirm]')) {
            $('[data-confirm-message]').textContent = button.dataset.confirm;
            $('#confirmation-modal-title').textContent = button.dataset.confirmTitle || 'Review action';
            $('[data-confirm-action]').textContent = button.dataset.confirmLabel || 'Confirm preview';
            openDialog('confirmation-modal', button);
        }
        if (button.matches('[data-confirm-action]')) {
            button.closest('dialog').close();
            notify('Action preview complete. No record, account, or publication status was changed.');
        }
        if (button.matches('[data-close-dialog]')) button.closest('dialog').close();
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
            syncForm(button.closest('form'));
            $('input, select, textarea', $$('[data-repeat-row]', repeater).at(-1))?.focus();
            notify('A new preview row was added.');
        }
        if (button.matches('[data-remove-row]')) {
            const repeater = button.closest('[data-repeater]');
            button.closest('[data-repeat-row]').remove();
            $$('[data-row-number]', repeater).forEach((number, i) => { number.textContent = i + 1; });
            $('[data-add-row]', repeater).focus(); notify('Preview row removed.');
        }
        if (button.matches('[data-add-participant]')) {
            const container = button.closest('[data-participants]');
            const input = $('[data-participant-search]', container);
            const name = input.value.trim();
            const choices = $$('datalist option', container).map(option => option.value);
            if (!choices.includes(name)) return notify('Choose Demo Member or Sample Member from the member list.');
            if ($$('[data-participant]', container).some(row => row.dataset.participant === name)) return notify('This member is already in the participant list.');
            const row = document.createElement('tr'); row.dataset.participant = name;
            const nameCell = row.insertCell(); nameCell.textContent = name;
            const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.value = name; nameCell.append(hidden);
            const attendance = document.createElement('select'); attendance.setAttribute('aria-label', `Attendance for ${name}`);
            attendance.append(new Option('Present'), new Option('Absent')); row.insertCell().append(attendance);
            const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'text-button text-danger'; remove.dataset.removeParticipant = ''; remove.textContent = 'Remove';
            row.insertCell().append(remove); $('[data-participant-rows]', container).append(row);
            reindexParticipants(container); input.value = ''; input.focus(); notify('Participant added to this preview.');
        }
        if (button.matches('[data-remove-participant]')) {
            const container = button.closest('[data-participants]');
            button.closest('tr').remove(); reindexParticipants(container); $('[data-participant-search]', container).focus();
        }
        if (button.matches('[data-location]')) {
            const association = button.dataset.location === 'association';
            $('[data-location-title]').textContent = association ? 'Demo Coastal Association' : 'Demo Aquaculture Project';
            $('[data-location-type]').textContent = association ? 'Association Location' : 'Project Location';
            const link = $('[data-location-link]'); link.href = association ? '#associations/show' : '#projects/show'; link.textContent = association ? 'View association' : 'View project';
        }
    });

    document.addEventListener('input', event => {
        const list = event.target.closest('[data-list]');
        if (list && event.target.matches('[data-search], [data-area-municipality]')) updateList(list);
        if (event.target.matches('#area-editor-municipality')) syncAreaForm();
        if (event.target.matches('input, textarea')) {
            event.target.removeAttribute('aria-invalid');
            const field = event.target.closest('.field');
            const error = field && $('.field-error', field);
            if (error) error.textContent = '';
        }
        if (event.target.matches('[name=start_date], [name=end_date], [name=implementation_date], [name=implementation_end_date]')) {
            const end = $('[name=end_date], [name=implementation_end_date]', event.target.form);
            if (end) end.setCustomValidity('');
        }
    });
    document.addEventListener('change', event => {
        const list = event.target.closest('[data-list]');
        if (list && event.target.matches('[data-sort]')) updateList(list);
        if (event.target.closest('[data-area-form]')) syncAreaForm();
        const form = event.target.closest('[data-preview-form]');
        if (form?.dataset.module) {
            if (event.target.matches('[name$="[primary]"]') && event.target.checked) $$('[name$="[primary]"]', form).forEach(input => { if (input !== event.target) input.checked = false; });
            syncForm(form);
        }
        if (event.target.closest('[data-report-form]')) syncReport();
    });
    document.addEventListener('invalid', event => {
        const field = event.target.closest('.field');
        if (!field) return;
        event.target.setAttribute('aria-invalid', 'true');
        const error = $('.field-error', field);
        if (error) error.textContent = event.target.validationMessage;
    }, true);
    document.addEventListener('submit', event => {
        event.preventDefault();
        const form = event.target;
        if (form.matches('[data-filter-form]')) {
            const list = form.closest('[data-list]');
            if (list) { list.appliedFilters = new FormData(form); updateList(list); notify('Filters applied to the fictional preview records.'); }
            else notify('Map filter selection reviewed. This static illustration does not change.');
        }
        if (form.matches('[data-preview-form]')) {
            const start = $('[name=start_date], [name=implementation_date]', form);
            const end = $('[name=end_date], [name=implementation_end_date]', form);
            if (start?.value && end?.value && end.value < start.value) {
                end.setCustomValidity('End date must be on or after the start date.'); end.reportValidity();
                end.addEventListener('input', () => end.setCustomValidity(''), { once: true }); return;
            }
            form.closest('dialog')?.close(); notify('Form preview checked. Nothing was saved, sent, or changed.');
        }
        if (form.matches('[data-report-form]')) {
            const data = new FormData(form); const scope = data.get('scope');
            const detail = scope === 'Association' ? data.get('association') : scope === 'Municipality' ? data.get('municipality') : 'Overall';
            $('[data-report-title]').textContent = data.get('report_type');
            $('[data-report-description]').textContent = [detail, data.get('program_year'), data.get('quarter'), data.get('component'), data.get('project_status'), data.get('format')].filter(Boolean).join(' · ');
            $('[data-report-status]').textContent = `Configuration preview ready: ${data.get('report_type')} for ${detail}. No file was generated.`;
            notify('Report configuration preview updated. No file was generated.');
        }
    });
    document.addEventListener('reset', event => {
        const form = event.target;
        setTimeout(() => {
            if (form.matches('[data-filter-form]')) {
                const list = form.closest('[data-list]');
                if (list) { list.appliedFilters = new FormData(); const area = $('[data-area-municipality]', list); if (area) area.value = ''; updateList(list); }
            }
            if (form.matches('[data-report-form]')) {
                syncReport(); $('[data-report-title]').textContent = 'Comprehensive Report'; $('[data-report-description]').textContent = 'Overall · 2026 · PDF';
                $('[data-report-status]').textContent = 'Select your scope and filters, then generate a preview of your report configuration.';
            }
        }, 0);
    });
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            if (body.classList.contains('drawer-open')) setDrawer(false);
            $$('details[open]').forEach(menu => { menu.open = false; $('summary', menu).focus(); });
        }
        if (event.key === 'Tab' && body.classList.contains('drawer-open')) {
            const items = $$('a, button', $('#admin-sidebar')).filter(element => element.getClientRects().length && !element.disabled);
            const first = items[0]; const last = items.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
    });
    $$('dialog').forEach(dialog => {
        dialog.addEventListener('close', () => dialogOpener?.focus());
        dialog.addEventListener('click', event => {
            const rect = dialog.getBoundingClientRect();
            if (event.target === dialog && (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom)) dialog.close();
        });
    });
    mobile.addEventListener('change', () => setDrawer(false, false));
    window.addEventListener('hashchange', () => navigate());
    $$('[data-repeater]').forEach(repeater => { repeater.dataset.nextIndex = $$('[data-repeat-row]', repeater).length; });
    $$('[data-preview-form][data-module]').forEach(syncForm);
    $$('[data-list]').forEach(updateList);
    syncAreaForm();
    syncReport();
    navigate(false);
})();
