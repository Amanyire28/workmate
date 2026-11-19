/**
 * Turning a phone video WorkMate can't use as it is (HEVC, MOV, WebM, too
 * big, too sharp) into an MP4 every phone can play: H.264 video, at most
 * 720p and 30 frames a second, AAC sound, sized to fit the upload limit.
 *
 * Everything happens in the member's own browser with WebCodecs (the
 * phone's own video hardware), through Mediabunny for reading and writing
 * the files. This module and Mediabunny are only downloaded when a video
 * needs it (import('@/utils/videoConvert.js')); videos that are already fine
 * never load them. Nothing here is trusted by the server, which checks the
 * uploaded file again.
 *
 * What a browser can convert depends on its decoders and encoders: probe()
 * says honestly what this one can do.
 */
import {
    Input, Output, Conversion, ConversionCanceledError, BlobSource, BufferTarget, Mp4OutputFormat,
    QTFF, MP4, WEBM, MATROSKA, canEncodeVideo, canEncodeAudio,
} from 'mediabunny';

// Only the containers phones record video in (keeps the download small)
const FORMATS = [MP4, QTFF, WEBM, MATROSKA];

// The output WorkMate asks for
const MAX_SHORT_SIDE = 720;
const MAX_LONG_SIDE = 1280;
const MAX_FPS = 30;
const MAX_VIDEO_BITRATE = 2_500_000;   // plenty for 720p30 on a phone screen
const MIN_VIDEO_BITRATE = 500_000;
const AUDIO_BITRATE = 96_000;
const SIZE_HEADROOM = 0.8;             // hardware encoders overshoot busy pictures by ~20%

const even = (n) => Math.max(2, Math.round(n / 2) * 2);

/** The output size keeping the shape, no bigger than 720p either way round */
export function targetSize(width, height) {
    const short = Math.min(width, height);
    const long = Math.max(width, height);
    const scale = Math.min(1, MAX_SHORT_SIDE / short, MAX_LONG_SIDE / long);
    return { width: even(width * scale), height: even(height * scale), scaled: scale < 1 };
}

/**
 * What the file is and what this browser can do with it.
 *
 * @returns {Promise<{container: string, duration: number, video: object|null, audio: object|null}>}
 */
export async function probe(file) {
    const input = new Input({ source: new BlobSource(file), formats: FORMATS });
    try {
        const format = await input.getFormat();
        const container = format === QTFF ? 'mov' : format === MP4 ? 'mp4' : format === WEBM ? 'webm' : format === MATROSKA ? 'mkv' : format.name;
        const duration = await input.computeDuration();
        const v = await input.getPrimaryVideoTrack();
        const a = await input.getPrimaryAudioTrack();
        let video = null;
        if (v) {
            const stats = await v.computePacketStats(120).catch(() => null);
            video = {
                codec: v.codec,                    // 'avc', 'hevc', 'vp9', 'av1', ... or null when unknown
                width: v.displayWidth,             // as shown (rotation applied)
                height: v.displayHeight,
                rotation: v.rotation,
                fps: stats ? stats.averagePacketRate : null,
                bitrate: stats ? stats.averageBitrate : null,
                canDecode: await v.canDecode(),
            };
        }
        const audio = a ? { codec: a.codec, channels: a.numberOfChannels, sampleRate: a.sampleRate, canDecode: await a.canDecode() } : null;
        return { container, duration, video, audio };
    } finally {
        input.dispose();
    }
}

/**
 * Decides what to do with a probed video.
 *
 * @returns {Promise<{action: 'convert'|'refuse', reason?: string, message?: string, video?: object, audio?: 'copy'|'aac'|'drop'|'none', needsAudioConsent?: boolean, remuxOnly?: boolean}>}
 */
export async function plan(info, file, { maxBytes, maxSeconds }) {
    const v = info.video;
    if (!v) return { action: 'refuse', reason: 'no_picture', message: 'This file has no picture. Choose a video.' };
    if (!Number.isFinite(info.duration) || info.duration <= 0) {
        return { action: 'refuse', reason: 'damaged', message: 'We couldn’t read this video. Try another one.' };
    }
    if (info.duration > maxSeconds + 0.5) {
        const m = Math.floor(info.duration / 60);
        const s = String(Math.round(info.duration % 60)).padStart(2, '0');
        return { action: 'refuse', reason: 'too_long', message: `This video is ${m}:${s} long. Videos can be up to ${maxSeconds} seconds. Trim it on your phone and try again.` };
    }
    if (!v.codec || !v.canDecode) {
        const name = { hevc: 'H.265 (HEVC)', av1: 'AV1', vp9: 'VP9', vp8: 'VP8', prores: 'ProRes' }[v.codec];
        return {
            action: 'refuse', reason: 'cannot_decode',
            message: v.codec === 'hevc'
                ? 'This video is H.265 (HEVC), and this browser can’t convert it. Try Chrome on your phone, or record in H.264 (Samsung: Camera settings → Advanced video options → turn off High efficiency video; iPhone: Camera → Formats → Most Compatible).'
                : name
                    ? `This video uses ${name}, which this browser can’t convert. Try another video, or record a new one with your phone’s camera.`
                    : 'This video’s format can’t be converted in this browser. Try another video, or record a new one with your phone’s camera.',
        };
    }

    const size = targetSize(v.width, v.height);
    const fpsTooHigh = v.fps && v.fps > MAX_FPS + 0.5;
    const tooBig = file.size > maxBytes;
    const remuxOnly = v.codec === 'avc' && !size.scaled && !fpsTooHigh && !tooBig;

    // Video: re-encoded unless the H.264 picture can simply be moved into an MP4
    let video = null;
    if (!remuxOnly) {
        const audioBits = info.audio ? AUDIO_BITRATE : 0;
        const budget = Math.floor((maxBytes * 8 * SIZE_HEADROOM) / info.duration) - audioBits;
        const bitrate = Math.max(MIN_VIDEO_BITRATE, Math.min(MAX_VIDEO_BITRATE, budget));
        if (!(await canEncodeVideo('avc', { width: size.width, height: size.height, bitrate }))) {
            return { action: 'refuse', reason: 'cannot_encode', message: 'This browser can’t make MP4 videos. Try Chrome or Safari on your phone, or choose a shorter H.264 video.' };
        }
        video = { width: size.width, height: size.height, bitrate, frameRate: fpsTooHigh ? MAX_FPS : undefined };
    }

    // Sound: kept when it's AAC, re-encoded when the browser can, else dropped only with consent
    let audio = 'none';
    let needsAudioConsent = false;
    if (info.audio) {
        if (info.audio.codec === 'aac') audio = 'copy';
        else if (info.audio.canDecode && await canEncodeAudio('aac', { numberOfChannels: Math.min(2, info.audio.channels || 2), sampleRate: 48000, bitrate: AUDIO_BITRATE })) audio = 'aac';
        else { audio = 'drop'; needsAudioConsent = true; }
    }

    return { action: 'convert', video, audio, needsAudioConsent, remuxOnly, target: size };
}

/**
 * Runs the conversion. Only a complete file is returned; a cancelled or
 * failed conversion throws and leaves nothing behind.
 *
 * @param {{onProgress?: (p: number) => void, signal?: AbortSignal}} options
 * @returns {Promise<File>} an MP4
 */
export async function convert(file, chosen, { onProgress, signal } = {}) {
    const input = new Input({ source: new BlobSource(file), formats: FORMATS });
    const target = new BufferTarget();
    // fastStart: the header goes first, so playback can start before the whole video has downloaded
    const output = new Output({ format: new Mp4OutputFormat({ fastStart: 'in-memory' }), target });

    const videoOptions = chosen.video
        ? { codec: 'avc', width: chosen.video.width, height: chosen.video.height, fit: 'contain', bitrate: chosen.video.bitrate, frameRate: chosen.video.frameRate, forceTranscode: true, keyFrameInterval: 2 }
        : { codec: 'avc' };
    const audioOptions = chosen.audio === 'drop' || chosen.audio === 'none' ? { discard: true }
        : chosen.audio === 'aac' ? { codec: 'aac', bitrate: AUDIO_BITRATE, numberOfChannels: 2, sampleRate: 48000, forceTranscode: true }
            : { codec: 'aac' };

    let conversion;
    try {
        conversion = await Conversion.init({ input, output, video: videoOptions, audio: audioOptions, tracks: 'primary' });
        if (!conversion.isValid) {
            const reasons = conversion.discardedTracks.map(t => `${t.track.type}: ${t.reason}`).join(', ');
            throw Object.assign(new Error(`This video can’t be converted here (${reasons}).`), { code: 'not_convertible' });
        }
        // A video track we needed must not have been dropped quietly
        if (conversion.discardedTracks.some(t => t.track.type === 'video' && t.reason !== 'discarded_by_user')) {
            throw Object.assign(new Error('The picture couldn’t be converted in this browser.'), { code: 'not_convertible' });
        }
        if (chosen.audio !== 'drop' && chosen.audio !== 'none'
            && conversion.discardedTracks.some(t => t.track.type === 'audio' && t.reason !== 'discarded_by_user')) {
            throw Object.assign(new Error('The sound couldn’t be converted in this browser.'), { code: 'audio_not_convertible' });
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
        if (e instanceof ConversionCanceledError || signal?.aborted) throw new DOMException('Conversion cancelled', 'AbortError');
        throw e;
    } finally {
        input.dispose();
    }

    const name = (file.name || 'video').replace(/\.[^.]+$/, '') + '.mp4';
    return new File([target.buffer], name, { type: 'video/mp4' });
}

export { ConversionCanceledError };
