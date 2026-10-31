import { count, kpiCount, statusTabs, setStatus, historySection } from './helpers.js';
import { ROLES } from '../permissions.js';

const roleLabel = (id) => ROLES.find(r => r.id === id)?.label || id;
const roleOptions = ROLES.map(r => [r.id, r.label]);

export default {
    /* ================= Admin users ================= */
    'admin-users': {
        type: 'records', entity: 'Admin user',
        notice: 'Admin sign-in isn’t connected yet. These are the planned accounts; changes are recorded in this session only.',
        search: { text: r => `${r.id} ${r.name} ${roleLabel(r.role)}`, placeholder: 'Search name, ID or role' },
        tabs: statusTabs(['active', 'deactivated']),
        filters: [{ key: 'role', label: 'Role', options: roleOptions }],
        defaultSort: { key: 'name', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Admin users', count(rows, r => r.status === 'active')),
            kpiCount('Super Administrators', count(rows, r => r.status === 'active' && r.role === 'super_admin'), { hint: 'Keep this number as small as possible.' }),
            kpiCount('Without two-factor sign-in', count(rows, r => r.status === 'active' && !r.twoFactor)),
            kpiCount('Deactivated', count(rows, r => r.status === 'deactivated')),
        ],
        columns: [
            { key: 'name', label: 'Admin', sortable: true, sub: r => r.id },
            { key: 'role', label: 'Role', value: r => roleLabel(r.role), sortable: true },
            { key: 'status', label: 'Status', format: 'status' },
            { key: 'twoFactor', label: 'Two-factor', format: 'bool', yes: 'On', no: 'Off' },
            { key: 'lastSignInAt', label: 'Last sign-in', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Account', fields: [['Role', roleLabel(r.role)], ['What the role covers', ROLES.find(x => x.id === r.role)?.description], ['Two-factor sign-in', r.twoFactor, 'bool'], ['Last sign-in', r.lastSignInAt, 'date'], ['Created', r.createdAt, 'date']] },
                { link: { to: '/admin/settings/roles', label: 'See what this role can do' } },
                historySection,
            ],
        },
        actions: [
            { id: 'role', label: 'Change role', done: 'Role changed', tone: 'primary', permission: 'admin.manage', icon: 'bi-person-gear',
              when: r => r.status === 'active',
              fields: (r) => [
                  { key: 'role', label: 'New role', type: 'select', options: roleOptions, initial: r.role },
                  { key: 'reason', label: 'Reason', type: 'textarea', required: true, minLength: 5 },
              ],
              message: () => 'Give the least access the job needs.',
              apply: (r, v) => ({ patch: { role: v.role }, detail: `${roleLabel(r.role)} → ${roleLabel(v.role)}` }) },
            { id: 'require-2fa', label: 'Require two-factor', done: 'Two-factor required', permission: 'admin.manage', when: r => r.status === 'active' && !r.twoFactor,
              message: r => `${r.name} will have to set up two-factor sign-in at their next sign-in.`, apply: () => ({ patch: { twoFactor: true } }) },
            setStatus('deactivate', 'Deactivate', 'deactivated', { tone: 'danger', reason: 'required', permission: 'admin.manage', done: 'Deactivated', when: r => r.status === 'active',
                message: r => `${r.name} loses admin access immediately. Their past actions stay in the audit log.` }),
            setStatus('reactivate', 'Reactivate', 'active', { reason: 'required', permission: 'admin.manage', done: 'Reactivated', when: r => r.status === 'deactivated' }),
        ],
        create: {
            label: 'Invite admin', done: 'Admin user added', permission: 'admin.manage', confirmLabel: 'Add',
            message: 'Invitations aren’t connected yet.',
            fields: [
                { key: 'name', label: 'Full name', type: 'text', required: true },
                { key: 'role', label: 'Role', type: 'select', options: roleOptions.slice(1).concat([roleOptions[0]]), help: 'Choose the narrowest role that covers their work.' },
            ],
            build: (v) => ({ id: `ADM-${Date.now().toString().slice(-3)}`, name: v.name, role: v.role, status: 'active', twoFactor: false, lastSignInAt: null, createdAt: new Date().toISOString() }),
        },
    },

    /* ================= Roles and permissions ================= */
    roles: { type: 'roles' },

    /* ================= Platform settings ================= */
    platform: {
        type: 'settings', editPermission: 'admin.manage',
        sections: [
            { title: 'Platform', fields: [
                { key: 'platformName', label: 'Platform name', type: 'text' },
                { key: 'defaultCity', label: 'Default city', type: 'text', help: 'Used when a customer hasn’t chosen an area.' },
                { key: 'currency', label: 'Currency', type: 'select', options: [['UGX', 'Ugandan shilling (UGX)']] },
                { key: 'languages', label: 'Languages', type: 'select', options: [['English, Luganda', 'English and Luganda'], ['English', 'English only']] },
            ] },
            { title: 'Support contact', description: 'Shown in the app and in messages.', fields: [
                { key: 'supportPhone', label: 'Support phone', type: 'text' },
                { key: 'supportEmail', label: 'Support email', type: 'text' },
            ] },
            { title: 'Availability', fields: [
                { key: 'providerSignups', label: 'Accept new provider applications', type: 'toggle' },
                { key: 'maintenanceMode', label: 'Maintenance mode', type: 'toggle', help: 'Shows a maintenance notice to customers and pauses new bookings.' },
            ] },
            { title: 'Online presence', description: 'How the admin decides whether someone is online. Presence is separate from a provider’s availability for work.', fields: [
                { key: 'presenceOnlineMin', label: 'Online if active within', type: 'number', unit: 'minutes', min: 1, max: 30, help: 'After this long without a heartbeat or activity, the account shows as recently active, not online.' },
                { key: 'presenceRecentMin', label: 'Recently active if active within', type: 'number', unit: 'minutes', min: 2, max: 240 },
                { key: 'presenceHeartbeatSec', label: 'App heartbeat interval', type: 'number', unit: 'seconds', min: 15, max: 600, help: 'How often the app reports it is open, once presence collection is connected.' },
            ] },
        ],
        validate: (v) => ({
            ...(/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(v.supportEmail) ? {} : { supportEmail: 'Enter a valid email address.' }),
            ...(v.presenceRecentMin > v.presenceOnlineMin ? {} : { presenceRecentMin: 'Must be longer than the online window.' }),
        }),
    },

    /* ================= Business rules ================= */
    rules: {
        type: 'settings', editPermission: 'admin.manage',
        description: 'Commission, cancellation, quotation and payout rules.',
        sections: [
            { title: 'Money', fields: [
                { key: 'commissionRate', label: 'Platform commission', type: 'number', unit: '%', min: 0, max: 30, step: 0.5, help: 'Applies to new bookings only. Existing bookings keep their rate.' },
                { key: 'minPayout', label: 'Minimum payout', type: 'number', unit: 'UGX', min: 0, max: 500000, step: 1000, help: 'Smaller balances roll over to the next week.' },
                { key: 'payoutDay', label: 'Weekly payout day', type: 'select', options: ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'].map(d => [d, d]) },
            ] },
            { title: 'Bookings', fields: [
                { key: 'freeCancellationHours', label: 'Free cancellation up to', type: 'number', unit: 'hours before', min: 0, max: 72 },
                { key: 'providerResponseHours', label: 'Provider must respond within', type: 'number', unit: 'hours', min: 1, max: 72, help: 'After this the booking expires and the customer is told.' },
                { key: 'autoCompleteDays', label: 'Mark complete automatically after', type: 'number', unit: 'days', min: 1, max: 14, help: 'If the customer doesn’t confirm or dispute.' },
                { key: 'maxActiveBookingsNewCustomer', label: 'Active bookings allowed for new customers', type: 'number', min: 1, max: 10 },
            ] },
            { title: 'Quotations and disputes', fields: [
                { key: 'quoteExpiryDays', label: 'Quotations expire after', type: 'number', unit: 'days', min: 1, max: 30 },
                { key: 'disputeWindowDays', label: 'Disputes can be opened up to', type: 'number', unit: 'days after completion', min: 1, max: 30 },
            ] },
        ],
    },

    /* ================= Feature flags ================= */
    flags: {
        type: 'records', entity: 'Feature flag', statusKey: false,
        notice: 'Flags here aren’t read by the app yet; turning one on or off is recorded only.',
        search: { keys: ['key', 'name', 'description', 'audience'], placeholder: 'Search flags' },
        tabs: [{ id: 'all', label: 'All' }, { id: 'on', label: 'On', filter: r => r.enabled }, { id: 'off', label: 'Off', filter: r => !r.enabled }],
        defaultSort: { key: 'name', dir: 'asc' },
        columns: [
            { key: 'name', label: 'Feature', sortable: true, sub: r => r.key },
            { key: 'description', label: 'What it does', hideBelow: 'lg' },
            { key: 'audience', label: 'Audience' },
            { key: 'enabled', label: 'Enabled', format: 'bool', yes: 'On', no: 'Off' },
            { key: 'updatedAt', label: 'Changed', format: 'relative', sortable: true },
        ],
        detail: { sections: (r) => [{ title: 'Flag', fields: [['Key', r.key], ['What it does', r.description], ['Audience', r.audience], ['Enabled', r.enabled, 'bool'], ['Changed', r.updatedAt, 'date']] }, historySection] },
        actions: [
            { id: 'on', label: 'Turn on', done: 'Turned on', tone: 'primary', permission: 'admin.manage', reason: 'required', when: r => !r.enabled, apply: () => ({ patch: { enabled: true, updatedAt: new Date().toISOString() } }) },
            { id: 'off', label: 'Turn off', done: 'Turned off', tone: 'danger', permission: 'admin.manage', reason: 'required', when: r => r.enabled, apply: () => ({ patch: { enabled: false, updatedAt: new Date().toISOString() } }) },
            { id: 'audience', label: 'Change audience', done: 'Audience changed', permission: 'admin.manage',
              fields: (r) => [{ key: 'audience', label: 'Who gets it', type: 'select', initial: r.audience, options: ['Nobody', 'Staff only', '10% of customers', '25% of customers', '50% of customers', 'Everyone'].map(a => [a, a]) }, { key: 'reason', label: 'Reason', type: 'textarea', required: true, minLength: 5 }],
              apply: (r, v) => ({ patch: { audience: v.audience, updatedAt: new Date().toISOString() }, detail: `${r.audience} → ${v.audience}` }) },
        ],
    },

    /* ================= Data management ================= */
    data: {
        type: 'records', entity: 'Data request',
        description: 'Requests from customers and providers to export, correct or delete their data.',
        notice: 'Data requests aren’t recorded yet. Exports and deletions will be handled here once they are.', noticeTone: 'warning',
        search: { keys: ['id', 'subjectName', 'subjectId', 'type'], placeholder: 'Search request, person or ID' },
        tabs: statusTabs(['requested', 'completed', 'rejected']),
        filters: [{ key: 'type', label: 'Type', options: [['Data export', 'Data export'], ['Account deletion', 'Account deletion'], ['Correction', 'Correction']] }],
        defaultSort: { key: 'requestedAt', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Waiting', count(rows, r => r.status === 'requested')),
            kpiCount('Deletion requests waiting', count(rows, r => r.status === 'requested' && r.type === 'Account deletion')),
            kpiCount('Due within 7 days', count(rows, r => r.status === 'requested' && new Date(r.dueAt) - Date.now() < 7 * 86400000)),
            kpiCount('Completed', count(rows, r => r.status === 'completed')),
        ],
        columns: [
            { key: 'type', label: 'Request', sortable: true, sub: r => r.id },
            { key: 'subjectName', label: 'Person', sortable: true, sub: r => r.subjectId },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'requestedAt', label: 'Requested', format: 'relative', sortable: true },
            { key: 'dueAt', label: 'Due', format: 'date', sortable: true },
        ],
        detail: { sections: (r) => [{ title: 'Request', fields: [['Type', r.type], ['Person', `${r.subjectName} (${r.subjectId})`], ['Requested', r.requestedAt, 'date'], ['Due', r.dueAt, 'date']] }, historySection] },
        actions: [
            setStatus('complete', 'Mark completed', 'completed', { tone: 'primary', reason: 'required', reasonLabel: 'What was done?', permission: 'admin.manage', done: 'Completed', when: r => r.status === 'requested' }),
            setStatus('reject', 'Reject', 'rejected', { tone: 'danger', reason: 'required', permission: 'admin.manage', done: 'Rejected', when: r => r.status === 'requested',
                message: () => 'Only reject when the law allows keeping the data (for example, financial records).' }),
        ],
    },
};
