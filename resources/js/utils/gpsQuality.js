/**
 * Rules for judging device location readings. No imports, so they can be
 * checked on their own (see the location store for how they're used).
 *
 * Browsers report `accuracy` in metres: the radius the true position is
 * probably within. A high-accuracy request doesn't guarantee GPS; indoors
 * or on a laptop the answer may come from Wi-Fi, mobile masts or the
 * internet connection and be kilometres off.
 */

/** Starting values; adjust after testing on real phones */
export const GPS_RULES = {
    goodM: 100,          // 0–100 m: good reading
    approximateM: 500,   // 101–500 m: acceptable, approximate
    lowM: 1000,          // 501–1,000 m: low confidence, try again once
                         // over 1,000 m: too rough to name a neighbourhood
    placeNearKm: 1.5,    // a place this close (with a good reading) is where they are
    placeAroundKm: 4,    // closer than this: "around" the place
    freshMs: 5 * 60 * 1000,   // a reading younger than this still counts as recent
    minMoveM: 250,       // smallest move that counts, whatever the accuracy
};

/** good | approximate | low | unreliable, from the reported accuracy in metres */
export function accuracyQuality(accuracyM, rules = GPS_RULES) {
    if (accuracyM == null || !Number.isFinite(accuracyM)) return 'unreliable';
    if (accuracyM <= rules.goodM) return 'good';
    if (accuracyM <= rules.approximateM) return 'approximate';
    if (accuracyM <= rules.lowM) return 'low';
    return 'unreliable';
}

/** Worth one more try for a better reading */
export function shouldRetry(accuracyM, rules = GPS_RULES) {
    const q = accuracyQuality(accuracyM, rules);
    return q === 'low' || q === 'unreliable';
}

/** Straight-line distance in metres between two { latitude, longitude } points */
export function distanceM(a, b) {
    const R = 6371000;
    const rad = deg => (deg * Math.PI) / 180;
    const dLat = rad(b.latitude - a.latitude);
    const dLng = rad(b.longitude - a.longitude);
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(rad(a.latitude)) * Math.cos(rad(b.latitude)) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

/**
 * Of two readings ({ latitude, longitude, accuracy, timestamp }), the one to
 * use. A recent reading isn't swapped for a much rougher one; otherwise the
 * newer one wins (people move).
 */
export function betterReading(current, next, now = Date.now(), rules = GPS_RULES) {
    if (!current) return next;
    if (!next) return current;
    const currentFresh = now - current.timestamp <= rules.freshMs;
    const muchWorse = next.accuracy > Math.max(current.accuracy * 2, current.accuracy + rules.approximateM);
    if (currentFresh && muchWorse) return current;
    return next;
}

/**
 * Whether the person has really moved, rather than the reading drifting:
 * the move has to be bigger than both readings' uncertainty together.
 */
export function movedMeaningfully(previous, next, rules = GPS_RULES) {
    if (!previous || !next) return true;
    const threshold = Math.max(rules.minMoveM, (previous.accuracy || 0) + (next.accuracy || 0));
    return distanceM(previous, next) > threshold;
}

/**
 * How confidently a place can be named for a reading:
 *   near    "Near me · Kisaasi": a good or approximate reading, the place close by
 *   around  "Near me · around Kisaasi": the place a little further, or a rougher reading
 *   broad   only the wider area (district or town) is trustworthy
 *   none    don't name anything
 * maxKm is the administrators' "match a shared location to a place within" setting.
 */
export function placeConfidence(placeDistanceKm, accuracyM, maxKm, rules = GPS_RULES) {
    if (placeDistanceKm == null || !Number.isFinite(placeDistanceKm) || placeDistanceKm > maxKm) return 'none';
    const quality = accuracyQuality(accuracyM, rules);
    if (quality === 'unreliable') return 'broad';
    if ((quality === 'good' || quality === 'approximate') && placeDistanceKm <= rules.placeNearKm) return 'near';
    if (placeDistanceKm <= Math.min(rules.placeAroundKm, maxKm)) return 'around';
    return 'broad';
}
