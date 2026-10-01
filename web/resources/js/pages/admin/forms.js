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
        const type = $('[name=area_type]', form).value;
        $$('[data-area-fields]', form).forEach(group => setFieldVisibility(group, group.dataset.areaFields === type));
    }
}

export function initForms() {
    $$('[data-form]').forEach(form => {
        syncForm(form);
        form.addEventListener('submit', event => event.preventDefault());
        form.addEventListener('change', () => syncForm(form));
        form.addEventListener('reset', () => setTimeout(() => syncForm(form), 0));
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
