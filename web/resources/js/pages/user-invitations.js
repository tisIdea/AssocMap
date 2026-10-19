export function initUserInvitations() {
    const form = document.querySelector('[data-user-invitation-form]');
    if (!form) return;

    const role = form.querySelector('[data-invitation-role]');
    const association = form.querySelector('[data-invitation-association]');
    const submit = form.querySelector('[data-invitation-submit]');
    const status = form.querySelector('[data-invitation-status]');
    const initiallyDisabled = submit.disabled;
    let pending = false;

    const updateAssociation = () => {
        const needsAssociation = role.selectedOptions[0]?.dataset.roleCode === 'association_account';
        association.required = needsAssociation;
        association.disabled = !needsAssociation;
        if (!needsAssociation) association.value = '';
    };
    role.addEventListener('change', updateAssociation);
    updateAssociation();

    form.addEventListener('submit', (event) => {
        if (pending) {
            event.preventDefault();
            return;
        }
        if (!form.checkValidity()) {
            event.preventDefault();
            form.reportValidity();
            return;
        }
        pending = true;
        submit.disabled = true;
        form.setAttribute('aria-busy', 'true');
        status.textContent = 'Submitting invitation. Wait for the server response.';
    });

    window.addEventListener('pageshow', () => {
        pending = false;
        submit.disabled = initiallyDisabled;
        form.removeAttribute('aria-busy');
        status.textContent = '';
    });
}
