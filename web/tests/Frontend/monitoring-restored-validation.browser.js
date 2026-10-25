export async function checkRestoredValidation({ form, initialize, populate, initial, initialError, initialErrorId, check }) {
    const input = form.elements.overall_remarks;
    const field = input.closest('.field');
    const errors = () => Array.from(field.querySelectorAll('.field-error'));
    const references = () => (input.getAttribute('aria-describedby') ?? '').split(/\s+/).filter(Boolean);
    const payload = () => Array.from(new FormData(form), ([name, value]) => [name, name === '_token' ? '[csrf-present]' : value]);
    check(errors().length === 1, 'one restored field message regardless of initialization order', {
        nodes: errors().map(node => ({ id: node.id, text: node.textContent })), references: references(),
    });
    const error = errors()[0];
    check(error === initialError && error.id === initialErrorId, 'the first renderer error node and ID are reused');
    check(error.textContent === 'Synthetic validation feedback.' && input.getAttribute('aria-invalid') === 'true', 'validation text and invalid state remain visible');
    check(references().filter(id => id === error.id).length === 1 && references().every(id => document.getElementById(id)), 'accessible descriptions resolve without duplicate error references');
    const dialog = document.querySelector('[data-global-feedback]');
    check(dialog.open && dialog.textContent.includes('Review Your Entries'), 'validation result retains its native feedback dialog');
    dialog.querySelector('[data-feedback-acknowledge]').click();
    for (let attempt = 0; attempt < 50 && dialog.open; attempt++) await new Promise(resolve => setTimeout(resolve, 100));
    check(!dialog.open && document.activeElement === input, 'acknowledging feedback restores focus to the invalid field');
    const before = payload();
    const beforeMarkup = form.outerHTML;
    const listen = EventTarget.prototype.addEventListener;
    let added = 0;
    try {
        EventTarget.prototype.addEventListener = function (...args) { added++; return listen.apply(this, args); };
        initialize(); initialize(); initialize();
    } finally { EventTarget.prototype.addEventListener = listen; }
    check(added === 0 && form.outerHTML === beforeMarkup && errors().length === 1, 'reinitialization changes no messages, markup or listeners');
    const helper = document.createElement('p');
    helper.id = 'synthetic-field-help'; helper.textContent = 'Synthetic accessible help.'; field.append(helper);
    input.setAttribute('aria-describedby', [helper.id, ...references()].join(' '));
    window.AssocMapFeedback.fields(form, { overall_remarks: ['Replacement <b>literal</b> feedback.'] });
    check(errors().length === 1 && errors()[0] === error && error.textContent === 'Replacement <b>literal</b> feedback.' && !error.querySelector('b'), 'subsequent validation updates the same node using safe text');
    check(references().includes(helper.id) && references().includes(error.id), 'replacing errors retains accessible helper descriptions');
    check(JSON.stringify(payload()) === JSON.stringify(before), 'validation and reinitialization preserve the complete native form payload');
    populate(initial);
    check(errors().length === 0 && !input.hasAttribute('aria-invalid') && !references().includes(error.id), 'resolved draft repopulation removes the reused error and stale reference');
    check(JSON.stringify(payload()) === JSON.stringify(before) && input.value === 'Synthetic restored draft', 'clearing field feedback retains restored drafts, sparse indices and payload');
    return before;
}
