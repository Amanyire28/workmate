import { availabilityInfo, trustRank } from './trust.js';
import { getSearchLocation } from '@/data/searchLocations.js';
import { isNearPlace } from './nearbySearch.js';

/**
 * "Recommended" ordering: providers near the chosen place (id) first, then
 * those available soonest, then blue tick and WorkMate Verified providers, then by rating.
 * Replace with the API's ranking once it exists.
 */
export function recommendProviders(list, areaId = '') {
    const place = getSearchLocation(areaId);
    const near = new Map(list.map(p => [p.id, isNearPlace(p, place)]));
    return [...list].sort((a, b) =>
        near.get(b.id) - near.get(a.id) ||
        availabilityInfo(a).rank - availabilityInfo(b).rank ||
        trustRank(b) - trustRank(a) ||
        (b.rating || 0) - (a.rating || 0)
    );
}

/**
 * The main-skill rule. Someone who offers a service as one of their other
 * skills is shown only when nobody based in the same area offers it as their
 * main skill. Providers that matched for other reasons (their name or profile
 * text) are left alone.
 */
export function preferMainSkill(list, serviceIds) {
    if (!serviceIds?.length) return list;
    const areaKey = (p) => p.area || `provider-${p.id}`;
    // "service|area" pairs where someone has the service as their main skill
    const mainHere = new Set(list.filter(p => serviceIds.includes(p.mainService)).map(p => `${p.mainService}|${areaKey(p)}`));
    return list.filter(p => {
        if (serviceIds.includes(p.mainService)) return true;
        const offered = p.services.filter(s => serviceIds.includes(s));
        // Kept if any service they offer has no main-skill provider in their area
        return !offered.length || offered.some(s => !mainHere.has(`${s}|${areaKey(p)}`));
    });
}
