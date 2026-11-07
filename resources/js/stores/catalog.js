import { reactive } from 'vue';
import { api } from '@/api/client.js';
import { applySearchSettings } from '@/utils/nearbySearch.js';

/**
 * Shared marketplace data, loaded from the Laravel API: categories, areas,
 * providers, recent reviews and open jobs.
 *
 * The arrays are filled in place, so a page can import them once and stay
 * up to date. Field names keep the shape the pages were written for; the
 * mapping from the API's names happens here, in one place.
 *
 * Providers, reviews and jobs marked isSample are invented development
 * records; the pages label them.
 */

export const catalog = reactive({ status: 'idle', error: '' });   // idle | loading | ready | error

// Settings administrators change (Admin → Settings) that the app applies.
// Defaults until the catalogue arrives; the server's values replace them.
export const appSettings = reactive({
    defaultArea: 'kampala',
    supportPhone: null,
    supportEmail: null,
    providerSignups: true,
    maintenanceMode: false,
    heartbeatSeconds: 60,
    // Admin → Legal Documents
    askLegal: true,
    minimumAge: 18,
});

export const categories = reactive([]);
// Places the pages know about: the quick picks, every place a listed provider
// or open job uses, and any place someone has searched for and chosen
export const searchLocations = reactive([]);
export const providers = reactive([]);
export const providerReviews = reactive([]);
export const jobs = reactive([]);

function replace(target, items) {
    target.splice(0, target.length, ...items);
}

/** "2 hours ago", "3 days ago", "Today" */
export function agoLabel(iso) {
    const minutes = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 60000));
    if (minutes < 1) return 'Just now';
    if (minutes < 60) return `${minutes} minute${minutes === 1 ? '' : 's'} ago`;
    const hours = Math.round(minutes / 60);
    if (hours < 24) return `${hours} hour${hours === 1 ? '' : 's'} ago`;
    const days = Math.round(hours / 24);
    if (days < 7) return days === 1 ? 'Yesterday' : `${days} days ago`;
    const weeks = Math.round(days / 7);
    if (days < 30) return `${weeks} week${weeks === 1 ? '' : 's'} ago`;
    const months = Math.round(days / 30);
    return `${months} month${months === 1 ? '' : 's'} ago`;
}

const PROVIDER_TYPES = { individual: 'individual', group: 'team', company: 'business' };

export function toProvider(p) {
    return {
        id: p.id,
        name: p.name,
        businessName: p.display_name !== p.name ? p.display_name : null,
        avatarUrl: p.avatar_url,
        type: PROVIDER_TYPES[p.type] || p.type,
        services: p.categories,
        mainService: p.main_category,
        professions: p.professions || [],
        area: p.base_area?.name || '',
        areaSlug: p.base_area?.slug || null,
        latitude: p.latitude,
        longitude: p.longitude,
        serviceAreas: p.service_areas,
        description: p.bio || '',
        skills: p.skills,
        availability: p.availability_note || '',
        priceFrom: p.price_from,
        priceBasis: p.price_basis || '',
        rating: p.rating,
        reviewCount: p.review_count,
        documentVerified: p.document_verified,
        blueTick: p.blue_tick,
        availabilityStatus: p.availability_status,
        nextAvailable: p.next_available,
        phone: null,
        isSample: p.is_sample,
    };
}

export function toReview(r) {
    return {
        id: r.id,
        providerId: r.provider_id,
        jobTitle: r.job_title,
        clientName: r.reviewer_name,
        area: r.area_name,
        completedAt: agoLabel(r.reviewed_at).replace('Just now', 'Today'),
        reviewedAt: r.reviewed_at,
        rating: r.rating,
        wouldHireAgain: r.would_hire_again,
        comment: r.comment,
        isSample: r.is_sample,
    };
}

export function toJob(j) {
    return {
        id: j.id,
        title: j.title,
        category: j.category,
        area: j.area_name,
        areaSlug: j.area,
        description: j.description || '',
        // Photos only reach the people allowed to see them; everyone sees how many there are
        photos: j.photos || [],
        photoCount: j.photo_count || 0,
        date: j.preferred_time || 'Flexible',
        workersNeeded: j.workers_needed,
        budget: j.budget,
        budgetBasis: j.budget_basis || '',
        postedAt: agoLabel(j.posted_at),
        postedAtIso: j.posted_at,
        status: j.status,
        applicants: j.applicants,
        isMine: j.is_mine,
        hasApplied: j.has_applied,
        isSample: j.is_sample,
    };
}

function toCategory(c) {
    return { id: c.slug, label: c.name, icon: c.icon, description: c.description || '', professions: c.professions || [] };
}

function toArea(a) {
    return {
        id: a.slug, name: a.name, town: a.town, district: a.district || a.town, kind: a.kind,
        latitude: a.latitude, longitude: a.longitude, wholeTown: a.is_whole_town, featured: !!a.is_featured,
    };
}

/**
 * Re-reads the settings while the app is open (e.g. maintenance switched on
 * or off), without reloading the rest of the catalogue.
 */
export async function refreshSettings() {
    try {
        applySettings((await api('/settings')).data);
    } catch {
        // Kept as they were; tried again next time
    }
}

function applySettings(s) {
    const pick = (key, fallback) => (key in s ? s[key] : fallback);
    Object.assign(appSettings, {
        defaultArea: pick('platform.default_area', appSettings.defaultArea),
        supportPhone: pick('platform.support_phone', null),
        supportEmail: pick('platform.support_email', null),
        providerSignups: !!pick('platform.provider_signups', true),
        maintenanceMode: !!pick('platform.maintenance_mode', false),
        heartbeatSeconds: pick('presence.heartbeat_seconds', 60),
        askLegal: !!pick('legal.ask_acceptance', true),
        minimumAge: pick('legal.minimum_age', 18),
    });
    applySearchSettings(s);
}

/** Adds a place from the API to the known places (once) and returns it */
export function rememberArea(a) {
    if (!a) return null;
    const known = searchLocations.find(l => l.id === a.slug);
    if (known) return known;
    const area = toArea(a);
    searchLocations.push(area);
    return area;
}

/** Up to 12 places whose name starts with (or contains a word starting with) the text, closest first */
export async function searchPlaces(text, near = null) {
    const params = new URLSearchParams({ q: text.trim() });
    if (near?.latitude != null) {
        params.set('near_latitude', near.latitude.toFixed(3));
        params.set('near_longitude', near.longitude.toFixed(3));
    }
    return (await api(`/areas/search?${params}`)).data.map(toArea);
}

/** The listed place nearest to a position (used only for this lookup) */
export async function nearestPlace(latitude, longitude) {
    const params = new URLSearchParams({ latitude: latitude.toFixed(5), longitude: longitude.toFixed(5) });
    return rememberArea((await api(`/areas/nearest?${params}`)).data);
}

let loading = null;

/** Loads everything once; later calls reuse it. Pass force to reload. */
export function loadCatalog({ force = false } = {}) {
    if (!force && catalog.status === 'ready') return Promise.resolve();
    if (loading) return loading;
    catalog.status = 'loading';
    catalog.error = '';

    // One request for everything, so the first page isn't kept waiting
    loading = api('/catalog')
        .then(({ data }) => {
            replace(categories, data.categories.map(toCategory));
            // Keep places chosen from a search before the catalogue arrived
            const chosen = searchLocations.filter(l => !data.areas.some(a => a.slug === l.id));
            replace(searchLocations, [...data.areas.map(toArea), ...chosen]);
            applySettings(data.settings || {});
            replace(providers, data.providers.map(toProvider));
            replace(providerReviews, data.reviews.map(toReview));
            replace(jobs, data.jobs.map(toJob));
            catalog.status = 'ready';
        })
        .catch((e) => {
            catalog.status = 'error';
            catalog.error = e.message;
        })
        .finally(() => { loading = null; });

    return loading;
}

export async function refreshJobs() {
    replace(jobs, (await api('/jobs')).data.map(toJob));
}

/** A provider's full review list (the catalog only holds the most recent ones) */
export async function loadProviderReviews(providerId) {
    const res = await api(`/providers/${providerId}`);
    const reviews = (res.data.reviews || []).map(toReview);
    const others = providerReviews.filter(r => r.providerId !== providerId);
    replace(providerReviews, [...others, ...reviews]);
    const i = providers.findIndex(p => p.id === providerId);
    if (i !== -1) providers[i] = toProvider(res.data);
    return reviews;
}

/** A known place by its id (slug) */
export function areaById(id) {
    return searchLocations.find(a => a.id === id) || null;
}

/** "Kyebando, Kampala"; "Kampala" for a whole town or a town named after its district */
export function areaLabel(idOrArea) {
    const a = typeof idOrArea === 'string' ? areaById(idOrArea) : idOrArea;
    if (!a) return '';
    if (a.wholeTown) return `All of ${a.name}`;
    return a.name === a.district || !a.district ? a.name : `${a.name}, ${a.district}`;
}

export function getCategoryById(id) {
    return categories.find(c => c.id === id) || { id, label: id, icon: 'bi-briefcase' };
}

export function formatUGX(amount) {
    if (!amount) return null;
    return `UGX ${amount.toLocaleString('en-UG')}`;
}

export const statusLabels = {
    waiting:     { label: 'Waiting for responses' },
    in_progress: { label: 'Work in progress' },
    completed:   { label: 'Completed' },
    cancelled:   { label: 'Cancelled' },
    open:        { label: 'Open' },
    accepted:    { label: 'Accepted' },
    pending:     { label: 'Waiting for reply' },
    declined:    { label: 'Not chosen' },
    new_request: { label: 'New request' },
    request_declined: { label: 'Declined' },
    withdrawn:   { label: 'Withdrawn' },
};
