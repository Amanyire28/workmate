import { reactive, watch } from 'vue';
import { resolveOrigin, searchNearby } from '@/utils/nearbySearch.js';
import { searchOrigin, requestDeviceLocation, gpsMaxAgeMs } from './location.js';
import { countUsage } from '@/utils/usage.js';

/**
 * State for the homepage "nearby" search: a short staged sequence the radar
 * and status text follow, then grouped results.
 *
 * The stages are simulated on mock data with fixed, short timings (about 2.5 s
 * at most). Once a search API exists, its progress and response should drive
 * `stage` and `result` instead of these timers.
 */
const TIMING = { init: 300, nearby: 850, wider: 750, done: 350 };

export const STAGES = ['init', 'locating', 'nearby', 'wider', 'done'];

export const nearbySearch = reactive({
    status: 'idle',    // idle | searching | done | error
    stage: null,       // one of STAGES while searching
    query: '',
    result: null,      // from searchNearby()
    found: [],         // provider ids the radar has "discovered" so far
    showWider: false,  // user asked to include the wider area even though nearby had enough
    error: null,       // { code, message } when status is 'error'
    cancelled: false,
});

let run = 0;

function prefersReducedMotion() {
    return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
}

// Reduced motion: keep the same steps, but don't hold anyone up for the animation
function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, prefersReducedMotion() ? Math.min(ms, 120) : ms));
}

/** Mark providers as found one by one across `duration` ms. Returns false if the run was replaced. */
async function discover(items, duration, id) {
    const step = duration / (items.length + 1);
    for (const item of items) {
        await sleep(step);
        if (id !== run) return false;
        nearbySearch.found.push(item.provider.id);
    }
    await sleep(step);
    return id === run;
}

export async function startNearbySearch(query) {
    const id = ++run;
    Object.assign(nearbySearch, {
        status: 'searching', stage: 'init', query: query.trim(),
        result: null, found: [], showWider: false, error: null, cancelled: false,
    });

    await sleep(TIMING.init);
    if (id !== run) return;

    // Refresh a shared position only if it is old; a picked area needs no location check
    let originState = searchOrigin.value;
    if (originState.kind === 'gps' && Date.now() - originState.timestamp > gpsMaxAgeMs()) {
        nearbySearch.stage = 'locating';
        try {
            originState = await requestDeviceLocation();
        } catch (err) {
            if (id !== run) return;
            Object.assign(nearbySearch, { status: 'error', stage: null, error: { code: err.code, message: err.message } });
            return;
        }
        if (id !== run) return;
    }

    let result;
    try {
        result = searchNearby(nearbySearch.query, resolveOrigin(originState));
    } catch (err) {
        console.error(err);
        Object.assign(nearbySearch, {
            status: 'error', stage: null,
            error: { code: 'failed', message: 'Something went wrong while searching. Please try again.' },
        });
        return;
    }
    nearbySearch.result = result;

    nearbySearch.stage = 'nearby';
    if (!await discover(result.groups.nearby, TIMING.nearby, id)) return;

    if (result.needsWider) {
        nearbySearch.stage = 'wider';
        if (!await discover([...result.groups.extended, ...result.groups.serving], TIMING.wider, id)) return;
    }

    nearbySearch.stage = 'done';
    // Counted for the dashboard: only how it turned out, never what or where
    const g = result.groups;
    countUsage('search', g.nearby.length ? 'nearby' : (g.extended.length || g.serving.length ? 'wider' : 'none'));
    await sleep(TIMING.done);
    if (id !== run) return;
    nearbySearch.status = 'done';
}

/** Stop a running search (or close the results) and return to the idle homepage */
export function cancelNearbySearch() {
    const wasSearching = nearbySearch.status === 'searching';
    run++;
    Object.assign(nearbySearch, {
        status: 'idle', stage: null, result: null, found: [], showWider: false, error: null,
        cancelled: wasSearching,
    });
}

export function retryNearbySearch() {
    if (nearbySearch.query) startNearbySearch(nearbySearch.query);
}

// Changing the location after a search re-runs it for the new area, no need to start over.
// Sync, so origin changes made by a running search (GPS refresh or fallback) are seen as such.
watch(searchOrigin, () => {
    const settled = nearbySearch.status === 'done' || nearbySearch.status === 'error';
    if (settled && nearbySearch.query) startNearbySearch(nearbySearch.query);
}, { flush: 'sync' });
