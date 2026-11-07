// Fixed local vector artwork; catalog text is never inserted into SVG markup.
const symbols = {
    fish_cage: ['#2563eb', '<rect x="6" y="7" width="12" height="10" rx="1"/><path d="M10 7v10m4-10v10M6 12h12M4 19q2-2 4 0t4 0 4 0 4 0"/>'],
    fish_pen: ['#0f766e', '<path d="M4 7l2-3 2 3v13H4ZM10 7l2-3 2 3v13h-4ZM16 7l2-3 2 3v13h-4ZM2 10h20M2 16h20"/>'],
    culture_pond: ['#0e7490', '<path d="M3 7q2-2 4 0t4 0 4 0 4 0M3 12q2-2 4 0t4 0 4 0 4 0M3 17q2-2 4 0t4 0 4 0 4 0"/>'],
    seaweed_cultivation_site: ['#15803d', '<path d="M6 20q4-4 0-8t0-8m6 16q4-4 0-8t0-8m6 16q4-4 0-8t0-8M4 21h16"/>'],
    hatchery_nursery: ['#b45309', '<path d="M19 14c0 4-3 7-7 7s-7-3-7-7S9 3 12 3s7 7 7 11Z"/>'],
    payao_installation: ['#7e22ce', '<circle cx="12" cy="5" r="2"/><path d="M12 7v14M8 11h8M3 14v2a9 9 0 0 0 18 0v-2M3 14l-2 3m2-3 3 3m15-3-3 3m3-3 2 3"/>'],
    fishing_gear_site: ['#315f83', '<path d="M14 4v11a5 5 0 0 1-10 0v-3l3 3m7-11h5m-5 0-3 3"/><circle cx="14" cy="4" r="1"/>'],
    post_harvest_facility: ['#795548', '<path d="m3 10 9-6 9 6M5 9v12h14V9M9 21v-7h6v7M8 11h2m4 0h2"/>'],
};
const fallback = ['#64748b', '<circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>'];

export function siteSymbol(code) {
    const [color, artwork] = Object.hasOwn(symbols, code) ? symbols[code] : fallback;
    return `<svg xmlns="http://www.w3.org/2000/svg" width="32" height="38" viewBox="0 0 32 38" aria-hidden="true" focusable="false"><path d="M16 37 5 24A15 15 0 1 1 27 24Z" fill="${color}" stroke="white" stroke-width="2"/><g transform="translate(4 3)" fill="none" stroke="white" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${artwork}</g></svg>`;
}
