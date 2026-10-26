import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const read = path => readFileSync(new URL(`../../resources/${path}`, import.meta.url), 'utf8');
const operations = ['projects', 'trainings', 'monitoring', 'program-activities'];

function fixture(role, { width = 390, module = 'monitoring', hashPages = false } = {}) {
    const document = { activeElement: null, dialogOpen: false };
    function node(parent = null) {
        const events = new Map(), attributes = new Map(), classes = new Set();
        return {
            parent, dataset: {}, hidden: false, inert: false, disabled: false, textContent: '',
            addEventListener(type, listener) { const list = events.get(type) ?? []; list.push(listener); events.set(type, list); },
            fire(type, event = {}) { for (const listener of events.get(type) ?? []) listener(event); },
            listenerCount(type) { return (events.get(type) ?? []).length; },
            setAttribute(name, value) { attributes.set(name, value); },
            getAttribute(name) { return attributes.get(name) ?? null; },
            removeAttribute(name) { attributes.delete(name); },
            focus() { document.activeElement = this; },
            contains(other) { for (let current = other; current; current = current.parent) if (current === this) return true; return false; },
            closest(selector) { for (let current = this; current; current = current.parent) if (current.matches(selector)) return current; return null; },
            matches(selector) { return selector === '[data-disclosure]' && Boolean(this.dataset.disclosure); },
            getClientRects() {
                for (let current = this; current; current = current.parent) if (current.hidden) return [];
                return [{}];
            },
            classList: {
                contains(name) { return classes.has(name); },
                toggle(name, enabled = !classes.has(name)) { enabled ? classes.add(name) : classes.delete(name); return enabled; },
            },
        };
    }
    Object.assign(document, node());
    const body = node(), sidebar = node(body), shell = node(body), opener = node(shell), backdrop = node(body);
    const brand = node(sidebar), nav = node(sidebar), collapse = node(sidebar), collapseLabel = node(collapse);
    const context = node(shell), workspace = node(shell), program = null;
    const panel = program ? node(nav) : null;
    const mobile = Object.assign(node(), { matches: width <= 1020 });
    const window = Object.assign(node(), {
        matchMedia(query) { assert.equal(query, '(max-width: 1020px)'); return mobile; },
        scrollTo() {},
    });
    sidebar.id = `${role}-sidebar`;
    sidebar.dataset.dashboardUrl = '/dashboard';
    workspace.dataset = { workspaceModule: module, workspaceTitle: module === 'monitoring' ? 'Monitoring' : module };
    const groupId = null;
    const modules = role === 'association' ? ['dashboard', 'member-applications', 'projects', 'trainings', 'program-activities', 'monitoring', 'gis', 'reports']
        : role === 'field-officer' ? ['dashboard', 'associations', 'association-officers', 'members', 'member-applications', 'projects', 'trainings', 'program-activities', 'monitoring', 'gis', 'reports']
            : ['dashboard', 'areas', 'associations', 'members', 'projects', 'trainings', 'program-activities', 'monitoring', 'gis', 'reports', 'users', 'audit-logs'];
    const pages = hashPages ? modules.map(key => {
        const page = Object.assign(node(shell), { dataset: { page: key, title: key } });
        page.heading = node(page);
        page.querySelector = selector => selector === 'h1' ? page.heading : null;
        return page;
    }) : [];
    const location = { hash: '#' + module };
    const links = modules.map(key => {
        const link = node(panel && operations.includes(key) ? panel : nav);
        link.module = key;
        if (role === 'admin') link.dataset.nav = key;
        if (role !== 'admin' && key === module) {
            link.classList.toggle('active', true);
            link.setAttribute('aria-current', 'page');
        }
        return link;
    });
    const items = [brand];
    links.forEach(link => {
        if (program && link.module === operations[0]) items.push(program);
        items.push(link);
    });
    items.push(collapse);
    collapse.getClientRects = () => mobile.matches ? [] : [{}];
    const selectors = {
        '[data-workspace-sidebar]': sidebar,
        [`#${sidebar.id}`]: sidebar,
        '.main-shell': shell,
        [`.mobile-menu[aria-controls="${sidebar.id}"]`]: opener,
        '.sidebar-backdrop': backdrop,
        '[data-workspace-module]': workspace,
        '.program-toggle': program,
        '#current-context': context,
    };
    document.body = body;
    document.querySelector = selector => selector === 'dialog[open]' ? (document.dialogOpen ? {} : null) : selectors[selector] ?? null;
    document.querySelectorAll = selector => selector === '[data-page]' ? pages : selector === '[data-nav]' && role === 'admin' ? links : [];
    document.getElementById = id => id === groupId ? panel : selectors[`#${id}`];
    sidebar.querySelector = selector => selector === 'a' ? brand : selector === '[data-collapse-sidebar]' ? collapse : null;
    sidebar.querySelectorAll = selector => selector === 'a, button' ? items : [];
    collapse.querySelector = selector => selector === '[data-sidebar-collapse-label]' ? collapseLabel : null;

    const source = path => read(path).replace(/^import .*;\r?\n/gm, '').replace(/^export /gm, '');
    const script = source('js/core/helpers.js') + source('js/layouts/workspace-sidebar.js') + source('js/layouts/admin-navigation.js')
        + '\ninitDisclosures(); const controller = initWorkspaceSidebar(); initAdminNavigation(controller); globalThis.reinitialize = initWorkspaceSidebar;';
    const sandbox = { document, window, location, history: { replaceState(_state, _unused, hash) { location.hash = hash; } } };
    runInNewContext(script, sandbox);
    const click = (target, { nested = false } = {}) => {
        if (target.disabled) return;
        target.focus();
        target.fire('click', { target });
        document.fire('click', { target: nested ? node(target) : target });
    };
    const key = (key, shiftKey = false) => {
        let prevented = false;
        document.fire('keydown', { key, shiftKey, preventDefault() { prevented = true; } });
        return prevented;
    };
    // Model native button activation; real browser keyboard acceptance remains separate.
    const activateButton = (button, value) => {
        button.focus();
        if (!key(value)) click(button);
    };
    const setWidth = value => {
        const changed = mobile.matches !== (value <= 1020);
        mobile.matches = value <= 1020;
        if (changed) mobile.fire('change');
    };
    const visibleItems = () => items.filter(item => item.getClientRects().length && !item.disabled);
    return { pages, setHash(value) { location.hash = '#' + value; window.fire('hashchange'); }, document, body, sidebar, shell, opener, backdrop, brand, links, mobile, context, program, panel, collapse, collapseLabel, click, key, activateButton, setWidth, visibleItems, reinitialize: sandbox.reinitialize };
}

for (const role of ['admin', 'field-officer', 'association']) {
    test(`${role}: drawer contains keyboard focus, Escape closes and restores the opener`, () => {
        const f = fixture(role);
        assert.equal(f.sidebar.inert, true);
        assert.equal(f.shell.inert, false);
        f.click(f.opener);
        assert.equal(f.body.classList.contains('drawer-open'), true);
        assert.equal(f.sidebar.inert, false);
        assert.equal(f.shell.inert, true);
        assert.equal(f.backdrop.hidden, false);
        assert.equal(f.opener.getAttribute('aria-expanded'), 'true');
        assert.equal(f.document.activeElement, f.brand);
        assert.equal(f.key('Tab', true), true);
        assert.equal(f.document.activeElement, f.visibleItems().at(-1));
        assert.equal(f.key('Tab'), true);
        assert.equal(f.document.activeElement, f.brand);
        assert.equal(f.key('Escape'), true);
        assert.equal(f.body.classList.contains('drawer-open'), false);
        assert.equal(f.sidebar.inert, true);
        assert.equal(f.shell.inert, false);
        assert.equal(f.backdrop.hidden, true);
        assert.equal(f.opener.getAttribute('aria-expanded'), 'false');
        assert.equal(f.document.activeElement, f.opener);
    });

    test(`${role}: desktop collapse and expand update class, label, title and aria state`, () => {
        const f = fixture(role, { width: 1440 });
        assert.equal(f.sidebar.inert, false);
        f.activateButton(f.collapse, 'Enter');
        assert.equal(f.body.classList.contains('sidebar-collapsed'), true);
        assert.equal(f.collapse.getAttribute('aria-expanded'), 'false');
        assert.equal(f.collapse.getAttribute('aria-label'), 'Expand sidebar');
        assert.equal(f.collapse.getAttribute('title'), 'Expand sidebar');
        assert.equal(f.collapseLabel.textContent, 'Expand sidebar');
        assert.equal(f.shell.inert, false);
        assert.equal(f.links.find(link => link.module === 'monitoring').getAttribute('aria-current'), 'page');
        f.activateButton(f.collapse, ' ');
        assert.equal(f.body.classList.contains('sidebar-collapsed'), false);
        assert.equal(f.collapse.getAttribute('aria-expanded'), 'true');
        assert.equal(f.collapse.getAttribute('aria-label'), 'Collapse sidebar');
        f.click(f.opener);
        assert.equal(f.body.classList.contains('drawer-open'), false);
    });

    test(`${role}: responsive transitions keep desktop preference separate from the mobile drawer`, () => {
        const f = fixture(role, { width: 1280 });
        f.click(f.collapse);
        f.setWidth(390);
        assert.equal(f.body.classList.contains('sidebar-collapsed'), false);
        assert.equal(f.collapse.getAttribute('aria-expanded'), 'true');
        assert.equal(f.sidebar.inert, true);
        assert.equal(f.document.activeElement, f.opener);
        f.click(f.opener);
        f.click(f.backdrop);
        assert.equal(f.document.activeElement, f.opener);
        assert.equal(f.shell.inert, false);
        f.click(f.opener);
        f.setWidth(1280);
        assert.equal(f.body.classList.contains('sidebar-collapsed'), true);
        assert.equal(f.body.classList.contains('drawer-open'), false);
        assert.equal(f.sidebar.inert, false);
        assert.equal(f.backdrop.hidden, true);
        assert.equal(f.shell.inert, false);
        f.click(f.collapse);
        f.setWidth(768);
        f.setWidth(1280);
        assert.equal(f.body.classList.contains('sidebar-collapsed'), false);
    });

    test(`${role}: repeated initialization cannot double-toggle or add drawer handlers`, () => {
        const f = fixture(role, { width: 1440 });
        const before = f.document.listenerCount('keydown');
        assert.equal(f.reinitialize(), f.reinitialize());
        assert.equal(f.document.listenerCount('keydown'), before);
        for (const control of [f.opener, f.backdrop, f.collapse]) assert.equal(control.listenerCount('click'), 1);
        assert.equal(f.mobile.listenerCount('change'), 1);
        f.click(f.collapse);
        assert.equal(f.body.classList.contains('sidebar-collapsed'), true);
    });

    test(`${role}: an open feedback or conflict dialog retains ownership of Tab and Escape`, () => {
        const f = fixture(role);
        f.click(f.opener);
        f.document.dialogOpen = true;
        assert.equal(f.key('Tab', true), false);
        assert.equal(f.key('Escape'), false);
        assert.equal(f.body.classList.contains('drawer-open'), true);
        f.document.dialogOpen = false;
        f.key('Escape');
        assert.equal(f.body.classList.contains('drawer-open'), false);
    });

    test(`${role}: viewport branches include phones, tablet, laptop, desktop and 200% effective width`, () => {
        // These cases verify JS state, not CSS geometry or actual browser zoom.
        for (const width of [320, 390, 768, 1020, 1020.5, 1021, 1280, 1440, 1920, 1280 / 2]) {
            const f = fixture(role, { width });
            assert.equal(f.sidebar.inert, width <= 1020, `width ${width}`);
            assert.equal(f.shell.inert, false);
            f.click(f.opener);
            assert.equal(f.body.classList.contains('drawer-open'), width <= 1020);
            f.click(f.backdrop);
            assert.equal(f.shell.inert, false);
        }
    });
}

for (const role of ['admin', 'field-officer', 'association']) {
    test(`${role}: every destination is directly available in desktop and mobile navigation`, () => {
        for (const width of [390, 1440]) {
            const f = fixture(role, { width });
            if (width <= 1020) f.click(f.opener);
            else f.click(f.collapse);
            assert.equal(f.program, null);
            assert.equal(f.panel, null);
            for (const link of f.links) assert.ok(f.visibleItems().includes(link), link.module);
            assert.equal(f.links.length, role === 'admin' ? 12 : role === 'field-officer' ? 11 : 8);
        }
    });
}

test('Admin route navigation preserves the active destination and title without a disclosure', () => {
    for (const module of ['dashboard', 'users', 'areas', 'associations', 'members', ...operations, 'gis', 'reports', 'audit-logs']) {
        const f = fixture('admin', { module });
        assert.equal(f.context.textContent, module === 'monitoring' ? 'Monitoring' : module);
        assert.equal(f.links.filter(link => link.getAttribute('aria-current') === 'page').length, 1);
        assert.equal(f.links.find(link => link.module === module).classList.contains('active'), true);
    }
});

test('Association Account stays flat without an artificial operational disclosure', () => {
    const f = fixture('association');
    assert.equal(f.program, null);
    assert.equal(f.panel, null);
    assert.equal(f.links.length, 8);
});

test('Admin dashboard hash navigation keeps each destination active and handles unknown hashes', () => {
    const f = fixture('admin', { width: 1440, module: 'dashboard', hashPages: true });
    for (const link of f.links) {
        f.setHash(link.module);
        assert.equal(f.links.filter(link => link.getAttribute('aria-current') === 'page').length, 1);
        assert.equal(link.classList.contains('active'), true);
        assert.equal(f.context.textContent, link.module);
        assert.deepEqual(f.pages.filter(page => !page.hidden).map(page => page.dataset.page), [link.module]);
        assert.equal(f.document.activeElement, f.pages.find(page => page.dataset.page === link.module).heading);
    }
    f.setHash('unknown');
    assert.equal(f.links.find(link => link.module === 'dashboard').getAttribute('aria-current'), 'page');
    assert.equal(f.context.textContent, 'dashboard');
});
