import { $, $$ } from '../core/helpers.js';

const controllers = new WeakMap();

export function initWorkspaceSidebar() {
    const sidebar = $('[data-workspace-sidebar]');
    if (!sidebar) return null;
    if (controllers.has(sidebar)) return controllers.get(sidebar);

    const body = document.body;
    const shell = $('.main-shell');
    const opener = $(`.mobile-menu[aria-controls="${sidebar.id}"]`);
    const backdrop = $('.sidebar-backdrop');
    const collapse = $('[data-collapse-sidebar]', sidebar);
    const collapseLabel = $('[data-sidebar-collapse-label]', collapse);
    const mobile = window.matchMedia('(max-width: 1020px)');
    let desktopCollapsed = false;
    let drawerOpen = false;

    function setCollapsed() {
        const collapsed = desktopCollapsed && !mobile.matches;
        body.classList.toggle('sidebar-collapsed', collapsed);
        collapse.setAttribute('aria-expanded', String(!collapsed));
        const label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        collapse.setAttribute('aria-label', label);
        collapse.setAttribute('title', label);
        collapseLabel.textContent = label;
    }

    function setDrawer(open, restore = true) {
        const wasOpen = drawerOpen;
        drawerOpen = Boolean(open && mobile.matches);
        body.classList.toggle('drawer-open', drawerOpen);
        backdrop.hidden = !drawerOpen;
        opener.setAttribute('aria-expanded', String(drawerOpen));
        sidebar.inert = mobile.matches && !drawerOpen;
        shell.inert = drawerOpen;
        if (drawerOpen) $('a', sidebar).focus();
        else if (wasOpen && restore && mobile.matches) opener.focus();
    }

    opener.addEventListener('click', () => setDrawer(true));
    backdrop.addEventListener('click', () => setDrawer(false));
    collapse.addEventListener('click', () => {
        if (mobile.matches) return;
        desktopCollapsed = !desktopCollapsed;
        setCollapsed();
    });
    document.addEventListener('keydown', event => {
        if (!drawerOpen || document.querySelector('dialog[open]')) return;
        if (event.key === 'Escape') {
            event.preventDefault();
            setDrawer(false);
        }
        if (event.key !== 'Tab') return;
        const items = $$('a, button', sidebar).filter(element => element.getClientRects().length && !element.disabled);
        const first = items[0];
        const last = items.at(-1);
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });
    mobile.addEventListener('change', () => {
        const sidebarHadFocus = sidebar.contains(document.activeElement);
        const openerHadFocus = document.activeElement === opener;
        setDrawer(false, false);
        setCollapsed();
        if (mobile.matches && sidebarHadFocus) opener.focus();
        else if (!mobile.matches && openerHadFocus) $('a', sidebar).focus();
    });

    const controller = { closeDrawer: (restore = false) => setDrawer(false, restore) };
    controllers.set(sidebar, controller);
    setDrawer(false, false);
    setCollapsed();
    return controller;
}
