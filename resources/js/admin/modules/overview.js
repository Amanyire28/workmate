import { count, kpiCount, statusOptions, statusTabs, setStatus, historySection } from './helpers.js';

export default {
    alerts: {
        type: 'records', entity: 'Operational alert',
        description: 'Issues raised by WorkMate’s checks that need an administrator: acknowledge them, then resolve them where they’re handled.',
        search: { keys: ['id', 'title', 'detail', 'area'], placeholder: 'Search alerts' },
        tabs: [
            { id: 'active', label: 'Active', filter: r => r.status !== 'resolved' },
            ...statusTabs(['open', 'acknowledged', 'resolved']).slice(1),
            { id: 'all', label: 'All' },
        ],
        filters: [
            { key: 'severity', label: 'Severity', options: statusOptions(['critical', 'warning', 'info']) },
            { key: 'area', label: 'Area', options: [['system', 'System'], ['trust', 'Trust and safety'], ['providers', 'Providers'], ['finance', 'Finance'], ['marketplace', 'Marketplace'], ['notifications', 'Notifications'], ['location', 'Location'], ['content', 'Content']] },
        ],
        defaultSort: { key: 'severity', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Open', count(rows, r => r.status === 'open')),
            kpiCount('Critical and open', count(rows, r => r.severity === 'critical' && r.status !== 'resolved')),
            kpiCount('Acknowledged', count(rows, r => r.status === 'acknowledged')),
            kpiCount('Resolved', count(rows, r => r.status === 'resolved')),
        ],
        columns: [
            { key: 'title', label: 'Alert', sortable: true, sub: r => r.detail },
            { key: 'severity', label: 'Severity', format: 'status', sortable: true, sortValue: r => ({ critical: 0, warning: 1, info: 2 })[r.severity] },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'raisedAt', label: 'Raised', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Alert', fields: [['Severity', r.severity, 'status'], ['Detail', r.detail], ['Raised', r.raisedAt, 'date'], ['Acknowledged by', r.acknowledgedBy || 'Nobody yet']] },
                { link: { to: r.to, label: 'Open the page where this is handled' } },
                historySection,
            ],
        },
        actions: [
            setStatus('ack', 'Acknowledge', 'acknowledged', { tone: 'primary', permission: 'dashboard.view', done: 'Acknowledged', when: r => r.status === 'open', extra: () => ({ acknowledgedBy: 'Administrator' }) }),
            setStatus('resolve', 'Resolve', 'resolved', { reason: 'required', reasonLabel: 'How was it resolved?', permission: 'dashboard.view', done: 'Resolved', when: r => r.status !== 'resolved' }),
        ],
    },
};
