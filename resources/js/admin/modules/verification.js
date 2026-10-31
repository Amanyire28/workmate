import { count, within, kpiCount, statusTabs, categoryOptions, me } from './helpers.js';

const OPEN = ['pending_review', 'under_review', 'info_requested'];
const docSummary = (r) => {
    const required = r.documents.filter(d => d.requirement === 'required');
    const accepted = required.filter(d => d.status === 'accepted').length;
    const submitted = r.documents.filter(d => d.files.length).length;
    return `${accepted} of ${required.length} required accepted · ${submitted} submitted`;
};

export default {
    /* ================= Verification applications ================= */
    'verification-applications': {
        type: 'records', entity: 'Verification application',
        description: 'Document verification for the WorkMate Verified badge. Each application opens in a review workspace; nothing is approved automatically.',
        rowLink: r => `/admin/verification/applications/${r.id}`,
        search: { keys: ['id', 'providerName', 'providerId', 'area'], placeholder: 'Search reference, provider name or ID, area' },
        tabs: [
            { id: 'review', label: 'Needs review', filter: r => r.status === 'pending_review' || r.status === 'under_review' },
            ...statusTabs(['pending_review', 'under_review', 'info_requested', 'approved', 'rejected', 'expired']).slice(1),
            { id: 'all', label: 'All' },
        ],
        filters: [
            { key: 'profession', label: 'Profession', options: categoryOptions },
            { key: 'reviewer', label: 'Reviewer', options: [['none', 'Unassigned'], ['me', 'Assigned to me']], match: (r, v) => (v === 'none' ? !r.reviewer : r.reviewer === me()) },
            { key: 'source', label: 'Source', options: [['provider_session', 'Submitted in this session']], match: (r, v) => r.source === v },
        ],
        period: { key: 'submittedAt', label: 'Submitted' },
        defaultSort: { key: 'submittedAt', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Awaiting review', count(rows, r => r.status === 'pending_review' || r.status === 'under_review')),
            kpiCount('Documents to check', rows.filter(r => OPEN.includes(r.status)).reduce((n, r) => n + r.documents.filter(d => d.status === 'awaiting_review').length, 0)),
            kpiCount('More information needed', count(rows, r => r.status === 'info_requested')),
            kpiCount('Verified providers', count(rows, r => r.status === 'approved'), { context: `${count(rows, r => r.status === 'approved' && within(r.lastActionAt, 30))} approved in 30 days` }),
        ],
        columns: [
            { key: 'id', label: 'Reference', sortable: true, sub: r => (r.source === 'provider_session' ? 'Submitted in this session' : `Requirements v${r.snapshot.version}`) },
            { key: 'providerName', label: 'Provider', sortable: true, sub: r => r.area },
            { key: 'profession', label: 'Profession', format: 'category', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'documents', label: 'Documents', value: docSummary, hideBelow: 'lg' },
            { key: 'reviewer', label: 'Reviewer', sortable: true, value: r => r.reviewer || null },
            { key: 'submittedAt', label: 'Submitted', format: 'relative', sortable: true },
            { key: 'lastActionAt', label: 'Last action', format: 'relative', sortable: true, hideBelow: 'lg' },
        ],
        exportable: true, exportPermission: 'documents.review',
    },
};

