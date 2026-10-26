import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

const source = readFileSync(new URL('../../resources/js/components/summary-counter.js', import.meta.url), 'utf8');
const css = readFileSync(new URL('../../resources/css/components/summary-counter.css', import.meta.url), 'utf8');

function fixture(text, { reduced = false, waapi = true, visible = true, failAt = Infinity } = {}) {
    const effects = [], timers = new Map(), listeners = new Map(), motionListeners = new Set();
    const values = [];
    let now = 0;
    const motion = {
        matches: reduced,
        addEventListener: (_, listener) => motionListeners.add(listener),
        removeEventListener: (_, listener) => motionListeners.delete(listener),
    };
    const view = {
        matchMedia: () => motion,
        setTimeout: (callback, delay) => { timers.set(callback, now + delay); return callback; },
        clearTimeout: id => timers.delete(id),
        addEventListener: (type, listener) => {
            if (!listeners.has(type)) listeners.set(type, []);
            listeners.get(type).push(listener);
        },
    };
    class Node {
        constructor(fragment = false) { this.nodes = []; this.attributes = {}; this.fragment = fragment; this.ownerDocument = document; }
        get children() { return this.nodes.filter(node => typeof node !== 'string'); }
        get textContent() { return this.nodes.map(node => typeof node === 'string' ? node : node.textContent).join(''); }
        set textContent(text) { this.nodes = [text]; }
        append(...nodes) { this.nodes.push(...nodes.flatMap(node => node.fragment ? node.nodes : [node])); }
        replaceChildren(...nodes) { this.nodes = []; this.append(...nodes); }
        setAttribute(key, value) { this.attributes[key] = value; }
        getClientRects() { return visible ? [{}] : []; }
        matches() { return Object.hasOwn(this.attributes, 'data-summary-counter'); }
        querySelectorAll() { return this.children.flatMap(node => [...(node.matches() ? [node] : []), ...node.querySelectorAll()]); }
        animate(frames, options) {
            if (effects.length === failAt) throw new Error('Synthetic animation failure');
            const effect = { frames, options, started: now, cancelled: false, cancel() { this.cancelled = true; } };
            effects.push(effect);
            return effect;
        }
    }
    const document = {
        defaultView: view,
        createElement: () => new Node(),
        createDocumentFragment: () => new Node(true),
        querySelectorAll: () => values,
    };
    const addValue = text => {
        const node = new Node();
        node.textContent = text;
        node.setAttribute('data-summary-counter', '');
        if (!waapi) node.animate = undefined;
        values.push(node);
        return node;
    };
    const value = addValue(text);
    const advance = time => {
        while (true) {
            const next = [...timers].sort((a, b) => a[1] - b[1])[0];
            if (!next || next[1] > time) break;
            now = next[1];
            timers.delete(next[0]);
            next[0]();
        }
        now = time;
    };
    const api = runInNewContext(`${source.replaceAll('export function', 'function')}\n({ initSummaryCounters, mountSummaryCounters });`, { document });
    const accessible = node => node.attributes['aria-hidden'] === 'true' ? '' : node.nodes.map(child => typeof child === 'string' ? child : accessible(child)).join('');
    return {
        ...api, value, document, effects, timers, listeners, motionListeners, addValue, advance,
        accessible: () => accessible(value),
        activeEffects: () => effects.filter(effect => !effect.cancelled && effect.started + effect.options.duration > now),
        finish: () => advance(1500),
        reveal: () => { visible = true; listeners.get('hashchange')?.forEach(listener => listener()); },
        reduce: () => { motion.matches = true; [...motionListeners].forEach(listener => listener()); },
    };
}

for (const text of ['0', '1', '9', '10', '45', '100', '999', '1,000', '12,345', '₱1,234.50', '-42.75', '18.50%', '12 kg', '9,007,199,254,740,993,123,456,789.01']) {
    test(`preserves exact accessible and final value: ${text}`, () => {
        const f = fixture(text);
        f.initSummaryCounters();
        assert.ok(f.effects.length > 0);
        assert.equal(f.accessible(), text);
        for (const effect of f.effects) {
            assert.ok(effect.options.duration > 0 && effect.options.duration <= 1500);
            assert.equal(effect.options.iterations ?? 1, 1);
            assert.equal(effect.options.fill, 'none');
        }
        f.finish();
        assert.equal(f.value.textContent, text);
        assert.equal(f.value.children.length, 0);
        assert.equal(f.timers.size, 0);
        assert.equal(f.motionListeners.size, 0);
        assert.ok(f.effects.every(effect => effect.cancelled));
    });
}

test('5 turns through 0, 1, 2, 3, 4 and 5 using the same hidden decorative layers', () => {
    const f = fixture('5');
    f.initSummaryCounters();
    const digit = f.value.children[0];
    const layers = [...digit.children];
    const sequence = [layers[2].textContent];
    for (let step = 0; step < 5; step++) {
        f.advance(step * 240);
        assert.deepEqual(digit.children, layers);
        assert.equal(layers[0].textContent, String(step + 1));
        assert.equal(layers[1].textContent, String(step));
        assert.equal(layers[2].textContent, String(step));
        assert.equal(layers[3].textContent, String(step + 1));
        assert.ok(layers.every(layer => layer.attributes['aria-hidden'] === 'true'));
        sequence.push(layers[3].textContent);
        assert.equal(f.accessible(), '5');
        const turns = f.activeEffects().slice(-2);
        assert.equal(turns[0].frames[0].transform, 'rotateX(0deg)');
        assert.equal(turns[0].frames[1].transform, 'rotateX(-90deg)');
        assert.equal(turns[1].frames[1].transform, 'rotateX(90deg)');
        assert.equal(turns[1].frames[2].transform, 'rotateX(0deg)');
    }
    assert.deepEqual(sequence, ['0', '1', '2', '3', '4', '5']);
    f.advance(1200);
    assert.equal(f.activeEffects().length, 0);
    f.advance(1250);
    assert.equal(f.value.textContent, '5');
    assert.equal(f.value.children.length, 0);
    assert.equal(f.timers.size, 0);
});

test('large formatted values use at most six transitions without numeric rounding', () => {
    const text = '9,007,199,254,740,993,123,456,789.01';
    const f = fixture(text);
    f.initSummaryCounters();
    assert.equal(f.timers.size, 6);
    const firstDigit = f.value.children[0];
    const sequence = [firstDigit.children[2].textContent];
    for (let step = 0; step < 6; step++) {
        f.advance(step * 200);
        sequence.push(firstDigit.children[3].textContent);
        assert.equal(f.accessible(), text);
    }
    assert.deepEqual(sequence, ['0', '1', '3', '4', '6', '7', '9']);
    assert.ok(f.effects.length <= f.value.children.length * 14);
    f.finish();
    assert.equal(f.value.textContent, text);
    assert.equal(f.timers.size, 0);
});

test('100 counts through bounded intermediate totals rather than flipping directly to 100', () => {
    const f = fixture('100');
    f.initSummaryCounters();
    const sequence = ['000'];
    for (let step = 0; step < 6; step++) {
        f.advance(step * 200);
        sequence.push(f.value.children.map(digit => digit.children[0].textContent).join(''));
    }
    assert.deepEqual(sequence, ['000', '016', '033', '050', '066', '083', '100']);
    f.finish();
    assert.equal(f.value.textContent, '100');
});

test('concurrent cards stay within a shared animation budget and release it after cleanup', () => {
    const f = fixture('999999');
    const cards = [f.value, ...Array.from({ length: 20 }, () => f.addValue('999999'))];
    f.mountSummaryCounters();
    assert.equal(cards.filter(card => card.children.length > 0).length, 20);
    assert.equal(cards.at(-1).textContent, '999999');
    assert.equal(cards.at(-1).children.length, 0);
    for (let step = 0; step < 6; step++) {
        f.advance(step * 200);
        assert.ok(f.activeEffects().length <= 480);
    }
    f.finish();
    assert.ok(cards.every(card => card.textContent === '999999' && card.children.length === 0));
    const next = f.addValue('5');
    f.initSummaryCounters(next);
    assert.equal(next.children.length, 1);
    f.reduce();
    assert.equal(next.textContent, '5');
    assert.equal(f.timers.size, 0);
});

test('without JavaScript the original text is present before any initialization', () => {
    const f = fixture('1,234.50');
    assert.equal(f.value.textContent, '1,234.50');
    assert.equal(f.value.children.length, 0);
    assert.equal(f.effects.length, 0);
});

for (const text of ['Unavailable', '—', 'N/A', 'Null', '', 'Unobserved']) {
    test(`leaves missing state static: ${text}`, () => {
        const f = fixture(text);
        f.initSummaryCounters();
        assert.equal(f.effects.length, 0);
        assert.equal(f.value.textContent, text);
    });
}

test('repeated setup, completion and panel activation never replay', () => {
    const f = fixture('10');
    f.mountSummaryCounters();
    const initialEffects = f.effects.length;
    f.mountSummaryCounters();
    f.initSummaryCounters(f.value);
    assert.equal(f.effects.length, initialEffects);
    f.finish();
    const completedEffects = f.effects.length;
    f.reveal();
    f.initSummaryCounters();
    assert.equal(f.effects.length, completedEffects);
    assert.deepEqual([...f.listeners.keys()], ['hashchange']);
    assert.equal(f.listeners.get('hashchange').length, 1);
});

test('unmeasurable panels stay readable and activate once when revealed', () => {
    const f = fixture('0', { visible: false });
    f.mountSummaryCounters();
    assert.equal(f.value.textContent, '0');
    assert.equal(f.effects.length, 0);
    f.reveal();
    assert.equal(f.effects.length, 2);
    f.finish();
    f.reveal();
    assert.equal(f.effects.length, 2);
});

for (const options of [{ reduced: true }, { waapi: false }]) {
    test(`static fallback: ${JSON.stringify(options)}`, () => {
        const f = fixture('1,234.50', options);
        f.initSummaryCounters();
        assert.equal(f.effects.length, 0);
        assert.equal(f.value.textContent, '1,234.50');
        assert.equal(f.timers.size, 0);
    });
}

test('changing motion preference immediately restores plain final text', () => {
    const f = fixture('45');
    f.initSummaryCounters();
    f.reduce();
    assert.equal(f.value.textContent, '45');
    assert.ok(f.effects.every(effect => effect.cancelled));
    assert.equal(f.timers.size, 0);
    assert.equal(f.motionListeners.size, 0);
});

test('mid-initialization failure cancels effects and preserves the value', () => {
    const f = fixture('999', { failAt: 1 });
    assert.doesNotThrow(() => f.initSummaryCounters());
    assert.equal(f.value.textContent, '999');
    assert.equal(f.value.children.length, 0);
    assert.ok(f.effects.every(effect => effect.cancelled));
    f.initSummaryCounters();
    assert.equal(f.effects.length, 1);
});

test('a later page-turn failure restores the original value and cancels remaining steps', () => {
    const f = fixture('5', { failAt: 4 });
    f.initSummaryCounters();
    assert.doesNotThrow(() => f.advance(240));
    assert.equal(f.value.textContent, '5');
    assert.equal(f.value.children.length, 0);
    assert.ok(f.effects.every(effect => effect.cancelled));
    assert.equal(f.timers.size, 0);
    assert.equal(f.motionListeners.size, 0);
    f.initSummaryCounters();
    assert.equal(f.effects.length, 4);
});

test('without cleanup execution the source stays accessible and effects expire independently', () => {
    const f = fixture('0');
    f.initSummaryCounters();
    assert.equal(f.accessible(), '0');
    assert.equal(f.value.children[0].nodes[0], '0');
    assert.ok(f.effects.every(effect => effect.options.fill === 'none'));
    assert.match(css, /visibility: hidden/);
    assert.match(css, /prefers-reduced-motion: reduce/);
});

test('does not depend on requests, storage, navigation, feedback or global mutation observation', () => {
    assert.doesNotMatch(source, /\b(fetch|XMLHttpRequest|MutationObserver|IntersectionObserver|localStorage|sessionStorage|location|showFeedback)\b/);
    const f = fixture('45');
    f.mountSummaryCounters();
    f.finish();
    assert.equal(f.value.textContent, '45');
});

test('report dashboard values stay static while other modules retain summary counters', () => {
    const reports = readFileSync(new URL('../../resources/js/pages/admin/reports.js', import.meta.url), 'utf8');
    const blade = readFileSync(new URL('../../resources/views/admin/reports/index.blade.php', import.meta.url), 'utf8');
    assert.match(reports, /function render\(result\)/);
    assert.match(reports, /render\(initial.result\)/);
    assert.match(reports, /render\(result\);/);
    assert.doesNotMatch(reports, /data-summary-counter/);
    assert.doesNotMatch(blade, /x-summary-counter/);
});
