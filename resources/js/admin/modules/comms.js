import { count, within, share, kpiCount, kpiRate, statusTabs, setStatus, historySection } from './helpers.js';

const NOT_CONNECTED = 'No SMS, email or push service is connected yet, so nothing is sent from the admin and no records exist.';

export default {
    /* ================= Notification centre ================= */
    notifications: {
        type: 'records', entity: 'Notification type',
        notice: NOT_CONNECTED, noticeTone: 'warning',
        search: { keys: ['name', 'kind', 'channels'], placeholder: 'Search notification types' },
        filters: [{ key: 'kind', label: 'Kind', options: [['Transactional', 'Transactional'], ['Engagement', 'Engagement'], ['Marketing', 'Marketing']] }],
        defaultSort: { key: 'name', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Notification types', rows.length),
            kpiCount('Active', count(rows, r => r.status === 'active')),
            kpiCount('Users can opt out', count(rows, r => r.optOut), { hint: 'Transactional messages (bookings, payments) can’t be switched off.' }),
            { label: 'Channels connected', value: '0 of 4', hint: 'In-app, SMS, email and push all need a provider.' },
        ],
        columns: [
            { key: 'name', label: 'Notification', sortable: true, sub: r => r.kind },
            { key: 'channels', label: 'Channels' },
            { key: 'optOut', label: 'Opt-out allowed', format: 'bool' },
            { key: 'status', label: 'Status', format: 'status' },
        ],
        detail: { sections: (r) => [{ title: 'Notification', fields: [['Kind', r.kind], ['Channels', r.channels], ['Users can opt out', r.optOut, 'bool'], ['Status', r.status, 'status']] }, historySection] },
        actions: [
            setStatus('disable', 'Turn off', 'inactive', { tone: 'danger', reason: 'required', permission: 'comms.view', done: 'Turned off', when: r => r.status === 'active' && r.kind !== 'Transactional' }),
            setStatus('enable', 'Turn on', 'active', { tone: 'primary', permission: 'comms.view', done: 'Turned on', when: r => r.status !== 'active' }),
        ],
    },

    /* ================= Message templates ================= */
    templates: {
        type: 'records', entity: 'Message template',
        search: { keys: ['id', 'name', 'english', 'luganda'], placeholder: 'Search templates' },
        tabs: statusTabs(['active', 'draft']),
        defaultSort: { key: 'name', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Templates', rows.length),
            kpiCount('Luganda missing', count(rows, r => r.lugandaStatus === 'missing')),
            kpiCount('Luganda waiting for review', count(rows, r => r.lugandaStatus === 'needs_review')),
            kpiCount('Drafts', count(rows, r => r.status === 'draft')),
        ],
        columns: [
            { key: 'name', label: 'Template', sortable: true, sub: r => r.id },
            { key: 'channels', label: 'Channels' },
            { key: 'english', label: 'English text', hideBelow: 'lg' },
            { key: 'lugandaStatus', label: 'Luganda', format: 'status' },
            { key: 'status', label: 'Status', format: 'status' },
        ],
        detail: {
            sections: (r) => [
                { title: 'English', text: r.english },
                { title: 'Luganda', text: r.luganda || 'Missing: Luganda readers receive the English text.' },
                { title: 'Template', fields: [['Key', r.id], ['Channels', r.channels], ['Status', r.status, 'status'], ['Updated', r.updatedAt, 'date']] },
                { note: 'Placeholders like {provider} are filled in when the message is created. Keep them unchanged in both languages.' },
                historySection,
            ],
        },
        actions: [
            { id: 'luganda', label: 'Edit Luganda version', done: 'Luganda version updated', tone: 'primary', permission: 'comms.view', icon: 'bi-translate',
              fields: (r) => [{ key: 'luganda', label: 'Luganda text', type: 'textarea', required: true, initial: r.luganda, help: `English: ${r.english}` }, { key: 'reason', label: 'Note (optional)', type: 'text' }],
              apply: (r, v) => ({ patch: { luganda: v.luganda, lugandaStatus: 'needs_review', updatedAt: new Date().toISOString() } }) },
            setStatus('activate', 'Activate', 'active', { permission: 'comms.view', done: 'Activated', when: r => r.status === 'draft' }),
        ],
    },

    /* ================= Campaigns ================= */
    campaigns: {
        type: 'records', entity: 'Campaign',
        notice: NOT_CONNECTED, noticeTone: 'warning',
        search: { keys: ['id', 'name', 'audience'], placeholder: 'Search campaigns' },
        tabs: statusTabs(['draft', 'scheduled', 'completed', 'cancelled']),
        defaultSort: { key: 'scheduledFor', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Drafts', count(rows, r => r.status === 'draft')),
            kpiCount('Scheduled', count(rows, r => r.status === 'scheduled')),
            kpiCount('Completed (demo)', count(rows, r => r.status === 'completed')),
            { label: 'Delivered', value: '', available: false, unavailableReason: 'Delivery results need a connected sending service.' },
        ],
        columns: [
            { key: 'name', label: 'Campaign', sortable: true, sub: r => r.audience },
            { key: 'channel', label: 'Channel' },
            { key: 'audienceSize', label: 'Recipients', format: 'number', align: 'right', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'scheduledFor', label: 'Send date', format: 'date', sortable: true },
        ],
        detail: { sections: (r) => [{ title: 'Campaign', fields: [['Audience', r.audience], ['Estimated recipients', r.audienceSize, 'number'], ['Channel', r.channel], ['Status', r.status, 'status'], ['Send date', r.scheduledFor, 'date'], ['Created by', r.createdBy]] }, historySection] },
        actions: [
            { id: 'schedule', label: 'Schedule', done: 'Scheduled', tone: 'primary', permission: 'comms.view', when: r => r.status === 'draft',
              fields: () => [{ key: 'days', label: 'Send in how many days?', type: 'number', required: true, initial: 3 }],
              message: () => 'Scheduling records the plan only. Nothing will be sent until a messaging service is connected.',
              apply: (r, v) => ({ patch: { status: 'scheduled', scheduledFor: new Date(Date.now() + v.days * 86400000).toISOString() } }) },
            setStatus('cancel', 'Cancel campaign', 'cancelled', { tone: 'danger', reason: 'required', permission: 'comms.view', done: 'Cancelled', when: r => r.status === 'scheduled' }),
        ],
        create: {
            label: 'New campaign', done: 'Draft campaign created', permission: 'comms.view',
            fields: [
                { key: 'name', label: 'Name', type: 'text', required: true },
                { key: 'audience', label: 'Audience', type: 'select', options: [['All customers', 'All customers'], ['All providers', 'All providers'], ['Customers in Kampala', 'Customers in Kampala'], ['Providers missing a trade certificate', 'Providers missing a trade certificate']] },
                { key: 'channel', label: 'Channel', type: 'select', options: [['Push', 'Push'], ['SMS', 'SMS'], ['Email', 'Email'], ['In-app', 'In-app']] },
            ],
            build: (v) => ({ id: `CMP-${Date.now().toString().slice(-4)}`, name: v.name, audience: v.audience, channel: v.channel, audienceSize: null, status: 'draft', scheduledFor: null, createdBy: 'Administrator' }),
        },
    },

    /* ================= Delivery logs ================= */
    delivery: {
        type: 'records', entity: 'Delivery',
        description: 'Each message’s sending status and delivery status, tracked separately.',
        notice: '“Sent” only means the messaging provider accepted the message. “Delivered” needs the provider’s receipt. No deliveries are recorded yet.',
        search: { keys: ['id', 'template', 'recipientMasked', 'recipientId'], placeholder: 'Search message ID, template or recipient ID' },
        tabs: [
            { id: 'failed', label: 'Failed', filter: r => r.sendStatus === 'failed' || r.deliveryStatus === 'undelivered' },
            { id: 'all', label: 'All' },
        ],
        filters: [
            { key: 'channel', label: 'Channel', options: [['SMS', 'SMS'], ['Push', 'Push'], ['Email', 'Email'], ['In-app', 'In-app']] },
            { key: 'language', label: 'Language', options: [['English', 'English'], ['Luganda', 'Luganda']] },
        ],
        period: { key: 'createdAt', label: 'Created' },
        defaultSort: { key: 'createdAt', dir: 'desc' },
        exportable: true, exportPermission: 'comms.view',
        kpis: (rows) => {
            const recent = rows.filter(r => within(r.createdAt, 1));
            return [
                kpiCount('Messages (24 h)', recent.length),
                kpiRate('Delivered (24 h)', share(count(recent, r => r.deliveryStatus === 'delivered'), recent.length)),
                kpiCount('Undelivered (24 h)', count(recent, r => r.deliveryStatus === 'undelivered')),
                kpiCount('Not sent (24 h)', count(recent, r => r.sendStatus === 'failed')),
            ];
        },
        columns: [
            { key: 'template', label: 'Message', sortable: true, sub: r => r.id },
            { key: 'channel', label: 'Channel', sortable: true },
            { key: 'recipientMasked', label: 'Recipient', hideBelow: 'lg' },
            { key: 'sendStatus', label: 'Sending', format: 'status' },
            { key: 'deliveryStatus', label: 'Delivery', format: 'status' },
            { key: 'createdAt', label: 'Created', format: 'relative', sortable: true },
        ],
        statusKey: 'deliveryStatus',
        detail: {
            title: r => `${r.template} (${r.channel})`,
            sections: (r) => [
                { title: 'Message', fields: [['Template', r.template], ['Channel', r.channel], ['Language', r.language], ['Recipient', `${r.recipientMasked} (${r.recipientId})`],
                    ['Sending status', r.sendStatus, 'status'], ['Delivery status', r.deliveryStatus, 'status'], ['Error', r.error], ['Created', r.createdAt, 'date']] },
                { note: 'Message contents aren’t stored in the log, only the template and status.' },
                historySection,
            ],
        },
        actions: [
            { id: 'retry', label: 'Add to retry queue', done: 'Added to retry queue', permission: 'comms.view', reason: 'optional',
              when: r => r.sendStatus === 'failed' || r.deliveryStatus === 'undelivered',
              message: () => 'Records the retry. It will only be sent once a messaging service is connected.', apply: () => ({ patch: {} }) },
        ],
    },

    /* ================= Notification preferences ================= */
    preferences: {
        type: 'settings',
        editPermission: 'comms.view',
        description: 'Defaults for new accounts. People can change their own preferences in the app.',
        sections: [
            { title: 'Bookings and payments', description: 'Transactional messages: needed to complete a booking, so they can’t be fully switched off.', fields: [
                { key: 'smsBooking', label: 'SMS when a booking is confirmed', type: 'toggle' },
                { key: 'pushBooking', label: 'Push when a booking is confirmed', type: 'toggle' },
                { key: 'smsReminders', label: 'SMS reminder the day before', type: 'toggle' },
                { key: 'emailReceipts', label: 'Email payment receipts', type: 'toggle' },
                { key: 'pushQuotes', label: 'Push when a quotation arrives', type: 'toggle' },
            ] },
            { title: 'Marketing', fields: [
                { key: 'marketingDefault', label: 'Opt new accounts in to marketing', type: 'toggle', help: 'Off by default: people should choose to receive promotions.' },
            ] },
            { title: 'Timing and language', fields: [
                { key: 'quietHoursStart', label: 'Quiet hours start', type: 'time', help: 'No non-urgent messages after this time.' },
                { key: 'quietHoursEnd', label: 'Quiet hours end', type: 'time' },
                { key: 'defaultLanguage', label: 'Default language', type: 'select', options: [['English', 'English'], ['Luganda', 'Luganda']] },
            ] },
        ],
    },
};

