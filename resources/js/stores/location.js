import { ref, reactive, watch } from 'vue';
import { catalog, searchLocations, nearestPlace } from '@/stores/catalog.js';
import { getSearchLocation, findSearchLocationByName } from '@/data/searchLocations.js';

/**
 * The user's chosen place (its id, e.g. "ntinda" or "kyebando-kampala"),
 * shared between pages and remembered in this browser. Empty string means
 * "All areas".
 */
const KEY = 'workmate.area';

function read(key) {
    try { return localStorage.getItem(key) || ''; } catch { return ''; }
}
function write(key, value) {
    try { localStorage.setItem(key, value); } catch { /* storage unavailable */ }
}

export const selectedArea = ref(read(KEY));

watch(selectedArea, (value) => write(KEY, value));

/**
 * Where nearby searches start from:
 *   { kind: 'area', areaId }                                           a place the user picked
 *   { kind: 'gps', latitude, longitude, accuracy, timestamp, areaId }  a position the user chose to share,
 *                                                                      with the nearest place's id
 * Only place choices are remembered; device coordinates stay in memory for this visit.
 */
const ORIGIN_KEY = 'workmate.searchArea';

// Places load from the API after start-up, so the saved choice is trusted
// here and checked once they arrive
let lastAreaId = read(ORIGIN_KEY) || 'kampala';
export const searchOrigin = ref({ kind: 'area', areaId: lastAreaId });

watch(() => catalog.status, (status) => {
    if (status !== 'ready') return;
    // Earlier versions saved the area's name; use its id now
    if (selectedArea.value && !getSearchLocation(selectedArea.value)) {
        selectedArea.value = findSearchLocationByName(selectedArea.value)?.id || '';
    }
    if (searchOrigin.value.kind !== 'area') return;
    if (getSearchLocation(searchOrigin.value.areaId) && read(ORIGIN_KEY)) return;
    lastAreaId = getSearchLocation(selectedArea.value)?.id || 'kampala';
    searchOrigin.value = { kind: 'area', areaId: lastAreaId };
}, { immediate: true });

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

/** A shared position older than this is refreshed before searching */
export const GPS_MAX_AGE_MS = 2 * 60 * 1000;

let pending = null;

function fail(code) {
    gps.status = 'error';
    gps.error = code;
    // Fall back to the last area the user picked, never to invented coordinates
    if (searchOrigin.value.kind === 'gps') searchOrigin.value = { kind: 'area', areaId: lastAreaId };
    const err = new Error(gpsMessages[code]);
    err.code = code;
    return err;
}

/**
 * Ask the browser for the device position. Call only from a user action
 * (a tap on "Use my location", or a search the user started with "Near me").
 */
export function requestDeviceLocation() {
    if (pending) return pending;
    if (typeof navigator === 'undefined' || !('geolocation' in navigator)) return Promise.reject(fail('unsupported'));
    if (!window.isSecureContext) return Promise.reject(fail('insecure'));

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
                gps.status = 'ready';
                resolve(origin);
                // Name the nearest place ("near Kisaasi"); searching works without it
                nearestPlace(origin.latitude, origin.longitude)
                    .then((area) => {
                        if (area && searchOrigin.value === origin) searchOrigin.value = { ...origin, areaId: area.id };
                    })
                    .catch(() => {});
            },
            (error) => reject(fail(codes[error.code] || 'unavailable')),
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 },
        );
    }).finally(() => { pending = null; });

    return pending;
}
