import { $, $$, setDisclosure } from '../core/helpers.js';

export function initAdminNavigation() {
    if (!$('#admin-sidebar')) return;
    const body = document.body;
    const mobile = window.matchMedia('(max-width: 1020px)');
    const pages = new Map($$('[data-page]').map(page => [page.dataset.page, page]));
    let drawerOpener;
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
        if (pages.size === 0) {
            const sidebar = $('#admin-sidebar');
            const workspace = $('[data-workspace-module]');
            const module = workspace?.dataset.workspaceModule || 'projects';
            const title = workspace?.dataset.workspaceTitle || 'Projects';
            $$('a[href^="#"]', sidebar).forEach(link => {
                link.href = sidebar.dataset.dashboardUrl + link.getAttribute('href');
            });
            $$('[data-nav]').forEach(link => {
                const active = link.dataset.nav === module;
                link.classList.toggle('active', active);
                if (active) link.setAttribute('aria-current', 'page');
                else link.removeAttribute('aria-current');
            });
            $('.program-toggle').classList.add('active');
            setDisclosure($('.program-toggle'), true);
            $('#current-context').textContent = title;
            document.title = `${title} · AssocMap`;
            setDrawer(false, false);
            return;
        }
        let key = location.hash.slice(1) || $('[data-initial-page]')?.dataset.initialPage || 'dashboard';
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


    document.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.matches('[data-open-sidebar]')) setDrawer(true);
        if (button.matches('[data-close-sidebar]')) setDrawer(false);
        if (button.matches('[data-collapse-sidebar]')) {
            const collapsed = body.classList.toggle('sidebar-collapsed');
            button.setAttribute('aria-expanded', String(!collapsed));
            button.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        }
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

    mobile.addEventListener('change', () => setDrawer(false, false));
    window.addEventListener('hashchange', () => navigate());
    navigate(false);
}
