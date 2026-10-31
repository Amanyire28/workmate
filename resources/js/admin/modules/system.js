import { count, within, kpiCount, statusOptions, statusTabs, setStatus, historySection } from './helpers.js';

const SAMPLE = 'No monitoring source is connected yet, so nothing is recorded here.';

export default {
    /* ================= System health ================= */
    health: {
        type: 'records', entity: 'Component', statusKey: 'state',
        notice: SAMPLE, noticeTone: 'warning',
        search: { keys: ['name', 'description'], placeholder: 'Search components' },
        defaultSort: { key: 'name', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Components', rows.length),
            kpiCount('Sample degraded', count(rows, r => r.state === 'degraded')),
            kpiCount('Not connected', count(rows, r => r.state === 'not_connected')),
            { label: 'Live monitoring', value: 'Not connected' },
        ],
        columns: [
            { key: 'name', label: 'Component', sortable: true, sub: r => r.description },
            { key: 'state', label: 'State', format: 'status', sortable: true },
            { key: 'latencyMs', label: 'Sample latency', align: 'right', value: r => (r.latencyMs == null ? null : `${r.latencyMs} ms`) },
            { key: 'checkedAt', label: 'Sample checked', format: 'relative' },
        ],
        detail: { sections: (r) => [{ title: 'Component', fields: [['Description', r.description], ['State', r.state, 'status'], ['Sample latency', r.latencyMs == null ? null : `${r.latencyMs} ms`], ['Sample checked', r.checkedAt, 'date']] }, { note: SAMPLE }] },
    },

    /* ================= Error monitoring ================= */
    errors: {
        type: 'records', entity: 'Error group',
        notice: 'Sample error groups. Only grouped messages are shown: never stack traces with secrets, tokens, passwords or personal data.', noticeTone: 'warning',
        search: { keys: ['id', 'message', 'service'], placeholder: 'Search errors' },
        tabs: statusTabs(['open', 'ignored', 'resolved']),
        filters: [{ key: 'severity', label: 'Severity', options: statusOptions(['critical', 'warning', 'info']) }, { key: 'service', label: 'Service', options: ['media', 'notifications', 'payments', 'search', 'uploads', 'queue'].map(s => [s, s]) }],
        defaultSort: { key: 'lastSeenAt', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Open error groups', count(rows, r => r.status === 'open')),
            kpiCount('Critical and open', count(rows, r => r.status === 'open' && r.severity === 'critical')),
            kpiCount('Occurrences (sample)', rows.reduce((s, r) => s + r.count, 0)),
            kpiCount('Seen in last hour', count(rows, r => within(r.lastSeenAt, 1 / 24))),
        ],
        columns: [
            { key: 'message', label: 'Error', sortable: true, sub: r => `${r.id} · ${r.service}` },
            { key: 'severity', label: 'Severity', format: 'status', sortable: true },
            { key: 'count', label: 'Occurrences', format: 'number', align: 'right', sortable: true },
            { key: 'lastSeenAt', label: 'Last seen', format: 'relative', sortable: true },
            { key: 'status', label: 'Status', format: 'status' },
        ],
        detail: { sections: (r) => [{ title: 'Error group', fields: [['Message', r.message], ['Service', r.service], ['Severity', r.severity, 'status'], ['Occurrences', r.count, 'number'], ['First seen', r.firstSeenAt, 'date'], ['Last seen', r.lastSeenAt, 'date']] }, historySection] },
        actions: [
            setStatus('resolve', 'Mark resolved', 'resolved', { tone: 'primary', reason: 'required', reasonLabel: 'Fix or release reference', permission: 'system.view', done: 'Resolved', when: r => r.status !== 'resolved' }),
            setStatus('ignore', 'Ignore', 'ignored', { reason: 'required', permission: 'system.view', done: 'Ignored', when: r => r.status === 'open' }),
            setStatus('reopen', 'Reopen', 'open', { permission: 'system.view', done: 'Reopened', when: r => r.status !== 'open' }),
        ],
    },

    /* ================= Integrations ================= */
    integrations: {
        type: 'records', entity: 'Integration', statusKey: 'state',
        notice: 'None of these services are connected yet. Each needs credentials stored server-side; they are never shown or entered in the admin UI.', noticeTone: 'warning',
        search: { keys: ['name', 'kind'], placeholder: 'Search integrations' },
        filters: [{ key: 'kind', label: 'Kind', options: [['Payments', 'Payments'], ['Communications', 'Communications'], ['Media', 'Media'], ['Monitoring', 'Monitoring']] }],
        defaultSort: { key: 'kind', dir: 'asc' },
        columns: [
            { key: 'name', label: 'Service', sortable: true },
            { key: 'kind', label: 'Used for', sortable: true },
            { key: 'state', label: 'State', format: 'status' },
            { key: 'lastSyncAt', label: 'Last activity', format: 'relative', value: r => r.lastSyncAt },
        ],
        detail: { sections: (r) => [{ title: 'Integration', fields: [['Used for', r.kind], ['State', r.state, 'status'], ['Last activity', r.lastSyncAt || 'Never']] }, { note: 'Connecting this is a backend task: credentials go in server configuration, never in the browser.' }] },
    },

    /* ================= Background jobs ================= */
    queues: {
        type: 'records', entity: 'Failed job',
        notice: SAMPLE, noticeTone: 'warning',
        search: { keys: ['id', 'job', 'queue', 'error'], placeholder: 'Search failed jobs' },
        tabs: [{ id: 'failed', label: 'Failed', filter: r => r.status === 'failed' }, { id: 'retrying', label: 'Queued for retry', filter: r => r.status === 'pending' }, { id: 'all', label: 'All' }],
        filters: [{ key: 'queue', label: 'Queue', options: ['media', 'notifications', 'payments', 'default'].map(q => [q, q]) }],
        defaultSort: { key: 'failedAt', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Failed jobs', count(rows, r => r.status === 'failed')),
            kpiCount('Media queue', count(rows, r => r.status === 'failed' && r.queue === 'media')),
            kpiCount('Payments queue', count(rows, r => r.status === 'failed' && r.queue === 'payments')),
            kpiCount('Queued for retry', count(rows, r => r.status === 'pending')),
        ],
        columns: [
            { key: 'job', label: 'Job', sortable: true, sub: r => `${r.id} · ${r.queue}` },
            { key: 'attempts', label: 'Attempts', format: 'number', align: 'right', sortable: true },
            { key: 'error', label: 'Error' },
            { key: 'failedAt', label: 'Failed', format: 'relative', sortable: true },
            { key: 'status', label: 'Status', format: 'status' },
        ],
        detail: { sections: (r) => [{ title: 'Job', fields: [['Job', r.job], ['Queue', r.queue], ['Attempts', r.attempts, 'number'], ['Error', r.error], ['Failed', r.failedAt, 'date']] }, historySection] },
        actions: [
            setStatus('retry', 'Retry', 'pending', { tone: 'primary', permission: 'system.view', done: 'Queued for retry', when: r => r.status === 'failed', message: () => 'There is no job queue connected yet.' }),
            setStatus('discard', 'Discard', 'removed', { tone: 'danger', reason: 'required', permission: 'system.view', done: 'Discarded', when: r => r.status === 'failed' }),
        ],
    },

    /* ================= Scheduled tasks ================= */
    scheduled: {
        type: 'records', entity: 'Scheduled task', statusKey: 'outcome',
        notice: SAMPLE, noticeTone: 'warning',
        search: { keys: ['name', 'schedule'], placeholder: 'Search tasks' },
        defaultSort: { key: 'nextRunAt', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Tasks', rows.length),
            kpiCount('Last run failed', count(rows, r => r.outcome === 'failure')),
            kpiCount('Paused', count(rows, r => !r.enabled)),
            { label: 'Scheduler', value: 'Not connected' },
        ],
        columns: [
            { key: 'name', label: 'Task', sortable: true, sub: r => r.schedule },
            { key: 'lastRunAt', label: 'Last run', format: 'relative', sortable: true },
            { key: 'outcome', label: 'Outcome', format: 'status' },
            { key: 'durationSec', label: 'Duration', align: 'right', value: r => `${r.durationSec} s` },
            { key: 'nextRunAt', label: 'Next run', format: 'date', sortable: true, hideBelow: 'lg' },
            { key: 'enabled', label: 'Enabled', format: 'bool' },
        ],
        detail: { sections: (r) => [{ title: 'Task', fields: [['Schedule', r.schedule], ['Last run', r.lastRunAt, 'date'], ['Outcome', r.outcome, 'status'], ['Duration', `${r.durationSec} s`], ['Next run', r.nextRunAt, 'date'], ['Enabled', r.enabled, 'bool']] }, historySection] },
        actions: [
            { id: 'pause', label: 'Pause', done: 'Paused', tone: 'danger', reason: 'required', permission: 'system.view', when: r => r.enabled, apply: () => ({ patch: { enabled: false } }) },
            { id: 'resume', label: 'Resume', done: 'Resumed', tone: 'primary', permission: 'system.view', when: r => !r.enabled, apply: () => ({ patch: { enabled: true } }) },
        ],
    },

    /* ================= Audit logs ================= */
    audit: {
        type: 'records', entity: 'Audit entry', statusKey: false,
        description: 'Every administrator action: approvals, suspensions, refunds, moderation, configuration and role changes. Entries can’t be edited or deleted.',
        notice: 'Every change made through WorkMate is recorded here by the server, with who made it and why. Entries can’t be edited or deleted.',
        search: { keys: ['id', 'summary', 'actor', 'target', 'reason', 'action'], placeholder: 'Search action, admin, record or reason' },
        tabs: [{ id: 'all', label: 'All' }, { id: 'session', label: 'This session', filter: r => r.source === 'session' }],
        period: { key: 'at', label: 'When' },
        defaultSort: { key: 'at', dir: 'desc' },
        exportable: true, exportPermission: 'security.view',
        columns: [
            { key: 'at', label: 'When', format: 'relative', sortable: true },
            { key: 'summary', label: 'Action', sub: r => r.action },
            { key: 'actor', label: 'Administrator', sortable: true },
            { key: 'target', label: 'Record' },
            { key: 'reason', label: 'Reason', hideBelow: 'lg' },
            { key: 'source', label: 'Source', value: r => (r.source === 'session' ? 'This session' : 'Demo') },
        ],
        detail: {
            title: r => r.summary,
            sections: (r) => [
                { title: 'Entry', fields: [['Action', r.action], ['Administrator', r.actor], ['Record', r.target], ['Reason', r.reason], ['When', r.at, 'date'], ['From', r.ip], ['Entry ID', r.id]] },
                { note: 'Audit entries are append-only. In production they need their own storage, retention period and access separate from the operational event log.' },
            ],
        },
    },

    /* ================= Security events ================= */
    security: {
        type: 'records', entity: 'Security event', statusKey: 'severity',
        notice: 'Sample events. IP addresses are partly masked.',
        search: { keys: ['id', 'type', 'account', 'location'], placeholder: 'Search events' },
        filters: [
            { key: 'type', label: 'Type', options: ['Failed admin sign-in', 'Account locked', 'New admin device', 'Password reset requested', 'Role changed'].map(t => [t, t]) },
            { key: 'severity', label: 'Severity', options: statusOptions(['warning', 'info']) },
        ],
        period: { key: 'at', label: 'When' },
        defaultSort: { key: 'at', dir: 'desc' },
        exportable: true, exportPermission: 'security.view',
        kpis: (rows) => [
            kpiCount('Failed sign-ins (7 days)', count(rows, r => r.type === 'Failed admin sign-in' && within(r.at, 7))),
            kpiCount('Accounts locked (7 days)', count(rows, r => r.type === 'Account locked' && within(r.at, 7))),
            kpiCount('New devices (7 days)', count(rows, r => r.type === 'New admin device' && within(r.at, 7))),
            kpiCount('Role changes (30 days)', count(rows, r => r.type === 'Role changed' && within(r.at, 30))),
        ],
        columns: [
            { key: 'type', label: 'Event', sortable: true, sub: r => r.id },
            { key: 'account', label: 'Admin account', sortable: true },
            { key: 'severity', label: 'Severity', format: 'status' },
            { key: 'ip', label: 'IP address', hideBelow: 'lg' },
            { key: 'location', label: 'Approx. location' },
            { key: 'at', label: 'When', format: 'relative', sortable: true },
        ],
        detail: { sections: (r) => [{ title: 'Event', fields: [['Type', r.type], ['Admin account', r.account], ['Severity', r.severity, 'status'], ['IP address', r.ip], ['Approximate location', r.location], ['When', r.at, 'date']] }] },
    },
};
