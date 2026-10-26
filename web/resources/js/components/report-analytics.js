const palette = ['#155a9c', '#187c78', '#a86117', '#7050a0', '#b5445a', '#58717f', '#776320', '#3e6792'];
const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const statusColors = { active: palette[1], inactive: palette[5], planned: palette[2], ongoing: palette[0], completed: palette[1], suspended: palette[3], cancelled: palette[4], terminated: '#743949' };
const categoryColor = label => statusColors[label] || palette[[...label].reduce((sum, character) => sum + character.charCodeAt(0), 0) % palette.length];
const configurations = {
    association_municipality: ['Associations by municipality', 'associations', 'Each bar counts distinct associations in a recorded municipality.'],
    association_barangay: ['Associations by barangay', 'associations', 'Each bar counts distinct associations; labels include their municipality.'],
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

export function buildReportCharts(result) {
    const charts = [];
    const filters = result.meta.filters;
    for (const section of result.sections) {
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
            charts.push(chart);
        }
        if (section.key === 'trainings' && section.rows.length) {
            const points = section.rows.map(row => ({ label: `${row.association} · ${row.training} · ${row.date}`, values: [row.present, row.absent] }));
            if (points.every(point => point.values.every(numeric))) charts.push(descriptor(section, 'Recorded attendance by training', 'attendance occurrences',
                'Present and absent participation rows are separate. Zero rows means no attendance recorded, not proof of zero actual attendance. Repeat attendance is not a distinct-person or official trained-member count.', points, ['Present', 'Absent'], 'grouped'));
        }
        if (section.key === 'production' && section.rows.length) {
            const units = [...new Set(section.rows.map(row => row.unit))];
            for (const unit of units.slice(0, 8)) {
                const rows = section.rows.filter(row => row.unit === unit);
                if (!rows.every(row => [row.target, row.actual].every(value => value === null || numeric(value)))) continue;
                const points = rows.map(row => ({ label: `${row.association} · ${row.project} · ${row.product} · ${row.year} Q${row.quarter}`, values: [row.target, row.actual] }));
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

function barPlot(chart, container) {
    const maximum = Math.max(0, ...chart.points.flatMap(point => point.values.filter(numeric).map(Number)));
    const plot = node('div', undefined, 'report-comparison-plot');
    plot.setAttribute('aria-hidden', 'true');
    plot.append(node('p', `Scale: 0–${formatReportValue(maximum, chart.unit)} ${chart.unit === 'PHP' ? '' : chart.unit}`, 'report-chart-axis'));
    chart.points.forEach((point, index) => {
        const group = node('div', undefined, 'report-comparison-row');
        group.append(node('strong', `${index + 1}. ${point.label}`));
        point.values.forEach((value, series) => {
            const row = node('div', undefined, 'report-comparison-value');
            const track = node('span', undefined, 'report-comparison-track');
            const fill = node('span', undefined, `report-comparison-fill report-series-${series}`);
            fill.style.width = `${numeric(value) && maximum ? Number(value) / maximum * 100 : 0}%`;
            track.append(fill);
            row.append(node('span', chart.series[series]), track, node('span', formatReportValue(value, chart.unit)));
            group.append(row);
        });
        plot.append(group);
    });
    container.append(plot);
}

function doughnutPlot(chart, container) {
    const total = chart.points.reduce((sum, point) => sum + Number(point.values[0]), 0);
    const plot = svg('svg', { viewBox: '0 0 200 200', class: 'report-doughnut', 'aria-hidden': 'true', focusable: 'false' });
    let offset = 0;
    for (const point of chart.points) {
        const proportion = Number(point.values[0]) / total * 100;
        plot.append(svg('circle', { cx: 100, cy: 100, r: 70, fill: 'none', stroke: categoryColor(point.label), 'stroke-width': 28,
            pathLength: 100, 'stroke-dasharray': `${proportion} ${100 - proportion}`, 'stroke-dashoffset': -offset, transform: 'rotate(-90 100 100)' }));
        offset += proportion;
    }
    const legend = node('ol', undefined, 'report-chart-legend');
    chart.points.forEach((point, index) => {
        const item = node('li');
        const swatch = node('span', undefined, 'report-chart-swatch');
        swatch.style.backgroundColor = categoryColor(point.label);
        swatch.setAttribute('aria-hidden', 'true');
        item.append(swatch, node('span', `${index + 1}. ${point.label}`), node('strong', formatReportValue(point.values[0])));
        legend.append(item);
    });
    const layout = node('div', undefined, 'report-doughnut-layout');
    layout.append(plot, legend);
    container.append(layout);
}

function linePlot(chart, container) {
    const maximum = Math.max(...chart.points.filter(point => numeric(point.values[0])).map(point => Number(point.values[0])));
    const plot = svg('svg', { viewBox: '0 0 620 260', class: 'report-line-chart', 'aria-hidden': 'true', focusable: 'false' });
    plot.append(svg('path', { d: 'M 58 20 V 210 H 600', stroke: '#8295a8', fill: 'none' }));
    for (const [value, y] of [[maximum, 28], [0, 210]]) {
        const label = svg('text', { x: 52, y, 'text-anchor': 'end', fill: '#334155', 'font-size': 12 });
        label.textContent = new Intl.NumberFormat('en-PH', { notation: 'compact', maximumFractionDigits: 1 }).format(value);
        plot.append(label);
    }
    let path = '', connected = false;
    chart.points.forEach((point, index) => {
        const x = 68 + index * 520 / Math.max(1, chart.points.length - 1);
        const label = svg('text', { x, y: 237, 'text-anchor': 'middle', fill: '#334155', 'font-size': 12 });
        label.textContent = point.label;
        plot.append(label);
        if (point.values[0] === null) { connected = false; return; }
        const y = 210 - (maximum ? Number(point.values[0]) / maximum * 180 : 0);
        path += `${connected ? ' L' : ' M'} ${x} ${y}`;
        connected = true;
        plot.append(svg('circle', { cx: x, cy: y, r: 4, fill: palette[0], stroke: 'white', 'stroke-width': 1 }));
    });
    plot.append(svg('path', { d: path, fill: 'none', stroke: palette[0], 'stroke-width': 2 }));
    const scroll = node('div', undefined, 'report-line-scroll');
    scroll.tabIndex = 0;
    scroll.setAttribute('role', 'region');
    scroll.setAttribute('aria-label', 'Recorded monthly production line chart');
    scroll.append(plot);
    container.append(node('p', `Vertical axis: ${chart.unit}. Horizontal axis: recorded month.`, 'report-chart-axis'), scroll,
        node('p', `Recorded points (${chart.unit}): ${chart.points.filter(point => point.values[0] !== null).map(point => `${point.label}: ${formatReportValue(point.values[0])}`).join(' · ')}`, 'report-chart-axis'));
}

function chartTable(chart, container) {
    const details = node('details', undefined, 'report-chart-data');
    details.append(node('summary', 'View chart values'));
    const scroll = node('div', undefined, 'report-table-scroll');
    scroll.tabIndex = 0;
    scroll.setAttribute('role', 'region');
    scroll.setAttribute('aria-label', `${chart.title} values`);
    const table = node('table');
    table.append(node('caption', `${chart.title} · ${chart.unit}`));
    const head = node('thead'), header = node('tr');
    for (const label of ['Category / period', ...chart.series]) {
        const cell = node('th', label); cell.scope = 'col'; header.append(cell);
    }
    head.append(header);
    const body = node('tbody');
    for (const point of chart.points) {
        const row = node('tr'), label = node('th', point.label);
        label.scope = 'row'; row.append(label);
        for (const value of point.values) row.append(node('td', formatReportValue(value, chart.unit), 'report-numeric'));
        body.append(row);
    }
    table.append(head, body); scroll.append(table); details.append(scroll); container.append(details);
}

export function renderReportAnalytics(result, container) {
    container.replaceChildren(node('h3', 'Visual analytics'), node('p', 'Charts show recorded data only. PDF/XLSX exports retain the canonical tables, summaries and notes; chart images are shown on this page.', 'field-help'));
    const charts = buildReportCharts(result);
    if (!charts.length) container.append(node('p', 'No suitable recorded chart data for this selection. Review the summary indicators and detailed tables below.', 'report-empty'));
    const grid = node('div', undefined, 'report-chart-grid');
    charts.forEach((chart, index) => {
        const figure = node('figure', undefined, 'report-chart-card');
        const title = node('figcaption', chart.title);
        title.id = `report-chart-${index}`;
        figure.setAttribute('aria-labelledby', title.id);
        const scope = result.meta.scope.length === 1 ? result.meta.scope[0] : `${result.meta.scope.length} authorized associations (see Reporting scope)`;
        figure.append(title, node('p', chart.explanation, 'report-chart-description'), node('p', `Scope: ${scope}. Period: ${reportPeriod(result.meta.filters, chart.snapshot)} Unit: ${chart.unit}.`, 'report-chart-context'));
        if (chart.kind === 'doughnut') doughnutPlot(chart, figure);
        else if (chart.kind === 'line') linePlot(chart, figure);
        else barPlot(chart, figure);
        if (chart.total > chart.points.length) figure.append(node('p', `Showing the first ${chart.points.length} of ${chart.total} groups / observations. The detailed table retains all recorded rows.`, 'field-help'));
        for (const note of chart.notes) figure.append(node('p', note, 'field-help'));
        chartTable(chart, figure);
        const link = node('a', 'Open detailed data table', 'report-table-link');
        link.href = `#report-table-${chart.key}`;
        figure.append(link);
        figure.dataset.chartKind = chart.kind;
        grid.append(figure);
    });
    container.append(grid);
    if (result.sections.some(section => section.key === 'monthly_production') && !charts.some(chart => chart.kind === 'line')) {
        container.append(node('p', 'For a monthly line, choose Production Performance and select one project and product. One consistent unit and at least two recorded months are required. Missing or ambiguous series remain in the detailed tables.', 'field-help'));
    }
    container.removeAttribute('aria-busy');
}
