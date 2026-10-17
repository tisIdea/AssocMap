const prepared = new WeakSet();
const mounted = new WeakSet();
const selector = '[data-summary-counter]';
const duration = 1200;
const maxActiveDigits = 120;
let activeDigits = 0;

function prepare(value) {
    if (prepared.has(value)) return;
    const text = value.textContent;
    if (!/[0-9]/.test(text) || value.children.length) {
        prepared.add(value);
        return;
    }
    if (!value.getClientRects().length) return;
    prepared.add(value);
    const view = value.ownerDocument.defaultView;
    const motion = view.matchMedia?.('(prefers-reduced-motion: reduce)');
    if (motion?.matches || typeof value.animate !== 'function') return;

    const targets = text.match(/[0-9]/g).map(Number);
    if (activeDigits + targets.length > maxActiveDigits) return;
    activeDigits += targets.length;
    const steps = targets.length === 1 ? Math.min(6, Math.max(1, targets[0])) : 6;
    const stepDuration = duration / steps;
    const animations = [];
    const flips = [];
    const timers = [];
    let finished = false;
    const finish = () => {
        if (finished) return;
        finished = true;
        timers.forEach(timer => view.clearTimeout(timer));
        motion?.removeEventListener?.('change', onMotion);
        [...animations, ...flips].forEach(animation => animation.cancel());
        activeDigits -= targets.length;
        value.textContent = text;
    };
    const onMotion = () => { if (motion.matches) finish(); };

    try {
        const total = BigInt(targets.join(''));
        const atStep = step => (total * BigInt(step) / BigInt(steps)).toString().padStart(targets.length, '0');
        const fragment = value.ownerDocument.createDocumentFragment();
        const digits = [];
        for (const character of text) {
            if (!/[0-9]/.test(character)) {
                fragment.append(character);
                continue;
            }
            const digit = value.ownerDocument.createElement('span');
            digit.className = 'summary-counter-digit';
            digit.append(character);
            const layers = [];
            for (const side of ['upper', 'lower', 'upper', 'lower']) {
                const half = value.ownerDocument.createElement('span');
                half.className = `summary-counter-half summary-counter-${side}`;
                half.setAttribute('aria-hidden', 'true');
                half.textContent = '0';
                digit.append(half);
                layers.push(half);
            }
            digits.push(layers);
            fragment.append(digit);
        }
        value.replaceChildren(fragment);
        // Static halves sit beneath each page turn; all effects expire independently.
        digits.forEach(layers => {
            layers.slice(0, 2).forEach(half => animations.push(half.animate([
                { visibility: 'visible' }, { visibility: 'visible' },
            ], { duration, fill: 'none' })));
        });
        const flip = step => {
            if (finished) return;
            flips.forEach(animation => animation.cancel());
            flips.length = 0;
            const remaining = duration - step * stepDuration;
            const halfway = stepDuration / remaining / 2;
            const previous = atStep(step);
            const next = atStep(step + 1);
            digits.forEach(([baseUpper, baseLower, upper, lower], index) => {
                const from = previous[index];
                const to = next[index];
                baseUpper.textContent = lower.textContent = to;
                baseLower.textContent = upper.textContent = from;
                if (from === to) return;
                for (const [half, opening] of [[upper, true], [lower, false]]) {
                    flips.push(half.animate([
                        { transform: opening ? 'rotateX(0deg)' : 'rotateX(90deg)', visibility: 'visible', offset: 0 },
                        { transform: opening ? 'rotateX(-90deg)' : 'rotateX(90deg)', visibility: 'visible', offset: halfway },
                        { transform: opening ? 'rotateX(-90deg)' : 'rotateX(0deg)', visibility: 'visible', offset: halfway * 2 },
                        { transform: opening ? 'rotateX(-90deg)' : 'rotateX(0deg)', visibility: 'visible', offset: 1 },
                    ], { duration: remaining, easing: 'linear', fill: 'none' }));
                }
            });
        };
        flip(0);
        for (let step = 1; step < steps; step++) {
            timers.push(view.setTimeout(() => {
                try { flip(step); } catch { finish(); }
            }, step * stepDuration));
        }
        motion?.addEventListener?.('change', onMotion);
        timers.push(view.setTimeout(finish, duration + 50));
    } catch {
        finish();
    }
}

export function initSummaryCounters(root = document) {
    if (root.matches?.(selector)) prepare(root);
    root.querySelectorAll(selector).forEach(prepare);
}

export function mountSummaryCounters(root = document) {
    if (mounted.has(root)) return;
    mounted.add(root);
    initSummaryCounters(root);
    // Legacy workspace panels are revealed by the existing hash navigation handler.
    const view = root.defaultView ?? root.ownerDocument.defaultView;
    view.addEventListener('hashchange', () => initSummaryCounters(root));
}
