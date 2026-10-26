import assert from 'node:assert/strict';
import test from 'node:test';
import { buildReportCharts, formatReportValue, renderReportAnalytics, reportPeriod } from '../../resources/js/components/report-analytics.js';

const section = (key, rows, chart = {}) => ({ key, title: key, rows, chart, notes: [], measures: [] });
const result = (sections, filters = {}) => ({ sections, meta: { filters: { report_type: 'comprehensive', year: 2026, ...filters }, scope: ['Synthetic association'], scope_basis: 'Synthetic authorized scope' } });
const distribution = (key, rows = [{ label: 'active', count: 3 }, { label: 'inactive', count: 2 }]) => section(key, rows, { label_key: 'label', value_key: 'count' });
const production = (changes = {}) => ({ association: 'A', project: 'P', product: 'Fish', year: 2026, quarter: 1, unit: 'kg', target: '10.00', actual: null, ...changes });
const monthly = (month, quantity, changes = {}) => ({ association: 'A', project: 'P', product: 'Fish', year: 2026, quarter: Math.ceil(month / 3), month, quantity, unit: 'kg', ...changes });
const selected = { report_type: 'production', project_id: 'selected-project', product_id: 'selected-product' };

test('known mutually exclusive status counts use complete doughnuts; overlapping classifications use bars', () => {
    const input = result([distribution('association_status'), distribution('project_status'), section('classifications', [{ classification: 'C1', members: 4 }, { classification: 'C2', members: 4 }])]);
    const charts = buildReportCharts(input);
    assert.deepEqual(charts.map(chart => chart.kind), ['doughnut', 'doughnut', 'bar']);
    assert.deepEqual(charts[0].points.map(point => point.values[0]), [3, 2]);
    assert.match(charts[2].explanation, /do not form a whole/);
    assert.deepEqual(charts[2].points.map(point => point.values[0]), [4, 4]);
});

test('existing category and income sections keep exact canonical labels, values and units', () => {
    for (const key of ['association_municipality', 'association_barangay', 'members', 'project_component', 'training_component', 'production_coverage', 'activity_component', 'monitoring_status', 'income_periods']) {
        const rows = [{ label: 'Recorded <label>', count: key === 'income_periods' ? '1234567890.12' : 0 }];
        const [chart] = buildReportCharts(result([distribution(key, rows)]));
        assert.equal(chart.kind, 'bar');
        assert.deepEqual(chart.points, [{ label: 'Recorded <label>', values: [rows[0].count] }]);
        if (key === 'income_periods') { assert.equal(chart.unit, 'PHP'); assert.match(chart.explanation, /not profit/); }
    }
});

test('production uses one pair per observation and separate unit panels, with null distinct from zero', () => {
    const rows = [production(), production({ quarter: 2, actual: '0.00' }), production({ unit: 'pieces', target: null, actual: '4.00' })];
    const charts = buildReportCharts(result([section('production', rows), section('monthly_production', [monthly(1, '5.00')])]));
    assert.equal(charts.length, 2);
    assert.deepEqual(charts.map(chart => chart.unit), ['kg', 'pieces']);
    assert.deepEqual(charts[0].points.map(point => point.values), [['10.00', null], ['10.00', '0.00']]);
    assert.deepEqual(charts[1].points[0].values, [null, '4.00']);
    assert.match(charts[0].explanation, /not added/);
});

test('attendance bars preserve occurrences and do not invent distinct-person or trained-member totals', () => {
    const [chart] = buildReportCharts(result([section('trainings', [{ association: 'A', training: 'T', date: '2026-01-01', present: 2, absent: 1, recorded: 3 }])]));
    assert.deepEqual(chart.series, ['Present', 'Absent']);
    assert.deepEqual(chart.points[0].values, [2, 1]);
    assert.match(chart.explanation, /not a distinct-person/);
});

test('monthly lines sort the calendar, retain recorded zeros and represent absent months as gaps', () => {
    const [chart] = buildReportCharts(result([section('monthly_production', [monthly(6, '5.00'), monthly(1, '0.00'), monthly(3, '10.25')])], selected));
    assert.equal(chart.kind, 'line');
    assert.equal(chart.points.length, 12);
    assert.deepEqual(chart.points.slice(0, 3), [{ label: 'Jan', values: ['0.00'] }, { label: 'Feb', values: [null] }, { label: 'Mar', values: ['10.25'] }]);
    assert.equal(chart.points[5].values[0], '5.00');
});

test('monthly line requires explicit identities, one unit, unique comparable months and two observations', () => {
    const valid = [monthly(1, '1.00'), monthly(2, '2.00')];
    for (const [rows, filters] of [[valid, {}], [valid, { project_id: 'P' }], [[valid[0]], selected], [[...valid, monthly(1, '4.00')], selected], [[valid[0], monthly(2, '2.00', { unit: 'pieces' })], selected], [[valid[0], monthly(2, '2.00', { year: 2025 })], selected], [valid, { ...selected, month: 1 }]]) {
        assert.deepEqual(buildReportCharts(result([section('monthly_production', rows)], filters)), []);
    }
    const [quarter] = buildReportCharts(result([section('monthly_production', [monthly(4, '0.00'), monthly(6, '5.00')])], { ...selected, quarter: 2 }));
    assert.deepEqual(quarter.points.map(point => point.label), ['Apr', 'May', 'Jun']);
});

test('missing or invalid category measurements never become fabricated zeros or empty doughnuts', () => {
    assert.deepEqual(buildReportCharts(result([distribution('association_status', [])])), []);
    for (const count of [null, undefined, '', ' ', false, 'NaN']) assert.deepEqual(buildReportCharts(result([distribution('association_status', [{ label: 'active', count }])])), []);
    assert.equal(buildReportCharts(result([distribution('association_status', [{ label: 'active', count: 0 }])]))[0].kind, 'bar');
    assert.deepEqual(buildReportCharts(result([distribution('unsupported')])) , []);
});

test('large categories and unit panels remain bounded without merging or discarding detailed source rows', () => {
    const rows = Array.from({ length: 30 }, (_, count) => ({ label: `G${count}`, count }));
    const input = result([distribution('association_status', rows)]);
    const [chart] = buildReportCharts(input);
    assert.equal(chart.kind, 'bar'); assert.equal(chart.total, 30); assert.equal(chart.points.length, 20);
    assert.equal(input.sections[0].rows.length, 30);
    assert.equal(buildReportCharts(result([section('production', rows.map((_, index) => production({ unit: `unit${index}` })))] )).length, 8);
});

test('formatting preserves canonical decimal digits, units, zero and unobserved values', () => {
    assert.equal(formatReportValue('1234567890.12', 'PHP'), 'PHP 1,234,567,890.12');
    assert.equal(formatReportValue('0.00', 'kg'), '0.00');
    assert.equal(formatReportValue(null), 'Unobserved');
    assert.match(reportPeriod({ year: 2026, program_year: 2024 }, true), /Program year 2024.*Current snapshot/);
    assert.equal(reportPeriod({ year: 2026, quarter: 2, month: 4 }), '2026 Q2 · recorded month Apr.');
});

class Element {
    constructor(tag) { this.tagName = tag; this.children = []; this.attributes = new Map(); this.style = {}; this.dataset = {}; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    setAttribute(key, value) { this.attributes.set(key, value); }
    removeAttribute(key) { this.attributes.delete(key); }
    all(tag) { return this.children.flatMap(child => [...(child.tagName === tag ? [child] : []), ...child.all(tag)]); }
}

function render(input) {
    const previous = globalThis.document;
    globalThis.document = { createElement: tag => new Element(tag), createElementNS: (_namespace, tag) => new Element(tag) };
    try { const container = new Element('section'); renderReportAnalytics(input, container); return container; }
    finally { globalThis.document = previous; }
}

test('rendered charts have visible exact table equivalents and no HTML interpolation', () => {
    const output = render(result([distribution('association_status'), section('production', [production({ project: '<script>unsafe</script>', target: null, actual: '0.00' })])]));
    assert.equal(output.all('figure').length, 2);
    assert.equal(output.all('table').length, 2);
    assert.ok(output.all('figure').every(figure => figure.attributes.has('aria-labelledby')));
    assert.ok(output.all('svg').every(plot => plot.attributes.get('aria-hidden') === 'true'));
    assert.ok(output.all('td').some(cell => cell.textContent === 'Unobserved'));
    assert.ok(output.all('strong').some(cell => String(cell.textContent).includes('<script>unsafe</script>')));
    assert.equal(output.all('script').length, 0);
    assert.ok(output.all('a').every(link => link.href.startsWith('#report-table-')));
});

test('line paths break at missing months and accessible tables retain exact point values', () => {
    const output = render(result([section('monthly_production', [monthly(1, '0.00'), monthly(3, '10.00'), monthly(6, '5.00')])], selected));
    const path = output.all('path').find(path => path.attributes.get('stroke-width') === '2');
    assert.equal(path.attributes.get('d').match(/ M/g).length, 3);
    assert.equal(path.attributes.get('d').includes(' L'), false);
    assert.ok(output.all('td').some(cell => cell.textContent === '0.00'));
    assert.ok(output.all('td').some(cell => cell.textContent === 'Unobserved'));
});

test('monthly line connects adjacent recorded months and restarts after a missing month', () => {
    const output = render(result([section('monthly_production', [monthly(1, '0.00'), monthly(2, '10.00'), monthly(4, '5.00')])], selected));
    const path = output.all('path').find(path => path.attributes.get('stroke-width') === '2');
    assert.equal(path.attributes.get('d').match(/ M/g).length, 2);
    assert.equal(path.attributes.get('d').match(/ L/g).length, 1);
    assert.equal(output.all('circle').length, 3);
});

test('status colors remain stable when other categories are filtered away', () => {
    const all = render(result([distribution('association_status')]));
    const one = render(result([distribution('association_status', [{ label: 'inactive', count: 2 }])]));
    assert.equal(all.all('circle')[1].attributes.get('stroke'), one.all('circle')[0].attributes.get('stroke'));
});
