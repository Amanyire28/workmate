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

// Box types that may start an MP4/MOV file (the server's Mp4Inspector has the same list).
// Most start with ftyp, but valid files can start with free/skip/wide/uuid, and older
// QuickTime-style files have no ftyp at all.
const ISO_TOP_LEVEL = ['ftyp', 'moov', 'mdat', 'free', 'skip', 'wide', 'uuid', 'meta', 'pdin',
    'moof', 'mfra', 'styp', 'sidx', 'ssix', 'prft', 'emsg', 'udta', 'pnot', 'junk'];

/**
 * The file's real structure, from its first bytes (not its name or type):
 * 'iso' (MP4, MOV, 3GP), 'ebml' (WebM, MKV) or null.
 */
export async function containerOf(file) {
    try {
        const b = new Uint8Array(await file.slice(0, 16).arrayBuffer());
        if (b[0] === 0x1a && b[1] === 0x45 && b[2] === 0xdf && b[3] === 0xa3) return 'ebml';
        if (b.length >= 8) {
            const size = new DataView(b.buffer).getUint32(0);
            const type = String.fromCharCode(b[4], b[5], b[6], b[7]);
            if ((size === 0 || size === 1 || size >= 8) && ISO_TOP_LEVEL.includes(type)) return 'iso';
        }
    } catch {
        // Unreadable: treated as unknown
    }
    return null;
}

/* ---------------- Reading an MP4/MOV header ourselves ---------------- */

async function bytesOf(file, start, length) {
    return new DataView(await file.slice(start, start + length).arrayBuffer());
}
const fourcc = (view, at) => String.fromCharCode(view.getUint8(at), view.getUint8(at + 1), view.getUint8(at + 2), view.getUint8(at + 3));

/**
 * The top-level boxes of an MP4/MOV file (headers only), its brands, and where
 * its movie header (moov) is. Data after the moov that isn't a box (Samsung
 * adds such a trailer) is noted and ignored.
 */
export async function isoLayout(file) {
    const out = { boxes: [], majorBrand: null, compatibleBrands: [], moov: null, reason: null };
    let at = 0;
    for (let i = 0; i < 32 && at < file.size; i++) {
        const head = await bytesOf(file, at, Math.min(16, file.size - at));
        if (head.byteLength < 8) { out.reason = `${file.size - at} stray bytes at the end`; break; }
        let size = head.getUint32(0);
        const type = fourcc(head, 4);
        let header = 8;
        if (size === 1 && head.byteLength >= 16) { size = Number(head.getBigUint64(8)); header = 16; } else if (size === 0) size = file.size - at;
        if (!/^[\x20-\x7e]{4}$/.test(type) || size < header || at + size > file.size) {
            out.reason = out.moov ? `data after the movie header that isn’t a box (${file.size - at} bytes, ignored)` : `not a valid box at byte ${at}`;
            break;
        }
        if (i === 0 && !ISO_TOP_LEVEL.includes(type)) { out.reason = `first box “${type}” isn’t an MP4/MOV box`; break; }
        out.boxes.push(`${type}:${size}`);
        if (type === 'ftyp') {
            const f = await bytesOf(file, at + header, Math.min(size - header, 64));
            if (f.byteLength >= 4) out.majorBrand = fourcc(f, 0);
            for (let k = 8; k + 4 <= f.byteLength; k += 4) out.compatibleBrands.push(fourcc(f, k));
        }
        if (type === 'moov') out.moov = { start: at + header, end: at + size };
        at += size;
    }
    if (!out.moov && !out.reason) out.reason = 'no movie header (moov) found';
    return out;
}

function* children(view, start, end) {
    let at = start;
    for (let i = 0; i < 256 && at + 8 <= end; i++) {
        let size = view.getUint32(at);
        let header = 8;
        if (size === 1) { size = Number(view.getBigUint64(at + 8)); header = 16; } else if (size === 0) size = end - at;
        if (size < header || at + size > end) return;
        yield { type: fourcc(view, at + 4), start: at + header, end: at + size };
        at += size;
    }
}

/**
 * Picture size (as shown, rotation applied), length and codecs, read from the
 * MP4/MOV header itself: for when neither this browser nor the trimming tool
 * can read the file. Returns null when it can't be read.
 */
export async function isoFacts(file) {
    try {
        const layout = await isoLayout(file);
        if (!layout.moov || layout.moov.end - layout.moov.start > 4 * 1024 * 1024) return null;
        const v = await bytesOf(file, layout.moov.start, layout.moov.end - layout.moov.start);
        let duration = null;
        let video = null;
        let audio = null;
        for (const box of children(v, 0, v.byteLength)) {
            if (box.type === 'mvhd') {
                const version = v.getUint8(box.start);
                const scale = v.getUint32(box.start + (version === 1 ? 20 : 12));
                const length = version === 1 ? Number(v.getBigUint64(box.start + 24)) : v.getUint32(box.start + 16);
                if (scale > 0 && length > 0) duration = length / scale;
            }
            if (box.type !== 'trak') continue;
            let handler = null;
            let format = null;
            let width = 0;
            let height = 0;
            let turned = false;
            for (const t of children(v, box.start, box.end)) {
                if (t.type === 'tkhd' && t.end - t.start >= 84) {
                    width = v.getUint32(t.end - 8) >>> 16;
                    height = v.getUint32(t.end - 4) >>> 16;
                    // Rotation matrix: a and d are 0 when the picture is turned a quarter
                    const m = t.end - 8 - 36;
                    turned = v.getInt32(m) === 0 && v.getInt32(m + 16) === 0;
                }
                if (t.type !== 'mdia') continue;
                for (const m of children(v, t.start, t.end)) {
                    if (m.type === 'hdlr' && m.end - m.start >= 12) handler = fourcc(v, m.start + 8);
                    if (m.type !== 'minf') continue;
                    for (const st of children(v, m.start, m.end)) {
                        if (st.type !== 'stbl') continue;
                        for (const sd of children(v, st.start, st.end)) {
                            if (sd.type === 'stsd' && sd.end - sd.start >= 16) format = fourcc(v, sd.start + 12);
                        }
                    }
                }
            }
            if (handler === 'vide' && !video) video = { codec: format, width: turned ? height : width, height: turned ? width : height };
            if (handler === 'soun' && !audio) audio = { codec: format };
        }
        return video && video.width && video.height ? { duration, video, audio, brand: layout.majorBrand } : null;
    } catch {
        return null;
    }
}

/**
 * TEMPORARY diagnostics for "[WorkMate video]": what the chosen file is.
 * Names, sizes and box types only, never its contents.
 */
export async function describeVideoFile(file) {
    const family = await containerOf(file);
    const d = {
        extension: ((file.name || '').match(/\.([^.]+)$/) || [])[1] || null,
        mime: file.type || null,
        bytes: file.size,
        container: family,
    };
    try {
        if (family === 'iso' || !family) {
            const l = await isoLayout(file);
            Object.assign(d, { boxes: l.boxes, majorBrand: l.majorBrand, compatibleBrands: l.compatibleBrands, movieHeader: !!l.moov, note: l.reason });
        }
        if (!family) {
            const b = new Uint8Array(await file.slice(0, 8).arrayBuffer());
            d.firstBytesHex = [...b].map(x => x.toString(16).padStart(2, '0')).join(' ');
        }
    } catch (e) {
        d.note = 'couldn’t read the file: ' + (e?.message || e);
    }
    return d;
}

/** The type sent to the server for each structure */
export const UPLOAD_TYPES = { iso: 'video/mp4', ebml: 'video/webm' };
