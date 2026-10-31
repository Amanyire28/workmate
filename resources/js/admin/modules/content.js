import { count, within, kpiCount, statusTabs, setStatus, historySection, categoryOptions } from './helpers.js';

const videoColumns = [
    { key: 'caption', label: 'Video', sortable: true, sub: r => `${r.id} · ${r.durationSec ? `${r.durationSec} s` : '—'}${r.sizeMb ? ` · ${r.sizeMb} MB` : ''}` },
    { key: 'providerName', label: 'Provider', sortable: true },
    { key: 'service', label: 'Service', format: 'category', hideBelow: 'lg' },
    { key: 'status', label: 'Status', format: 'status', sortable: true },
    { key: 'reports', label: 'Reports', format: 'number', align: 'right', sortable: true },
    { key: 'uploadedAt', label: 'Uploaded', format: 'relative', sortable: true },
];

const videoDetail = {
    title: r => r.caption,
    sections: (r) => [
        // No autoplay, no file: a still placeholder only
        { preview: { icon: 'bi-camera-video', text: 'Preview unavailable: video uploads aren’t built yet. Videos never autoplay in the admin.' } },
        { title: 'Video', fields: [
            ['Provider', `${r.providerName} (${r.providerId})`], ['Service', r.service, 'category'], ['Duration', r.durationSec ? `${r.durationSec} seconds` : null],
            ['File size', r.sizeMb ? `${r.sizeMb} MB` : null], ['Uploaded', r.uploadedAt, 'date'], ['Reports', r.reports, 'number'],
            ['Processing error', r.failure], ['Moderation note', r.moderationNote],
            ['Views and engagement', 'Not tracked yet'],
        ] },
        historySection,
    ],
};

const videoActions = [
    setStatus('approve', 'Approve and publish', 'published', { tone: 'primary', permission: 'content.view', done: 'Approved', when: r => r.status === 'pending_moderation' }),
    setStatus('reject', 'Reject', 'rejected', { tone: 'danger', reason: 'required', permission: 'content.view', done: 'Rejected', when: r => r.status === 'pending_moderation',
        extra: (r, v) => ({ moderationNote: v.reason }), message: () => 'The provider is told the reason and can upload a new version.' }),
    setStatus('remove', 'Remove', 'removed', { tone: 'danger', reason: 'required', permission: 'content.view', done: 'Removed', when: r => r.status === 'published',
        extra: (r, v) => ({ moderationNote: v.reason }) }),
    setStatus('keep', 'Dismiss reports', 'published', { reason: 'required', reasonLabel: 'Why does it stay up?', permission: 'content.view', done: 'Reports dismissed', when: r => r.status === 'published' && r.reports > 0, extra: () => ({ reports: 0 }) }),
    setStatus('retry', 'Retry processing', 'processing', { permission: 'content.view', done: 'Processing retried', when: r => r.status === 'processing_failed' }),
];

const lugandaTone = (s) => s;   // 'missing' | 'needs_review' | 'approved'

export default {
    /* ================= WorkMate in Action ================= */
    'in-action': {
        type: 'records', entity: 'Video',
        description: 'Short videos providers publish to show their work.',
        search: { keys: ['id', 'caption', 'providerName', 'providerId'], placeholder: 'Search caption, provider or video ID' },
        tabs: statusTabs(['published', 'pending_moderation', 'processing', 'uploading', 'processing_failed', 'rejected', 'removed']),
        filters: [{ key: 'service', label: 'Service', options: categoryOptions }],
        period: { key: 'uploadedAt', label: 'Uploaded' },
        defaultSort: { key: 'uploadedAt', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Published', count(rows, r => r.status === 'published')),
            kpiCount('Waiting for moderation', count(rows, r => r.status === 'pending_moderation'), { to: '/admin/content/video-moderation' }),
            kpiCount('Processing failed', count(rows, r => r.status === 'processing_failed')),
            { label: 'Views', value: '', available: false, unavailableReason: 'View counts aren’t tracked yet, so none are shown.' },
        ],
        columns: videoColumns, detail: videoDetail, actions: videoActions,
    },

    /* ================= Video moderation ================= */
    'video-moderation': {
        type: 'records', entity: 'Video',
        description: 'New uploads and reported videos. Rejecting or removing needs a reason.',
        search: { keys: ['id', 'caption', 'providerName'], placeholder: 'Search caption or provider' },
        tabs: [
            { id: 'pending', label: 'New uploads', filter: r => r.status === 'pending_moderation' },
            { id: 'reported', label: 'Reported', filter: r => r.reports > 0 && r.status === 'published' },
            { id: 'all', label: 'All' },
        ],
        defaultSort: { key: 'uploadedAt', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('New uploads waiting', count(rows, r => r.status === 'pending_moderation')),
            kpiCount('Reported and still live', count(rows, r => r.reports > 0 && r.status === 'published')),
            kpiCount('Waiting over 24 h', count(rows, r => r.status === 'pending_moderation' && !within(r.uploadedAt, 1))),
            kpiCount('Removed', count(rows, r => r.status === 'removed')),
        ],
        columns: videoColumns, detail: videoDetail, actions: videoActions,
    },

    /* ================= Homepage content ================= */
    homepage: {
        type: 'records', entity: 'Homepage section', statusKey: false,
        notice: 'Section order and visibility mirror the current homepage. Changes are recorded here only; the live homepage doesn’t read them yet.',
        search: { keys: ['name', 'description'], placeholder: 'Search sections' },
        defaultSort: { key: 'position', dir: 'asc' },
        columns: [
            { key: 'position', label: 'Order', format: 'number', align: 'right', sortable: true },
            { key: 'name', label: 'Section', sub: r => r.description },
            { key: 'visible', label: 'Visible', format: 'bool' },
            { key: 'updatedAt', label: 'Updated', format: 'relative', sortable: true },
        ],
        detail: { sections: (r) => [{ title: 'Section', fields: [['Name', r.name], ['Description', r.description], ['Order', r.position, 'number'], ['Visible', r.visible, 'bool'], ['Updated', r.updatedAt, 'date']] }, historySection] },
        actions: [
            { id: 'hide', label: 'Hide section', done: 'Hidden', tone: 'danger', permission: 'content.view', reason: 'required', when: r => r.visible, apply: () => ({ patch: { visible: false, updatedAt: new Date().toISOString() } }) },
            { id: 'show', label: 'Show section', done: 'Shown', tone: 'primary', permission: 'content.view', when: r => !r.visible, apply: () => ({ patch: { visible: true, updatedAt: new Date().toISOString() } }) },
        ],
    },

    /* ================= Announcements ================= */
    announcements: {
        type: 'records', entity: 'Announcement',
        notice: 'Publishing here doesn’t show anything in the app yet: announcements aren’t connected.',
        search: { keys: ['id', 'title', 'audience'], placeholder: 'Search announcements' },
        tabs: statusTabs(['published', 'scheduled', 'draft']),
        defaultSort: { key: 'publishAt', dir: 'desc' },
        columns: [
            { key: 'title', label: 'Announcement', sortable: true, sub: r => r.id },
            { key: 'audience', label: 'Audience' },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'luganda', label: 'Luganda', format: 'status', value: r => lugandaTone(r.luganda) },
            { key: 'publishAt', label: 'Publish date', format: 'date', sortable: true },
        ],
        detail: { sections: (r) => [{ title: 'Announcement', fields: [['Title', r.title], ['Audience', r.audience], ['Status', r.status, 'status'], ['Luganda version', r.luganda, 'status'], ['Publish date', r.publishAt, 'date']] }, historySection] },
        actions: [
            setStatus('publish', 'Publish', 'published', { tone: 'primary', permission: 'content.view', done: 'Published', when: r => r.status === 'draft' || r.status === 'scheduled', extra: () => ({ publishAt: new Date().toISOString() }),
                message: r => (r.luganda !== 'approved' ? 'The Luganda version isn’t approved yet, so Luganda readers will see English.' : '') }),
            setStatus('unpublish', 'Unpublish', 'draft', { tone: 'danger', reason: 'required', permission: 'content.view', done: 'Unpublished', when: r => r.status === 'published' }),
        ],
        create: {
            label: 'New announcement', done: 'Draft created', permission: 'content.view',
            fields: [
                { key: 'title', label: 'Title', type: 'text', required: true },
                { key: 'audience', label: 'Audience', type: 'select', options: [['Everyone', 'Everyone'], ['Customers', 'Customers'], ['Providers', 'Providers']] },
            ],
            build: (v) => ({ id: `AN-${Date.now().toString().slice(-4)}`, title: v.title, audience: v.audience, status: 'draft', luganda: 'missing', publishAt: null }),
        },
    },

    /* ================= Help centre ================= */
    help: {
        type: 'records', entity: 'Help article',
        search: { keys: ['id', 'title', 'section'], placeholder: 'Search articles' },
        tabs: statusTabs(['published', 'draft']),
        filters: [{ key: 'section', label: 'Section', options: ['Customers', 'Providers', 'Payments', 'Safety', 'Account'].map(s => [s, s]) }],
        defaultSort: { key: 'updatedAt', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Articles', rows.length),
            kpiCount('Published', count(rows, r => r.status === 'published')),
            kpiCount('Missing Luganda', count(rows, r => r.luganda === 'missing'), { to: '/admin/content/translations' }),
            kpiCount('Drafts', count(rows, r => r.status === 'draft')),
        ],
        columns: [
            { key: 'title', label: 'Article', sortable: true, sub: r => r.id },
            { key: 'section', label: 'Section', sortable: true },
            { key: 'status', label: 'Status', format: 'status' },
            { key: 'luganda', label: 'Luganda', format: 'status' },
            { key: 'updatedAt', label: 'Updated', format: 'relative', sortable: true },
        ],
        detail: { sections: (r) => [{ title: 'Article', fields: [['Title', r.title], ['Section', r.section], ['Status', r.status, 'status'], ['Luganda version', r.luganda, 'status'], ['Updated', r.updatedAt, 'date']] }, historySection] },
        actions: [
            setStatus('publish', 'Publish', 'published', { tone: 'primary', permission: 'content.view', done: 'Published', when: r => r.status === 'draft' }),
            setStatus('unpublish', 'Unpublish', 'draft', { reason: 'required', permission: 'content.view', done: 'Unpublished', when: r => r.status === 'published' }),
        ],
        create: {
            label: 'New article', done: 'Draft article created', permission: 'content.view',
            fields: [
                { key: 'title', label: 'Title', type: 'text', required: true },
                { key: 'section', label: 'Section', type: 'select', options: ['Customers', 'Providers', 'Payments', 'Safety', 'Account'].map(s => [s, s]) },
            ],
            build: (v) => ({ id: `HC-${Date.now().toString().slice(-4)}`, title: v.title, section: v.section, status: 'draft', luganda: 'missing', updatedAt: new Date().toISOString() }),
        },
    },

    /* ================= Translations ================= */
    translations: {
        type: 'records', entity: 'Translation',
        description: 'English and Luganda text for the customer app.',
        notice: 'WorkMate has no localisation system yet, so these keys are taken from the app’s current English text. Luganda drafts must be reviewed by a native speaker before approval.',
        search: { keys: ['key', 'english', 'luganda', 'area'], placeholder: 'Search key, English or Luganda text' },
        tabs: statusTabs(['missing', 'needs_review', 'approved']),
        filters: [{ key: 'area', label: 'Area', options: ['Homepage', 'Search', 'Navigation', 'Common', 'Service categories', 'Help Centre', 'Announcements'].map(a => [a, a]) }],
        defaultSort: { key: 'key', dir: 'asc' },
        exportable: true, exportPermission: 'content.view',
        kpis: (rows) => [
            kpiCount('Strings', rows.length),
            kpiCount('Missing Luganda', count(rows, r => r.status === 'missing')),
            kpiCount('Waiting for review', count(rows, r => r.status === 'needs_review')),
            kpiCount('Approved', count(rows, r => r.status === 'approved')),
        ],
        columns: [
            { key: 'key', label: 'Key', sortable: true, sub: r => r.area },
            { key: 'english', label: 'English' },
            { key: 'luganda', label: 'Luganda', value: r => r.luganda || null },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'updatedAt', label: 'Updated', format: 'relative', sortable: true, hideBelow: 'lg' },
        ],
        detail: {
            title: r => r.key,
            sections: (r) => [
                { title: 'Text', fields: [['English', r.english], ['Luganda', r.luganda || 'Missing'], ['Status', r.status, 'status'], ['Area', r.area], ['Last updated by', r.updatedBy || '—'], ['Updated', r.updatedAt, 'date']] },
                { title: 'Preview', text: r.luganda ? `EN: ${r.english}  ·  LG: ${r.luganda}` : `EN: ${r.english}  ·  LG: (falls back to English)` },
                historySection,
            ],
        },
        actions: [
            { id: 'edit', label: 'Edit Luganda', done: 'Luganda text updated', tone: 'primary', permission: 'content.view', icon: 'bi-pencil',
              fields: (r) => [
                  { key: 'luganda', label: `Luganda for “${r.english}”`, type: 'textarea', required: true, initial: r.luganda },
                  { key: 'reason', label: 'Note (optional)', type: 'text' },
              ],
              apply: (r, v) => ({ patch: { luganda: v.luganda, status: 'needs_review', updatedAt: new Date().toISOString(), updatedBy: 'Administrator' }, detail: `“${v.luganda}”` }) },
            setStatus('approve', 'Approve translation', 'approved', { permission: 'content.view', done: 'Translation approved', when: r => r.status === 'needs_review',
                reason: 'required', reasonLabel: 'Reviewed by (native speaker)', message: () => 'Only approve after a native Luganda speaker has checked it.' }),
        ],
    },
};
