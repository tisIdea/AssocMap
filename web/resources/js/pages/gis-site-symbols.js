// Fixed local vector artwork; catalog text is never inserted into SVG markup.
const symbols = {
    fish_cage: ['#228855', '<rect x="6" y="7" width="12" height="10" rx="1"/><path d="M10 7v10m4-10v10M6 12h12M4 19q2-2 4 0t4 0 4 0 4 0"/>'],
    fish_pen: ['#246c68', '<path d="M5 6v12m5-14v14m5-14v14m4-12v12M5 9h14M5 14h14M3 20h18"/>'],
    seaweed_cultivation_site: ['#26714a', '<path d="M6 20q4-4 0-8t0-8m6 16q4-4 0-8t0-8m6 16q4-4 0-8t0-8M4 21h16"/>'],
    fishing_gear_site: ['#315f83', '<path d="M14 4v11a5 5 0 0 1-10 0v-3l3 3m7-11h5m-5 0-3 3"/><circle cx="14" cy="4" r="1"/>'],
    post_harvest_facility: ['#875d30', '<path d="m3 10 9-6 9 6M5 9v12h14V9M9 21v-7h6v7M8 11h2m4 0h2"/>'],
};
const fallback = ['#596b78', '<circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>'];

export function siteSymbol(code) {
    const [color, artwork] = Object.hasOwn(symbols, code) ? symbols[code] : fallback;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="38" viewBox="0 0 32 38" aria-hidden="true" focusable="false"><path d="M16 37 5 24A15 15 0 1 1 27 24Z" fill="${color}" stroke="white" stroke-width="2"/><g transform="translate(4 3)" fill="none" stroke="white" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${artwork}</g></svg>`;
}
