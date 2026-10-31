import { availabilityInfo, trustRank } from './trust.js';

/**
 * Mock "recommended" ordering: providers in the chosen area first, then
 * those available soonest, then blue tick and WorkMate Verified providers, then by rating.
 * Replace with the API's ranking once it exists.
 */
export function recommendProviders(list, area = '') {
    return [...list].sort((a, b) =>
        (b.area === area) - (a.area === area) ||
        availabilityInfo(a).rank - availabilityInfo(b).rank ||
        trustRank(b) - trustRank(a) ||
        (b.rating || 0) - (a.rating || 0)
    );
}
