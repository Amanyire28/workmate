/**
 * Videos people add to community posts: checked and previewed on the phone
 * before anything is uploaded. The original file is uploaded as it is (any
 * format the server can check: MP4, MOV, 3GP, WebM, MKV); the server checks
 * it again, and nothing here is trusted.
 *
 * Videos over the size limit can be trimmed on the phone first, keeping
 * their format and quality (utils/videoTrim.js, loaded only then).
 */

const VIDEO_EXTENSIONS = /\.(mp4|m4v|mov|webm|mkv|3gp)$/i;
export const VIDEO_ACCEPT = 'video/*,.mp4,.m4v,.mov,.webm,.mkv,.3gp';

/** Some Android file pickers give no type; the name is the next best thing */
export function isVideoFile(file) {
    if (file.type) return file.type.startsWith('video/');
    return VIDEO_EXTENSIONS.test(file.name || '');
}

/**
 * Notes for diagnosing a video problem on a member's phone (browser console,
 * marked "[WorkMate video]"). Only facts about the file and the browser:
 * never upload or playback addresses, which carry signatures.
 */
export function videoLog(event, details = {}, problem = false) {
    try {
        (problem ? console.warn : console.info)('[WorkMate video]', event, details);
    } catch {
        // No console: nothing to do
    }
}

/** 640 KB, 3.4 MB */
export function formatBytes(bytes) {
    if (bytes < 1024 * 1024) return `${Math.max(1, Math.round(bytes / 1024))} KB`;
    const mb = bytes / (1024 * 1024);
    return `${mb < 10 ? mb.toFixed(1) : Math.round(mb)} MB`;
}

/** 0:07, 1:00 */
export function formatDuration(seconds) {
    const s = Math.max(0, Math.round(seconds || 0));
    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
}

function waitFor(el, events, ms) {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => done(reject, new Error('timeout')), ms);
        const ok = () => done(resolve);
        const bad = () => done(reject, new Error('error'));
        function done(fn, arg) {
            clearTimeout(timer);
            for (const e of events) el.removeEventListener(e, ok);
            el.removeEventListener('error', bad);
            fn(arg);
        }
        for (const e of events) el.addEventListener(e, ok, { once: true });
        el.addEventListener('error', bad, { once: true });
    });
}

function offscreenVideo(src) {
    const v = document.createElement('video');
    v.muted = true;
    v.playsInline = true;
    v.setAttribute('playsinline', '');
    v.preload = 'metadata';
    v.src = src;
    return v;
}

/**
 * Length and picture size, read by the browser itself.
 * Rejects when the browser can't open the video (often a format it can't play).
 *
 * @returns {Promise<{duration: number, width: number, height: number}>}
 */
export async function readVideoMetadata(src) {
    const v = offscreenVideo(src);
    try {
        await waitFor(v, ['loadedmetadata'], 15000);
        let duration = v.duration;
        // Recordings made in the browser report no length until the end is reached
        if (!Number.isFinite(duration)) {
            v.currentTime = 1e7;
            await waitFor(v, ['durationchange', 'timeupdate'], 8000).catch(() => {});
            duration = v.duration;
        }
        return { duration: Number.isFinite(duration) ? duration : NaN, width: v.videoWidth, height: v.videoHeight };
    } finally {
        v.removeAttribute('src');
        v.load();
    }
}

/**
 * A still from early in the video, as a JPEG, for the card before it plays.
 * Best effort: returns null where the browser can't draw video frames.
 *
 * @returns {Promise<Blob|null>}
 */
export async function makePoster(src, { at = 0.5, maxSide = 720 } = {}) {
    const v = offscreenVideo(src);
    v.preload = 'auto';
    try {
        await waitFor(v, ['loadeddata'], 15000);
        const duration = Number.isFinite(v.duration) ? v.duration : 0;
        const target = Math.min(at, duration / 3 || 0);
        if (target > 0.05) {
            v.currentTime = target;
            await waitFor(v, ['seeked'], 8000);
        }
        if (!v.videoWidth || !v.videoHeight) return null;
        const scale = Math.min(1, maxSide / Math.max(v.videoWidth, v.videoHeight));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(v.videoWidth * scale);
        canvas.height = Math.round(v.videoHeight * scale);
        canvas.getContext('2d').drawImage(v, 0, 0, canvas.width, canvas.height);
        return await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.8));
    } catch {
        return null;
    } finally {
        v.removeAttribute('src');
        v.load();
    }
}

/**
 * Checks a chosen file before anything is uploaded. The original file is
 * kept as it is; the server checks its structure and codecs again.
 *
 * - ok: ready to upload (the phone's player could read it)
 * - probe: fine to upload, but this browser can't play it to read its size
 *   and length (e.g. HEVC on some phones): read them from the file instead
 * - trim: bigger than the limit: the member chooses a part to keep
 * - otherwise an error to show
 *
 * @returns {Promise<{ok: true, duration: number, width: number, height: number, family: string} | {ok: false, probe?: true, trim?: true, family?: string, error?: string}>}
 */
export async function checkVideo(file, src, { maxMb }) {
    if (!isVideoFile(file)) {
        return { ok: false, error: 'That file isn’t a video. Choose a video.' };
    }
    const family = await containerOf(file);
    if (!family) {
        return { ok: false, error: UNKNOWN_FORMAT };
    }
    if (file.size > maxMb * 1024 * 1024) return { ok: false, trim: true, family };

    let meta;
    try {
        meta = await readVideoMetadata(src);
    } catch {
        return { ok: false, probe: true, family };
    }
    if (!meta.width || !meta.height || !Number.isFinite(meta.duration)) {
        return { ok: false, probe: true, family };
    }
    return { ok: true, ...meta, family };
}

export const UNKNOWN_FORMAT = 'We don’t recognise this video format. Choose an MP4, MOV or WebM video.';

/**
 * The file's real structure, from its first bytes (not its name or type):
 * 'iso' (MP4, MOV, 3GP), 'ebml' (WebM, MKV) or null.
 */
export async function containerOf(file) {
    try {
        const b = new Uint8Array(await file.slice(0, 12).arrayBuffer());
        if (b[0] === 0x1a && b[1] === 0x45 && b[2] === 0xdf && b[3] === 0xa3) return 'ebml';
        if (String.fromCharCode(b[4], b[5], b[6], b[7]) === 'ftyp') return 'iso';
    } catch {
        // Unreadable: treated as unknown
    }
    return null;
}

/** The type sent to the server for each structure */
export const UPLOAD_TYPES = { iso: 'video/mp4', ebml: 'video/webm' };
