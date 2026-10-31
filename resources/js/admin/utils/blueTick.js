/**
 * Blue tick eligibility engine (pure functions).
 *
 * The blue tick is never applied for: providers are evaluated automatically against
 * the published rule set. In production Laravel runs this same logic on performance
 * events, on a schedule, when standing changes and when rules change; the prototype
 * runs it in the browser over demo metrics.
 *
 * Two modes, never mixed:
 *   all_mandatory — every enabled rule must pass.
 *   weighted      — enabled performance rules earn points towards a minimum score,
 *                   AND every enabled standing rule (trust, financial, account) must pass.
 * A rule whose data isn't collected yet is "not evaluated": it never counts as passed.
 */

export const CATEGORIES = {
    booking:        { label: 'Booking performance', standing: false },
    satisfaction:   { label: 'Customer satisfaction', standing: false },
    responsiveness: { label: 'Responsiveness', standing: false },
    trust:          { label: 'Trust and safety', standing: true },
    financial:      { label: 'Financial standing', standing: true },
    account:        { label: 'Account standing', standing: true },
};

export const MODES = {
    all_mandatory: { label: 'All mandatory rules', help: 'A provider must pass every enabled rule. Simple and strict: one shortfall means no blue tick.' },
    weighted: { label: 'Weighted score', help: 'Enabled performance rules earn points. A provider needs the minimum score AND must pass every trust, financial and account rule.' },
};

/**
 * Rule definitions. `metric` names a value from the provider's metrics; `kind` says how
 * the threshold is compared (min, max, zero, true). `periodOptions` lists the windows an
 * admin can pick; `collected: false` marks data WorkMate doesn't record yet.
 */
export const RULE_DEFS = [
    { id: 'completed_total', category: 'booking', label: 'Completed bookings (all time)', metric: 'completedTotal', kind: 'min', unit: 'bookings' },
    { id: 'completed_period', category: 'booking', label: 'Completed bookings in period', metric: 'completedIn', kind: 'min', unit: 'bookings', periodOptions: [30, 90, 180] },
    { id: 'completion_rate', category: 'booking', label: 'Completion rate', metric: 'completionRate', kind: 'min', unit: '%', percent: true, help: 'Completed ÷ (completed + cancelled + expired) bookings.' },
    { id: 'cancellation_rate', category: 'booking', label: 'Cancellation rate', metric: 'cancellationRate', kind: 'max', unit: '%', percent: true },
    { id: 'tenure', category: 'booking', label: 'Time as an active provider', metric: 'tenureDays', kind: 'min', unit: 'days' },
    { id: 'avg_rating', category: 'satisfaction', label: 'Average rating', metric: 'avgRating', kind: 'min', unit: '★', decimals: 2 },
    { id: 'review_count', category: 'satisfaction', label: 'Eligible reviews', metric: 'reviewCount', kind: 'min', unit: 'reviews' },
    { id: 'positive_share', category: 'satisfaction', label: 'Positive reviews (4–5 stars)', metric: 'positiveShare', kind: 'min', unit: '%', percent: true },
    { id: 'recent_rating', category: 'satisfaction', label: 'Recent average rating', metric: 'recentRating', kind: 'min', unit: '★', decimals: 2, periodOptions: [90, 180] },
    { id: 'response_rate', category: 'responsiveness', label: 'Response rate', metric: 'responseRate', kind: 'min', unit: '%', percent: true, collected: false },
    { id: 'response_time', category: 'responsiveness', label: 'Average response time', metric: 'responseMinutes', kind: 'max', unit: 'min', collected: false },
    { id: 'enquiries', category: 'responsiveness', label: 'Eligible customer enquiries', metric: 'enquiries', kind: 'min', unit: 'enquiries', collected: false },
    { id: 'no_open_serious', category: 'trust', label: 'No unresolved serious complaints', metric: 'openSerious', kind: 'zero', help: 'Only high-severity disputes still open count. Uninvestigated reports never disqualify.' },
    { id: 'no_restriction', category: 'trust', label: 'No active disciplinary restriction', metric: 'restricted', kind: 'false' },
    { id: 'no_violations', category: 'trust', label: 'No substantiated serious violations', metric: 'violationsIn', kind: 'zero', periodOptions: [180, 365, 730], help: 'Cases resolved against the provider (for example, warned or suspended).' },
    { id: 'no_repeat_misconduct', category: 'trust', label: 'No repeated confirmed misconduct', metric: 'confirmedMisconduct', kind: 'max', unit: 'cases' },
    { id: 'no_overdue_commission', category: 'financial', label: 'No overdue platform commission', metric: 'overdueCommission', kind: 'zero', help: 'Only confirmed amounts past their due date. Disputed or unreconciled amounts don’t count.' },
    { id: 'no_debt', category: 'financial', label: 'No outstanding confirmed debt', metric: 'confirmedDebt', kind: 'zero' },
    { id: 'no_fin_restriction', category: 'financial', label: 'No financial compliance restriction', metric: 'financialRestriction', kind: 'false' },
    { id: 'verified', category: 'account', label: 'Document verification active', metric: 'documentVerified', kind: 'true' },
    { id: 'active', category: 'account', label: 'Account active', metric: 'accountActive', kind: 'true' },
    { id: 'profile_complete', category: 'account', label: 'Profile completeness', metric: 'profileComplete', kind: 'min', unit: '%' },
];
export const RULE_BY_ID = Object.fromEntries(RULE_DEFS.map(d => [d.id, d]));

/** Default published rule set (version 2). Version 1 was a little more lenient on ratings. */
export function defaultRuleSet(overrides = {}) {
    const rules = {
        completed_total:   { enabled: true, value: 10, weight: 15 },
        completed_period:  { enabled: true, value: 3, period: 90, weight: 10 },
        completion_rate:   { enabled: true, value: 0.78, weight: 10 },
        cancellation_rate: { enabled: true, value: 0.12, weight: 10 },
        tenure:            { enabled: true, value: 90, weight: 5 },
        avg_rating:        { enabled: true, value: 4.5, weight: 20 },
        review_count:      { enabled: true, value: 5, weight: 10 },
        positive_share:    { enabled: true, value: 0.8, weight: 10 },
        recent_rating:     { enabled: false, value: 4.3, period: 90, weight: 10 },
        response_rate:     { enabled: false, value: 0.85, weight: 10 },
        response_time:     { enabled: false, value: 60, weight: 10 },
        enquiries:         { enabled: false, value: 10, weight: 0 },
        no_open_serious:   { enabled: true },
        no_restriction:    { enabled: true },
        no_violations:     { enabled: true, period: 365 },
        no_repeat_misconduct: { enabled: true, value: 1 },
        no_overdue_commission: { enabled: true },
        no_debt:           { enabled: true },
        no_fin_restriction: { enabled: true },
        verified:          { enabled: true },
        active:            { enabled: true },
        profile_complete:  { enabled: true, value: 90 },
    };
    return {
        version: 2, mode: 'all_mandatory', threshold: 75, approachMargin: 0.8,
        publishedAt: new Date(Date.now() - 10 * 86400000).toISOString(), publishedBy: 'Okoth Sharon',
        note: 'Raised the minimum average rating from 4.4 to 4.5 and added the eligible-review minimum.',
        rules, ...overrides,
    };
}

export const PREVIOUS_RULESET = (() => {
    const v = defaultRuleSet({ version: 1, publishedAt: new Date(Date.now() - 120 * 86400000).toISOString(), publishedBy: 'Okoth Sharon', note: 'First blue tick rules.' });
    v.rules = structuredClone(v.rules);
    v.rules.avg_rating.value = 4.4;
    v.rules.review_count.enabled = false;
    return v;
})();

const fmt = (def, v) => {
    if (v == null) return '—';
    if (typeof v === 'boolean') return v ? 'Yes' : 'No';
    if (def.percent) return `${Math.round(v * 100)}%`;
    if (def.decimals) return v.toFixed(def.decimals);
    return `${Math.round(v * 10) / 10}`;
};

/** Human-readable requirement, e.g. "at least 25 bookings", "at most 10%" */
export function describeRule(def, cfg) {
    const v = def.percent ? `${Math.round(cfg.value * 100)}%` : `${cfg.value}${def.unit && !def.percent ? ` ${def.unit}` : ''}`;
    const period = cfg.period ? ` in the last ${cfg.period} days` : '';
    if (def.kind === 'min') return `At least ${v}${period}`;
    if (def.kind === 'max') return `At most ${v}${period}`;
    if (def.kind === 'zero') return `None${period}`;
    return 'Required';
}

/** Evaluate one provider. metrics: from reputation data; metric functions receive the rule's period. */
export function evaluate(metrics, ruleset) {
    const results = [];
    for (const def of RULE_DEFS) {
        const cfg = ruleset.rules[def.id];
        if (!cfg?.enabled) continue;
        const raw = metrics[def.metric];
        const value = typeof raw === 'function' ? raw(cfg.period) : raw;
        let pass = null;
        if (def.collected !== false && value !== undefined && value !== null) {
            if (def.kind === 'min') pass = value >= cfg.value;
            else if (def.kind === 'max') pass = value <= cfg.value;
            else if (def.kind === 'zero') pass = value === 0;
            else if (def.kind === 'false') pass = value === false;
            else if (def.kind === 'true') pass = value === true;
        }
        const standing = CATEGORIES[def.category].standing;
        // "Close": a performance minimum within the approach margin of its threshold
        const near = pass === false && !standing && def.kind === 'min' && value >= cfg.value * ruleset.approachMargin;
        results.push({
            id: def.id, label: def.label, category: def.category, standing,
            requirement: describeRule(def, cfg), value: def.collected === false ? null : value,
            display: def.collected === false ? 'Not collected yet' : fmt(def, value),
            pass, near, weight: standing ? 0 : (cfg.weight || 0),
        });
    }

    const standingFails = results.filter(r => r.standing && r.pass !== true);
    const perf = results.filter(r => !r.standing);
    const notEvaluated = results.filter(r => r.pass === null);
    let eligible;
    let score = null;
    if (ruleset.mode === 'weighted') {
        const evaluated = perf.filter(r => r.pass !== null && r.weight > 0);
        const available = evaluated.reduce((s, r) => s + r.weight, 0);
        score = available ? Math.round((evaluated.filter(r => r.pass).reduce((s, r) => s + r.weight, 0) / available) * 100) : 0;
        eligible = !standingFails.length && score >= ruleset.threshold;
    } else {
        eligible = results.every(r => r.pass === true);
    }

    // Approaching: standing is fine and the remaining gaps are small
    const perfFails = perf.filter(r => r.pass === false);
    const approaching = !eligible && !standingFails.length && (ruleset.mode === 'weighted'
        ? score >= ruleset.threshold - 15
        : perfFails.length <= 2 && perfFails.every(r => r.near) && !perf.some(r => r.pass === null));

    const reasons = [...standingFails, ...perfFails].map(r => (r.pass === null ? `${r.label}: not evaluated` : r.label));
    return {
        results, eligible, approaching, score,
        passed: results.filter(r => r.pass === true).length,
        failed: results.filter(r => r.pass === false).length,
        notEvaluated: notEvaluated.length,
        reasons,
        blockedBySerious: standingFails.some(r => r.id === 'no_open_serious') && standingFails.length === 1 && (ruleset.mode === 'weighted' ? score >= ruleset.threshold : perfFails.length === 0),
    };
}
