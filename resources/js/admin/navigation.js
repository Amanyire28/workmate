/**
 * Admin navigation registry: the single source for the sidebar, breadcrumbs and
 * routes. Each item's page is defined in admin/modules/ (or pages/ for the
 * dashboard and Activity Centre).
 *
 * hidden: kept and routed, but left out of the sidebar. Used for screens of
 *         features that aren't built yet; remove the flag when they are.
 * tabs:   the screen shows these items as tabs (the first is itself).
 * tabOf:  shown as a tab of that item; its own path opens that tab.
 */
export const navGroups = [
    {
        id: 'overview', label: 'Overview', icon: 'bi-speedometer2',
        items: [
            { id: 'dashboard', label: 'Executive Dashboard', path: '/admin', permission: 'dashboard.view',
              summary: 'Headline metrics, trends, priorities and recent activity across the marketplace.' },
            { id: 'activity', label: 'Activity and Audit Log', tabs: ['activity', 'audit'], path: '/admin/activity', permission: 'activity.view',
              summary: 'Everything that happened across WorkMate, and the audit log of administrator actions with what changed.' },
            { id: 'alerts', label: 'Operational Alerts', hidden: true, path: '/admin/alerts', permission: 'dashboard.view',
              summary: 'Alert rules, thresholds and acknowledgement for issues that need an administrator.' },
        ],
    },
    {
        id: 'marketplace', label: 'Marketplace', icon: 'bi-shop',
        items: [
            { id: 'customers', label: 'Customers', path: '/admin/marketplace/customers', permission: 'marketplace.view',
              summary: 'Customer directory with profile, activity, bookings, transactions, reports and audit history.' },
            { id: 'providers', label: 'Service Providers', path: '/admin/marketplace/providers', permission: 'marketplace.view',
              summary: 'Provider directory and workspace: services, base location, service areas, verification records, performance and restrictions.' },
            { id: 'applications', label: 'Provider Applications', path: '/admin/marketplace/applications', permission: 'providers.review',
              summary: 'Review queue with approve, reject and request-information actions, reasons and audit history.' },
            { id: 'jobs', label: 'Jobs and Requests', path: '/admin/marketplace/jobs', permission: 'marketplace.view',
              summary: 'Job requests with matching status, assigned provider, related booking and failure reasons.' },
            { id: 'quotations', label: 'Job Applications', path: '/admin/marketplace/quotations', permission: 'marketplace.view',
              summary: 'Providers’ applications to posted jobs, with price, start time and whether they were hired.' },
            { id: 'bookings', label: 'Bookings', hidden: true, path: '/admin/marketplace/bookings', permission: 'marketplace.view',
              summary: 'Booking lifecycle, timeline, payment state, disputes and recorded interventions.' },
            { id: 'profession-suggestions', label: 'Typed Professions', path: '/admin/marketplace/typed-professions', permission: 'providers.review',
              summary: 'Professions applicants typed under “Other”: add them to the list, match them to an existing one or decline them.' },
            { id: 'categories', label: 'Service Categories', path: '/admin/marketplace/categories', permission: 'marketplace.view',
              summary: 'Service categories, keywords and display order.' },
            { id: 'portfolios', label: 'Provider Portfolios', hidden: true, path: '/admin/marketplace/portfolios', permission: 'content.view',
              summary: 'Portfolio images submitted by providers and their moderation state.' },
            { id: 'reviews', label: 'Reviews and Ratings', path: '/admin/marketplace/reviews', permission: 'trust.view',
              summary: 'Customer feedback, reported reviews and moderation decisions.' },
        ],
    },
    {
        id: 'location', label: 'Places', icon: 'bi-geo-alt',
        items: [
            { id: 'location-overview', label: 'Places', tabs: ['location-overview', 'coverage'], path: '/admin/location/overview', permission: 'location.view',
              summary: 'Providers and demand by place, and service coverage by place and category.' },
            { id: 'coverage', label: 'Provider Coverage', hidden: true, tabOf: 'location-overview', path: '/admin/location/coverage', permission: 'location.view',
              summary: 'Service-area coverage, coverage gaps and providers missing valid coordinates.' },
            { id: 'search-analytics', label: 'Search Analytics', hidden: true, path: '/admin/location/search-analytics', permission: 'location.view',
              summary: 'Search volume, nearby-match rate, radius expansion and geolocation errors.' },
            { id: 'unmatched', label: 'Unmatched Searches', hidden: true, path: '/admin/location/unmatched', permission: 'location.view',
              summary: 'Searches that found no suitable provider, grouped by service and area.' },
            { id: 'matching', label: 'Matching Configuration', hidden: true, tabOf: 'platform', path: '/admin/location/matching', permission: 'location.configure',
              summary: 'Search radii, fallback rules, service-area matching and ranking factors, with their effects explained.' },
        ],
    },
    {
        id: 'trust', label: 'Trust and Safety', icon: 'bi-shield-check',
        items: [
            { id: 'disputes', label: 'Disputes and Complaints', hidden: true, path: '/admin/trust/disputes', permission: 'trust.view',
              summary: 'Case workflow with severity, assignment, restricted notes, evidence references and resolution.' },
            { id: 'reports', label: 'Reports and Abuse', path: '/admin/trust/reports', permission: 'trust.view',
              summary: 'Spam, abuse, impersonation and fraud reports with escalation queues.' },
            { id: 'moderation', label: 'Content Moderation', hidden: true, path: '/admin/trust/moderation', permission: 'content.view',
              summary: 'Shared moderation queue for reviews, portfolio items and profile text.' },
            { id: 'restricted', label: 'Restricted Accounts', hidden: true, path: '/admin/trust/restricted', permission: 'trust.view',
              summary: 'Suspended and restricted accounts with reasons, expiry and appeal state.' },
        ],
    },
    {
        id: 'verification', label: 'Verification Management', icon: 'bi-file-earmark-check',
        items: [
            { id: 'verification-applications', label: 'Applications', path: '/admin/verification/applications', permission: 'documents.review',
              summary: 'Document verification applications: review each document, request information, approve or reject. Approval grants the WorkMate Verified badge.' },
            { id: 'verification-requirements', label: 'Document Requirements', path: '/admin/verification/requirements', permission: 'documents.review',
              summary: 'Which documents each profession must provide, with versions so submitted applications keep the rules they were made under.' },
        ],
    },
    {
        id: 'reputation', label: 'Trust and Reputation', icon: 'bi-patch-check',
        items: [
            { id: 'bluetick', label: 'Blue Tick Management', hidden: true, path: '/admin/reputation/blue-tick', permission: 'reputation.view',
              summary: 'Automatic blue tick status for every provider, with the evidence and reasons behind it.' },
            { id: 'bluetick-rules', label: 'Blue Tick Rules', hidden: true, path: '/admin/reputation/rules', permission: 'reputation.view',
              summary: 'The rules that award the blue tick automatically: thresholds, evaluation mode, preview and versioned publishing.' },
        ],
    },
    {
        id: 'finance', label: 'Finance', icon: 'bi-cash-stack',
        items: [
            { id: 'finance-overview', label: 'Financial Overview', hidden: true, path: '/admin/finance/overview', permission: 'finance.view',
              summary: 'Gross transaction value, commission, refunds, fees and net revenue kept as separate figures.' },
            { id: 'transactions', label: 'Transactions', hidden: true, path: '/admin/finance/transactions', permission: 'finance.view',
              summary: 'Payments with provider references, fees, settlement and failure or reversal reasons.' },
            { id: 'commissions', label: 'Commissions and Fees', hidden: true, path: '/admin/finance/commissions', permission: 'finance.view',
              summary: 'Commission rules and collected platform fees.' },
            { id: 'refunds', label: 'Refunds', hidden: true, path: '/admin/finance/refunds', permission: 'finance.view',
              summary: 'Refund requests, approvals and their status, with an audit trail.' },
            { id: 'payouts', label: 'Provider Payouts', hidden: true, path: '/admin/finance/payouts', permission: 'finance.view',
              summary: 'Payouts to providers, failures and reconciliation status.' },
            { id: 'reconciliation', label: 'Reconciliation', hidden: true, path: '/admin/finance/reconciliation', permission: 'finance.view',
              summary: 'Unmatched or duplicate payment references and settlement differences.' },
            { id: 'finance-reports', label: 'Financial Reports', hidden: true, path: '/admin/finance/reports', permission: 'finance.view',
              summary: 'Period statements ready for export.' },
        ],
    },
    {
        id: 'content', label: 'Content and Engagement', icon: 'bi-collection-play',
        items: [
            { id: 'in-action', label: 'WorkMate in Action', hidden: true, path: '/admin/content/in-action', permission: 'content.view',
              summary: 'Directory of provider short videos with processing, publication and report status.' },
            { id: 'video-moderation', label: 'Video Moderation', hidden: true, path: '/admin/content/video-moderation', permission: 'content.view',
              summary: 'Preview, reports, notes, and approve, reject or remove with a required reason.' },
            { id: 'homepage', label: 'Homepage Content', hidden: true, path: '/admin/content/homepage', permission: 'content.view',
              summary: 'Homepage sections and featured categories.' },
            { id: 'announcements', label: 'Announcements', hidden: true, path: '/admin/content/announcements', permission: 'content.view',
              summary: 'Announcements shown in the app, with scheduling.' },
            { id: 'help', label: 'Help Centre', hidden: true, path: '/admin/content/help', permission: 'content.view',
              summary: 'Help articles in English and Luganda.' },
            { id: 'translations', label: 'Translation Management', hidden: true, path: '/admin/content/translations', permission: 'content.view',
              summary: 'English and Luganda strings, missing translations and review status.' },
        ],
    },
    {
        id: 'comms', label: 'Communications', icon: 'bi-megaphone',
        items: [
            { id: 'notifications', label: 'Notification Centre', hidden: true, path: '/admin/comms/notifications', permission: 'comms.view',
              summary: 'In-app, email, SMS and push notifications by channel.' },
            { id: 'push', label: 'Push Announcements', path: '/admin/comms/push', permission: 'comms.view',
              summary: 'Send a push notification to every device that turned notifications on.' },
            { id: 'templates', label: 'Message Templates', hidden: true, path: '/admin/comms/templates', permission: 'comms.view',
              summary: 'Templates with English and Luganda variants.' },
            { id: 'campaigns', label: 'Campaigns', hidden: true, path: '/admin/comms/campaigns', permission: 'comms.view',
              summary: 'Announcement campaigns, recipient groups and scheduling.' },
            { id: 'delivery', label: 'Delivery Logs', hidden: true, path: '/admin/comms/delivery', permission: 'comms.view',
              summary: 'Sending status and delivery status tracked separately, with a failed-delivery queue.' },
            { id: 'preferences', label: 'Notification Preferences', hidden: true, path: '/admin/comms/preferences', permission: 'comms.view',
              summary: 'Default and per-user notification preferences.' },
        ],
    },
    {
        id: 'analytics', label: 'Usage', icon: 'bi-graph-up',
        items: [
            { id: 'marketplace-analytics', label: 'Marketplace Analytics', hidden: true, path: '/admin/analytics/marketplace', permission: 'analytics.view',
              summary: 'Growth, activation, retention and repeat bookings.' },
            { id: 'customer-analytics', label: 'Customer Analytics', hidden: true, path: '/admin/analytics/customers', permission: 'analytics.view',
              summary: 'Customer cohorts and behaviour.' },
            { id: 'provider-analytics', label: 'Provider Analytics', hidden: true, path: '/admin/analytics/providers', permission: 'analytics.view',
              summary: 'Supply by category, response times and cancellation rates.' },
            { id: 'demand', label: 'Service Demand', hidden: true, path: '/admin/analytics/demand', permission: 'analytics.view',
              summary: 'Demand by category and area, and repeat demand.' },
            { id: 'geo-analytics', label: 'Geographic Analytics', hidden: true, path: '/admin/analytics/geographic', permission: 'analytics.view',
              summary: 'Supply and demand compared across areas.' },
            { id: 'conversion', label: 'Conversion and Retention', hidden: true, path: '/admin/analytics/conversion', permission: 'analytics.view',
              summary: 'Search → profile view → enquiry → booking → completion.' },
            { id: 'report-builder', label: 'Report Builder', hidden: true, path: '/admin/analytics/reports', permission: 'analytics.view',
              summary: 'Saved, export-ready reports.' },
            { id: 'app-usage', label: 'App Usage', hidden: true, path: '/admin/analytics/app-usage', permission: 'usage.view',
              summary: 'Active users, app launches, sessions and new versus returning users. Users, devices and events are counted separately.' },
            { id: 'downloads', label: 'Downloads and Installs', path: '/admin/analytics/downloads', permission: 'usage.view',
              summary: 'Install prompts, confirmed web app installs and first launches, with what each platform can and can’t measure.' },
            { id: 'live-presence', label: 'Live Presence', hidden: true, path: '/admin/analytics/presence', permission: 'activity.users',
              summary: 'Who is online now, recently active or offline, for customers and providers separately. Presence is not availability.' },
            { id: 'activity-explorer', label: 'User Activity', hidden: true, path: '/admin/analytics/user-activity', permission: 'activity.users',
              summary: 'Searchable activity per account: last seen, launches, sessions, platform and presence.' },
        ],
    },
    {
        id: 'system', label: 'System', icon: 'bi-cpu',
        items: [
            { id: 'health', label: 'System Health', hidden: true, path: '/admin/system/health', permission: 'system.view',
              summary: 'Application, API, storage and email health with timestamps.' },
            { id: 'errors', label: 'Error Monitoring', hidden: true, path: '/admin/system/errors', permission: 'system.view',
              summary: 'Grouped application errors, never raw secrets or tokens.' },
            { id: 'integrations', label: 'Integration Status', hidden: true, path: '/admin/system/integrations', permission: 'system.view',
              summary: 'Payments, SMS, email, storage and video processing connections.' },
            { id: 'queues', label: 'Background Jobs', hidden: true, path: '/admin/system/queues', permission: 'system.view',
              summary: 'Queue status and failed jobs.' },
            { id: 'scheduled', label: 'Scheduled Tasks', hidden: true, path: '/admin/system/scheduled', permission: 'system.view',
              summary: 'Scheduled task runs and their outcomes.' },
            { id: 'audit', label: 'Audit Logs', hidden: true, tabOf: 'activity', path: '/admin/system/audit', permission: 'security.view',
              summary: 'Administrator actions: approvals, suspensions, financial adjustments, configuration and role changes.' },
            { id: 'security', label: 'Security Events', hidden: true, path: '/admin/system/security', permission: 'security.view',
              summary: 'Failed sign-ins and other security events.' },
        ],
    },
    {
        id: 'administration', label: 'Administration', icon: 'bi-gear',
        items: [
            { id: 'admin-users', label: 'Admin Users', path: '/admin/settings/users', permission: 'admin.manage',
              summary: 'Administrator accounts and their roles.' },
            { id: 'roles', label: 'Roles and Permissions', path: '/admin/settings/roles', permission: 'admin.manage',
              summary: 'Which roles can view, create, edit, approve, export or delete each kind of record.' },
            { id: 'platform', label: 'Settings', tabs: ['platform', 'matching'], path: '/admin/settings/platform', permission: 'admin.manage',
              summary: 'Platform-wide settings, and how nearby search finds and orders providers.' },
            { id: 'rules', label: 'Business Rules', hidden: true, path: '/admin/settings/rules', permission: 'admin.manage',
              summary: 'Booking states, commission rates and cancellation rules.' },
            { id: 'flags', label: 'Feature Flags', hidden: true, path: '/admin/settings/flags', permission: 'admin.manage',
              summary: 'Features switched on or off, by audience.' },
            { id: 'data', label: 'Data Management', hidden: true, path: '/admin/settings/data', permission: 'admin.manage',
              summary: 'Retention, exports and data-subject requests.' },
        ],
    },
];

export const navItems = navGroups.flatMap(g => g.items.map(item => ({ ...item, group: g })));

/** Permissions that open an item: any one of its tabs' */
export function itemPermissions(item) {
    return item.tabs ? item.tabs.map(id => findNavItem(id)?.permission).filter(Boolean) : [item.permission];
}

export function findNavItem(id) {
    return navItems.find(i => i.id === id) || null;
}
