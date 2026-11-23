/**
 * Shadow commission screens (Phase B4), as pure functions with no imports so they run the same in the app and in the Node
 * tests. Everything on these screens is an ESTIMATE ("Estimated — Not Collected"): nothing is charged, owed or collected.
 * Rates are whole basis points (500 = 5%) and are converted with whole-number arithmetic only, never floating point.
 */

export const ESTIMATE_LABEL = 'Estimated — Not Collected';

/** "5%", "2.5%", "0.05%" from basis points */
export function bpToPercent(bp) {
    const n = Number(bp);
    if (!Number.isInteger(n) || n < 0) return '';
    const whole = Math.floor(n / 100);
    const rest = n % 100;
    if (rest === 0) return `${whole}%`;

    return `${whole}.${String(rest).padStart(2, '0').replace(/0+$/, '')}%`;
}

/**
 * Basis points from what an administrator typed: "5", "5%", "2.5", "0.25 %". At most two decimals, from 0 to 100%.
 * Returns null when it isn't a rate (so nothing is ever guessed).
 */
export function percentToBp(text) {
    const m = /^\s*(\d{1,3})(?:\.(\d{1,2}))?\s*%?\s*$/.exec(String(text ?? ''));
    if (!m) return null;
    const bp = Number(m[1]) * 100 + (m[2] ? Number(m[2].padEnd(2, '0')) : 0);

    return bp <= 10000 ? bp : null;
}

/** The commission of an amount at a rate, as the server works it out: half up, whole numbers only (for the examples on screen) */
export function estimate(amountUgx, rateBp) {
    return Math.floor((amountUgx * rateBp + 5000) / 10000);
}

/** What a rate can apply to, in the order the engine looks (most specific first) */
export const SCOPES = [
    { id: 'provider', label: 'One provider', hint: 'Beats every other rule' },
    { id: 'profession', label: 'A profession', hint: 'Plumber, electrician…' },
    { id: 'category', label: 'A service category', hint: 'Plumbing, cleaning…' },
    { id: 'area', label: 'A place', hint: 'Where the job is' },
    { id: 'district', label: 'A district', hint: 'Where the job is' },
    { id: 'global', label: 'Everything (default)', hint: 'When nothing more specific applies' },
];

export const TIER_LABEL = { provider: 'Provider', profession: 'Profession', category: 'Category', area: 'Place', district: 'District', global: 'Default' };

/** Why a payment has no estimate, in plain words */
export const REASON_LABEL = {
    no_policy: 'No rate rule was in force when the customer confirmed it',
    ambiguous_policy: 'Two rate rules overlapped, so none was chosen',
    sample_record: 'A sample record, not real',
    job_cancelled: 'The job was cancelled',
    legacy_request: 'Made before bookings existed',
    missing_reference: 'A record the calculation needs is missing',
    awaiting_confirmation: 'Waiting for the customer to confirm',
    disputed: 'The customer disputes it',
    withdrawn: 'The provider took the report back',
    payment_no_longer_confirmed: 'The payment is no longer confirmed',
};

export const reasonLabel = (reason) => REASON_LABEL[reason] || String(reason || '').replace(/_/g, ' ');

export const STATE_LABEL = { scheduled: 'Starts later', active: 'In force', ended: 'Ended' };

export const blankRule = () => ({ scope_type: 'global', scope_ref: '', scope_key: '', percent: '', from: '', to: '', reason: '', confirm: '' });

/**
 * What is wrong with a rate rule form, field by field ('' when fine). The server checks everything again, and also needs a
 * preview before it saves anything.
 */
export function validateRule(form, ceilingBp, now = new Date()) {
    const errors = { scope: '', percent: '', from: '', to: '' };
    if (!SCOPES.some((s) => s.id === form.scope_type)) errors.scope = 'Choose what the rate applies to.';
    else if (['provider', 'profession', 'category', 'area'].includes(form.scope_type) && !/^\d+$/.test(String(form.scope_ref))) errors.scope = 'Choose which one.';
    else if (form.scope_type === 'district' && !String(form.scope_key).trim()) errors.scope = 'Choose a district.';

    const bp = percentToBp(form.percent);
    if (bp === null) errors.percent = 'Enter the rate as a percentage, for example 5 or 2.5.';
    else if (bp > ceilingBp) errors.percent = `That is above the safety ceiling of ${bpToPercent(ceilingBp)}.`;

    const from = form.from ? new Date(form.from) : null;
    if (!from || Number.isNaN(from.getTime())) errors.from = 'Choose when the rate starts.';
    else if (from.getTime() < now.getTime() - 60000) errors.from = 'A rate can’t start in the past.';
    if (form.to) {
        const to = new Date(form.to);
        if (Number.isNaN(to.getTime()) || (from && to.getTime() <= from.getTime())) errors.to = 'The end must be after the start.';
    }

    return errors;
}

export const hasErrors = (errors) => Object.values(errors).some(Boolean);

/** The fields to send for a preview or a save (dates as exact moments) */
export function ruleBody(form) {
    const body = { scope_type: form.scope_type, rate_bp: percentToBp(form.percent), effective_from: new Date(form.from).toISOString(), effective_to: form.to ? new Date(form.to).toISOString() : null };
    if (['provider', 'profession', 'category', 'area'].includes(form.scope_type)) body.scope_ref = Number(form.scope_ref);
    if (form.scope_type === 'district') body.scope_key = String(form.scope_key).trim();

    return body;
}

/** A value for <input type="datetime-local"> from a moment (local time, to the minute) */
export function localInput(date) {
    const d = new Date(date);
    const pad = (n) => String(n).padStart(2, '0');

    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

/** A bar's width for a figure against the biggest one (never below 2% when there is something) */
export function barPercent(value, max) {
    if (!max || value <= 0) return 0;

    return Math.max(2, Math.round((value / max) * 100));
}
