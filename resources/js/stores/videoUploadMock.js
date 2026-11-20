import { ApiError } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { videoLimits } from '@/stores/video.js';
import { makePoster } from '@/utils/video.js';

/**
 * LOCAL DEVELOPMENT ONLY. A pretend video server that lives in the browser,
 * so the composer, upload progress and video cards can be tried without a
 * storage bucket. Loaded only when VIDEO_MOCK is true (npm run dev with
 * VITE_COMMUNITY_VIDEO_MOCK=true); production builds leave this file out.
 *
 * Nothing is saved: posts made here disappear when the page reloads, and
 * liking or commenting on them fails (the real server doesn't know them).
 *
 * To try failures, in the browser console:
 *   localStorage.setItem('wm-video-mock', 'fail-authorize' | 'fail-upload' | 'fail-complete' | 'processing')
 *   localStorage.removeItem('wm-video-mock')
 */

const MB = 1024 * 1024;
const wait = (ms) => new Promise(r => setTimeout(r, ms));
const mode = () => { try { return localStorage.getItem('wm-video-mock') || ''; } catch { return ''; } };

let counter = 0;
const files = new Map();

export const mockAdapter = {
    async authorize(fields) {
        await wait(400);
        if (mode() === 'fail-authorize') throw new ApiError(503, { code: 'video_uploads_unavailable', message: 'Video uploads are paused for a moment. Please try again later.' });
        // The checks the real server makes again
        const { maxMb } = videoLimits.value;
        if (fields.size > maxMb * MB) throw new ApiError(422, { code: 'validation_failed', message: 'The video is too large.', errors: { size: [`Videos can be up to ${maxMb} MB.`] } });
        const id = `mock-${Date.now().toString(36)}-${++counter}`;
        return {
            upload_id: id,
            upload: { url: `mock://uploads/${id}`, method: 'PUT', headers: { 'Content-Type': fields.mime_type } },
            expires_at: new Date(Date.now() + 15 * 60000).toISOString(),
            fields,
        };
    },

    // About 1.5 MB a second, like a fair 3G/4G connection
    upload(ticket, file, { onProgress, signal }) {
        const total = Math.min(12000, Math.max(1500, (file.size / (1.5 * MB)) * 1000));
        const failAt = mode() === 'fail-upload' ? 0.45 : 2;
        return new Promise((resolve, reject) => {
            const started = Date.now();
            const timer = setInterval(() => {
                if (signal?.aborted) { clearInterval(timer); reject(new DOMException('Upload cancelled', 'AbortError')); return; }
                const p = Math.min(1, (Date.now() - started) / total);
                if (p >= failAt) { clearInterval(timer); reject(new ApiError(0, { code: 'network_error', message: 'The upload stopped. Check your internet connection and try again.' })); return; }
                onProgress?.(p);
                if (p >= 1) { clearInterval(timer); files.set(ticket.upload_id, file); resolve(); }
            }, 120);
        });
    },

    async complete(ticket, poster) {
        await wait(500);
        if (mode() === 'fail-complete') throw new ApiError(422, { code: 'video_not_received', message: 'We didn’t receive the whole video. Please upload it again.' });
        const file = files.get(ticket.upload_id);
        const f = ticket.fields;
        const posterUrl = poster ? URL.createObjectURL(poster) : null;
        return mockPost({
            id: ticket.upload_id,
            category: f.category,
            body: f.body,
            author: {
                id: auth.user?.id ?? 0, name: auth.user?.name || 'You', avatar_url: auth.user?.avatar_url || null,
                title: null, provider_id: null, document_verified: false, blue_tick: false, following: false,
            },
            mine: true,
            video: {
                url: URL.createObjectURL(file), poster_url: posterUrl, width: f.width, height: f.height,
                duration: f.duration, size: f.size, status: mode() === 'processing' ? 'processing' : 'ready',
            },
        });
    },

    async cancel(ticket) {
        files.delete(ticket.upload_id);
    },
};

function mockPost({ id, category, body, author, mine, video }) {
    return {
        id,
        mock: true,
        category,
        body,
        created_at: new Date().toISOString(),
        edited_at: null,
        author,
        topic: null,
        area: null,
        media: [{ id: `${id}-v`, type: 'video', thumb_url: video.poster_url, ...video }],
        likes_count: 0,
        comments_count: 0,
        liked: false,
        saved: false,
        mine,
        removed: null,
    };
}

/* ---------------- Sample video posts for trying the feed ---------------- */

/** A short moving test picture, recorded in the browser (no video files in the code) */
async function recordClip(width, height, seconds, label) {
    const canvas = Object.assign(document.createElement('canvas'), { width, height });
    const ctx = canvas.getContext('2d');
    const stream = canvas.captureStream(24);
    const type = ['video/webm;codecs=vp8', 'video/webm'].find(t => MediaRecorder.isTypeSupported(t));
    const recorder = new MediaRecorder(stream, type ? { mimeType: type } : undefined);
    const chunks = [];
    recorder.ondataavailable = (e) => { if (e.data.size) chunks.push(e.data); };
    const started = performance.now();
    const draw = () => {
        const t = (performance.now() - started) / 1000;
        ctx.fillStyle = `hsl(${(205 + t * 40) % 360} 70% 45%)`;
        ctx.fillRect(0, 0, width, height);
        ctx.fillStyle = '#fff';
        ctx.beginPath();
        ctx.arc(width / 2 + Math.sin(t * 2) * width / 4, height / 2, Math.min(width, height) / 8, 0, Math.PI * 2);
        ctx.fill();
        ctx.font = `600 ${Math.round(Math.min(width, height) / 12)}px sans-serif`;
        ctx.textAlign = 'center';
        ctx.fillText(`${label} ${t.toFixed(1)}s`, width / 2, height - Math.min(width, height) / 8);
    };
    draw();
    const timer = setInterval(draw, 1000 / 24);
    recorder.start(250);
    await wait(seconds * 1000);
    recorder.stop();
    await new Promise(r => { recorder.onstop = r; });
    clearInterval(timer);
    return new Blob(chunks, { type: 'video/webm' });
}

/**
 * Two sample video posts (portrait and landscape) for the feed, by a listed
 * provider from the real feed where there is one, so "View provider" and
 * "Request service" can be tried. Shown only in local development with
 * ?videoSamples=1 on the community page.
 */
export async function samplePosts(feed) {
    const provider = feed.find(p => p.author?.provider_id && !p.mine)?.author;
    const author = provider ? { ...provider } : {
        id: 0, name: 'Sample member', avatar_url: null, title: null, provider_id: null, document_verified: false, blue_tick: false, following: false,
    };
    const shapes = [
        { w: 360, h: 640, label: 'Portrait', body: 'Sample portrait video (development only).' },
        { w: 640, h: 360, label: 'Landscape', body: 'Sample landscape video (development only).' },
    ];
    const posts = [];
    for (const s of shapes) {
        const blob = await recordClip(s.w, s.h, 4, s.label);
        const url = URL.createObjectURL(blob);
        const poster = await makePoster(url);
        posts.push(mockPost({
            id: `mock-sample-${s.label.toLowerCase()}`,
            category: 'my_work',
            body: s.body,
            author,
            mine: false,
            video: { url, poster_url: poster ? URL.createObjectURL(poster) : null, width: s.w, height: s.h, duration: 4, size: blob.size, status: 'ready' },
        }));
    }
    return posts;
}
