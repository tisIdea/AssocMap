export async function gisRequest(url, { method = 'GET', body, csrf, signal } = {}) {
    const target = new URL(url, window.location.origin);
    if (target.origin !== window.location.origin) throw new Error('The GIS endpoint is unavailable.');
    const headers = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    if (csrf) headers['X-CSRF-TOKEN'] = csrf;
    const response = await fetch(target, { method, headers, credentials: 'same-origin', redirect: 'error', signal,
        body: body === undefined ? undefined : JSON.stringify(body) });
    if (!response.headers.get('Content-Type')?.includes('application/json')) {
        const error = new Error('Your session may have expired. Reload and sign in again.');
        error.status = response.ok ? 503 : response.status;
        throw error;
    }
    let data;
    try { data = await response.json(); }
    catch {
        const error = new Error('The server response could not be read. Refresh records before retrying an action.');
        error.status = response.ok ? 503 : response.status;
        throw error;
    }
    if (!data || typeof data !== 'object') {
        const error = new Error('The GIS response could not be confirmed. Refresh records before retrying.');
        error.status = response.ok ? 503 : response.status;
        throw error;
    }
    if (!response.ok) {
        const messages = Object.values(data.errors || {}).flat().filter(value => typeof value === 'string').slice(0, 10);
        const error = new Error(messages.length ? messages.join(' ') : (data.message || 'GIS is temporarily unavailable.'));
        error.status = response.status;
        error.fields = data.errors || {};
        throw error;
    }
    return data;
}

export function queryUrl(base, values) {
    const url = new URL(base, window.location.origin);
    Object.entries(values).forEach(([key, value]) => {
        if (value !== null && value !== undefined && value !== '') url.searchParams.set(key, String(value));
    });
    return url;
}

export function fillOptions(select, data, selected = select.value) {
    const placeholder = select.options[0]?.textContent || 'Select a record';
    select.replaceChildren(new Option(placeholder, ''));
    (data.options || []).forEach(option => select.add(new Option(String(option.label), String(option.value))));
    select.value = selected;
}

export function showStatus(element, message, error = false) {
    delete element.dataset.feedbackFallback;
    element.textContent = message;
    element.classList.toggle('notice-error', error);
    element.setAttribute('role', error ? 'alert' : 'status');
}

export function addDetail(list, label, value) {
    const row = document.createElement('div');
    const term = document.createElement('dt');
    const detail = document.createElement('dd');
    term.textContent = label;
    detail.textContent = value === null || value === undefined || value === '' ? '—' : String(value);
    row.append(term, detail);
    list.append(row);
}

export function debounce(callback, delay = 450) {
    let timer;
    return (...args) => { clearTimeout(timer); timer = setTimeout(() => callback(...args), delay); };
}
