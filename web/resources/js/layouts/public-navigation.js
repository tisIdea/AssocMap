export function initPublicNavigation() {
    const publicNavbar = document.querySelector('[data-public-navbar]');
    if (publicNavbar) {
        const toggle = publicNavbar.querySelector('[data-public-menu-toggle]');
        const navigation = publicNavbar.querySelector('#public-navigation');
        const smallScreen = window.matchMedia('(max-width: 760px)');

        const setMenu = expanded => {
            toggle.setAttribute('aria-expanded', String(expanded));
            navigation.hidden = smallScreen.matches && !expanded;
        };
        const syncMenu = () => {
            const focusWasInside = navigation.contains(document.activeElement);
            const toggleWasFocused = document.activeElement === toggle;
            toggle.hidden = !smallScreen.matches;
            setMenu(false);
            if (smallScreen.matches && focusWasInside) toggle.focus();
            else if (!smallScreen.matches && toggleWasFocused) navigation.querySelector('a').focus();
        };

        toggle.addEventListener('click', () => setMenu(toggle.getAttribute('aria-expanded') !== 'true'));
        navigation.addEventListener('click', event => {
            const link = event.target.closest('a');
            if (!link || !smallScreen.matches) return;
            setMenu(false);
            const section = document.getElementById(link.hash.slice(1));
            if (section) {
                section.setAttribute('tabindex', '-1');
                section.focus({ preventScroll: true });
            }
        });
        publicNavbar.addEventListener('keydown', event => {
            if (event.key === 'Escape' && smallScreen.matches && !navigation.hidden) {
                setMenu(false);
                toggle.focus();
            }
        });
        smallScreen.addEventListener('change', syncMenu);
        syncMenu();
    }

}
