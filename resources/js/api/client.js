/**
 * The one way the website talks to the Laravel API.
 *
 * Sign-in uses Laravel Sanctum's cookie mode: the session cookie travels with
 * every same-origin request, and changing requests carry the X-XSRF-TOKEN
 * header that Laravel checks against its XSRF-TOKEN cookie. Nothing secret is
 * kept in JavaScript or localStorage.
 *
 * Every error becomes an ApiError with the API's { message, code, errors }.
 */

const BASE = '/api/v1';
const XSRF_COOKIE = 'XSRF-TOKEN';

export class ApiError extends Error {
    constructor(status, body = {}) {
        super(body.message || 'Something went wrong on our side. Please try again.');
        this.name = 'ApiError';
        this.status = status;
        this.code = body.code || (status ? 'http_error' : 'network_error');
        this.errors = body.errors || {};
    }
}

/** { field: 'first message' } for showing next to form fields */
export function fieldErrors(error) {
    const out = {};
    for (const [field, messages] of Object.entries(error?.errors || {})) out[field] = messages[0];
    return out;
}

function readCookie(name) {
    const match = document.cookie.split('; ').find(c => c.startsWith(`${name}=`));
    return match ? decodeURIComponent(match.slice(name.length + 1)) : '';
}

let csrfRequest = null;
function ensureCsrfCookie(force = false) {
    if (!force && readCookie(XSRF_COOKIE)) return Promise.resolve();
    csrfRequest ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .finally(() => { csrfRequest = null; });
    return csrfRequest;
}

export async function api(path, { method = 'GET', body, signal } = {}, retried = false) {
    const verb = method.toUpperCase();
    const changing = verb !== 'GET' && verb !== 'HEAD';
    const headers = { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    let response;
    try {
        if (changing) {
            await ensureCsrfCookie();
            headers['X-XSRF-TOKEN'] = readCookie(XSRF_COOKIE);
        }
        // Files go as FormData; the browser sets its own multipart Content-Type
        const isForm = body instanceof FormData;
        if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json';

        response = await fetch(`${BASE}${path}`, {
            method: verb,
            headers,
            body: body === undefined ? undefined : (isForm ? body : JSON.stringify(body)),
            credentials: 'same-origin',
            signal,
        });
    } catch (e) {
        if (e.name === 'AbortError') throw e;
        throw new ApiError(0, { code: 'network_error', message: 'We can’t reach WorkMate. Check your internet connection and try again.' });
    }

    // Expired CSRF token (e.g. the tab was open a long time): refresh it once
    if (response.status === 419 && changing && !retried) {
        await ensureCsrfCookie(true);
        return api(path, { method, body, signal }, true);
    }

    if (response.status === 204) return null;

    let data = null;
    try { data = await response.json(); } catch { /* not JSON */ }

    if (!response.ok) throw new ApiError(response.status, data || undefined);
    return data;
}
