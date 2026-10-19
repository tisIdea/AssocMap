export function initUserMenus(root = document.querySelector('[data-user-management], [data-project-actions]')) {
    if (!root || root.dataset.userMenusInitialized === 'true') return;
    root.dataset.userMenusInitialized = 'true';
    const projectActions = 'projectActions' in root.dataset;
    const menus = [...root.querySelectorAll('[data-user-actions]')];
    const close = (menu, restore = false) => {
        menu.open = false;
        if (restore) menu.querySelector('summary').focus();
    };
    const closeAll = () => menus.filter(menu => menu.open).forEach(menu => close(menu));
    const position = menu => {
        const trigger = menu.querySelector('summary').getBoundingClientRect();
        const panel = menu.querySelector('[data-user-actions-panel]');
        const width = panel.offsetWidth;
        const height = panel.offsetHeight;
        panel.style.left = Math.max(12, Math.min(trigger.right - width, window.innerWidth - width - 12)) + 'px';
        panel.style.top = (trigger.bottom + height + 8 <= window.innerHeight
            ? trigger.bottom + 8 : Math.max(12, trigger.top - height - 8)) + 'px';
    };
    menus.forEach(menu => menu.addEventListener('toggle', () => {
        if (!menu.open) return;
        menus.filter(other => other !== menu && other.open).forEach(other => close(other));
        position(menu);
    }));
    document.addEventListener('click', event => {
        menus.filter(menu => menu.open && !menu.contains(event.target)).forEach(menu => close(menu));
    });
    document.addEventListener('keydown', event => {
        if (event.key !== 'Escape' || document.querySelector('dialog[open]')) return;
        const menu = menus.find(candidate => candidate.open);
        if (!menu) return;
        event.preventDefault();
        event.stopPropagation();
        close(menu, true);
    }, true);
    window.addEventListener('resize', closeAll);
    window.addEventListener('scroll', event => {
        if (projectActions && menus.some(menu => menu.open && menu.querySelector('[data-user-actions-panel]') === event.target)) return;
        closeAll();
    }, true);
}
