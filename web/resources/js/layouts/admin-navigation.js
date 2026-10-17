import { $, $$, setDisclosure } from '../core/helpers.js';

export function initAdminNavigation(sidebarController) {
    if (!$('#admin-sidebar')) return;
    const pages = new Map($$('[data-page]').map(page => [page.dataset.page, page]));

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
            const operations = ['projects', 'trainings', 'monitoring', 'program-activities'].includes(module);
            $('.program-toggle').classList.toggle('active', operations);
            setDisclosure($('.program-toggle'), operations);
            $('#current-context').textContent = title;
            document.title = `${title} · AssocMap`;
            sidebarController?.closeDrawer();
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
        sidebarController?.closeDrawer();
        if (focus) { $('h1', current)?.focus({ preventScroll: true }); window.scrollTo({ top: 0, behavior: 'instant' }); }
    }


    document.addEventListener('keydown', event => {
        if (event.key === 'Escape') {
            $$('details[open]').forEach(menu => { menu.open = false; $('summary', menu).focus(); });
        }
    });

    window.addEventListener('hashchange', () => navigate());
    navigate(false);
}
