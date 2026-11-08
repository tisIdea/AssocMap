const palette = ['#0b427b', '#63876e', '#b07835', '#796a99', '#ad5968', '#6c8295', '#897c48', '#35679d'];
const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const statusColors = { active: palette[1], inactive: palette[5], planned: palette[2], ongoing: palette[0], completed: palette[1], suspended: palette[3], cancelled: palette[4], terminated: '#743949' };
const categoryColor = label => statusColors[label] || palette[[...label].reduce((sum, character) => sum + character.charCodeAt(0), 0) % palette.length];
const configurations = {
    association_status: ['Current association statuses', 'associations', 'One recorded current status per association. Archive state is separate from status.', 'doughnut'],
    members: ['Retained members by association', 'members', 'Totals include retained archived members. These are current counts, not historical growth.'],
    classifications: ['Recorded member classifications', 'members', 'A member may appear in several classifications. These counts do not form a whole and must not be added.'],
    project_status: ['Current project statuses', 'projects', 'One current status per selected project, including retained archived projects. Status does not establish success or effectiveness.', 'doughnut'],
    project_component: ['Projects by component', 'projects', 'Counts describe the selected portfolio and program-year cohort, not an event-year trend.'],
    training_component: ['Training activities by component', 'training activities', 'Counts use selected training start dates. Activity counts do not measure training effectiveness.'],
    production_coverage: ['Production observation coverage', 'observations', 'Counts of product-quarter observations by period and recorded unit; these are not output quantities.'],
    income_periods: ['Recorded gross income by period', 'PHP', 'Saved gross-income entries by quarter and recording frequency. Gross income is not profit. Missing entries and incomplete monthly coverage are not complete-quarter estimates.'],
    activity_component: ['Program activities by component', 'activities', 'Recorded activity counts, not livelihood outcomes. Program-wide activities follow the authorized report scope.'],
    monitoring_status: ['Recorded monitoring statuses', 'quarterly records', 'Historically saved status on quarterly containers. Containers and legacy report rows are not verified physical visits.'],
};

const numeric = value => (typeof value === 'number' || (typeof value === 'string' && /^\d+(?:\.\d+)?$/.test(value))) && Number.isFinite(Number(value)) && Number(value) >= 0;
const node = (tag, text, className) => {
    const element = document.createElement(tag);
    if (text !== undefined) element.textContent = text;
    if (className) element.className = className;
    return element;
};
const svg = (tag, attributes) => {
    const element = document.createElementNS('http://www.w3.org/2000/svg', tag);
    for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, String(value));
    return element;
};

export function formatReportValue(value, unit = '') {
    if (value === null || value === undefined) return 'Unobserved';
    const [whole, fraction] = String(value).split('.');
    const exact = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',') + (fraction === undefined ? '' : `.${fraction}`);
    return unit === 'PHP' ? `PHP ${exact}` : exact;
}

export function reportPeriod(filters, snapshot = false) {
    const cohort = filters.program_year ? `Program year ${filters.program_year}. ` : '';
    if (snapshot) return `${cohort}Current snapshot; not a historical trend.`;
    const period = filters.year ? `${filters.year}${filters.quarter ? ` Q${filters.quarter}` : ' · all quarters'}` : 'Recorded periods';
    return `${cohort}${period}${filters.month ? ` · recorded month ${months[Number(filters.month) - 1]}` : ''}.`;
}

function descriptor(section, title, unit, explanation, points, series, kind = 'bar') {
    return { key: section.key, title, unit, explanation, points: points.slice(0, 20), total: points.length,
        series, kind, notes: section.notes || [] };
}

export function rankReportPoints(points, limit = 10) {
    const sorted = points.map((point, index) => ({ ...point, index })).sort((a, b) =>
        Number(b.values[0]) - Number(a.values[0]) || a.label.localeCompare(b.label, 'en') || a.index - b.index);
    let rank = 0;
    return sorted.slice(0, limit).map((point, index) => {
        if (!index || Number(point.values[0]) !== Number(sorted[index - 1].values[0])) rank = index + 1;
        const { index: _sourceIndex, ...data } = point;
        return { ...data, rank };
    });
}

export function buildReportCharts(result) {
    const charts = [];
    const filters = result.meta.filters;
    for (const section of result.sections) {
        if (section.key === 'association_geography' && section.rows.length && section.rows.every(row => row.municipality_id && numeric(row.municipality_total))) {
            const municipalities = new Map();
            section.rows.forEach(row => municipalities.set(row.municipality_id, { label: row.municipality, values: [row.municipality_total] }));
            const points = [...municipalities.values()];
            const chart = descriptor(section, 'Municipalities by association count', 'associations',
                'Ranked by distinct authorized associations, including retained archived associations. Higher counts do not measure success or effectiveness.', points, ['Associations']);
            chart.points = rankReportPoints(points, 5); chart.ranking = true; chart.snapshot = true;
            charts.push(chart);
        }
        const config = configurations[section.key];
        if (config && section.rows.length) {
            const labelKey = section.key === 'classifications' ? 'classification' : section.chart.label_key;
            const valueKey = section.key === 'classifications' ? 'members' : section.chart.value_key;
            if (!labelKey || !valueKey || !section.rows.every(row => numeric(row[valueKey]))) continue;
            const points = section.rows.map(row => ({ label: String(row[labelKey]), values: [row[valueKey]] }));
            const whole = points.reduce((sum, point) => sum + Number(point.values[0]), 0);
            // A status doughnut always uses the entire mutually exclusive population.
            const kind = config[3] === 'doughnut' && points.length <= palette.length && whole > 0 ? 'doughnut' : 'bar';
            const chart = descriptor(section, config[0], config[1], config[2], points, ['Recorded value'], kind);
            chart.snapshot = ['association_municipality', 'association_barangay', 'association_status', 'members', 'classifications', 'project_status', 'project_component'].includes(section.key);
            if (['members', 'project_component'].includes(section.key)) {
                chart.points = rankReportPoints(points, 5); chart.ranking = true;
                chart.explanation += ' Ranked by recorded count, not success, effectiveness or financial performance.';
            }
            charts.push(chart);
        }
        if (section.key === 'trainings' && section.rows.length) {
            const points = section.rows.map(row => ({ label: `${row.date} · ${row.training} · ${row.association}`, values: [row.present, row.absent] }));
            if (points.every(point => point.values.every(numeric))) charts.push(descriptor(section, 'Recorded attendance by training', 'attendance occurrences',
                'Present and absent participation rows are separate. Zero rows means no attendance recorded, not proof of zero actual attendance. Repeat attendance is not a distinct-person or official trained-member count.', points, ['Present', 'Absent'], 'grouped'));
        }
        if (section.key === 'production' && section.rows.length) {
            const units = [...new Set(section.rows.map(row => row.unit))];
            for (const unit of units.slice(0, 8)) {
                const rows = section.rows.filter(row => row.unit === unit);
                if (!rows.every(row => [row.target, row.actual].every(value => value === null || numeric(value)))) continue;
                const points = rows.map(row => ({ label: `${row.year} Q${row.quarter} · ${row.product} · ${row.project} · ${row.association}`, values: [row.target, row.actual] }));
                const chart = descriptor(section, `Recorded target and actual output (${unit})`, unit,
                    'Each pair is one product-quarter observation. Units and quarters are kept separate. Missing output is unobserved, not zero. Monthly details are the same production and are not added to quarterly actual output. A month filter does not turn this quarter context into monthly output.', points, ['Target', 'Actual'], 'grouped');
                if (units.length > 8) chart.notes = [...chart.notes, 'Showing the first 8 recorded units. Detailed tables retain all units.'];
                charts.push(chart);
            }
        }
        if (section.key === 'monthly_production' && section.rows.length && filters.project_id && filters.product_id) {
            const rows = section.rows;
            // Canonical rows contain display names, not identities. Explicit ID filters prevent combining namesakes.
            if (new Set(rows.map(row => row.unit)).size !== 1 || new Set(rows.map(row => `${row.year}-${row.month}`)).size !== rows.length
                || rows.some(row => Number(row.year) !== Number(filters.year) || !numeric(row.quantity))) continue;
            const start = filters.quarter ? (Number(filters.quarter) - 1) * 3 : 0;
            const selectedMonths = filters.month ? [Number(filters.month)] : Array.from({ length: filters.quarter ? 3 : 12 }, (_, index) => start + index + 1);
            const points = selectedMonths.map(month => ({ label: months[month - 1], values: [rows.find(row => Number(row.month) === month)?.quantity ?? null] }));
            if (points.filter(point => point.values[0] !== null).length < 2) continue;
            charts.push(descriptor(section, 'Recorded monthly production', rows[0].unit,
                `Selected project/product: ${rows[0].project} · ${rows[0].product}. Each point is a saved monthly quantity. Missing months break the line; recorded zeros remain zero. The line does not estimate missing production or show growth rates.`, points, ['Monthly quantity'], 'line'));
        }
    }
    return charts;
}

export function reportScale(maximum, integers = false) {
    const rough = (maximum || 1) / 4;
    const power = 10 ** Math.floor(Math.log10(rough));
    const fraction = rough / power;
    let step = (fraction <= 1 ? 1 : fraction <= 2 ? 2 : fraction <= 5 ? 5 : 10) * power;
    if (integers) step = Math.max(1, Math.ceil(step));
    const top = Math.ceil((maximum || 1) / step) * step;
    return Array.from({ length: Math.round(top / step) + 1 }, (_, index) => Number((index * step).toPrecision(12)));
}

const axisValue = value => new Intl.NumberFormat('en-PH', { notation: 'compact', maximumFractionDigits: 2 }).format(value);
function plotText(plot, text, x, y, options = {}) {
    const label = svg('text', { x, y, fill: '#475569', 'font-size': 12, ...options });
    label.textContent = text; plot.append(label); return label;
}
function plotLabel(plot, text, x, y, width, anchor = 'middle') {
    const label = svg('text', { x, y, fill: '#334155', 'font-size': 12, 'text-anchor': anchor });
    const words = text.match(new RegExp(`.{1,${width}}(?:\\s|$)|.{1,${width}}`, 'g')) || [''];
    words.slice(0, 3).forEach((line, index) => {
        const span = svg('tspan', { x, dy: index ? 14 : 0 });
        span.textContent = line.trim() + (index === 2 && words.length > 3 ? '…' : ''); label.append(span);
    });
    const title = svg('title', {}); title.textContent = text; label.append(title); plot.append(label);
    return Math.min(words.length, 3);
}
function plotWidth(container) {
    const style = typeof getComputedStyle === 'function' ? getComputedStyle(container) : null;
    const padding = style ? parseFloat(style.paddingLeft) + parseFloat(style.paddingRight) : 48;
    return Math.max(200, (container.clientWidth || 608) - padding);
}
function plotRegion(plot, chart, container) {
    const scroll = node('div', undefined, 'report-plot-scroll');
    scroll.tabIndex = 0; scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', `${chart.title} graph`);
    scroll.append(plot); container.append(scroll);
}
function seriesLegend(chart, container) {
    if (chart.series.length < 2) return;
    const legend = node('div', undefined, 'report-series-legend');
    chart.series.forEach((series, index) => {
        const item = node('span'), color = node('i');
        color.style.backgroundColor = palette[index]; color.setAttribute('aria-hidden', 'true');
        item.append(color, node('span', series)); legend.append(item);
    });
    container.append(legend);
}
function horizontalBars(chart, container, width, ticks) {
    const left = 12, right = width - 12, top = ticks.at(-1);
    const plot = svg('svg', { class: 'report-stat-chart report-horizontal-bars', 'aria-hidden': 'true', focusable: 'false' });
    chart.orientation = 'horizontal';
    ticks.forEach(tick => plotText(plot, axisValue(tick), left + tick / top * (right - left), 18,
        { 'text-anchor': tick === 0 ? 'start' : tick === top ? 'end' : 'middle', 'font-size': 11 }));
    let cursor = 52;
    chart.points.forEach(point => {
        const label = `${point.rank ? `${point.rank}. ` : ''}${point.label}`;
        const lines = plotLabel(plot, label, left, cursor, Math.max(20, Math.floor((right - left) / 7)), 'start');
        cursor += lines * 14 + 8;
        point.values.forEach((value, series) => {
            if (chart.series.length > 1) plotText(plot, chart.series[series], left, cursor, { 'font-size': 11 });
            plotText(plot, formatReportValue(value), right, cursor, { 'text-anchor': 'end', fill: '#203348', 'font-size': 11 });
            const y = cursor + 9;
            plot.append(svg('rect', { x: left, y, width: right - left, height: 12, fill: '#edf2f6', rx: 3 }));
            for (const tick of ticks) {
                const x = left + tick / top * (right - left);
                plot.append(svg('path', { d: `M ${x} ${y} v 12`, stroke: '#dfe7ee', fill: 'none' }));
            }
            if (numeric(value)) {
                const bar = svg('rect', { x: left, y, width: Number(value) / top * (right - left), height: 12, fill: palette[series], rx: 3 });
                const tooltip = svg('title', {}); tooltip.textContent = `${label} · ${chart.series[series]}: ${formatReportValue(value, chart.unit)}`;
                bar.append(tooltip); plot.append(bar);
                if (Number(value) === 0) plot.append(svg('circle', { cx: left + 2, cy: y + 6, r: 3, fill: palette[series] }));
            }
            cursor += 36;
        });
        cursor += 24;
    });
    plot.setAttribute('viewBox', `0 0 ${width} ${cursor}`);
    container.append(node('p', `Value (${chart.unit}) · zero-based scale`, 'report-chart-axis'));
    seriesLegend(chart, container); plotRegion(plot, chart, container);
}
function barPlot(chart, container) {
    const maximum = Math.max(0, ...chart.points.flatMap(point => point.values.filter(numeric).map(Number)));
    const ticks = reportScale(maximum, chart.unit !== 'PHP' && chart.kind !== 'grouped');
    const top = ticks.at(-1);
    const available = plotWidth(container);
    const horizontal = chart.kind === 'grouped' || chart.points.length > Math.floor(available / 85)
        || chart.points.some(point => point.label.length > 22) || chart.unit === 'PHP';
    if (horizontal) { horizontalBars(chart, container, available, ticks); return; }
    const width = available;
    const height = 340, left = 54, right = width - 24, bottom = 254;
    const plot = svg('svg', { viewBox: `0 0 ${width} ${height}`, class: 'report-stat-chart report-vertical-bars', 'aria-hidden': 'true', focusable: 'false' });
    chart.orientation = 'vertical';
    for (const tick of ticks) {
        const y = bottom - tick / top * (bottom - 32);
        plot.append(svg('path', { d: `M ${left} ${y} H ${right}`, stroke: '#e2e8f0', fill: 'none' }));
        plotText(plot, axisValue(tick), left - 10, y + 4, { 'text-anchor': 'end' });
    }
    plot.append(svg('path', { d: `M ${left} 20 V ${bottom} H ${right}`, stroke: '#94a3b8', fill: 'none' }));
    chart.points.forEach((point, index) => {
        const categoryWidth = (right - left) / chart.points.length;
        const center = left + (index + 0.5) * categoryWidth;
        const label = `${point.rank ? `${point.rank}. ` : ''}${point.label}`;
        plotLabel(plot, label, center, bottom + 24, Math.max(6, Math.floor(categoryWidth / 7)));
        point.values.forEach((value, series) => {
            const barWidth = Math.min(44, categoryWidth * 0.65 / chart.series.length);
            const x = center - barWidth * chart.series.length / 2 + series * barWidth;
            const size = numeric(value) ? Number(value) / top : 0;
            const y = bottom - size * (bottom - 32);
            if (value !== null) {
                const rect = svg('rect', { x, y, width: Math.max(1, barWidth - 3), height: size * (bottom - 32), fill: palette[series], rx: 4 });
                const tooltip = svg('title', {}); tooltip.textContent = `${label} · ${chart.series[series]}: ${formatReportValue(value, chart.unit)}`; rect.append(tooltip); plot.append(rect);
                if (Number(value) === 0) plot.append(svg('circle', { cx: x + barWidth / 2, cy: bottom, r: 3, fill: palette[series] }));
            }
            plotText(plot, formatReportValue(value), x + barWidth / 2, value === null ? bottom - 10 : y - 8,
                { 'text-anchor': 'middle', fill: value === null ? '#64748b' : '#0f172a', 'font-size': 12 });
        });
    });
    seriesLegend(chart, container); plotRegion(plot, chart, container);
}

function doughnutPlot(chart, container) {
    const total = chart.points.reduce((sum, point) => sum + Number(point.values[0]), 0);
    const plot = svg('svg', { viewBox: '0 0 200 200', class: 'report-doughnut', 'aria-hidden': 'true', focusable: 'false' });
    plot.append(svg('circle', { cx: 100, cy: 100, r: 70, fill: 'none', stroke: '#edf2f6', 'stroke-width': 25 }));
    let offset = 0;
    for (const point of chart.points) {
        const proportion = Number(point.values[0]) / total * 100;
        const segment = svg('circle', { cx: 100, cy: 100, r: 70, fill: 'none', stroke: categoryColor(point.label), 'stroke-width': 25,
            pathLength: 100, 'stroke-dasharray': `${proportion} ${100 - proportion}`, 'stroke-dashoffset': -offset, transform: 'rotate(-90 100 100)' });
        const tooltip = svg('title', {}); tooltip.textContent = `${point.label}: ${formatReportValue(point.values[0])} ${chart.unit}`;
        segment.append(tooltip); plot.append(segment);
        offset += proportion;
    }
    plotText(plot, formatReportValue(total), 100, 101, { 'text-anchor': 'middle', fill: palette[0], 'font-size': String(total).length > 7 ? 18 : 27, 'font-weight': 700 });
    plotText(plot, chart.unit, 100, 122, { 'text-anchor': 'middle', 'font-size': 11 });
    const legend = node('ol', undefined, 'report-chart-legend');
    chart.points.forEach(point => {
        const item = node('li');
        const swatch = node('span', undefined, 'report-chart-swatch');
        swatch.style.backgroundColor = categoryColor(point.label);
        swatch.setAttribute('aria-hidden', 'true');
        item.append(swatch, node('span', point.label), node('strong', formatReportValue(point.values[0])));
        legend.append(item);
    });
    const layout = node('div', undefined, 'report-doughnut-layout');
    layout.append(plot, legend);
    container.append(layout);
}

function linePlot(chart, container) {
    const maximum = Math.max(...chart.points.filter(point => numeric(point.values[0])).map(point => Number(point.values[0])));
    const ticks = reportScale(maximum), top = ticks.at(-1);
    const width = plotWidth(container);
    const left = width < 400 ? 48 : 64, right = width - 24;
    const labelStep = Math.max(1, Math.ceil((chart.points.length - 1) / Math.max(1, Math.floor((right - left) / 48))));
    const plot = svg('svg', { viewBox: `0 0 ${width} 310`, class: 'report-stat-chart report-line-chart', 'aria-hidden': 'true', focusable: 'false' });
    for (const value of ticks) {
        const y = 246 - value / top * 208;
        plot.append(svg('path', { d: `M ${left} ${y} H ${right}`, stroke: '#e2e8f0', fill: 'none', 'stroke-dasharray': '3 5' }));
        plotText(plot, axisValue(value), left - 8, y + 4, { 'text-anchor': 'end', 'font-size': width < 400 ? 10 : 12 });
    }
    plot.append(svg('path', { d: `M ${left} 28 V 246 H ${right}`, stroke: '#94a3b8', fill: 'none' }));
    let path = '', connected = false;
    chart.points.forEach((point, index) => {
        const x = left + 8 + index * (right - left - 16) / Math.max(1, chart.points.length - 1);
        const last = index === chart.points.length - 1;
        if (last || index % labelStep === 0 && chart.points.length - 1 - index >= labelStep / 2) {
            plotText(plot, point.label, x, 273, { 'text-anchor': 'middle', 'font-size': 11 });
        }
        if (point.values[0] === null) { connected = false; return; }
        const y = 246 - Number(point.values[0]) / top * 208;
        path += `${connected ? ' L' : ' M'} ${x} ${y}`;
        connected = true;
        const pointNode = svg('circle', { cx: x, cy: y, r: 5, fill: palette[0], stroke: 'white', 'stroke-width': 2 });
        const tooltip = svg('title', {}); tooltip.textContent = `${point.label}: ${formatReportValue(point.values[0])} ${chart.unit}`;
        pointNode.append(tooltip); plot.append(pointNode);
        if (width / chart.points.length >= 85) plotText(plot, formatReportValue(point.values[0]), x, y - 12, { 'text-anchor': 'middle', fill: '#0f172a' });
    });
    plot.append(svg('path', { d: path, fill: 'none', stroke: palette[0], 'stroke-width': 2 }));
    container.append(node('p', `Quantity (${chart.unit}) · recorded month`, 'report-chart-axis'));
    plotRegion(plot, chart, container);
    if (width / chart.points.length < 85) container.append(node('p', 'Point tooltips and the chart values table show every recorded value. Missing months break the line.', 'report-chart-context'));
}

function chartTable(chart, container, scope, filters) {
    const details = node('details', undefined, 'report-chart-data');
    details.append(node('summary', 'Chart values & interpretation'));
    details.append(node('p', chart.explanation, 'report-chart-explanation'));
    details.append(node('p', `Scope: ${scope}. Period: ${reportPeriod(filters, chart.snapshot)} Unit: ${chart.unit}.`, 'report-chart-context'));
    if (chart.ranking) details.append(node('p', 'Equal counts share competition rank (1, 1, 3). Ties are ordered by label, then canonical source order. Top 5 may cut a tie; full detail retains every row.', 'report-chart-explanation'));
    for (const note of chart.notes) details.append(node('p', note, 'report-chart-explanation'));
    const scroll = node('div', undefined, 'report-table-scroll');
    scroll.tabIndex = 0;
    scroll.setAttribute('role', 'region');
    scroll.setAttribute('aria-label', `${chart.title} values`);
    const table = node('table');
    table.append(node('caption', `${chart.title} · ${chart.unit}`));
    const head = node('thead'), header = node('tr');
    for (const label of [...(chart.ranking ? ['Rank'] : []), 'Category / period', ...chart.series]) {
        const cell = node('th', label); cell.scope = 'col'; header.append(cell);
    }
    head.append(header);
    const body = node('tbody');
    for (const point of chart.points) {
        const row = node('tr'), label = node('th', point.label);
        if (chart.ranking) row.append(node('td', point.rank, 'report-numeric'));
        label.scope = 'row'; row.append(label);
        for (const value of point.values) row.append(node('td', formatReportValue(value, chart.unit), 'report-numeric'));
        body.append(row);
    }
    table.append(head, body); scroll.append(table); details.append(scroll); container.append(details);
}

export function renderReportGeography(result, container) {
    const section = result.sections.find(section => section.key === 'association_geography');
    if (!section) return;
    const total = result.sections.find(section => section.key === 'associations')?.measures.find(measure => measure.label === 'Distinct associations')?.value;
    const card = node('details', undefined, 'report-geography'); card.id = 'report-geography'; card.tabIndex = -1;
    card.append(node('summary', section.title), node('p', 'Distinct authorized associations · current snapshot · archived associations retained', 'field-help'));
    if (!section.rows.length) { card.append(node('p', 'No recorded associations in this scope.', 'report-empty')); container.append(card); return; }
    const scroll = node('div', undefined, 'report-table-scroll');
    scroll.tabIndex = 0; scroll.setAttribute('role', 'region'); scroll.setAttribute('aria-label', section.title);
    const table = node('table'); table.append(node('caption', 'Geographic IDs determine groups. Distribution bars use the authorized association total.'));
    const head = node('thead'), header = node('tr');
    for (const text of ['Municipality', 'Barangay', 'Association count', 'Visual distribution']) { const th = node('th', text); th.scope = 'col'; header.append(th); }
    head.append(header); table.append(head);
    let body, municipality;
    for (const row of section.rows) {
        if (row.municipality_id !== municipality) {
            municipality = row.municipality_id; body = node('tbody'); body.dataset.municipalityId = municipality;
            const subtotal = node('tr', undefined, 'report-geography-subtotal'), label = node('th', row.municipality);
            label.scope = 'rowgroup'; label.colSpan = 2;
            subtotal.append(label, node('td', formatReportValue(row.municipality_total), 'report-numeric'), node('td', 'Municipality subtotal'));
            body.append(subtotal); table.append(body);
        }
        const tr = node('tr'), municipalityCell = node('td', row.municipality), barangay = node('th', row.barangay);
        municipalityCell.className = 'report-geography-parent'; barangay.scope = 'row'; tr.dataset.barangayId = row.barangay_id;
        const visual = node('td'), bar = node('span', undefined, 'report-geography-bar');
        bar.setAttribute('aria-hidden', 'true');
        if (numeric(total) && Number(total) > 0 && numeric(row.count)) { bar.style.width = `${row.count / total * 100}%`; visual.append(bar); }
        tr.append(municipalityCell, barangay, node('td', formatReportValue(row.count), 'report-numeric'), visual); body.append(tr);
    }
    const foot = node('tfoot'), grand = node('tr'), label = node('th', 'Authorized association total'); label.colSpan = 2; label.scope = 'row';
    grand.append(label, node('td', formatReportValue(total), 'report-numeric'), node('td', 'All recorded barangays')); foot.append(grand); table.append(foot);
    scroll.append(table); card.append(scroll); container.append(card);
}

export function renderReportAnalytics(result, container) {
    const heading = node('div', undefined, 'report-section-heading'), copy = node('div');
    const title = node('h3', 'Visual analytics'); title.id = 'report-analytics-title';
    copy.append(title, node('p', 'Compare recorded values and explore the detail behind each chart.'));
    heading.append(copy); container.replaceChildren(heading);
    const charts = buildReportCharts(result);
    if (!charts.length) container.append(node('p', 'No suitable recorded chart data for this selection. Review the summary indicators and detailed tables below.', 'report-empty'));
    const grid = node('div', undefined, 'report-chart-grid');
    const priority = result.meta.filters.report_type === 'comprehensive' ? ['association_geography', 'project_status', 'income_periods', 'production'] : ['monthly_production'];
    charts.sort((a, b) => (priority.includes(a.key) ? priority.indexOf(a.key) : 100) - (priority.includes(b.key) ? priority.indexOf(b.key) : 100));
    const figures = charts.map(chart => {
        const figure = node('figure', undefined, 'report-chart-card');
        figure.dataset.chartKind = chart.kind;
        grid.append(figure);
        return figure;
    });
    container.append(grid);
    charts.forEach((chart, index) => {
        const figure = figures[index];
        const title = node('figcaption', chart.title);
        title.id = `report-chart-${index}`;
        figure.setAttribute('aria-labelledby', title.id);
        const scope = result.meta.scope.length === 1 ? result.meta.scope[0] : `${result.meta.scope.length} authorized associations (see Reporting scope)`;
        figure.append(node('span', chart.kind === 'doughnut' ? 'Distribution' : chart.kind === 'line' ? 'Recorded history' : 'Comparison', 'report-chart-type'), title,
            node('p', `${chart.unit} · ${chart.snapshot ? reportPeriod(result.meta.filters, true) : reportPeriod(result.meta.filters)}`, 'report-chart-context'));
        if (chart.kind === 'doughnut') doughnutPlot(chart, figure);
        else if (chart.kind === 'line') linePlot(chart, figure);
        else barPlot(chart, figure);
        if (chart.ranking) figure.append(node('p', `Top ${chart.points.length} of ${chart.total} · ranked by recorded count`, 'report-ranking-caption'));
        else if (chart.total > chart.points.length) figure.append(node('p', `First ${chart.points.length} of ${chart.total} observations · full detail below`, 'field-help'));
        chartTable(chart, figure, scope, result.meta.filters);
        const link = node('a', 'Explore detailed records →', 'report-table-link');
        link.href = chart.key === 'association_geography' ? '#report-geography' : `#report-table-${chart.key}`;
        figure.append(link);
        figure.dataset.chartKind = chart.kind;
        figure.dataset.ranking = String(Boolean(chart.ranking));
        if (chart.orientation) figure.dataset.orientation = chart.orientation;
    });
    renderReportGeography(result, container);
    if (result.sections.some(section => section.key === 'monthly_production') && !charts.some(chart => chart.kind === 'line')) {
        container.append(node('p', 'Monthly trend unavailable for this selection. Choose Production Performance and select one project and product, one consistent unit and at least two recorded months. Missing or ambiguous series remain in detailed tables.', 'report-chart-guidance'));
    }
    container.removeAttribute('aria-busy');
}
