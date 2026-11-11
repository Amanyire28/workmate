/**
 * Bookings in plain words, in Uganda time (Africa/Kampala) whatever the
 * phone's own setting: dates, times, lengths, what each status means for the
 * customer and the provider, and directions links.
 */

const TZ = 'Africa/Kampala';

/** "Friday, 20 November 2026" */
export function formatDay(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-GB', { timeZone: TZ, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
}

/** "10:00 AM" */
export function formatTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('en-US', { timeZone: TZ, hour: 'numeric', minute: '2-digit' });
}

/** "Fri 20 Nov, 10:00 AM" */
export function formatShort(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const day = d.toLocaleDateString('en-GB', { timeZone: TZ, weekday: 'short', day: 'numeric', month: 'short' });
    return `${day}, ${formatTime(iso)}`;
}

/** Today's date in Uganda, "2026-11-18", for a date picker's minimum */
export function todayInUganda(offsetDays = 0) {
    const d = new Date(Date.now() + offsetDays * 86400000);
    return d.toLocaleDateString('en-CA', { timeZone: TZ });
}

/** Times people choose from: 6:00 AM to 9:00 PM, every half hour */
export const TIME_SLOTS = Array.from({ length: 31 }, (_, i) => {
    const minutes = 6 * 60 + i * 30;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    const value = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    const label = `${((h + 11) % 12) + 1}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
    return { value, label };
});

/** How long it might take (the server accepts exactly these) */
export const DURATIONS = [
    { value: '', label: 'Not sure' },
    { value: 60, label: 'About 1 hour' },
    { value: 120, label: 'About 2 hours' },
    { value: 180, label: 'About 3 hours' },
    { value: 240, label: 'Half a day' },
    { value: 480, label: 'A full day' },
];

export function durationLabel(minutes) {
    if (!minutes) return '';
    if (minutes < 60) return `${minutes} minutes`;
    if (minutes === 240) return 'Half a day';
    if (minutes >= 480) return 'A full day';
    const h = minutes / 60;
    return `${Number.isInteger(h) ? h : h.toFixed(1)} hour${h === 1 ? '' : 's'}`;
}

/** Opens the phone's map app (or Google Maps in the browser) with directions to the point */
export function directionsUrl(lat, lng) {
    return `https://www.google.com/maps/dir/?api=1&destination=${lat},${lng}`;
}

/** "Accurate to about 20 m" from a reading's accuracy in metres */
export function accuracyLabel(m) {
    if (m == null) return '';
    if (m <= 25) return 'Accurate to within a few metres';
    if (m < 1000) return `Accurate to about ${Math.round(m / 10) * 10} m`;
    return `Approximate (about ${(m / 1000).toFixed(1)} km)`;
}

/**
 * What a request's status means for the customer: a short title, one line
 * of explanation and a tone. Works for older requests without a time too.
 */
export function customerStatus(req) {
    const s = req.status;
    if (s === 'open' && req.schedule?.proposed_start) {
        return { key: 'proposed', title: 'Time change proposed', text: 'The provider suggested a different time. Accept it, or keep your request as it is.', tone: 'warning' };
    }
    if (s === 'open') {
        return req.schedule?.timing
            ? { key: 'awaiting', title: 'Awaiting confirmation', text: 'The provider hasn’t confirmed the time yet.', tone: 'info' }
            : { key: 'sent', title: 'Request sent', text: 'Your request has been sent to the provider.', tone: 'info' };
    }
    if (s === 'scheduled') return { key: 'confirmed', title: 'Booking confirmed', text: 'The provider has accepted your appointment.', tone: 'success' };
    if (s === 'in_progress') {
        return req.schedule?.timing
            ? { key: 'working', title: 'Work in progress', text: 'The provider has started the job.', tone: 'info' }
            : { key: 'accepted', title: 'Accepted', text: 'The provider accepted your request.', tone: 'success' };
    }
    if (s === 'completed') return { key: 'done', title: 'Work completed', text: 'The provider marked the job as done.', tone: 'success' };
    if (s === 'cancelled') {
        if (req.schedule?.cancelled_by === 'customer') return { key: 'cancelled', title: 'Cancelled', text: 'You cancelled this request.', tone: 'neutral' };
        if (req.schedule?.cancelled_by === 'provider') return { key: 'cancelled', title: 'Booking cancelled', text: 'The provider had to cancel.', tone: 'neutral' };
        return { key: 'declined', title: 'Declined', text: 'The provider can’t take this request.', tone: 'neutral' };
    }
    return { key: s, title: s, text: '', tone: 'neutral' };
}

/** The provider's side of the same statuses, as short labels */
export function providerStatus(req) {
    const map = {
        open: req.schedule?.proposed_start ? 'You suggested a time' : 'New request',
        scheduled: 'Booked',
        in_progress: 'In progress',
        completed: 'Completed',
        cancelled: req.schedule?.cancelled_by ? 'Cancelled' : 'Declined',
    };
    return map[req.status] || req.status;
}

/** "Available now" / "Busy · Accepting bookings" / "Bookings paused", with a line for customers */
export const WORK_STATUS = {
    available: { label: 'Available now', text: 'This provider is accepting immediate requests.', tone: 'success' },
    busy: { label: 'Busy · Accepting bookings', text: 'This provider is currently working but can receive future booking requests.', tone: 'warning' },
    paused: { label: 'Bookings paused', text: 'This provider is not accepting new requests at the moment.', tone: 'neutral' },
};
