import { count, within, kpiCount, statusTabs, categoryOptions, categoryLabel } from './helpers.js';
import { ranking } from '../components/charts/builders.js';
import { MODES } from '../utils/blueTick.js';

const HOLDS = ['awarded', 'under_review'];
const tally = (rows) => {
    const m = new Map();
    for (const r of rows) if (r.primaryReason) m.set(r.primaryReason, (m.get(r.primaryReason) || 0) + 1);
    return [...m.entries()].map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value).slice(0, 8);
};
const override = (label) => `${label} (administrative override)`;

export default {
    /* ================= Blue tick management ================= */
    bluetick: {
        type: 'records', entity: 'Blue tick evaluation',
        description: 'Every provider is evaluated automatically against the published rules. Nobody applies for the blue tick and it can’t be bought.',
        notice: 'Automatic evaluation isn’t running yet: it needs completed bookings and response data, which aren’t recorded. Providers appear here once it runs.',
        search: { text: r => `${r.id} ${r.name} ${r.area || ''} ${categoryLabel(r.service)}`, placeholder: 'Search provider name, ID, service or area' },
        tabs: [
            ...statusTabs(['awarded', 'eligible', 'approaching', 'under_review', 'suspended', 'removed']).slice(1),
            { id: 'not_eligible', label: 'Not eligible', filter: r => r.status === 'not_eligible' },
            { id: 'all', label: 'All' },
        ],
        filters: [{ key: 'service', label: 'Service', options: categoryOptions }],
        defaultSort: { key: 'completedJobs', dir: 'desc' },
        exportable: true, exportPermission: 'reputation.view',
        kpis: (rows) => [
            kpiCount('Blue tick holders', count(rows, r => HOLDS.includes(r.status)), { hint: 'Awarded, plus holders under review who keep it until the outcome.' }),
            kpiCount('Newly awarded (30 days)', count(rows, r => r.status === 'awarded' && within(r.awardedAt, 30))),
            kpiCount('Approaching eligibility', count(rows, r => r.status === 'approaching')),
            kpiCount('No longer qualify (30 days)', count(rows, r => r.status === 'removed' && within(r.removedAt, 30)), { context: `${count(rows, r => r.status === 'suspended')} temporarily suspended` }),
        ],
        charts: (rows) => {
            const reasons = tally(rows.filter(r => ['approaching', 'removed'].includes(r.status) || (r.status === 'not_eligible' && r.completedJobs >= 5)));
            return [ranking({ id: 'reasons', title: 'Most common reasons for not qualifying', summary: reasons[0] ? `“${reasons[0].label}” is the most common blocker among active providers with a track record.` : 'No blockers.', rows: reasons, columnLabel: 'Providers', showShare: true })];
        },
        columns: [
            { key: 'name', label: 'Provider', sortable: true, sub: r => `${r.id} · ${categoryLabel(r.service)}` },
            { key: 'status', label: 'Blue tick', format: 'status', sortable: true },
            { key: 'rules', label: 'Rules', value: r => (r.score != null ? `Score ${r.score}` : `${r.evaluation.passed} of ${r.evaluation.results.length} passed`), sortValue: r => r.evaluation.passed },
            { key: 'completedJobs', label: 'Completed', format: 'number', align: 'right', sortable: true },
            { key: 'rating', label: 'Rating', align: 'right', sortable: true, value: r => (r.rating ? `${r.rating} (${r.reviewCount})` : null), sortValue: r => r.rating },
            { key: 'primaryReason', label: 'Main reason', hideBelow: 'lg', value: r => r.primaryReason },
            { key: 'evaluatedAt', label: 'Evaluated', format: 'relative', hideBelow: 'lg' },
        ],
        detail: {
            title: r => r.name,
            sections: (r) => [
                { title: 'Status', fields: [
                    ['Blue tick', r.status, 'status'], ['Rules version', `v${r.ruleVersion}`], ['Mode', MODES[r.evaluation.score != null ? 'weighted' : 'all_mandatory'].label],
                    ...(r.score != null ? [['Score', `${r.score}`]] : []),
                    ['Rules passed', `${r.evaluation.passed} of ${r.evaluation.results.length}`], ['Not evaluated', r.evaluation.notEvaluated, 'number'],
                    ['Evaluated', r.evaluatedAt, 'date'], ['Awarded', r.awardedAt, 'date'], ['Removed', r.removedAt, 'date'],
                    ['Reason', r.evaluation.eligible ? 'Meets every requirement' : r.evaluation.reasons.join('; ')],
                ] },
                { title: 'Rule by rule', table: { limit: 30, columns: [
                    { key: 'label', label: 'Rule' }, { key: 'requirement', label: 'Needs' }, { key: 'display', label: 'Provider' }, { key: 'result', label: 'Result', format: 'status' },
                ], rows: r.evaluation.results.map(x => ({ ...x, result: x.pass === true ? 'pass' : x.pass === false ? 'fail' : 'not_evaluated' })) } },
                ...(r.debts.length ? [{ title: 'Platform balances (evidence)', permission: 'finance.summary', table: { columns: [
                    { key: 'amount', label: 'Amount', format: 'money' }, { key: 'status', label: 'Status' }, { key: 'dueAt', label: 'Due', format: 'date' },
                ], rows: r.debts.map(d => ({ ...d, status: d.status.replace(/_/g, ' ') })) } }] : []),
                { note: 'Only confirmed, overdue amounts count against a provider. Uninvestigated reports never disqualify anyone; only substantiated outcomes do.' },
                { title: 'Lifecycle', timeline: true },
            ],
        },
        actions: [
            { id: 'reassess', label: 'Reassess now', done: 'Reassessed', icon: 'bi-arrow-repeat', permission: 'reputation.view',
              when: r => r.status !== 'suspended',
              message: () => 'Re-runs the automatic evaluation with the published rules.',
              apply: (r) => {
                  const ev = r.evaluation;
                  const held = HOLDS.includes(r.status);
                  const status = ev.eligible ? 'awarded' : held && ev.blockedBySerious ? 'under_review' : held ? 'removed' : ev.approaching ? 'approaching' : 'not_eligible';
                  return { patch: { status, evaluatedAt: new Date().toISOString(), ...(status === 'awarded' && !held ? { awardedAt: new Date().toISOString() } : {}) },
                      detail: ev.eligible ? 'Meets every requirement' : `Not met: ${ev.reasons.join(', ')}` };
              } },
            { id: 'suspend', label: 'Suspend recognition', done: override('Recognition suspended'), tone: 'danger', permission: 'bluetick.override', reason: 'required',
              when: r => ['awarded', 'under_review', 'eligible'].includes(r.status),
              message: () => 'Hides the blue tick until restored. The automatic evaluation won’t lift a suspension.',
              apply: () => ({ patch: { status: 'suspended' } }) },
            { id: 'restore', label: 'Restore', done: override('Recognition restored'), tone: 'primary', permission: 'bluetick.override', reason: 'required',
              when: r => r.status === 'suspended',
              apply: (r) => ({ patch: { status: r.evaluation.eligible ? 'awarded' : 'removed' }, detail: r.evaluation.eligible ? 'Still meets the rules' : 'No longer meets the rules, so it stays off' }) },
            { id: 'remove', label: 'Remove blue tick', done: override('Blue tick removed'), tone: 'danger', permission: 'bluetick.override', reason: 'required',
              when: r => HOLDS.includes(r.status),
              apply: () => ({ patch: { status: 'removed', removedAt: new Date().toISOString() } }) },
            { id: 'award', label: 'Award by override', done: override('Blue tick awarded'), permission: 'bluetick.override', reason: 'required',
              when: r => !HOLDS.includes(r.status) && r.status !== 'suspended' && r.providerStatus === 'active',
              message: r => (r.evaluation.eligible ? '' : `${r.name} doesn’t meet the rules: ${r.evaluation.reasons.slice(0, 3).join(', ')}. Overrides are exceptional and fully audited.`),
              apply: () => ({ patch: { status: 'awarded', awardedAt: new Date().toISOString() } }) },
        ],
    },
};
