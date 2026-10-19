import vm from 'node:vm';
import { pathToFileURL } from 'node:url';

function emitter(properties = {}) {
    const handlers = new Map();
    return Object.assign(properties, {
        addEventListener(type, handler) { handlers.set(type, [...(handlers.get(type) ?? []), handler]); },
        dispatchEvent(event) { for (const handler of handlers.get(event.type) ?? []) handler(event); },
    });
}

class Option {
    constructor(label, value) { this.textContent = String(label); this.value = String(value); this.disabled = false; }
    cloneNode() { return Object.assign(new Option(this.textContent, this.value), { disabled: this.disabled }); }
}

class Select {
    constructor(options) { this.options = options; this.value = options.find(option => option.defaultSelected)?.value ?? options[0]?.value ?? ''; }
    get tagName() { return 'SELECT'; }
    get value() { return this.options[this.selectedIndex]?.value ?? ''; }
    set value(value) { this.selectedIndex = this.options.findIndex(option => option.value === String(value)); }
    get selectedOptions() { return this.selectedIndex < 0 ? [] : [this.options[this.selectedIndex]]; }
    replaceChildren(...options) { this.options = options; this.selectedIndex = options.length ? 0 : -1; }
    add(option) { this.options.push(option); }
}

const decode = value => value.replace(/&quot;/g, '"').replace(/&#0?39;|&apos;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');
const attribute = (attributes, name) => decode(attributes.match(new RegExp(`\\b${name}="([^"]*)"`))?.[1] ?? '');

export function trainingFormFixture(html) {
    const markup = html.match(/<form\b[^>]*id="training-form"[^>]*>([\s\S]*?)<\/form>/)?.[1];
    const script = [...html.matchAll(/<script type="module">([\s\S]*?)<\/script>/g)].find(match => match[1].includes('const editingRecord'))?.[1];
    if (!markup || !script) throw new Error('Rendered Training editor and its script are required.');
    const elements = {};
    for (const [, attributes, contents] of markup.matchAll(/<select\b([^>]*)>([\s\S]*?)<\/select>/g)) {
        const options = [...contents.matchAll(/<option\b([^>]*)>([\s\S]*?)<\/option>/g)].map(([, attrs, label]) =>
            Object.assign(new Option(decode(label), attribute(attrs, 'value')), { defaultSelected: /\bselected\b/.test(attrs) }));
        elements[attribute(attributes, 'name')] = emitter(new Select(options));
    }
    for (const [, attributes] of markup.matchAll(/<input\b([^>]*)>/g)) {
        const name = attribute(attributes, 'name');
        if (name) elements[name] = { value: attribute(attributes, 'value'), disabled: /\bdisabled\b/.test(attributes) };
    }
    for (const [, attributes, value] of markup.matchAll(/<textarea\b([^>]*)>([\s\S]*?)<\/textarea>/g)) elements[attribute(attributes, 'name')] = { value: decode(value) };
    const defaults = Object.fromEntries(Object.entries(elements).map(([name, input]) => [name, { value: input.value, disabled: input.disabled }]));
    const submit = { disabled: false };
    const form = emitter({ elements, dataset: {},
        reset() { for (const [name, input] of Object.entries(elements)) Object.assign(input, defaults[name]); },
        querySelector(selector) { if (selector === 'button[type="submit"]') return submit; return null; },
        querySelectorAll() { return []; },
    });
    const emptyContainer = () => emitter({ replaceChildren() {}, querySelectorAll() { return []; } });
    const notice = { hidden: true, textContent: '' };
    const nodes = {
        'training-form': form,
        'training-participants': emptyContainer(),
        'training-removed-participants': emptyContainer(),
        'training-add-participant': emitter({}),
        'training-participants-empty': {},
        'training-choice-notice': notice,
        'training-editor-title': {},
        'training-editor': { dataset: {} },
    };
    const document = emitter({ getElementById: id => nodes[id] });
    vm.runInNewContext(script, { document, Option });
    return { form, notice, Option, submit,
        snapshot() {
            const fields = Object.fromEntries(Object.entries(elements).filter(([, input]) => !input.disabled && (input.tagName !== 'SELECT' || input.selectedIndex >= 0)).map(([name, input]) => [name, input.value]));
            return { fields, projectLabel: elements.related_project_id.selectedOptions[0]?.textContent,
                notice: { ...notice }, submitDisabled: submit.disabled };
        },
    };
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
    let input = '';
    for await (const chunk of process.stdin) input += chunk;
    const { html, actions = [] } = JSON.parse(input);
    const fixture = trainingFormFixture(html);
    for (const action of actions) {
        if (action.type !== 'select-project') throw new Error(`Unsupported Training form action: ${action.type}`);
        fixture.form.elements.related_project_id.value = action.value;
        fixture.form.elements.related_project_id.dispatchEvent({ type: 'change' });
    }
    process.stdout.write(JSON.stringify(fixture.snapshot()));
}
