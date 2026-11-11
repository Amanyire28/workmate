/**
 * Availability, verification and client-feedback helpers for provider profiles.
 */
import { providerReviews } from '@/stores/catalog.js';

const availability = {
    available:   { label: 'Available now', tone: 'success', rank: 0 },
    busy:        { label: 'Busy',          tone: 'warning', rank: 1 },
    unavailable: { label: 'Unavailable',   tone: 'neutral', rank: 2 },
};

// What customers are told now: their preferences plus real bookings (server work_status)
const workStatus = {
    available: { label: 'Available now',             tone: 'success', rank: 0 },
    busy:      { label: 'Busy · Accepting bookings', tone: 'warning', rank: 1 },
    paused:    { label: 'Bookings paused',           tone: 'neutral', rank: 2 },
};

export function availabilityInfo(provider) {
    const info = workStatus[provider.workStatus] || availability[provider.availabilityStatus] || availability.unavailable;
    // e.g. "Busy · free Tomorrow", "Unavailable · Back on Monday"
    let detail = null;
    const next = provider.nextAvailable;
    if (next) {
        // "Free tomorrow", "Free next week", but keep day names capitalised: "Free Thursday"
        const isDayName = /^(Mon|Tue|Wed|Thu|Fri|Sat|Sun)/.test(next);
        detail = provider.availabilityStatus === 'busy' ? `Free ${isDayName ? next : next.toLowerCase()}` : next;
    }
    return { ...info, detail };
}

/**
 * One tick per provider, never two: grey once their documents are approved,
 * turning blue when WorkMate's rules qualify them. The site shows the tick
 * without explaining it.
 */
const TICKS = {
    grey: { level: 'grey', colorClass: 'text-[#94A3B8]' },
    blue: { level: 'blue', colorClass: 'text-primary' },
};

/** The provider's tick, or null when they have none */
export function tickInfo(provider) {
    if (provider.blueTick) return TICKS.blue;
    if (provider.documentVerified) return TICKS.grey;
    return null;
}

/** For ordering: blue tick first, then verified, then neither */
export function trustRank(provider) {
    return (provider.blueTick ? 2 : 0) + (provider.documentVerified ? 1 : 0);
}

export const satisfactionLabels = {
    5: 'Very satisfied',
    4: 'Satisfied',
    3: 'Neutral',
    2: 'Unsatisfied',
    1: 'Very unsatisfied',
};

export function reviewsFor(providerId) {
    return providerReviews.filter(r => r.providerId === providerId);
}

/** Summary of recent client feedback: average satisfaction and % who'd hire again */
export function feedbackSummary(reviews) {
    if (!reviews.length) return null;
    const avg = reviews.reduce((s, r) => s + r.rating, 0) / reviews.length;
    const again = reviews.filter(r => r.wouldHireAgain).length;
    return {
        count: reviews.length,
        averageSatisfaction: Math.round(avg * 10) / 10,
        hireAgainCount: again,
        hireAgainPercent: Math.round((again / reviews.length) * 100),
    };
}
