import { reactive } from 'vue';
import { api } from '@/api/client.js';

/**
 * Installable app ("PWA") support: the service worker, release updates and
 * installing to the home screen.
 *
 * Updates: every deploy produces new file names in /build/manifest.json. The
 * app compares them with the files it is running. When a new release is out
 * it reloads by itself if that's safe (just opened, or coming back after a
 * while with nothing typed), and otherwise asks first. After the reload it
 * says "You're all set".
 */

const UPDATE_CHECK_MS = 15 * 60_000;
const AUTO_UPDATE_AFTER_AWAY_MS = 10 * 60_000;
const AUTO_UPDATE_ON_LAUNCH_MS = 15_000;
const UPDATED_FLAG = 'wm-just-updated';
const INSTALL_DISMISSED_KEY = 'wm-install-dismissed';
const INSTALL_DISMISS_DAYS = 14;
const DEVICE_KEY = 'wm-device';
const REPORTED_KEY = 'wm-install-reported';

export const pwa = reactive({
    platform: detectPlatform(),
    standalone: isStandalone(),
    canPrompt: false,       // the browser offered its own install dialog
    installed: false,       // installed during this visit
    installOpen: false,     // the install instructions sheet
    updateReady: false,     // a newer release is on the server
    updating: false,
    justUpdated: false,     // show "You're all set" after an update
    offline: typeof navigator !== 'undefined' && navigator.onLine === false,
});

let installEvent = null;
let installViaButton = false;
let runningRelease = '';
let startedAt = Date.now();
let hiddenAt = null;

/* ---------- Device detection ---------- */

function detectPlatform() {
    const ua = navigator.userAgent;
    const ios = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const inApp = /FBAN|FBAV|FB_IAB|Instagram|Line\/|Twitter|TikTok|Snapchat|MicroMessenger|; wv\)/i.test(ua);

    if (ios) {
        if (inApp) return 'ios-in-app';
        if (/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua)) return 'ios-other';
        return 'ios-safari';
    }
    if (/Android/i.test(ua)) return inApp ? 'android-in-app' : 'android';
    return 'desktop';
}

function isStandalone() {
    return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
}

export const isMobile = () => pwa.platform !== 'desktop';

/* ---------- Install figures for the admin area ---------- */

/** A random ID for this browser, so a device is counted once. Nothing personal. */
function deviceId() {
    let id = localStorage.getItem(DEVICE_KEY);
    if (!id) {
        id = crypto.randomUUID ? crypto.randomUUID()
            : '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, c => (c ^ crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4).toString(16));
        localStorage.setItem(DEVICE_KEY, id);
    }
    return id;
}

/**
 * Reports an install event: prompt_shown (once a day), installed and
 * first_launch (once per device). Failures are retried next time.
 */
export async function reportInstallEvent(event, source = null) {
    try {
        const today = new Date().toISOString().slice(0, 10);
        const reported = JSON.parse(localStorage.getItem(REPORTED_KEY) || '{}');
        if (event === 'prompt_shown' ? reported[event] === today : reported[event]) return;

        const platform = pwa.platform.startsWith('ios') ? 'ios' : pwa.platform.startsWith('android') ? 'android' : 'desktop';
        await api('/app-events', { method: 'POST', body: { device_id: deviceId(), event, platform, source } });

        localStorage.setItem(REPORTED_KEY, JSON.stringify({ ...reported, [event]: today }));
    } catch { /* offline, private mode or rate limited: never get in the way */ }
}

/* ---------- Install ---------- */

export function openInstall() {
    pwa.installOpen = true;
    reportInstallEvent('prompt_shown');
}

export function closeInstall() {
    pwa.installOpen = false;
}

/** Shows the browser's own install dialog (Android and desktop Chrome/Edge) */
export async function promptInstall() {
    if (!installEvent) return 'unavailable';
    let outcome;
    try {
        installViaButton = true;
        reportInstallEvent('prompt_shown');
        installEvent.prompt();
        ({ outcome } = await installEvent.userChoice);
    } catch {
        // The browser refused (the offer can only be used once): show the steps instead
        installEvent = null;
        pwa.canPrompt = false;
        openInstall();
        return 'unavailable';
    }
    installEvent = null;
    pwa.canPrompt = false;
    if (outcome === 'accepted') {
        pwa.installed = true;
        reportInstallEvent('installed', 'button');
    } else {
        installViaButton = false;
    }
    return outcome;
}

export function installBannerDismissed() {
    try {
        const at = Number(localStorage.getItem(INSTALL_DISMISSED_KEY));
        return at > 0 && Date.now() - at < INSTALL_DISMISS_DAYS * 86_400_000;
    } catch {
        return false;
    }
}

export function dismissInstallBanner() {
    try { localStorage.setItem(INSTALL_DISMISSED_KEY, String(Date.now())); } catch { /* private mode */ }
}

/* ---------- Updates ---------- */

/**
 * The core build files this page is running, e.g. "/build/assets/app-abc.css|/build/assets/app-def.js".
 * Read once at start: screens opened later add their own stylesheets.
 */
function readRunningRelease() {
    const tags = document.querySelectorAll('script[type="module"][src*="/build/assets/"], link[rel="stylesheet"][href*="/build/assets/"]');
    return [...tags].map(tag => new URL(tag.src || tag.href).pathname).sort().join('|');
}

async function fetchBuildManifest() {
    const response = await fetch('/build/manifest.json', { cache: 'no-store', credentials: 'same-origin' });
    if (!response.ok) throw new Error('No build manifest');
    return response.json();
}

const CORE_ENTRIES = ['resources/js/app.js', 'resources/css/app.css'];

function releaseOf(manifest) {
    return filesOf(CORE_ENTRIES.map(key => manifest[key])).sort().join('|');
}

function filesOf(entries) {
    const files = new Set();
    for (const entry of entries) {
        if (!entry) continue;
        if (entry.file) files.add(`/build/${entry.file}`);
        (entry.css || []).forEach(css => files.add(`/build/${css}`));
    }
    return [...files];
}

/** Tells the worker which files belong to this release, and which to save now */
function syncBuild(manifest) {
    navigator.serviceWorker?.controller?.postMessage({
        type: 'SYNC_BUILD',
        keep: filesOf(Object.values(manifest)),
        save: filesOf(CORE_ENTRIES.map(key => manifest[key])),
    });
}

/** True when the person has typed something that a reload would lose */
function hasUnsavedInput() {
    return [...document.querySelectorAll('input, textarea')].some((el) => {
        if (el.readOnly || el.disabled || el.type === 'hidden' || el.type === 'search') return false;
        if (el.type === 'checkbox' || el.type === 'radio') return false;
        return el.value.trim() !== '' && el.closest('[data-pwa-ignore]') === null;
    });
}

export async function checkForUpdate({ allowAuto = false } = {}) {
    if (pwa.updating || pwa.offline) return;
    let manifest;
    try {
        manifest = await fetchBuildManifest();
    } catch {
        return;
    }

    if (!runningRelease || releaseOf(manifest) === runningRelease) {
        // Up to date: keep this release's files saved for offline use
        syncBuild(manifest);
        return;
    }

    if (allowAuto && !hasUnsavedInput()) {
        applyUpdate();
    } else {
        pwa.updateReady = true;
    }
}

/** Loads the new release. Pages are always fetched fresh, so a reload is all it takes. */
export function applyUpdate() {
    pwa.updating = true;
    try { sessionStorage.setItem(UPDATED_FLAG, '1'); } catch { /* private mode */ }
    window.location.reload();
}

export function dismissUpdate() {
    pwa.updateReady = false;
}

export function dismissUpdated() {
    pwa.justUpdated = false;
}

/* ---------- Start ---------- */

export function initPwa() {
    // The browser offers installing; keep the event for our own install button
    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        installEvent = event;
        pwa.canPrompt = true;
    });
    window.addEventListener('appinstalled', () => {
        installEvent = null;
        pwa.canPrompt = false;
        pwa.installed = true;
        pwa.installOpen = false;
        reportInstallEvent('installed', installViaButton ? 'button' : 'browser');
    });
    window.matchMedia('(display-mode: standalone)').addEventListener?.('change', () => {
        pwa.standalone = isStandalone();
        if (pwa.standalone) reportInstallEvent('first_launch');
    });

    // Opened from the home-screen icon: also counts devices that installed before tracking began
    if (pwa.standalone) reportInstallEvent('first_launch');

    window.addEventListener('online', () => { pwa.offline = false; checkForUpdate(); });
    window.addEventListener('offline', () => { pwa.offline = true; });

    try {
        if (sessionStorage.getItem(UPDATED_FLAG)) {
            sessionStorage.removeItem(UPDATED_FLAG);
            pwa.justUpdated = true;
        }
    } catch { /* private mode */ }

    // The dev server (npm run dev) reloads by itself; the worker would get in its way
    if (import.meta.env.DEV || !('serviceWorker' in navigator)) return;

    startedAt = Date.now();
    runningRelease = readRunningRelease();
    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(() => {});
    // First visit: once the worker takes over, hand it this release's files to save
    navigator.serviceWorker.addEventListener('controllerchange', () => checkForUpdate());

    // Just opened: update straight away if a release came out since this page was saved
    setTimeout(() => checkForUpdate({ allowAuto: Date.now() - startedAt <= AUTO_UPDATE_ON_LAUNCH_MS }), 2_000);

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            hiddenAt = Date.now();
            return;
        }
        const away = hiddenAt ? Date.now() - hiddenAt : 0;
        hiddenAt = null;
        navigator.serviceWorker.getRegistration().then(r => r?.update()).catch(() => {});
        // Coming back after a while counts as opening the app again
        checkForUpdate({ allowAuto: away >= AUTO_UPDATE_AFTER_AWAY_MS });
    });

    setInterval(() => {
        if (document.visibilityState === 'visible') checkForUpdate();
    }, UPDATE_CHECK_MS);
}
