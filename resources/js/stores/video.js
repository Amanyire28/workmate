import { reactive, computed } from 'vue';
import { appSettings } from '@/stores/catalog.js';
import { auth } from '@/stores/auth.js';

/**
 * Community videos on this phone: whether they're switched on, the limits,
 * Data Saver, and making sure only one plays at a time.
 */

/**
 * Local development only: uploads go to a pretend server in the browser
 * (stores/videoUploadMock.js). Turned on with VITE_COMMUNITY_VIDEO_MOCK=true
 * while running `npm run dev`; always false in a production build.
 */
export const VIDEO_MOCK = import.meta.env.DEV && import.meta.env.VITE_COMMUNITY_VIDEO_MOCK === 'true';

/** Videos are offered wherever photos are: to signed-in members (the server checks the posting rules) */
export const videoPostsEnabled = computed(() => VIDEO_MOCK || !!auth.user);

/** The same limits the server checks (community.video_max_mb / video_max_seconds) */
export const videoLimits = computed(() => ({
    maxMb: appSettings.videoMaxMb,
    maxSeconds: appSettings.videoMaxSeconds,
}));

/* ---------------- Preferences (this phone only) ---------------- */

const SAVER_KEY = 'wm-data-saver';

function readSaver() {
    try {
        const v = localStorage.getItem(SAVER_KEY);
        // On unless they turned it off: most members are on mobile data
        return v === null ? true : v === '1';
    } catch {
        return true;
    }
}

function reducedMotion() {
    try { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch { return false; }
}

export const videoPrefs = reactive({
    // Data Saver: videos load only when tapped, and never start by themselves
    dataSaver: readSaver(),
    // A tapped video has sound unless they muted one before; videos that start by themselves never do
    muted: false,
    reducedMotion: reducedMotion(),
});

export function setDataSaver(on) {
    videoPrefs.dataSaver = !!on;
    try { localStorage.setItem(SAVER_KEY, on ? '1' : '0'); } catch { /* kept for this visit only */ }
}

/** Videos may start (muted) as they scroll into view: Data Saver off and motion welcome */
export const autoplayAllowed = computed(() => !videoPrefs.dataSaver && !videoPrefs.reducedMotion);

/* ---------------- One video at a time ---------------- */

let playing = null;

/** Call as a video starts: pauses whichever was playing before */
export function claimPlayback(player) {
    if (playing && playing !== player) playing.pause();
    playing = player;
}

export function releasePlayback(player) {
    if (playing === player) playing = null;
}

// Leaving the app or switching tabs stops the sound
if (typeof document !== 'undefined') {
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && playing) playing.pause();
    });
}
