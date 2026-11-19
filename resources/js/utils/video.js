/**
 * Videos people add to community posts: checked and previewed on the phone
 * before anything is uploaded. The server checks again (size, length and
 * type); nothing here is trusted.
 *
 * Nothing is compressed in the browser yet (see videoCompressor below).
 */

// The pilot takes MP4 (H.264): what every phone browser can play
export const VIDEO_TYPES = ['video/mp4'];
const VIDEO_EXTENSIONS = /\.(mp4|m4v)$/i;
export const VIDEO_ACCEPT = 'video/mp4,.mp4,.m4v';

// A little slack: phones round a 60-second recording up
const DURATION_SLACK = 0.5;

/** Some Android file pickers give no type; the name is the next best thing */
export function isVideoFile(file) {
    if (file.type) return VIDEO_TYPES.includes(file.type);
    return VIDEO_EXTENSIONS.test(file.name || '');
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
 * Checks a chosen file against the limits before anything is uploaded.
 *
 * @returns {Promise<{ok: true, duration: number, width: number, height: number} | {ok: false, error: string}>}
 */
export async function checkVideo(file, src, { maxMb, maxSeconds }) {
    if (!isVideoFile(file)) {
        return { ok: false, error: /\.mov$/i.test(file.name || '') || file.type === 'video/quicktime'
            ? 'This is a MOV video. Choose an MP4 video, or on an iPhone set Camera → Formats → Most Compatible and record again.'
            : 'That file isn’t a video we can use. Choose an MP4 video.' };
    }
    if (file.size > maxMb * 1024 * 1024) {
        return { ok: false, error: `This video is ${formatBytes(file.size)}. Videos can be up to ${maxMb} MB. Try a shorter clip.` };
    }
    let meta;
    try {
        meta = await readVideoMetadata(src);
    } catch {
        return { ok: false, error: 'We couldn’t open this video on your phone. Try another video, or one saved as MP4.' };
    }
    if (!meta.width || !meta.height) {
        return { ok: false, error: 'This file has no picture. Choose a video.' };
    }
    if (!Number.isFinite(meta.duration)) {
        return { ok: false, error: 'We couldn’t tell how long this video is. Try another video, or one saved as MP4.' };
    }
    if (meta.duration > maxSeconds + DURATION_SLACK) {
        return { ok: false, error: `This video is ${formatDuration(meta.duration)} long. Videos can be up to ${maxSeconds} seconds. Trim it on your phone and try again.` };
    }
    // The same header check the server makes, before any data is spent uploading
    const problem = await mp4Problem(file);
    if (problem) return { ok: false, error: problem };
    return { ok: true, ...meta };
}

/* ---------------- The MP4 header (as the server checks it) ---------------- */

const MP4_MESSAGES = {
    not_mp4: 'This file isn’t an MP4 video. Choose an MP4 video.',
    mov: 'This is a MOV video. Choose an MP4 video, or on an iPhone set Camera → Formats → Most Compatible and record again.',
    fragmented: 'This kind of MP4 can’t be used yet. Try saving or recording the video again.',
    hevc: 'This video uses the H.265 (HEVC) format, which many phones can’t play. Record in H.264 (“Most Compatible”) and try again.',
    codec: 'This video’s format can’t be played on most phones. Choose an MP4 recorded in H.264.',
    audio: 'This video’s sound format can’t be played on most phones. Choose a video with standard (AAC) sound.',
    damaged: 'This video looks damaged and can’t be used. Try recording it again.',
};

/**
 * Reads only the MP4's box headers and its "moov" header (never the whole
 * file) and returns a message when the server would refuse it, else null.
 * Unreadable cases are left for the server to decide.
 */
export async function mp4Problem(file) {
    try {
        const code = await mp4Check(file);
        return code ? MP4_MESSAGES[code] : null;
    } catch {
        return null;
    }
}

async function bytesOf(file, start, length) {
    return new DataView(await file.slice(start, start + length).arrayBuffer());
}
const fourcc = (view, at) => String.fromCharCode(view.getUint8(at), view.getUint8(at + 1), view.getUint8(at + 2), view.getUint8(at + 3));

function boxAt(view, at, remaining) {
    let size = view.getUint32(at);
    const type = fourcc(view, at + 4);
    let header = 8;
    if (size === 1) { size = Number(view.getBigUint64(at + 8)); header = 16; }
    else if (size === 0) size = remaining;
    return { size, type, header };
}

function* children(view, start, end) {
    let at = start;
    for (let i = 0; i < 256 && at + 8 <= end; i++) {
        const box = boxAt(view, at, end - at);
        if (box.size < box.header || at + box.size > end) return;
        yield { type: box.type, start: at + box.header, end: at + box.size };
        at += box.size;
    }
}

async function mp4Check(file) {
    let at = 0;
    let brand = null;
    let moov = null;
    for (let i = 0; i < 64 && at < file.size; i++) {
        const head = await bytesOf(file, at, Math.min(16, file.size - at));
        if (head.byteLength < 8) return 'damaged';
        const box = boxAt(head, 0, file.size - at);
        if (box.size < box.header || box.size > file.size - at) return 'damaged';
        if (i === 0 && box.type !== 'ftyp') return 'not_mp4';
        if (box.type === 'ftyp') brand = fourcc(await bytesOf(file, at + box.header, 4), 0);
        else if (box.type === 'moof') return 'fragmented';
        else if (box.type === 'moov') {
            if (box.size > 4 * 1024 * 1024) return null; // the server decides
            moov = await bytesOf(file, at, box.size);
            moov = { view: moov, start: box.header, end: box.size };
        }
        at += box.size;
    }
    if (!brand) return 'not_mp4';
    if (brand === 'qt  ') return 'mov';
    if (!moov) return 'damaged';

    let video = null;
    const audio = [];
    for (const trak of children(moov.view, moov.start, moov.end)) {
        if (trak.type !== 'trak') continue;
        let handler = null;
        let format = null;
        for (const mdia of children(moov.view, trak.start, trak.end)) {
            if (mdia.type !== 'mdia') continue;
            for (const m of children(moov.view, mdia.start, mdia.end)) {
                if (m.type === 'hdlr') handler = fourcc(moov.view, m.start + 8);
                if (m.type !== 'minf') continue;
                for (const stbl of children(moov.view, m.start, m.end)) {
                    if (stbl.type !== 'stbl') continue;
                    for (const s of children(moov.view, stbl.start, stbl.end)) {
                        if (s.type === 'stsd') format = fourcc(moov.view, s.start + 12);
                    }
                }
            }
        }
        if (handler === 'vide' && !video) video = format;
        if (handler === 'soun') audio.push(format);
    }
    if (!video) return 'damaged';
    if (video === 'hvc1' || video === 'hev1') return 'hevc';
    if (video !== 'avc1' && video !== 'avc3') return 'codec';
    if (audio.some(a => a !== 'mp4a')) return 'audio';
    return null;
}

/**
 * Making videos smaller before upload. Not available yet: doing it in the
 * browser needs a large download (FFmpeg compiled to WebAssembly, about
 * 25–30 MB) and a lot of memory and battery on low-cost phones. Kept as one
 * place to plug it in later; until then videos are uploaded as they are.
 */
export const videoCompressor = {
    available: false,
    /** @returns {Promise<File>} the file to upload */
    async compress(file) {
        return file;
    },
};
