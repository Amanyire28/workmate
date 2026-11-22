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
