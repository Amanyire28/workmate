/**
 * Budgets and estimates (Phase B1), as words and as what the server is sent. Pure functions with no imports,
 * so they run the same in the app and in the Node tests.
 *
 * Nothing here is the price of a job: a budget is what a customer has in mind and an estimate is a guide a
 * provider gives when applying. The price is agreed (and confirmed by the customer) later. Amounts are whole UGX.
 */

export const MIN_AMOUNT = 1000;

export const ugx = (amount) => `UGX ${Number(amount).toLocaleString('en-UG')}`;

/* ---------------- Reading what the server sent ---------------- */

/** The budget kind of a request, also for responses that predate budget types */
export function budgetKind(b) {
    if (['fixed', 'range', 'negotiable', 'none'].includes(b.budgetType)) return b.budgetType;
    if (!b.budget) return 'none';

    return b.budgetBasis === 'negotiable' ? 'negotiable' : 'fixed';
}

/** A request as the API sent it (snake_case), in the shape the helpers read */
export const budgetFromApi = (r) => ({
    budgetType: r.budget_type, budget: r.budget, budgetBasis: r.budget_basis, budgetMin: r.budget_min, budgetMax: r.budget_max,
});

/**
 * How a budget reads: { main, basis }. With the pricing options off ("rich" false) it reads exactly as it always
 * has, an amount and its basis; with them on, ranges and negotiable budgets are said in words.
 */
export function budgetParts(b, rich = true) {
    if (!rich) return { main: b.budget ? ugx(b.budget) : null, basis: b.budgetBasis || '' };

    switch (budgetKind(b)) {
        case 'range':
            return { main: `${ugx(b.budgetMin)} – ${ugx(b.budgetMax)}`, basis: b.budgetBasis && b.budgetBasis !== 'fixed' ? b.budgetBasis : '' };
        case 'negotiable':
            return { main: 'Negotiable', basis: b.budget ? `about ${ugx(b.budget)}` : '' };
        case 'fixed':
            return { main: ugx(b.budget), basis: b.budgetBasis || '' };
        default:
            return { main: null, basis: '' };
    }
}

/** "UGX 30,000 – UGX 60,000 · per day", or the plain words when there is no budget */
export function budgetText(b, rich = true, none = 'Budget to be discussed') {
    const { main, basis } = budgetParts(b, rich);

    return main ? (basis ? `${main} · ${basis}` : main) : none;
}

/** A provider's estimate on an application: what the customer reads (a guide, not the price of the job) */
export function estimateParts(q, rich = true) {
    if (!rich) return { main: q.amount ? ugx(q.amount) : null, basis: '' };

    switch (q.estimateType) {
        case 'range':
            return { main: `${ugx(q.amount)} – ${ugx(q.amountMax)}`, basis: 'estimate' };
        case 'inspection_required':
            return { main: 'Needs to see the job first', basis: 'no price yet' };
        case 'fixed':
            return { main: ugx(q.amount), basis: 'estimate' };
        default:
            return { main: q.amount ? ugx(q.amount) : null, basis: q.amount ? 'estimate' : '' };
    }
}

/** An application as the API sent it (snake_case) */
export const estimateFromApi = (a) => ({ estimateType: a.estimate_type, amount: a.amount, amountMax: a.amount_max });

/* ---------------- The customer's budget form ---------------- */

export const BUDGET_CHOICES = [
    { id: 'fixed', label: 'A set amount', hint: 'What you can pay' },
    { id: 'range', label: 'A range', hint: 'From – to' },
    { id: 'negotiable', label: 'Negotiable', hint: 'Open to talk' },
    { id: 'none', label: 'No budget', hint: 'Let workers suggest' },
];

export const BASIS_CHOICES = [
    { id: 'total', label: 'In total' },
    { id: 'per person', label: 'Per person' },
    { id: 'per day', label: 'Per day' },
    { id: 'per hour', label: 'Per hour' },
];

export const blankBudget = () => ({ type: 'none', amount: '', min: '', max: '', basis: 'total' });

const isAmount = (v) => v !== '' && v != null && Number.isFinite(Number(v)) && Number(v) >= MIN_AMOUNT && Number.isInteger(Number(v));
const empty = (v) => v === '' || v == null;

/** What is wrong with a budget, field by field ('' when fine); the server checks it all again */
export function validateBudget(b) {
    const errors = { amount: '', min: '', max: '' };
    if (b.type === 'fixed' && !isAmount(b.amount)) errors.amount = `Enter an amount of at least ${ugx(MIN_AMOUNT)}.`;
    if (b.type === 'negotiable' && !empty(b.amount) && !isAmount(b.amount)) errors.amount = `Enter an amount of at least ${ugx(MIN_AMOUNT)}, or leave it empty.`;
    if (b.type === 'range') {
        if (!isAmount(b.min)) errors.min = `Enter the lowest amount, at least ${ugx(MIN_AMOUNT)}.`;
        if (!isAmount(b.max)) errors.max = 'Enter the highest amount.';
        else if (isAmount(b.min) && Number(b.max) <= Number(b.min)) errors.max = 'The highest amount must be more than the lowest.';
    }

    return errors;
}

export const hasErrors = (errors) => Object.values(errors).some(Boolean);

/** The fields to send for a budget. `withBasis`: the job form asks what the amount is "per" */
export function budgetBody(b, withBasis = false) {
    const n = (v) => (empty(v) ? null : Number(v));
    const basis = withBasis ? { budget_basis: b.basis } : {};

    switch (b.type) {
        case 'fixed':
            return { budget_type: 'fixed', budget: n(b.amount), ...basis };
        case 'range':
            return { budget_type: 'range', budget_min: n(b.min), budget_max: n(b.max), ...basis };
        case 'negotiable':
            return { budget_type: 'negotiable', budget: n(b.amount) };
        default:
            return { budget_type: 'none' };
    }
}

/** A budget form as words, for the "check your job" step */
export function budgetFormText(b, withBasis = false) {
    return budgetText({
        budgetType: b.type, budget: empty(b.amount) ? null : Number(b.amount), budgetMin: empty(b.min) ? null : Number(b.min),
        budgetMax: empty(b.max) ? null : Number(b.max), budgetBasis: withBasis && b.type !== 'negotiable' ? b.basis : '',
    }, true, 'Open to offers');
}

/* ---------------- The provider's estimate form ---------------- */

export const ESTIMATE_CHOICES = [
    { id: '', label: 'Decide later', hint: 'Agree it in chat' },
    { id: 'fixed', label: 'A set price', hint: 'One amount' },
    { id: 'range', label: 'A range', hint: 'From – to' },
    { id: 'inspection_required', label: 'See the job first', hint: 'No price yet' },
];

export const blankEstimate = () => ({ type: '', amount: '', max: '' });

export function validateEstimate(e) {
    const errors = { amount: '', max: '' };
    if (e.type === 'fixed' && !isAmount(e.amount)) errors.amount = `Enter your price, at least ${ugx(MIN_AMOUNT)}.`;
    if (e.type === 'range') {
        if (!isAmount(e.amount)) errors.amount = `Enter the lowest amount, at least ${ugx(MIN_AMOUNT)}.`;
        if (!isAmount(e.max)) errors.max = 'Enter the highest amount.';
        else if (isAmount(e.amount) && Number(e.max) <= Number(e.amount)) errors.max = 'The highest amount must be more than the lowest.';
    }

    return errors;
}

/** The fields to send for an estimate; without one, the application carries no price, as it always could */
export function estimateBody(e) {
    switch (e.type) {
        case 'fixed':
            return { estimate_type: 'fixed', amount: Number(e.amount) };
        case 'range':
            return { estimate_type: 'range', amount: Number(e.amount), amount_max: Number(e.max) };
        case 'inspection_required':
            return { estimate_type: 'inspection_required' };
        default:
            return { amount: null };
    }
}

/* ---------------- Listed service prices ---------------- */

/** "UGX 20,000 – UGX 45,000 per visit" for a service listed as a range (a guide, not an amount owed) */
export function rangeLabel(service, unitWords = '') {
    return `${ugx(service.price)} – ${ugx(service.price_max)}${unitWords ? ` ${unitWords}` : ''}`;
}

/* ---------------- Price proposals and the agreed price (Phase B2) ---------------- */

/**
 * Where a request's price stands, from the `pricing` block the server sends ({ agreed, open }) and whether the
 * provider said they must inspect first. One of:
 *   none | inspection | proposed | change_requested | expired | agreed | closed
 *   amendment_proposed | amendment_change_requested | amendment_expired   (a price is agreed AND a newer one is open)
 * Only "agreed" (with or without an amendment waiting) means a price is agreed; nothing here is ever inferred.
 */
export function priceState(pricing, inspection = false) {
    const agreed = pricing && pricing.agreed;
    const open = pricing && pricing.open;
    const openKind = !open ? '' : open.status === 'pending_customer' ? 'proposed' : open.status === 'revision_requested' ? 'change_requested' : 'expired';
    if (agreed) return open ? `amendment_${openKind}` : 'agreed';
    if (open) return openKind;
    // Nothing open or agreed, but a price was proposed once: it ended (declined, expired, taken back)
    if (pricing && pricing.latest) return 'closed';

    return inspection ? 'inspection' : 'none';
}

/** The simple words shown for each state, for the customer and for the provider */
export const PRICE_WORDS = {
    none: { title: '', tone: 'neutral' },
    inspection: { title: 'Inspection needed', tone: 'warning', customer: 'The price isn’t set yet. The provider needs to see the job first.', provider: 'You need to see the job before pricing it.' },
    proposed: { title: 'Proposed price', tone: 'info', customer: 'Look at it, then accept it or ask for a change.', provider: 'Waiting for the customer to answer.' },
    change_requested: { title: 'Change requested', tone: 'warning', customer: 'You asked for a change. The provider will send a new price.', provider: 'The customer asked for a change. Send a new price.' },
    expired: { title: 'Price offer ended', tone: 'neutral', customer: 'This price offer ended without an answer. Ask the provider for a new one.', provider: 'It wasn’t answered in time. You can send it again.' },
    closed: { title: 'Price offer ended', tone: 'neutral', customer: 'Ask the provider for a new price.', provider: 'You can send a new price.' },
    agreed: { title: 'Price agreed', tone: 'success', customer: 'You and the provider agreed this price.', provider: 'You and the customer agreed this price.' },
};

/** The state of the newer price while an agreed one stands, in the same words */
export const openKind = (state) => (state.startsWith('amendment_') ? state.slice('amendment_'.length) : state);

/** "Answer by Fri 28 Nov": when a waiting offer ends */
export function shortDate(iso) {
    if (!iso) return '';
    const d = new Date(iso);

    return Number.isNaN(d.getTime()) ? '' : d.toLocaleDateString('en-UG', { weekday: 'short', day: 'numeric', month: 'short' });
}

export const MIN_SCOPE = 10;

export const blankProposal = (amount = '') => ({ amount: amount === null ? '' : String(amount), scope: '', exclusions: '' });

/** What is wrong with a price proposal, field by field ('' when fine); the server checks it all again */
export function validateProposal(p) {
    const errors = { amount: '', scope: '', exclusions: '' };
    const amount = String(p.amount ?? '').trim();
    if (!/^\d+$/.test(amount) || Number(amount) < MIN_AMOUNT || Number(amount) > 100000000) errors.amount = `Enter the price in UGX, from ${ugx(MIN_AMOUNT)} to ${ugx(100000000)}.`;
    if (String(p.scope ?? '').trim().length < MIN_SCOPE) errors.scope = 'Say what the price includes (a few words).';
    if (String(p.scope ?? '').trim().length > 2000) errors.scope = 'Keep it under 2,000 characters.';
    if (String(p.exclusions ?? '').trim().length > 1000) errors.exclusions = 'Keep it under 1,000 characters.';

    return errors;
}

/** The fields to send; `token` is one per try, so a retry after a dropped connection can't send it twice */
export function proposalBody(p, token) {
    const exclusions = String(p.exclusions ?? '').trim();

    return { amount: Number(String(p.amount).trim()), scope: String(p.scope).trim(), exclusions: exclusions || null, client_token: token };
}

/** The error codes that mean "the price changed meanwhile": the app reloads and shows the latest */
export const PRICE_STALE_CODES = ['stale_proposal', 'proposal_expired', 'already_decided', 'request_status_conflict'];

/** What each step of the history is called, in plain words (by is provider | customer | system) */
export function eventWords(e) {
    const who = e.by === 'customer' ? 'You' : e.by === 'provider' ? 'Provider' : 'WorkMate';
    switch (e.event) {
        case 'proposed': return `${who === 'You' ? 'You' : 'Provider'} proposed ${ugx(e.amount)}`;
        case 'revised': return `New price proposed: ${ugx(e.amount)}`;
        case 'superseded': return `Version ${e.revision_no} was replaced`;
        case 'change_requested': return 'A change was requested';
        case 'accepted': return `Price agreed: ${ugx(e.amount)}`;
        case 'rejected': return 'Price declined';
        case 'withdrawn': return 'Provider took the price back';
        case 'expired': return 'Offer ended without an answer';
        case 'cancelled': return 'Closed because the job ended';
        default: return e.event;
    }
}
