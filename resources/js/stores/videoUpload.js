import { api, upload, ApiError } from '@/api/client.js';
import { newClientToken } from '@/utils/photos.js';
import { videoCompressor } from '@/utils/video.js';
import { VIDEO_MOCK } from '@/stores/video.js';

/**
 * Publishing a video post. The video goes straight from the phone to storage
 * (Cloudflare R2), never through the WorkMate server; Laravel only hands out
 * a one-off upload address and checks the upload once it's done.
 *
 *   1. POST /community/video-uploads             → where to upload (one-off, short-lived)
 *   2. PUT  the video to that address              (progress shown)
 *   3. POST /community/video-uploads/{id}/complete → the post (published or processing)
 *
 * The post only appears once step 3 succeeds, so a half-uploaded video is
 * never published. See docs/community-video-api.md for the full contract.
 * No storage keys are ever in the website.
 */

/* ---------------- The real server ---------------- */

/** Sends the file to the address Laravel gave, reporting progress (0–1) */
function putFile(target, file, { onProgress, signal }) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open(target.method || 'PUT', target.url);
        // Storage is another site: no cookies, and only the headers Laravel signed
        xhr.withCredentials = false;
        for (const [k, v] of Object.entries(target.headers || {})) xhr.setRequestHeader(k, v);
        xhr.upload.onprogress = (e) => { if (e.lengthComputable) onProgress?.(e.loaded / e.total); };
        xhr.onload = () => (xhr.status >= 200 && xhr.status < 300
            ? resolve()
            : reject(new ApiError(xhr.status, { code: 'video_upload_failed', message: 'The video didn’t finish uploading. Please try again.' })));
        xhr.onerror = () => reject(new ApiError(0, { code: 'network_error', message: 'The upload stopped. Check your internet connection and try again.' }));
        xhr.onabort = () => reject(new DOMException('Upload cancelled', 'AbortError'));
        if (signal) {
            if (signal.aborted) { reject(new DOMException('Upload cancelled', 'AbortError')); return; }
            signal.addEventListener('abort', () => xhr.abort(), { once: true });
        }
        xhr.send(file);
    });
}

const httpAdapter = {
    async authorize(fields, { signal }) {
        return (await api('/community/video-uploads', { method: 'POST', body: fields, signal })).data;
    },
    upload(ticket, file, options) {
        return putFile(ticket.upload, file, options);
    },
    async complete(ticket, poster) {
        const form = new FormData();
        if (poster) form.append('poster', poster, 'poster.jpg');
        return (await upload(`/community/video-uploads/${ticket.upload_id}/complete`, form)).data;
    },
    async cancel(ticket) {
        try { await api(`/community/video-uploads/${ticket.upload_id}`, { method: 'DELETE' }); } catch { /* it expires anyway */ }
    },
};

// The pretend server is only ever loaded in local development (never in a production build)
async function adapter() {
    if (VIDEO_MOCK) return (await import('./videoUploadMock.js')).mockAdapter;
    return httpAdapter;
}

/* ---------------- Publishing ---------------- */

/**
 * @param {{category: string, body: string, topic: string, video: {file: File, poster: Blob|null, duration: number, width: number, height: number}}} post
 * @param {{onStage?: (stage: 'preparing'|'uploading'|'finishing') => void, onProgress?: (p: number) => void, signal?: AbortSignal}} options
 * @returns {Promise<object>} the new post, as the feed shows it
 */
export async function publishVideoPost({ category, body, topic, video }, { onStage, onProgress, signal } = {}) {
    const server = await adapter();
    onStage?.('preparing');
    const file = await videoCompressor.compress(video.file);

    const ticket = await server.authorize({
        category,
        body: body || null,
        topic: topic || null,
        file_name: file.name || 'video',
        mime_type: file.type || 'video/mp4',
        size: file.size,
        duration: Math.round(video.duration * 10) / 10,
        width: video.width,
        height: video.height,
        // The same post isn't made twice if the phone sends this twice
        client_token: newClientToken(),
    }, { signal });

    try {
        onStage?.('uploading');
        await server.upload(ticket, file, { onProgress, signal });
        if (signal?.aborted) throw new DOMException('Upload cancelled', 'AbortError');
        onStage?.('finishing');
        return await server.complete(ticket, video.poster, { signal });
    } catch (e) {
        if (e?.name === 'AbortError') server.cancel(ticket);
        throw e;
    }
}
