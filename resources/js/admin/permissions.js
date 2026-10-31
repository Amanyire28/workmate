import { computed } from 'vue';
import { auth } from '@/stores/auth.js';

/**
 * Admin roles and what each may see or do. The server's copy in
 * config/permissions.php is the one that counts (a test keeps them identical);
 * this copy decides which menus and buttons to show.
 *
 * Least privilege: each role gets only the areas its job needs. Only the
 * Super Administrator holds 'admin.manage'.
 */
export const PERMISSIONS = {
    'dashboard.view':     'View the executive dashboard',
    'activity.view':      'View the operational event log',
    'marketplace.view':   'View customers, providers, jobs and bookings',
    'providers.review':   'Review provider applications and verification',
    'location.view':      'View location intelligence (no precise provider positions)',
    'location.configure': 'Change matching configuration',
    'trust.view':         'Work trust and safety cases',
    'finance.summary':    'See headline revenue figures',
    'finance.view':       'Work transactions, refunds, payouts and reconciliation',
    'content.view':       'Moderate videos and manage content and translations',
    'comms.view':         'Manage notifications, templates and campaigns',
    'analytics.view':     'View analytics and build reports',
    'system.view':        'View system health, jobs and integrations',
    'security.view':      'View audit logs and security events',
    'documents.view':     'Open private verification documents (each view is logged)',
    'documents.review':   'Review verification applications and documents',
    'verification.configure': 'Change document requirements and reopen decided applications',
    'reputation.view':    'View blue tick eligibility and rules',
    'bluetick.configure': 'Change and publish blue tick rules',
    'bluetick.override':  'Suspend, restore or remove a blue tick by override',
    'usage.view':         'View aggregate app usage and installs',
    'activity.users':     'View individual users’ activity and online presence',
    'admin.manage':       'Manage admin users, roles and platform settings',
};

export const ROLES = [
    {
        id: 'super_admin', label: 'Super Administrator',
        description: 'Full access. Keep to a very small number of people.',
        permissions: Object.keys(PERMISSIONS),
    },
    {
        id: 'operations', label: 'Operations Administrator',
        description: 'Runs the marketplace day to day.',
        permissions: ['dashboard.view', 'activity.view', 'marketplace.view', 'providers.review', 'location.view', 'trust.view', 'finance.summary', 'comms.view', 'analytics.view', 'documents.review', 'reputation.view', 'bluetick.configure', 'verification.configure', 'usage.view', 'activity.users'],
    },
    {
        id: 'provider_review', label: 'Provider Review Administrator',
        description: 'Checks and approves provider applications.',
        permissions: ['dashboard.view', 'activity.view', 'marketplace.view', 'providers.review', 'documents.view', 'documents.review', 'reputation.view'],
    },
    {
        id: 'support', label: 'Customer Support Agent',
        description: 'Helps customers and providers with their accounts and bookings.',
        permissions: ['dashboard.view', 'activity.view', 'marketplace.view', 'trust.view', 'comms.view', 'activity.users'],
    },
    {
        id: 'finance', label: 'Finance Administrator',
        description: 'Transactions, refunds, payouts and reconciliation.',
        permissions: ['dashboard.view', 'activity.view', 'finance.summary', 'finance.view', 'analytics.view'],
    },
    {
        id: 'trust_safety', label: 'Trust and Safety Moderator',
        description: 'Disputes, reports, restrictions and verification issues.',
        permissions: ['dashboard.view', 'activity.view', 'marketplace.view', 'trust.view', 'content.view', 'documents.view', 'reputation.view', 'bluetick.override'],
    },
    {
        id: 'content', label: 'Content Moderator',
        description: 'Videos, homepage content, help articles and translations.',
        permissions: ['dashboard.view', 'content.view', 'comms.view'],
    },
    {
        id: 'analytics', label: 'Analytics Viewer',
        description: 'Read-only reporting.',
        permissions: ['dashboard.view', 'analytics.view', 'finance.summary', 'location.view', 'usage.view', 'reputation.view'],
    },
    {
        id: 'technical', label: 'Technical Administrator',
        description: 'System health, integrations, matching configuration and security events.',
        permissions: ['dashboard.view', 'activity.view', 'system.view', 'security.view', 'location.view', 'location.configure', 'usage.view'],
    },
];

/**
 * What each permission allows per kind of record. Module actions use these same
 * keys, so the Roles and Permissions matrix matches what the screens enforce.
 * A missing verb means the action doesn't exist for that record (e.g. nobody
 * deletes a payment: it is reversed or refunded, and the history is kept).
 */
export const RECORD_ACCESS = [
    ['Customers',                    { view: 'marketplace.view', edit: 'trust.view', export: 'analytics.view', delete: 'admin.manage' }],
    ['Service providers',            { view: 'marketplace.view', edit: 'trust.view', approve: 'providers.review', export: 'analytics.view', delete: 'admin.manage' }],
    ['Provider applications',        { view: 'providers.review', edit: 'providers.review', approve: 'providers.review' }],
    ['Jobs, quotations and bookings',{ view: 'marketplace.view', edit: 'marketplace.view', export: 'analytics.view' }],
    ['Service categories',           { view: 'marketplace.view', create: 'admin.manage', edit: 'admin.manage' }],
    ['Transactions',                 { view: 'finance.view', export: 'finance.view' }],
    ['Refunds and payouts',          { view: 'finance.view', approve: 'finance.view', export: 'finance.view' }],
    ['Disputes and reports',         { view: 'trust.view', edit: 'trust.view', approve: 'trust.view' }],
    ['Videos, reviews and content',  { view: 'content.view', create: 'content.view', edit: 'content.view', approve: 'content.view', delete: 'content.view' }],
    ['Templates and campaigns',      { view: 'comms.view', create: 'comms.view', edit: 'comms.view' }],
    ['Analytics and saved reports',  { view: 'analytics.view', create: 'analytics.view', export: 'analytics.view' }],
    ['Matching configuration',       { view: 'location.view', edit: 'location.configure' }],
    ['System and integrations',      { view: 'system.view', edit: 'system.view' }],
    ['Audit and security logs',      { view: 'security.view', export: 'security.view' }],
    ['Verification documents',       { view: 'documents.view', approve: 'documents.review' }],
    ['Verification applications',    { view: 'documents.review', approve: 'documents.review', edit: 'verification.configure' }],
    ['Document requirements',        { view: 'documents.review', create: 'verification.configure', edit: 'verification.configure', delete: 'verification.configure' }],
    ['Blue tick rules',              { view: 'reputation.view', edit: 'bluetick.configure' }],
    ['Blue tick decisions',          { view: 'reputation.view', approve: 'bluetick.override' }],
    ['App usage (aggregate)',        { view: 'usage.view', export: 'usage.view' }],
    ['User activity and presence',   { view: 'activity.users', export: 'activity.users' }],
    ['Admin users, roles, settings', { view: 'admin.manage', create: 'admin.manage', edit: 'admin.manage', delete: 'admin.manage' }],
];

/** The signed-in administrator's main role key (an account may hold several) */
export const currentRoleId = computed(() => auth.user?.roles?.[0] || null);

/** The signed-in administrator's role, as shown in the admin menus */
export function currentRole() {
    const roles = auth.user?.roles || [];
    const found = ROLES.filter(r => roles.includes(r.id));
    if (found.length) return { ...found[0], label: found.map(r => r.label).join(', ') };
    return { id: null, label: 'No admin role', description: '', permissions: [] };
}

/**
 * Whether the signed-in administrator holds a permission, as reported by the
 * server. Only decides what to show: Laravel checks every request itself.
 * No permission named → open to every administrator.
 */
export function can(permission) {
    if (!permission) return true;
    return (auth.user?.permissions || []).includes(permission);
}

/** Whether the account may use the admin area at all */
export function isAdmin() {
    return (auth.user?.permissions || []).length > 0;
}
