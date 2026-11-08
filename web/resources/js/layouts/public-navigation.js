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
        publicNavbar.classList.add('is-enhanced');

        const sections = [...document.querySelectorAll('.public-main section[id]')];
        const sectionLinks = [...navigation.querySelectorAll('a[href*="#"]')];
        if (sections.length && 'IntersectionObserver' in window) {
            const updateCurrent = () => {
                const current = sections.findLast(section => section.getBoundingClientRect().top <= window.innerHeight * 0.45) ?? sections[0];
                sectionLinks.forEach(link => {
                    if (link.hash === `#${current.id}`) link.setAttribute('aria-current', 'location');
                    else link.removeAttribute('aria-current');
                });
            };
            const sectionObserver = new IntersectionObserver(updateCurrent, {
                rootMargin: '-90px 0px -55% 0px',
            });
            sections.forEach(section => sectionObserver.observe(section));
            updateCurrent();
        }
    }

    // Reveals move visible content; a missing observer or script never hides it.
    if (!document.body.classList.contains('public-page') || !('IntersectionObserver' in window)) return;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (reducedMotion.matches) return;
    const revealObserver = new IntersectionObserver(entries => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) return;
            entry.target.classList.add('is-revealed');
            revealObserver.unobserve(entry.target);
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('[data-public-reveal]').forEach(element => revealObserver.observe(element));
    reducedMotion.addEventListener('change', event => {
        if (event.matches) revealObserver.disconnect();
    });
}
