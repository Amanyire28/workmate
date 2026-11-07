<?php

/*
|--------------------------------------------------------------------------
| Administrator permissions and roles
|--------------------------------------------------------------------------
|
| The server-side source of truth for what each admin role may do. The admin
| frontend mirrors this list in resources/js/admin/permissions.js to decide
| what to show; tests/Feature/PermissionDefinitionsTest keeps the two in step.
|
| Every permission key is a Gate ability, so routes are protected with
| middleware('can:finance.view') and code with $user->can('finance.view').
| Run `php artisan db:seed --class=RolesAndPermissionsSeeder` after editing.
|
*/

return [

    'permissions' => [
        'dashboard.view' => 'View the executive dashboard',
        'activity.view' => 'View the operational event log',
        'marketplace.view' => 'View customers, providers, jobs and bookings',
        'providers.review' => 'Review provider applications and verification',
        'catalogue.manage' => 'Add, rename, reorder and hide service categories and professions',
        'location.view' => 'View location intelligence (no precise provider positions)',
        'location.configure' => 'Change matching configuration',
        'trust.view' => 'Work trust and safety cases',
        'finance.summary' => 'See headline revenue figures',
        'finance.view' => 'Work transactions, refunds, payouts and reconciliation',
        'content.view' => 'Moderate videos and manage content and translations',
        'comms.view' => 'Manage notifications, templates and campaigns',
        'comms.send' => 'Send push announcements to everyone who turned them on',
        'analytics.view' => 'View analytics and build reports',
        'system.view' => 'View system health, jobs and integrations',
        'security.view' => 'View audit logs and security events',
        'documents.view' => 'Open private verification documents (each view is logged)',
        'documents.review' => 'Review verification applications and documents',
        'verification.configure' => 'Change document requirements and reopen decided applications',
        'reputation.view' => 'View blue tick eligibility and rules',
        'bluetick.configure' => 'Change and publish blue tick rules',
        'bluetick.override' => 'Suspend, restore or remove a blue tick by override',
        'usage.view' => 'View aggregate app usage and installs',
        'activity.users' => 'View individual users’ activity and online presence',
        'legal.manage' => 'Draft, publish and export legal documents, and set the company facts they show',
        'admin.manage' => 'Manage admin users, roles and platform settings',
    ],

    // '*' grants every permission above. Only super_admin holds admin.manage.
    'roles' => [
        'super_admin' => [
            'name' => 'Super Administrator',
            'description' => 'Full access. Keep to a very small number of people.',
            'permissions' => '*',
        ],
        'operations' => [
            'name' => 'Operations Administrator',
            'description' => 'Runs the marketplace day to day.',
            'permissions' => ['dashboard.view', 'activity.view', 'marketplace.view', 'providers.review', 'catalogue.manage', 'location.view', 'trust.view', 'finance.summary', 'comms.view', 'analytics.view', 'documents.review', 'reputation.view', 'bluetick.configure', 'verification.configure', 'usage.view', 'activity.users'],
        ],
        'provider_review' => [
            'name' => 'Provider Review Administrator',
            'description' => 'Checks and approves provider applications.',
            'permissions' => ['dashboard.view', 'activity.view', 'marketplace.view', 'providers.review', 'documents.view', 'documents.review', 'reputation.view'],
        ],
        'support' => [
            'name' => 'Customer Support Agent',
            'description' => 'Helps customers and providers with their accounts and bookings.',
            'permissions' => ['dashboard.view', 'activity.view', 'marketplace.view', 'trust.view', 'comms.view', 'activity.users'],
        ],
        'finance' => [
            'name' => 'Finance Administrator',
            'description' => 'Transactions, refunds, payouts and reconciliation.',
            'permissions' => ['dashboard.view', 'activity.view', 'finance.summary', 'finance.view', 'analytics.view'],
        ],
        'trust_safety' => [
            'name' => 'Trust and Safety Moderator',
            'description' => 'Disputes, reports, restrictions and verification issues.',
            'permissions' => ['dashboard.view', 'activity.view', 'marketplace.view', 'trust.view', 'content.view', 'documents.view', 'reputation.view', 'bluetick.override'],
        ],
        'content' => [
            'name' => 'Content Moderator',
            'description' => 'Videos, homepage content, help articles and translations.',
            'permissions' => ['dashboard.view', 'content.view', 'comms.view'],
        ],
        'analytics' => [
            'name' => 'Analytics Viewer',
            'description' => 'Read-only reporting.',
            'permissions' => ['dashboard.view', 'analytics.view', 'finance.summary', 'location.view', 'usage.view', 'reputation.view'],
        ],
        'technical' => [
            'name' => 'Technical Administrator',
            'description' => 'System health, integrations, matching configuration and security events.',
            'permissions' => ['dashboard.view', 'activity.view', 'system.view', 'security.view', 'location.view', 'location.configure', 'usage.view'],
        ],
    ],

];
