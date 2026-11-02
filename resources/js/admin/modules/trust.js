import { count, sum, within, kpiCount, statusOptions, statusTabs, setStatus, historySection, categoryLabel, me } from './helpers.js';

const OPEN = ['new', 'under_review', 'awaiting_information', 'escalated'];
const severityOrder = { high: 0, medium: 1, low: 2 };
const REPORT_OPEN = ['new', 'under_review', 'info_requested'];

/** Shared case workflow: assign → review → (awaiting info | escalated) → resolved → closed */
function caseActions(resolutions) {
    return [
        { id: 'assign', label: 'Take for review', done: 'Taken for review', permission: 'trust.view', icon: 'bi-person-check', tone: 'primary',
          when: r => r.status === 'new',
          apply: () => ({ patch: { status: 'under_review' } }) },
        setStatus('request-info', 'Request information', 'awaiting_information', { reason: 'required', reasonLabel: 'What is needed, and from whom?', permission: 'trust.view', done: 'Information requested', when: r => r.status === 'new' || r.status === 'under_review' }),
        setStatus('escalate', 'Escalate', 'escalated', { reason: 'required', permission: 'trust.view', done: 'Escalated', when: r => ['new', 'under_review', 'awaiting_information'].includes(r.status) }),
        { id: 'resolve', label: 'Resolve', done: 'Resolved', permission: 'trust.view', tone: 'primary', icon: 'bi-check2-circle',
          when: r => OPEN.includes(r.status),
          fields: () => [
              { key: 'resolution', label: 'Outcome', type: 'select', options: resolutions.map(x => [x, x]) },
              { key: 'reason', label: 'Reason', type: 'textarea', required: true, minLength: 5, help: 'Shared with the people involved once messaging is connected.' },
          ],
          apply: (r, v) => ({ patch: { status: 'resolved', resolution: v.resolution, resolvedAt: new Date().toISOString() }, detail: `Outcome: ${v.resolution}` }) },
        setStatus('close', 'Close case', 'closed', { reason: 'optional', permission: 'trust.view', done: 'Closed', when: r => r.status === 'resolved' }),
        { id: 'note', label: 'Add internal note', done: 'Internal note', permission: 'trust.view', icon: 'bi-sticky',
          fields: () => [{ key: 'reason', label: 'Note (staff only)', type: 'textarea', required: true, minLength: 3 }], apply: () => ({ patch: {} }) },
    ];
}

const caseKpis = (rows, dateKey) => {
    const open = rows.filter(r => OPEN.includes(r.status));
    const resolved = rows.filter(r => r.resolvedAt && within(r.resolvedAt, 30));
    const avg = resolved.length ? sum(resolved, r => (new Date(r.resolvedAt) - new Date(r[dateKey])) / 86400000) / resolved.length : null;
    return [
        kpiCount('Open cases', open.length),
        kpiCount('High severity, open', count(open, r => r.severity === 'high')),
        kpiCount('Unassigned', count(open, r => !r.assignedTo)),
        { label: 'Average time to resolve (30 days)', value: avg == null ? '—' : `${avg.toFixed(1)} days` },
    ];
};

export default {
    /* ================= Disputes ================= */
    disputes: {
        type: 'records', entity: 'Dispute',
        search: { keys: ['id', 'bookingId', 'customerId', 'providerId', 'category', 'assignedTo'], placeholder: 'Search case, booking, customer or provider ID' },
        tabs: [{ id: 'open', label: 'Open', filter: r => OPEN.includes(r.status) }, ...statusTabs(['new', 'under_review', 'awaiting_information', 'escalated', 'resolved', 'closed'])],
        filters: [
            { key: 'severity', label: 'Severity', options: statusOptions(['high', 'medium', 'low']) },
            { key: 'category', label: 'Category', options: ['Work quality', 'No-show', 'Pricing', 'Conduct', 'Payment', 'Damage'].map(c => [c, c]) },
            { key: 'assignee', label: 'Assignee', options: [['none', 'Unassigned'], ['me', 'Assigned to me']], match: (r, v) => (v === 'none' ? !r.assignedTo : r.assignedTo === me()) },
        ],
        period: { key: 'openedAt', label: 'Opened' },
        defaultSort: { key: 'severity', dir: 'asc' },
        kpis: (rows) => caseKpis(rows, 'openedAt'),
        columns: [
            { key: 'id', label: 'Case', sortable: true, sub: r => `${r.category} · ${r.bookingId}` },
            { key: 'severity', label: 'Severity', format: 'status', sortable: true, sortValue: r => severityOrder[r.severity] },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'assignedTo', label: 'Assigned to', sortable: true, hideBelow: 'lg' },
            { key: 'openedAt', label: 'Opened', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => `${r.category} dispute ${r.id}`,
            sections: (r, { rel }) => {
                const R = rel();
                const b = R.booking(r.bookingId);
                return [
                    { title: 'Case', fields: [
                        ['Severity', r.severity, 'status'], ['Category', r.category], ['Opened', r.openedAt, 'date'], ['Assigned to', r.assignedTo || 'Unassigned'],
                        ['Resolution', r.resolution], ['Resolved', r.resolvedAt, 'date'],
                    ] },
                    { title: 'People and booking', fields: [
                        ['Customer', `${R.nameOf(r.customerId)} (${r.customerId})`], ['Provider', `${R.nameOf(r.providerId)} (${r.providerId})`],
                        ['Booking', b ? `${b.id}: ${categoryLabel(b.service)}, ${b.area}` : r.bookingId], ['Agreed price', b?.price, 'money'],
                    ] },
                    { title: 'Evidence', permission: 'trust.view', fields: [['Files attached', `${r.evidence} (attachments aren’t built yet)`]] },
                    { title: 'Internal notes and actions', permission: 'trust.view', timeline: true },
                ];
            },
        },
        actions: caseActions(['Partial refund to customer', 'Full refund to customer', 'Provider redid the work', 'Provider warned', 'No action needed']),
    },

    /* ================= Reports and abuse ================= */
    reports: {
        type: 'records', entity: 'Report',
        description: 'Reports and complaints from customers and providers about each other, or anything else that went wrong. The reported person is never told who reported them.',
        search: { keys: ['id', 'type', 'subjectName', 'subjectId', 'reporterName', 'jobTitle'], placeholder: 'Search report, person, job or ID' },
        tabs: [
            { id: 'open', label: 'Open', filter: r => REPORT_OPEN.includes(r.status) },
            ...statusTabs(['new', 'under_review', 'info_requested', 'resolved', 'closed']),
        ],
        filters: [
            { key: 'target', label: 'About', options: [['provider', 'A provider'], ['customer', 'A customer'], ['general', 'Something else']] },
            { key: 'severity', label: 'Severity', options: statusOptions(['high', 'medium', 'low']) },
            { key: 'reporterType', label: 'Reported by', options: [['Customer', 'A customer'], ['Provider', 'A provider']] },
        ],
        period: { key: 'submittedAt', label: 'Submitted' },
        defaultSort: { key: 'submittedAt', dir: 'desc' },
        kpis: (rows) => {
            const open = rows.filter(r => REPORT_OPEN.includes(r.status));
            return [
                kpiCount('Open reports', open.length),
                kpiCount('High severity, open', count(open, r => r.severity === 'high')),
                kpiCount('Waiting for the reporter', count(rows, r => r.status === 'info_requested')),
                kpiCount('Unassigned', count(open, r => !r.assignedTo)),
            ];
        },
        columns: [
            { key: 'type', label: 'Report', sortable: true, sub: r => `${r.id} · by a ${r.reporterType.toLowerCase()}` },
            { key: 'subjectName', label: 'About', sortable: true, sub: r => r.jobTitle || (r.subjectId || '') },
            { key: 'severity', label: 'Severity', format: 'status', sortable: true, sortValue: r => severityOrder[r.severity] },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'submittedAt', label: 'Submitted', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => `${r.type}: ${r.subjectName}`,
            sections: (r) => [
                { title: 'Report', fields: [['What happened', r.type], ['About', r.subjectId ? `${r.subjectName} (${r.subjectId})` : r.subjectName],
                    ['Reported by', `${r.reporterName} (${r.reporterType.toLowerCase()}${r.reporterId ? `, ${r.reporterId}` : ''})`], ['Job', r.jobTitle],
                    ['Severity', r.severity, 'status'], ['Submitted', r.submittedAt, 'date'], ['Assigned to', r.assignedTo || 'Unassigned']] },
                { title: 'Their account', text: r.details || 'No details given.' },
                { title: `Evidence photos (${r.evidence})`, permission: 'trust.view',
                  files: (r.photos || []).map(f => ({ label: f.name, href: `/api/v1/admin/reports/${r.reportId}/files/${f.index}` })) },
                ...(r.question ? [{ title: 'Question to the reporter', fields: [['Asked', r.question], ['Their answer', r.reporterReply || 'Not answered yet']] }] : []),
                ...(r.outcome ? [{ title: 'Outcome', fields: [['Result', r.resolution], ['Told the reporter', r.outcomeMessage], ['Resolved', r.resolvedAt, 'date']] }] : []),
                historySection,
            ],
        },
        actions: [
            { id: 'assign', label: 'Take this report', done: 'Taken', permission: 'trust.view', icon: 'bi-person-check', tone: 'primary',
              when: r => REPORT_OPEN.includes(r.status) && !r.assignedTo },
            { id: 'request-info', label: 'Ask the reporter', done: 'Question sent', permission: 'trust.view', icon: 'bi-chat-left-text',
              when: r => r.status === 'new' || r.status === 'under_review',
              message: () => 'The reporter sees your question in their account and gets a notification. The reported person isn’t told.',
              fields: () => [{ key: 'question', label: 'Your question', type: 'textarea', required: true, minLength: 5, maxLength: 1000 }] },
            { id: 'resolve', label: 'Resolve', done: 'Resolved', permission: 'trust.view', icon: 'bi-check2-circle', tone: 'primary',
              when: r => REPORT_OPEN.includes(r.status),
              message: (r) => `The action is carried out straight away. ${r.canWarn ? 'A warning is shown to the reported person, never naming the reporter.' : 'This report isn’t about a particular person, so only “No violation” or “Resolved” apply.'}`,
              fields: (r) => [
                  { key: 'outcome', label: 'Outcome', type: 'select', required: true, options: [
                      ['no_violation', 'No violation found'],
                      ['resolved', 'Resolved (no action on an account)'],
                      ...(r.canWarn ? [['warned', 'Send the reported person a warning']] : []),
                      ...(r.canSuspendProvider ? [['provider_suspended', 'Suspend the provider listing']] : []),
                      ...(r.canWarn ? [['account_suspended', 'Suspend the reported person’s account']] : []),
                  ] },
                  { key: 'message', label: 'What the reporter is told', type: 'textarea', required: true, minLength: 5, maxLength: 1000, help: 'Shown in their account. Don’t include anything private about the other person.' },
                  ...(r.canWarn ? [{ key: 'warning', label: 'Warning for the reported person (only if sending a warning)', type: 'textarea', maxLength: 1000, help: 'Shown to them in WorkMate. Never mention who reported them.' }] : []),
                  { key: 'reason', label: 'Reason (for the audit log)', type: 'textarea', required: true, minLength: 5 },
              ] },
            { id: 'note', label: 'Add internal note', done: 'Note added', permission: 'trust.view', icon: 'bi-sticky',
              when: r => REPORT_OPEN.includes(r.status),
              fields: () => [{ key: 'note', label: 'Note (safety team only)', type: 'textarea', required: true, minLength: 3 }] },
        ],
    },

    /* ================= Content moderation ================= */
    moderation: {
        type: 'records', entity: 'Moderation item',
        description: 'One queue for reported reviews, new portfolio images and new videos.',
        search: { keys: ['id', 'title', 'owner', 'ownerId'], placeholder: 'Search item, owner or ID' },
        tabs: statusTabs(['pending', 'approved', 'removed']),
        filters: [{ key: 'kind', label: 'Kind', options: [['Review', 'Reviews'], ['Portfolio image', 'Portfolio images'], ['Video', 'Videos']] }],
        defaultSort: { key: 'submittedAt', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Waiting', count(rows, r => r.status === 'pending')),
            kpiCount('Reported reviews', count(rows, r => r.status === 'pending' && r.kind === 'Review')),
            kpiCount('New images', count(rows, r => r.status === 'pending' && r.kind === 'Portfolio image')),
            kpiCount('New videos', count(rows, r => r.status === 'pending' && r.kind === 'Video'), { to: '/admin/content/video-moderation' }),
        ],
        columns: [
            { key: 'title', label: 'Item', sub: r => `${r.kind} · ${r.id}` },
            { key: 'owner', label: 'Owner', sortable: true },
            { key: 'reason', label: 'Why it’s here' },
            { key: 'status', label: 'Status', format: 'status' },
            { key: 'submittedAt', label: 'Waiting since', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: r.kind, fields: [['Content', r.title], ['Owner', `${r.owner} (${r.ownerId})`], ['Why it’s here', r.reason], ['Waiting since', r.submittedAt, 'date']] },
                historySection,
            ],
        },
        actions: [
            setStatus('approve', 'Approve', 'approved', { tone: 'primary', permission: 'content.view', done: 'Approved', when: r => r.status === 'pending' }),
            setStatus('remove', 'Remove', 'removed', { tone: 'danger', reason: 'required', permission: 'content.view', done: 'Removed', when: r => r.status === 'pending' }),
        ],
    },

    /* ================= Restricted accounts ================= */
    restricted: {
        type: 'records', entity: 'Restricted account',
        search: { keys: ['id', 'name', 'area', 'reason'], placeholder: 'Search name or ID' },
        tabs: [{ id: 'all', label: 'All' }, ...statusTabs(['suspended', 'restricted', 'deactivated']).slice(1)],
        filters: [{ key: 'kind', label: 'Account type', options: [['Customer', 'Customers'], ['Provider', 'Providers']] }, { key: 'appeal', label: 'Appeal', options: [['Appeal submitted', 'Appeal submitted'], ['None', 'No appeal']] }],
        defaultSort: { key: 'since', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Suspended', count(rows, r => r.status === 'suspended')),
            kpiCount('Restricted', count(rows, r => r.status === 'restricted')),
            kpiCount('Deactivated', count(rows, r => r.status === 'deactivated')),
            kpiCount('Appeals waiting', count(rows, r => r.appeal === 'Appeal submitted')),
        ],
        columns: [
            { key: 'name', label: 'Account', sortable: true, sub: r => `${r.id} · ${r.kind}` },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'reason', label: 'Reason' },
            { key: 'since', label: 'Since', format: 'relative', sortable: true },
            { key: 'appeal', label: 'Appeal', hideBelow: 'lg' },
        ],
        detail: {
            sections: (r) => [
                { title: 'Restriction', fields: [['Account', `${r.name} (${r.id})`], ['Type', r.kind], ['Status', r.status, 'status'], ['Reason', r.reason], ['Since', r.since, 'date'], ['Expires', r.expires, 'date'], ['Appeal', r.appeal]] },
                historySection,
            ],
        },
        actions: [
            setStatus('lift', 'Lift restriction', 'active', { tone: 'primary', reason: 'required', permission: 'trust.view', done: 'Restriction lifted', when: r => r.status !== 'active' && r.status !== 'deactivated' }),
            setStatus('reinstate', 'Reinstate account', 'active', { reason: 'required', permission: 'admin.manage', done: 'Reinstated', when: r => r.status === 'deactivated' }),
        ],
    },
};
