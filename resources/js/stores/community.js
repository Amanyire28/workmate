import { reactive } from 'vue';
import { api, upload } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { showFlash } from '@/stores/flash.js';
import router from '@/router/index.js';

/**
 * The WorkMate community: what members share about their work. Posts come
 * from the Laravel API (/api/v1/community/...); likes and saves change on
 * screen straight away and go back if the server says no.
 */

export const POST_CATEGORIES = [
    { id: 'my_work',     label: 'My Work',       icon: 'bi-hammer',          hint: 'Finished jobs, projects and before-and-afters' },
    { id: 'tips',        label: 'Work Tips',     icon: 'bi-lightbulb',       hint: 'Techniques and practical advice' },
    { id: 'experience',  label: 'Experience',    icon: 'bi-journal-text',    hint: 'Lessons and stories from your work' },
    { id: 'question',    label: 'Questions',     icon: 'bi-question-circle', hint: 'Ask the community for help' },
    { id: 'opportunity', label: 'Opportunities', icon: 'bi-briefcase',       hint: 'Work and collaboration' },
];

export const categoryOf = (id) => POST_CATEGORIES.find(c => c.id === id) || POST_CATEGORIES[0];

// The same limits the server checks
export const LIMITS = { body: 3000, photos: 6, photoMb: 8, comment: 1000 };

/* ---------------- The feed, kept while someone opens a post and comes back ---------------- */

export const feedCache = reactive({ key: '', posts: [], cursor: null, done: false, personalised: false, scrollY: 0, at: 0 });

/** The same post object everywhere it's on screen, so likes and counts stay in step */
export function cachedPost(id) {
    return feedCache.posts.find(p => p.id === id) || null;
}

export function forgetFeed() {
    feedCache.key = '';
    feedCache.posts = [];
}

/* ---------------- Taking part ---------------- */

/** Sends signed-out visitors to sign in, then back here */
export function needsSignIn() {
    if (auth.user) return false;
    router.push({ path: '/sign-in', query: { redirect: router.currentRoute.value.fullPath } });
    return true;
}

function complain(e) {
    showFlash(e?.code === 'email_not_verified' ? 'Confirm your email address to take part. Use the link we sent you.' : (e?.message || 'That didn’t work. Please try again.'), 'error', 5000);
}

export async function toggleLike(post) {
    if (needsSignIn()) return;
    const on = !post.liked;
    post.liked = on;
    post.likes_count += on ? 1 : -1;
    try {
        const r = await api(`/community/posts/${post.id}/like`, { method: on ? 'POST' : 'DELETE' });
        post.likes_count = r.data.likes_count;
    } catch (e) {
        post.liked = !on;
        post.likes_count += on ? -1 : 1;
        complain(e);
    }
}

export async function toggleSave(post) {
    if (needsSignIn()) return;
    const on = !post.saved;
    post.saved = on;
    try {
        await api(`/community/posts/${post.id}/save`, { method: on ? 'POST' : 'DELETE' });
        showFlash(on ? 'Saved. Find it under Saved posts.' : 'Removed from your saved posts.');
    } catch (e) {
        post.saved = !on;
        complain(e);
    }
}

export function postUrl(post) {
    return `${window.location.origin}/community/post/${post.id}`;
}

/** The phone's share menu where there is one, otherwise the link is copied */
export async function sharePost(post) {
    const url = postUrl(post);
    if (navigator.share) {
        try {
            await navigator.share({ title: `${post.author.name} on WorkMate`, text: (post.body || '').slice(0, 120), url });
            return;
        } catch (e) {
            if (e?.name === 'AbortError') return;
        }
    }
    try {
        await navigator.clipboard.writeText(url);
    } catch {
        // Older browsers, or a page not served over HTTPS
        const field = Object.assign(document.createElement('textarea'), { value: url });
        field.setAttribute('readonly', '');
        field.style.position = 'fixed';
        field.style.opacity = '0';
        document.body.appendChild(field);
        field.select();
        document.execCommand('copy');
        field.remove();
    }
    showFlash('Link copied. Paste it anywhere to share.');
}

/* ---------------- People ---------------- */

/** The signed-in member's own card (counts), loaded on first use */
export const me = reactive({ card: null, loading: null });

export function loadMe({ force = false } = {}) {
    if (!auth.user) { me.card = null; return Promise.resolve(null); }
    if (me.card && me.card.id === auth.user.id && !force) return Promise.resolve(me.card);
    me.loading ??= api('/community/me').then(r => { me.card = r.data; return me.card; }).catch(() => null).finally(() => { me.loading = null; });
    return me.loading;
}

/**
 * Follows or unfollows straight away on screen. `person` is a card or a post's
 * author ({ id, following, followers_count? }); every post by them updates too.
 */
export async function toggleFollow(person) {
    if (needsSignIn()) return;
    const on = !person.following;
    const sync = (value) => {
        person.following = value;
        for (const p of feedCache.posts) if (p.author.id === person.id) p.author.following = value;
    };
    sync(on);
    if (typeof person.followers_count === 'number') person.followers_count += on ? 1 : -1;
    try {
        const r = await api(`/community/people/${person.id}/follow`, { method: on ? 'POST' : 'DELETE' });
        if (typeof person.followers_count === 'number') person.followers_count = r.data.followers_count;
        if (me.card) me.card.following_count += on ? 1 : -1;
        showFlash(on ? `You’re following ${person.name}.` : `You unfollowed ${person.name}.`);
    } catch (e) {
        sync(!on);
        if (typeof person.followers_count === 'number') person.followers_count += on ? -1 : 1;
        complain(e);
    }
}

/** Where someone's name leads: a listed provider's profile, otherwise their community page */
export function personLink(person) {
    if (person.provider_id) return `/provider/${person.provider_id}`;
    return person.linkable === false ? null : `/community/people/${person.id}`;
}

export async function deletePost(post) {
    await api(`/community/posts/${post.id}`, { method: 'DELETE' });
    feedCache.posts = feedCache.posts.filter(p => p.id !== post.id);
}

export { complain as showCommunityError };

/* ---------------- Writing a post ---------------- */

// Shrinking photos before upload is shared with requests
export { preparePhoto } from '@/utils/photos.js';

export async function publishPost({ category, body, topic, photos }, onProgress) {
    const form = new FormData();
    form.append('category', category);
    if (body) form.append('body', body);
    if (topic) form.append('topic', topic);
    for (const p of photos) form.append('photos[]', p);
    return (await upload('/community/posts', form, { onProgress })).data;
}
