/**
 * Areas people search from, loaded from the API (see stores/catalog.js).
 * Coordinates are approximate area centres used to estimate distances.
 *
 * `town` groups neighbourhoods for the "More professionals around …" step.
 * `wholeTown` marks an entry that stands for the whole town rather than one area.
 */
import { searchLocations } from '@/stores/catalog.js';

export { searchLocations };

export function getSearchLocation(id) {
    return searchLocations.find(l => l.id === id) || null;
}

export function findSearchLocationByName(name) {
    return searchLocations.find(l => l.name === name) || null;
}

/** "Ntinda, Kampala" — or just "Kampala" when the area is the whole town */
export function locationLabel(location) {
    if (!location) return '';
    return location.name === location.town ? location.name : `${location.name}, ${location.town}`;
}
