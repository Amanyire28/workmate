/**
 * Admin routes, generated from the navigation registry. The dashboard and Activity
 * Centre have their own pages; every other module renders through ModulePage,
 * which picks the workspace its definition asks for (admin/modules/*).
 * Everything is lazy-loaded: customers never download the admin bundle.
 */
import { navItems } from './navigation.js';

const pages = {
    dashboard: () => import('./pages/ExecutiveDashboard.vue'),
    activity:  () => import('./pages/ActivityCentre.vue'),
    'verification-requirements': () => import('./pages/VerificationRequirementsPage.vue'),
    'bluetick-rules': () => import('./pages/BlueTickRulesPage.vue'),
    'live-presence': () => import('./pages/LivePresencePage.vue'),
};
const modulePage = () => import('./pages/ModulePage.vue');

export const adminRoutes = [
    {
        path: '/admin',
        component: () => import('./layouts/AdminLayout.vue'),
        meta: { layout: 'admin' },
        children: [
            ...navItems.map(item => ({
                // Child paths are relative to /admin
                path: item.path.replace(/^\/admin\/?/, ''),
                component: pages[item.id] || modulePage,
                meta: {
                    navId: item.id,
                    permission: item.permission,
                    groupLabel: item.group.label,
                    pageLabel: item.label,
                    title: `${item.label} – WorkMate Admin`,
                },
            })),
            // Review workspace for one verification application
            {
                path: 'verification/applications/:id',
                component: () => import('./pages/VerificationReviewPage.vue'),
                meta: { navId: 'verification-applications', permission: 'documents.review', groupLabel: 'Verification Management', pageLabel: 'Application review', title: 'Verification review – WorkMate Admin' },
            },
            { path: ':pathMatch(.*)*', redirect: '/admin' },
        ],
    },
];
