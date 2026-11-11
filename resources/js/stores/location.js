import { ref, reactive, watch, computed } from 'vue';
import { catalog, nearestPlace, appSettings } from '@/stores/catalog.js';
import { SEARCH_CONFIG, resolveOrigin } from '@/utils/nearbySearch.js';
import { getSearchLocation, findSearchLocationByName } from '@/data/searchLocations.js';
import { GPS_RULES, shouldRetry, betterReading, movedMeaningfully, accuracyQuality } from '@/utils/gpsQuality.js';

/**
 * Where people are, and where they're looking.
 *
 * - Current location: the device position, found automatically each time
 *   WorkMate opens or comes back on screen (once permission is given). Kept
 *   in memory only; never stored.
 * - A place picked by hand: used instead for the rest of this visit
 *   (session storage), until they choose "Use my location" again.
 * - Service locations (where a job is) and a provider's base area are set in
 *   their own forms. The current location only suggests a starting value and
 *   never changes them by itself.
 */
const KEY = 'workmate.area';

function read(key) {
    try { return sessionStorage.getItem(key) || ''; } catch { return ''; }
}
function write(key, value) {
    try { sessionStorage.setItem(key, value); } catch { /* storage unavailable */ }
}
function forget(key) {
    try { sessionStorage.removeItem(key); } catch { /* storage unavailable */ }
}
function readLocal(key) {
    try { return localStorage.getItem(key) || ''; } catch { return ''; }
}
function writeLocal(key, value) {
    try { localStorage.setItem(key, value); } catch { /* storage unavailable */ }
}
// Earlier versions kept the last place for good; it no longer overrides the current location
try { localStorage.removeItem(KEY); localStorage.removeItem('workmate.searchArea'); } catch { /* storage unavailable */ }

/** A place the person picked by hand this visit (browse pages, forms' starting value). Empty: none. */
export const selectedArea = ref(read(KEY));

watch(selectedArea, (value) => write(KEY, value));

/**
 * Where nearby searches start from:
 *   { kind: 'area', areaId }   a place the user picked (or the default town)
 *   { kind: 'gps', latitude, longitude, accuracy, timestamp, fixId, areaId?, placeDistanceKm? }
 *       the device position; areaId is the nearest place the server named and
 *       placeDistanceKm how far that place is (see utils/gpsQuality.js for how
 *       confidently it is named)
 */
const ORIGIN_KEY = 'workmate.searchArea';

// Places load from the API after start-up, so the saved choice is trusted
// here and checked once they arrive
let lastAreaId = read(ORIGIN_KEY) || appSettings.defaultArea;
export const searchOrigin = ref({ kind: 'area', areaId: lastAreaId });

watch(() => catalog.status, (status) => {
    if (status !== 'ready') return;
    // Earlier versions saved the area's name; use its id now
    if (selectedArea.value && !getSearchLocation(selectedArea.value)) {
        selectedArea.value = findSearchLocationByName(selectedArea.value)?.id || '';
    }
    if (searchOrigin.value.kind !== 'area') return;
    if (getSearchLocation(searchOrigin.value.areaId) && read(ORIGIN_KEY)) return;
    lastAreaId = getSearchLocation(selectedArea.value)?.id || appSettings.defaultArea;
    searchOrigin.value = { kind: 'area', areaId: lastAreaId };
}, { immediate: true });

/** Picked a place by hand this visit (it wins over the current location until they ask for it again) */
const pickedThisVisit = () => !!read(ORIGIN_KEY);

/**
 * Whether the place is the person's own (their current location, or a place
 * they picked) rather than the default town, so pages only say "near you" when it is
 */
export const originChosen = computed(() => searchOrigin.value.kind === 'gps' || pickedThisVisit());

/**
 * What can honestly be said about where the current location is:
 *   { confidence: near | around | broad | none, name, wider }
 * Null when the current location isn't in use.
 */
export const gpsPlace = computed(() => (searchOrigin.value.kind === 'gps' ? resolveOrigin(searchOrigin.value).place : null));

/** "Kisaasi", "around Kisaasi", "Kampala area" or '' for "Near me · …" */
export const gpsPlaceName = computed(() => {
    const p = gpsPlace.value;
    if (!p) return '';
    if (p.confidence === 'near') return p.name;
    if (p.confidence === 'around') return `around ${p.name}`;
    if (p.confidence === 'broad' && p.wider) return `${p.wider} area`;
    return '';
});

/**
 * The place people are in or looking at, for pages that work by place
 * (browse order, a form's suggested place): a place picked this visit, else
 * the current location's place when it can be named confidently.
 */
export const currentAreaId = computed(() => {
    const o = searchOrigin.value;
    if (o.kind === 'area') return pickedThisVisit() ? o.areaId : '';
    const p = gpsPlace.value;
    return p && (p.confidence === 'near' || p.confidence === 'around') ? resolveOrigin(o).area?.id || '' : '';
});

export const locationSheetOpen = ref(false);
export function openLocationSheet() { locationSheetOpen.value = true; }

let manualSeq = 0;
export function chooseSearchArea(areaId) {
    manualSeq++;
    lastAreaId = areaId;
    searchOrigin.value = { kind: 'area', areaId };
    write(ORIGIN_KEY, areaId);
    // The browse pages use the same place, unless it stands for a whole town
    selectedArea.value = getSearchLocation(areaId)?.wholeTown ? '' : areaId;
}

// A place picked elsewhere (e.g. on the account page) becomes the search place too
watch(selectedArea, (id) => {
    if (id && getSearchLocation(id) && searchOrigin.value.kind === 'area' && searchOrigin.value.areaId !== id) {
        lastAreaId = id;
        searchOrigin.value = { kind: 'area', areaId: id };
        write(ORIGIN_KEY, id);
    }
});

/* ---------------- Device location (browser Geolocation API) ---------------- */

export const gps = reactive({
    status: 'idle',        // idle | locating | ready | error
    error: null,           // key of gpsMessages when status is 'error'
    // What the browser says: unknown | prompt | granted | denied | unsupported
    permission: 'unknown',
    // good | approximate | low | unreliable, for the reading in use
    quality: null,
});

export const gpsMessages = {
    unsupported: 'This browser can’t share your location. Choose an area instead.',
    insecure:    'Sharing your location needs a secure (https) connection. Choose an area instead.',
    denied:      'Location access is turned off for WorkMate. Choose an area, or allow location in your browser settings.',
    unavailable: 'We couldn’t work out where you are right now. Check that location is on for your device, or choose an area.',
    timeout:     'Finding your location took too long. Try again, or choose an area.',
};

/** A shared position older than this is refreshed (Admin → Settings) */
export function gpsMaxAgeMs() {
    return SEARCH_CONFIG.gpsMaxAgeMin * 60 * 1000;
}

// The browser remembered permission from an earlier visit (for browsers without the Permissions API)
const GRANTED_KEY = 'workmate.locationGranted';
// The explanation before the browser's own question has been shown once on this device
const INTRO_KEY = 'workmate.locationIntro';

// A reading older than this is dropped if it can't be refreshed (they may be somewhere else now)
const KEEP_LAST_MS = 30 * 60 * 1000;

const HIGH = { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 };
const RETRY = { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 };
// Last resort when precise positioning is unavailable: a rougher, quicker answer
const ROUGH = { enableHighAccuracy: false, timeout: 10000, maximumAge: 5 * 60 * 1000 };

function readOnce(options) {
    return new Promise((resolve, reject) => navigator.geolocation.getCurrentPosition(resolve, reject, options));
}

function toReading(position) {
    return {
        latitude: position.coords.latitude,
        longitude: position.coords.longitude,
        accuracy: Number.isFinite(position.coords.accuracy) ? position.coords.accuracy : Infinity,
        timestamp: position.timestamp || Date.now(),
    };
}

/** The more precise of two readings taken moments apart */
function moreAccurate(a, b) {
    if (!a) return b;
    if (!b) return a;
    return b.accuracy < a.accuracy ? b : a;
}

let pending = null;
let pendingLoud = false;
let fixSeq = 0;

function hasRecentReading() {
    const o = searchOrigin.value;
    return o.kind === 'gps' && Date.now() - o.timestamp <= KEEP_LAST_MS;
}

function fail(code, quiet) {
    if (code === 'denied') {
        gps.permission = 'denied';
        writeLocal(GRANTED_KEY, '');
    }
    // Keep a recent reading when only a refresh failed; otherwise fall back to
    // the last area picked (or the default town), never to invented coordinates
    const keep = code !== 'denied' && hasRecentReading();
    if (!keep && searchOrigin.value.kind === 'gps') searchOrigin.value = { kind: 'area', areaId: lastAreaId };
    // An automatic check fails silently; a tap on "Use my location" explains why
    gps.status = quiet ? (keep ? 'ready' : 'idle') : 'error';
    gps.error = quiet ? null : code;
    const err = new Error(gpsMessages[code]);
    err.code = code;
    return err;
}

/** Puts a new reading in use, naming its place again only when they've really moved */
function apply(reading) {
    const current = searchOrigin.value.kind === 'gps' ? searchOrigin.value : null;
    const chosen = betterReading(current, reading);
    gps.status = 'ready';
    gps.error = null;
    gps.permission = 'granted';
    writeLocal(GRANTED_KEY, '1');
    if (current && chosen === current) {
        // A much rougher reading than the recent one: keep the recent one
        gps.quality = accuracyQuality(current.accuracy);
        return current;
    }
    gps.quality = accuracyQuality(reading.accuracy);

    if (current && !movedMeaningfully(current, reading)) {
        // Same place (or GPS drift): newer reading, same place name, no reloads
        const origin = { ...current, ...reading };
        searchOrigin.value = origin;
        return origin;
    }

    const origin = { kind: 'gps', ...reading, fixId: ++fixSeq };
    searchOrigin.value = origin;
    nameThePlace(origin);
    return origin;
}

/** Asks the server which place this is; a late answer for an older position is ignored */
function nameThePlace(origin) {
    nearestPlace(origin.latitude, origin.longitude)
        .then((found) => {
            const now = searchOrigin.value;
            if (!found || now.kind !== 'gps' || now.fixId !== origin.fixId) return;
            searchOrigin.value = { ...now, areaId: found.area.id, placeDistanceKm: found.distanceKm };
        })
        // Offline or the lookup failed: the place is worked out from the places already known
        .catch(() => {});
}

/**
 * Ask the browser for the device position: when the app opens or comes back
 * on screen (quiet), on a tap on "Use my location", or for a "Near me" search.
 * Asks for a precise reading; if it's rough, tries once more and keeps the
 * better one; if precise positioning isn't available, accepts a rougher one.
 * quiet: failures leave no error message.
 */
export function requestDeviceLocation({ quiet = false } = {}) {
    // Several parts of the page asking at once share one request; a tap makes it report errors
    if (pending) {
        if (!quiet) pendingLoud = true;
        return pending;
    }
    if (typeof navigator === 'undefined' || !('geolocation' in navigator)) {
        gps.permission = 'unsupported';
        return Promise.reject(fail('unsupported', quiet));
    }
    if (!window.isSecureContext) return Promise.reject(fail('insecure', quiet));

    pendingLoud = !quiet;
    const startedAt = manualSeq;
    const hadReading = searchOrigin.value.kind === 'gps';
    gps.status = 'locating';
    gps.error = null;
    const codes = { 1: 'denied', 2: 'unavailable', 3: 'timeout' };

    pending = (async () => {
        let reading = null;
        try {
            reading = toReading(await readOnce(HIGH));
        } catch (error) {
            if (error?.code === 1) throw fail('denied', !pendingLoud);
            // Precise positioning failed: a rougher answer is better than none
            try {
                reading = toReading(await readOnce(ROUGH));
            } catch (second) {
                throw fail(codes[second?.code] || codes[error?.code] || 'unavailable', !pendingLoud);
            }
        }
        // A rough reading: one more try, keeping whichever is more precise
        if (shouldRetry(reading.accuracy)) {
            try {
                reading = moreAccurate(reading, toReading(await readOnce(RETRY)));
            } catch { /* keep the first reading */ }
        }
        // They picked a place by hand while this was running: an automatic check doesn't undo that
        if (!pendingLoud && manualSeq !== startedAt) {
            gps.status = hadReading ? 'ready' : 'idle';
            return searchOrigin.value;
        }
        if (pendingLoud) forget(ORIGIN_KEY);
        return apply(reading);
    })().finally(() => { pending = null; pendingLoud = false; });

    return pending;
}

/* ---------------- Automatically, as WorkMate opens and comes back ---------------- */

/** The one-time explanation before the browser's own question (see LocationIntro.vue) */
export const locationIntro = reactive({ open: false });

/** Whether the browser will answer without asking (permission given before) */
function permitted() {
    return gps.permission === 'granted' || (gps.permission === 'unknown' && readLocal(GRANTED_KEY) === '1');
}

/** Refresh when the reading is missing or old, unless they picked a place by hand */
function refreshIfDue({ force = false } = {}) {
    if (!permitted() || pickedThisVisit()) return;
    const o = searchOrigin.value;
    const stale = o.kind !== 'gps' || Date.now() - o.timestamp > gpsMaxAgeMs();
    if (force || stale) requestDeviceLocation({ quiet: true }).catch(() => {});
}

function decideOnOpen() {
    if (pickedThisVisit()) return;
    if (permitted()) { refreshIfDue({ force: true }); return; }
    // Not decided yet: explain once, then let the browser ask (only after a tap)
    if ((gps.permission === 'prompt' || gps.permission === 'unknown') && readLocal(INTRO_KEY) !== 'shown') {
        locationIntro.open = true;
    }
    // Declined or blocked: never asked again automatically; "Use my location" still works
}

/** "Enable Location" in the explanation: the browser now asks */
export function enableLocationFromIntro() {
    locationIntro.open = false;
    return requestDeviceLocation().catch(() => {});
}

export function dismissLocationIntro() {
    locationIntro.open = false;
}

/** Called when the explanation is actually on screen: it isn't shown again on this device */
export function markLocationIntroShown() {
    writeLocal(INTRO_KEY, 'shown');
}

let started = false;

/**
 * Starts the automatic location on customer pages: a fresh reading on
 * opening, when WorkMate comes back on screen and every few minutes while it
 * stays open and visible (never in the background). Changes in the browser's
 * permission are followed: granted → located; revoked → back to a place.
 */
export function useCurrentLocation() {
    if (started) return;
    started = true;
    if (typeof navigator === 'undefined' || !('geolocation' in navigator)) { gps.permission = 'unsupported'; return; }
    if (!window.isSecureContext) return;

    const query = navigator.permissions?.query?.({ name: 'geolocation' });
    if (query) {
        query.then((status) => {
            gps.permission = status.state;
            status.onchange = () => {
                gps.permission = status.state;
                if (status.state === 'granted') refreshIfDue({ force: true });
                if (status.state === 'denied') fail('denied', true);
            };
            decideOnOpen();
        }).catch(decideOnOpen);
    } else {
        decideOnOpen();
    }

    // Back on screen (another app, the lock screen, a tab switch, the back-forward cache)
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') refreshIfDue();
    });
    window.addEventListener('pageshow', (e) => { if (e.persisted) refreshIfDue(); });
    // Open and on screen for a while: catch people on the move, without tracking
    setInterval(() => {
        if (document.visibilityState === 'visible') refreshIfDue();
    }, Math.max(gpsMaxAgeMs(), 5 * 60 * 1000));
}

export { GPS_RULES };
