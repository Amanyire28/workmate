import { ref, reactive, watch, computed } from 'vue';
import { catalog, searchLocations, nearestPlace, appSettings } from '@/stores/catalog.js';
import { SEARCH_CONFIG } from '@/utils/nearbySearch.js';
import { getSearchLocation, findSearchLocationByName } from '@/data/searchLocations.js';

/**
 * The place pages work from (its id, e.g. "ntinda" or "kyebando-kampala"),
 * shared between pages. Empty string means "All areas".
 *
 * WorkMate starts from the person's current location every time the app
 * opens. A place they pick by hand is kept for this visit only (session
 * storage), so the next visit starts from where they are again.
 */
const KEY = 'workmate.area';

function read(key) {
    try { return sessionStorage.getItem(key) || ''; } catch { return ''; }
}
function write(key, value) {
    try { sessionStorage.setItem(key, value); } catch { /* storage unavailable */ }
}
// Earlier versions kept the last place for good; it no longer overrides the current location
try { localStorage.removeItem(KEY); localStorage.removeItem('workmate.searchArea'); } catch { /* storage unavailable */ }

export const selectedArea = ref(read(KEY));

watch(selectedArea, (value) => write(KEY, value));

/**
 * Where nearby searches start from:
 *   { kind: 'area', areaId }                                           a place the user picked
 *   { kind: 'gps', latitude, longitude, accuracy, timestamp, areaId }  a position the user chose to share,
 *                                                                      with the nearest place's id
 * Only place choices are remembered, for this visit; device coordinates stay in memory.
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

/**
 * Whether the place is the person's own (their current location, or a place
 * they picked) rather than the default town, so pages only say "near you" when it is
 */
export const originChosen = computed(() => searchOrigin.value.kind === 'gps' || !!read(ORIGIN_KEY));

export const locationSheetOpen = ref(false);
export function openLocationSheet() { locationSheetOpen.value = true; }

export function chooseSearchArea(areaId) {
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
    status: 'idle',   // idle | locating | ready | error
    error: null,      // key of gpsMessages when status is 'error'
});

export const gpsMessages = {
    unsupported: 'This browser can’t share your location. Choose an area instead.',
    insecure:    'Sharing your location needs a secure (https) connection. Choose an area instead.',
    denied:      'Location access is turned off for WorkMate. Choose an area, or allow location in your browser settings.',
    unavailable: 'We couldn’t work out where you are right now. Choose an area instead.',
    timeout:     'Finding your location took too long. Try again, or choose an area.',
};

/** A shared position older than this is refreshed before searching (Admin → Settings) */
export function gpsMaxAgeMs() {
    return SEARCH_CONFIG.gpsMaxAgeMin * 60 * 1000;
}

let pending = null;

function fail(code, quiet = false) {
    // The automatic start-up check fails silently; a tap on "Use my location" explains why
    gps.status = quiet ? 'idle' : 'error';
    gps.error = quiet ? null : code;
    // Fall back to the last area the user picked, never to invented coordinates
    if (searchOrigin.value.kind === 'gps') searchOrigin.value = { kind: 'area', areaId: lastAreaId };
    const err = new Error(gpsMessages[code]);
    err.code = code;
    return err;
}

/**
 * Ask the browser for the device position: when the app opens (see
 * useCurrentLocation), on a tap on "Use my location", or for a "Near me" search.
 * quiet: failures leave no error message (the start-up check).
 */
export function requestDeviceLocation({ quiet = false } = {}) {
    if (pending) return pending;
    if (typeof navigator === 'undefined' || !('geolocation' in navigator)) return Promise.reject(fail('unsupported', quiet));
    if (!window.isSecureContext) return Promise.reject(fail('insecure', quiet));

    gps.status = 'locating';
    gps.error = null;
    const codes = { 1: 'denied', 2: 'unavailable', 3: 'timeout' };

    pending = new Promise((resolve, reject) => {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                const origin = {
                    kind: 'gps',
                    latitude: position.coords.latitude,
                    longitude: position.coords.longitude,
                    accuracy: position.coords.accuracy,
                    timestamp: position.timestamp,
                };
                searchOrigin.value = origin;
                // Their current location is the choice now, for the rest of this visit too
                try { sessionStorage.removeItem(ORIGIN_KEY); } catch { /* storage unavailable */ }
                gps.status = 'ready';
                resolve(origin);
                // Name the nearest place ("near Kisaasi"); searching works without it
                nearestPlace(origin.latitude, origin.longitude)
                    .then((area) => {
                        if (!area || searchOrigin.value !== origin) return;
                        searchOrigin.value = { ...origin, areaId: area.id };
                        // Forms and browse pages start from the place they're in, unless they picked one this visit
                        if (!read(ORIGIN_KEY) && getSearchLocation(area.id) && !getSearchLocation(area.id).wholeTown) selectedArea.value = area.id;
                    })
                    .catch(() => {});
            },
            (error) => reject(fail(codes[error.code] || 'unavailable', quiet)),
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 },
        );
    }).finally(() => { pending = null; });

    return pending;
}

/**
 * The default every time the app opens: the person's current location, unless
 * they already picked a place during this visit. If location is blocked or
 * can't be found, the default town is used and nothing is shown as an error.
 */
let started = false;
export function useCurrentLocation() {
    if (started || read(ORIGIN_KEY)) return;
    started = true;
    if (typeof navigator === 'undefined' || !('geolocation' in navigator) || !window.isSecureContext) return;
    const locate = () => requestDeviceLocation({ quiet: true }).catch(() => {});
    const query = navigator.permissions?.query?.({ name: 'geolocation' });
    if (!query) { locate(); return; }
    // Blocked in this browser: don't ask again on every visit
    query.then(status => { if (status.state !== 'denied') locate(); }).catch(locate);
}
