import assert from 'node:assert/strict';
import test from 'node:test';
import { buildReportCharts, formatReportValue, rankReportPoints, renderReportAnalytics, reportPeriod, reportScale } from '../../resources/js/components/report-analytics.js';

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
    for (const key of ['members', 'project_component', 'training_component', 'production_coverage', 'activity_component', 'monitoring_status', 'income_periods']) {
        const rows = [{ label: 'Recorded <label>', count: key === 'income_periods' ? '1234567890.12' : 0 }];
        const [chart] = buildReportCharts(result([distribution(key, rows)]));
        assert.equal(chart.kind, 'bar');
        assert.deepEqual(chart.points.map(({ label, values }) => ({ label, values })), [{ label: 'Recorded <label>', values: [rows[0].count] }]);
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

function render(input, width = 608) {
    const previous = globalThis.document;
    const create = tag => Object.assign(new Element(tag), { clientWidth: width });
    globalThis.document = { createElement: create, createElementNS: (_namespace, tag) => create(tag) };
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
    assert.ok(output.all('th').some(cell => String(cell.textContent).includes('<script>unsafe</script>')));
    assert.equal(output.all('script').length, 0);
    assert.ok(output.all('a').every(link => link.href.startsWith('#report-table-')));
});

test('rankings sort descending without mutating source, share competition ranks and bound ties to Top 10', () => {
    const points = [{ label: 'B', values: [2] }, { label: 'Z', values: [9] }, { label: 'A', values: [2] }, { label: 'A', values: [2] }, { label: 'Zero', values: [0] }];
    assert.deepEqual(rankReportPoints(points).map(p => [p.label, p.values[0], p.rank]), [['Z', 9, 1], ['A', 2, 2], ['A', 2, 2], ['B', 2, 2], ['Zero', 0, 5]]);
    assert.deepEqual(points.map(p => p.label), ['B', 'Z', 'A', 'A', 'Zero']);
    assert.equal(rankReportPoints(Array.from({ length: 14 }, (_, index) => ({ label: `A${index}`, values: [1] }))).length, 10);
    const [chart] = buildReportCharts(result([distribution('project_component', Array.from({ length: 14 }, (_, index) => ({ label: `C${index}`, count: index })))]));
    assert.equal(chart.points.length, 5); assert.deepEqual(chart.points.map(point => point.values[0]), [13, 12, 11, 10, 9]);
    assert.equal(chart.total, 14);
    assert.deepEqual(buildReportCharts(result([section('association_geography', [{ municipality_id: 'M', municipality_total: null }])])), []);
});

test('geography ranking uses canonical municipality IDs and subtotals without combining namesakes or duplicating barangays', () => {
    const rows = [{ municipality_id: 'm1', municipality: 'Same', barangay_id: 'b1', barangay: 'Same', count: 2, municipality_total: 3 },
        { municipality_id: 'm1', municipality: 'Same', barangay_id: 'b2', barangay: 'Same', count: 1, municipality_total: 3 },
        { municipality_id: 'm2', municipality: 'Same', barangay_id: 'b3', barangay: 'Same', count: 1, municipality_total: 1 }];
    const input = result([section('associations', []), section('association_geography', rows), distribution('association_municipality'), distribution('association_barangay')]);
    input.sections[0].measures = [{ label: 'Distinct associations', value: 4 }];
    const [chart] = buildReportCharts(input);
    assert.equal(chart.key, 'association_geography'); assert.deepEqual(chart.points.map(p => p.values[0]), [3, 1]);
    assert.equal(buildReportCharts(input).length, 1, 'Old geography sections remain data-only.');
    const output = render(input);
    const geography = output.all('details').find(section => section.id === 'report-geography');
    assert.deepEqual(geography.all('tbody').map(body => body.dataset.municipalityId), ['m1', 'm2']);
    assert.deepEqual(geography.all('tr').filter(row => row.dataset.barangayId).map(row => row.dataset.barangayId), ['b1', 'b2', 'b3']);
    assert.equal(geography.all('tfoot')[0].all('td')[0].textContent, '4');
    assert.equal(geography.all('span')[0].style.width, '50%');
});

test('conventional bars have SVG rects, zero-based numeric gridlines, category labels and exact values', () => {
    const output = render(result([distribution('training_component', [{ label: 'A', count: 2 }, { label: 'B', count: 4 }])]));
    const plot = output.all('svg')[0];
    assert.match(plot.attributes.get('class'), /vertical-bars/);
    assert.equal(output.all('rect').length, 2);
    assert.ok(output.all('text').some(text => text.textContent === '0'));
    assert.ok(output.all('text').some(text => text.textContent === '4'));
    assert.ok(output.all('tspan').some(text => text.textContent === 'A'));
    assert.ok(output.all('path').length >= 4);
    const long = render(result([distribution('training_component', [{ label: 'A long component name requiring readable horizontal labels', count: 2 }])]));
    assert.match(long.all('svg')[0].attributes.get('class'), /horizontal-bars/);
});

test('numeric scales contain zero, cover actual maxima and retain integer count ticks', () => {
    assert.deepEqual(reportScale(0, true), [0, 1]);
    assert.deepEqual(reportScale(4, true), [0, 1, 2, 3, 4]);
    assert.deepEqual(reportScale(10.25), [0, 5, 10, 15]);
    for (const maximum of [0.03, 3, 12, 1234567890.12]) {
        const ticks = reportScale(maximum); assert.equal(ticks[0], 0); assert.ok(ticks.at(-1) >= maximum);
        assert.ok(ticks.every((value, index) => !index || value > ticks[index - 1]));
    }
});

test('dashboard retains every available chart and consolidates values and interpretation in closed disclosures', () => {
    const input = result(['members', 'project_component', 'training_component', 'production_coverage', 'activity_component', 'monitoring_status'].map(key => distribution(key)));
    const output = render(input), grid = output.children.find(node => node.className === 'report-chart-grid');
    assert.equal(grid.all('figure').length, 6);
    const values = output.all('details').filter(node => node.className === 'report-chart-data');
    assert.equal(values.length, 6);
    assert.ok(values.every(node => !node.open && node.all('table').length === 1 && node.all('p').length > 0));
    assert.ok(output.all('table').some(table => table.all('th').some(th => th.textContent === 'Rank')));
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
    const inactive = output => output.all('circle').find(circle => circle.all('title').some(title => title.textContent.startsWith('inactive:')));
    assert.equal(inactive(all).attributes.get('stroke'), inactive(one).attributes.get('stroke'));
});

test('narrow comparison charts fit their card and retain exact zero and missing values', () => {
    const input = result([section('production', [production({ target: '0.00', actual: null })])]);
    const snapshot = structuredClone(input);
    const output = render(input, 288), plot = output.all('svg')[0];
    assert.equal(Number(plot.attributes.get('viewBox').split(' ')[2]), 240);
    assert.equal(plot.style.minWidth, undefined);
    assert.match(plot.attributes.get('class'), /horizontal-bars/);
    assert.ok(output.all('td').some(cell => cell.textContent === '0.00'));
    assert.ok(output.all('td').some(cell => cell.textContent === 'Unobserved'));
    assert.deepEqual(input, snapshot);
});

test('dense monthly plots reduce axis labels without dropping points, tooltips or table values', () => {
    const rows = Array.from({ length: 12 }, (_, index) => monthly(index + 1, `${index}.00`));
    const input = result([section('monthly_production', rows)], selected);
    const output = render(input, 288), plot = output.all('svg')[0];
    assert.equal(Number(plot.attributes.get('viewBox').split(' ')[2]), 240);
    assert.equal(plot.style.minWidth, undefined);
    assert.equal(output.all('circle').length, 12);
    assert.equal(output.all('circle').filter(circle => circle.all('title').length).length, 12);
    assert.equal(output.all('tbody')[0].all('tr').length, 12);
    assert.ok(plot.all('text').filter(label => /^(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)$/.test(label.textContent)).length <= 5);
});
