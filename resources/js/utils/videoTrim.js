/**
 * Reading and trimming phone videos in the member's own browser, without
 * changing their format or quality: the original encoded pictures and sound
 * are copied into a shorter file of the same kind (MP4 stays MP4, MOV stays
 * MOV, WebM stays WebM). Nothing is decoded or re-encoded, so it also works
 * for formats this browser can't play (e.g. HEVC on many computers).
 *
 * Copying can only cut where the video has a key frame (on phones, about
 * every 1–2 seconds): the clip may start up to that much earlier than
 * chosen. The real size of the result is checked before it's used.
 *
 * Loaded only when needed (import('@/utils/videoTrim.js')), with Mediabunny
 * for reading and writing the files. The server checks the result again.
 */
import {
    Input, Output, Conversion, ConversionCanceledError, BlobSource, BufferTarget,
    Mp4OutputFormat, MovOutputFormat, WebMOutputFormat, MkvOutputFormat, QTFF, MP4, WEBM, MATROSKA,
} from 'mediabunny';

// The containers phones record video in (keeps the download small)
const FORMATS = [MP4, QTFF, WEBM, MATROSKA];

/**
 * What the file is, read from the file itself (no decoding).
 *
 * @returns {Promise<{container: string, duration: number|null, video: {codec: string|null, width: number, height: number}|null, audio: {codec: string|null}|null}>}
 */
export async function probe(file) {
    const input = new Input({ source: new BlobSource(file), formats: FORMATS });
    try {
        const format = await input.getFormat();
        const container = format === QTFF ? 'mov' : format === MP4 ? 'mp4' : format === WEBM ? 'webm' : 'mkv';
        let duration = null;
        try {
            duration = await input.computeDuration();
        } catch {
            // Some recordings don't say; trimming then can't be offered
        }
        const v = await input.getPrimaryVideoTrack();
        const a = await input.getPrimaryAudioTrack();
        return {
            container,
            duration: Number.isFinite(duration) && duration > 0 ? duration : null,
            video: v ? { codec: v.codec, width: v.displayWidth, height: v.displayHeight } : null,
            audio: a ? { codec: a.codec } : null,
        };
    } finally {
        input.dispose();
    }
}

function outputFor(container) {
    switch (container) {
        case 'mov': return { format: new MovOutputFormat({ fastStart: 'in-memory' }), type: 'video/quicktime', ext: 'mov' };
        case 'webm': return { format: new WebMOutputFormat(), type: 'video/webm', ext: 'webm' };
        case 'mkv': return { format: new MkvOutputFormat(), type: 'video/x-matroska', ext: 'mkv' };
        default: return { format: new Mp4OutputFormat({ fastStart: 'in-memory' }), type: 'video/mp4', ext: 'mp4' };
    }
}

/**
 * Copies the part from `start` to `end` (seconds) into a new file of the same
 * kind. Picture and sound are copied as they are; if either can't be copied
 * in this browser, it fails rather than dropping it. Only a complete file is
 * returned; stopping or failing leaves nothing.
 *
 * @param {{onProgress?: (p: number) => void, signal?: AbortSignal}} options
 * @returns {Promise<File>}
 */
export async function trim(file, info, start, end, { onProgress, signal } = {}) {
    const input = new Input({ source: new BlobSource(file), formats: FORMATS });
    const target = new BufferTarget();
    const out = outputFor(info.container);
    const output = new Output({ format: out.format, target });

    try {
        const conversion = await Conversion.init({
            input,
            output,
            tracks: 'primary',
            trim: { start, end },
            // Copy only: never decode or re-encode (format and quality stay as they are);
            // the cut moves to the nearest key frame so that nothing chosen is lost
            copy: { mode: 'forced', boundaryPolicy: 'expand', shiftTolerance: Infinity },
        });
        const lost = conversion.discardedTracks.filter(t => t.reason !== 'discarded_by_user');
        if (!conversion.isValid || lost.some(t => t.track.type === 'video')) {
            throw Object.assign(new Error('The picture can’t be copied in this browser.'), { code: 'video_not_copyable', reasons: lost.map(t => `${t.track.type}: ${t.reason}`) });
        }
        if (info.audio && lost.some(t => t.track.type === 'audio')) {
            // Never drop the sound quietly
            throw Object.assign(new Error('The sound can’t be copied in this browser.'), { code: 'audio_not_copyable', reasons: lost.map(t => `${t.track.type}: ${t.reason}`) });
        }
        conversion.onProgress = (p) => onProgress?.(Math.max(0, Math.min(1, p)));
        const stop = () => conversion.cancel();
        signal?.addEventListener('abort', stop, { once: true });
        try {
            await conversion.execute();
        } finally {
            signal?.removeEventListener('abort', stop);
        }
    } catch (e) {
        if (e instanceof ConversionCanceledError || signal?.aborted) throw new DOMException('Trimming stopped', 'AbortError');
        throw e;
    } finally {
        input.dispose();
    }

    const name = (file.name || 'video').replace(/\.[^.]+$/, '') + '-trimmed.' + out.ext;
    return new File([target.buffer], name, { type: out.type });
}
