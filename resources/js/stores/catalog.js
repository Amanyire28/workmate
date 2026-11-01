import { reactive } from 'vue';
import { api } from '@/api/client.js';

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

export const categories = reactive([]);
export const searchLocations = reactive([]);
export const kampalaAreas = reactive([]);       // area names, for simple pickers
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
    return { id: a.slug, name: a.name, town: a.town, latitude: a.latitude, longitude: a.longitude, wholeTown: a.is_whole_town };
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
            replace(searchLocations, data.areas.map(toArea));
            // Simple pickers list the neighbourhoods, not whole towns
            replace(kampalaAreas, data.areas.filter(a => !a.is_whole_town).map(a => a.name));
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

export function areaSlugFor(name) {
    return searchLocations.find(a => a.name === name)?.id || null;
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
    withdrawn:   { label: 'Withdrawn' },
};
