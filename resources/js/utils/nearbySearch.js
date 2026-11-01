/**
 * Nearby-first provider search for the WorkMate prototype.
 * Runs on mock data in the browser. A backend search endpoint can later take
 * the same inputs (query + origin) and return the same grouped shape.
 */
import { providers, categories } from '@/stores/catalog.js';
import { getSearchLocation, findSearchLocationByName, searchLocations, locationLabel } from '@/data/searchLocations.js';
import { categoriesMatching } from './search.js';
import { displayName, tradeLabel } from './ui.js';
import { preferMainSkill } from './recommend.js';

/** Demonstration thresholds: change them here, not in components. */
export const SEARCH_CONFIG = {
    nearbyKm: 5,           // "Closest to you"
    extendedKm: 10,        // "More … around {town}" (plus anyone based in the same town)
    minNearbyResults: 3,   // fewer nearby matches than this → expand the search
    radarRangeKm: 12,      // distance drawn at the radar's outer edge
    gpsAreaMatchKm: 6,     // a GPS fix within this of a sample area counts as being in it
};

// Plural "people" wording per category, for headings like "Plumbers near Ntinda"
const peopleLabels = {
    plumbing:     'plumbers',
    electrical:   'electricians',
    cleaning:     'cleaners',
    moving:       'movers',
    carpentry:    'carpenters',
    painting:     'painters',
    repairs:      'repair technicians',
    gardening:    'gardeners',
    driving:      'drivers',
    construction: 'builders',
};

export function capitalise(text) {
    return text ? text[0].toUpperCase() + text.slice(1) : text;
}

/** Straight-line (great-circle) distance in km between two { latitude, longitude } points */
export function haversineKm(a, b) {
    const R = 6371;
    const rad = deg => (deg * Math.PI) / 180;
    const dLat = rad(b.latitude - a.latitude);
    const dLng = rad(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 +
        Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

function hasCoords(point) {
    return point && point.latitude != null && point.longitude != null;
}

/** "Under 1 km", "2.1 km", "16 km" */
export function formatKm(km) {
    if (km < 1) return 'Under 1 km';
    return `${km < 10 ? km.toFixed(1) : Math.round(km)} km`;
}

/**
 * Turn the stored origin (a sample area id, or a GPS fix the user shared)
 * into everything the ranking and the copy need.
 */
export function resolveOrigin(state) {
    if (state?.kind === 'gps') {
        // Nearest sample area, so "serves your area" can still be checked
        let area = null;
        let best = Infinity;
        for (const loc of searchLocations) {
            if (loc.wholeTown) continue;
            const d = haversineKm(state, loc);
            if (d < best) { best = d; area = loc; }
        }
        if (best > SEARCH_CONFIG.gpsAreaMatchKm) area = null;
        return {
            kind: 'gps',
            latitude: state.latitude,
            longitude: state.longitude,
            area,
            town: area?.town || null,
            name: 'you',
            label: 'your location',
        };
    }
    const area = getSearchLocation(state?.areaId) || getSearchLocation('kampala');
    return {
        kind: 'area',
        latitude: area.latitude,
        longitude: area.longitude,
        area,
        town: area.town,
        name: area.wholeTown ? `central ${area.name}` : area.name,
        label: locationLabel(area),
    };
}

/** Whether the provider lists the origin's area (or its whole town) in their coverage */
export function servesOrigin(provider, origin) {
    const target = origin.area;
    if (!target) return false;
    return (provider.serviceAreas || []).some(id => {
        if (id === target.id) return true;
        const covered = getSearchLocation(id);
        if (!covered || covered.town !== target.town) return false;
        // "All of Kampala" covers each Kampala area; a whole-town search accepts any area in it
        return covered.wholeTown || target.wholeTown;
    });
}

/** Providers offering the requested service, by category keywords or profile text */
export function matchService(query) {
    const q = query.trim().toLowerCase();
    const serviceIds = categoriesMatching(q, categories);
    const matches = preferMainSkill(providers.filter(p =>
        p.services.some(s => serviceIds.includes(s)) ||
        [displayName(p), p.name, tradeLabel(p, 99), p.skills.join(' ')].join(' ').toLowerCase().includes(q)
    ), serviceIds);
    return { serviceIds, matches };
}

/**
 * Rank providers for a query around an origin, nearby first:
 *   nearby    within nearbyKm of the origin
 *   extended  within extendedKm, or based in the origin's town (even without coordinates)
 *   serving   further away, but lists the origin's area in their coverage
 * Anyone else is left out. A provider never appears in two groups.
 */
export function searchNearby(query, origin, config = SEARCH_CONFIG) {
    const { serviceIds, matches } = matchService(query);
    const groups = { nearby: [], extended: [], serving: [] };

    for (const provider of matches) {
        const distanceKm = hasCoords(provider) ? haversineKm(origin, provider) : null;
        const serves = servesOrigin(provider, origin);
        const baseTown = findSearchLocationByName(provider.area)?.town || null;
        const sameTown = !!origin.town && baseTown === origin.town;

        let tier = null;
        if (distanceKm != null && distanceKm <= config.nearbyKm) tier = 'nearby';
        else if ((distanceKm != null && distanceKm <= config.extendedKm) || sameTown) tier = 'extended';
        else if (serves) tier = 'serving';

        if (tier) groups[tier].push({ provider, distanceKm, serves, tier });
    }

    // Unknown distances sort after known ones
    const byDistance = (a, b) => (a.distanceKm ?? Infinity) - (b.distanceKm ?? Infinity);
    groups.nearby.sort(byDistance);
    groups.extended.sort((a, b) => (b.serves - a.serves) || byDistance(a, b));
    groups.serving.sort(byDistance);

    const people = serviceIds.length === 1 ? peopleLabels[serviceIds[0]] : 'professionals';
    const widerCount = groups.extended.length + groups.serving.length;

    return {
        query: query.trim(),
        serviceIds,
        people,
        origin,
        groups,
        matchedTotal: matches.length,
        // Too few close matches, and more exist further out → expand the search
        needsWider: groups.nearby.length < config.minNearbyResults && widerCount > 0,
    };
}

/**
 * Groups to show, in order. Wider groups appear when the search expanded on its own,
 * or when the user asked for more after enough nearby results.
 */
export function visibleGroups(result, showWider = false) {
    const tiers = result.needsWider || showWider ? ['nearby', 'extended', 'serving'] : ['nearby'];
    return tiers
        .map(tier => ({ tier, items: result.groups[tier] }))
        .filter(g => g.items.length);
}

/** "Plumbers near Ntinda, Kampala" / "Professionals for “tiles” near you" */
export function searchHeading(result) {
    const who = result.serviceIds.length === 1 ? capitalise(result.people) : `Professionals for “${result.query}”`;
    const where = result.origin.kind === 'gps' ? 'you' : result.origin.label;
    return `${who} near ${where}`;
}

/**
 * Radar scale: share of the radius (0–1) for a distance. Square-root, so the
 * nearby kilometres get room instead of crowding the centre pin.
 */
export function radarFraction(km, rangeKm = SEARCH_CONFIG.radarRangeKm) {
    return Math.min(Math.sqrt(km / rangeKm), 1) * 0.92;
}

/**
 * Where a provider's registered base sits on the radar, as % of the radar box.
 * Uses the real bearing from the origin; distances past the radar range sit at the edge.
 */
export function radarPosition(origin, provider, rangeKm = SEARCH_CONFIG.radarRangeKm) {
    const kmPerDegLat = 110.57;
    const kmPerDegLng = 111.32 * Math.cos((origin.latitude * Math.PI) / 180);
    const dx = (provider.longitude - origin.longitude) * kmPerDegLng;
    const dy = (provider.latitude - origin.latitude) * kmPerDegLat;
    const d = Math.hypot(dx, dy);
    // Keep markers clear of the centre pin and inside the outer ring
    const fraction = Math.min(Math.max(radarFraction(d, rangeKm), 0.32), 0.88);
    const angle = d ? Math.atan2(dy, dx) : -Math.PI / 4;
    return {
        x: 50 + Math.cos(angle) * fraction * 46,
        y: 50 - Math.sin(angle) * fraction * 46,
    };
}
