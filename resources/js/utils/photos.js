/**
 * Photos people add from their phone (community posts, photos of a job).
 * The server checks and re-draws every photo anyway; this only makes the
 * upload smaller and quicker on slow connections.
 */

const PHOTO_SIDE = 2000;
export const PHOTO_MAX_MB = 8;
export const PHOTO_TYPES = /^image\/(jpeg|png|webp)$/;

/** Shrinks a photo to at most 2000 px as JPEG; falls back to the original */
export async function preparePhoto(file) {
    if (!PHOTO_TYPES.test(file.type) || typeof createImageBitmap !== 'function') return file;
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, PHOTO_SIDE / Math.max(bitmap.width, bitmap.height));
        if (scale === 1 && file.size < 1.5 * 1024 * 1024) return file;
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, canvas.width, canvas.height);
        ctx.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.86));
        if (!blob) return file;
        return new File([blob], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' });
    } catch {
        return file;
    }
}

/** A one-off key for a form, so a retry after a dropped connection can't create a second copy */
export function newClientToken() {
    if (globalThis.crypto?.randomUUID) return crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;
        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

/** Form fields plus photos (as photos[]), ready to send with upload() */
export function formWithPhotos(fields, photos) {
    const form = new FormData();
    for (const [k, v] of Object.entries(fields)) if (v !== null && v !== undefined && v !== '') form.append(k, v);
    for (const p of photos) form.append('photos[]', p);
    return form;
}

/** Frees the previews of a PhotoPicker list once the form is done with them */
export function releasePhotos(list) {
    for (const p of list) if (p.preview) URL.revokeObjectURL(p.preview);
}
