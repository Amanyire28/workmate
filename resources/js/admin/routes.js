/**
 * Admin routes, generated from the navigation registry. The dashboard and Activity
 * Centre have their own pages; every other module renders through ModulePage,
 * which picks the workspace its definition asks for (admin/modules/*).
 * Everything is lazy-loaded: customers never download the admin bundle.
 */
import { navItems, findNavItem, itemPermissions } from './navigation.js';

const pages = {
    dashboard: () => import('./pages/ExecutiveDashboard.vue'),
    activity:  () => import('./pages/ActivityCentre.vue'),
    'verification-requirements': () => import('./pages/VerificationRequirementsPage.vue'),
    'bluetick-rules': () => import('./pages/BlueTickRulesPage.vue'),
    'live-presence': () => import('./pages/LivePresencePage.vue'),
    legal: () => import('./pages/LegalDocumentsPage.vue'),
    'commission-shadow': () => import('./pages/CommissionShadowPage.vue'),
    'payment-disputes': () => import('./pages/PaymentDisputesPage.vue'),
    settlements: () => import('./pages/SettlementsPage.vue'),
    'commission-payments': () => import('./pages/CommissionPaymentsPage.vue'),
    'financial-administration': () => import('./pages/FinancialAdministrationPage.vue'),
};
const modulePage = () => import('./pages/ModulePage.vue');
// Finance screens with no real data behind them yet. They stay hidden from the menu and keep their permission check, but a direct
// address shows "Service currently not available." instead of sample records.
const unavailablePage = () => import('./pages/ServiceUnavailablePage.vue');
const UNAVAILABLE = ['finance-overview', 'transactions', 'commissions', 'refunds', 'payouts', 'reconciliation', 'finance-reports'];
const tabbedPage = () => import('./pages/TabbedPage.vue');

export const adminRoutes = [
    {
        path: '/admin',
        component: () => import('./layouts/AdminLayout.vue'),
        meta: { layout: 'admin' },
        children: [
            // A screen shown as a tab of another opens there, on its tab
            ...navItems.filter(item => item.tabOf).map(item => ({
                path: item.path.replace(/^\/admin\/?/, ''),
                redirect: (to) => ({ path: findNavItem(item.tabOf).path, query: { ...to.query, tab: item.id } }),
            })),
            ...navItems.filter(item => !item.tabOf).map(item => ({
                // Child paths are relative to /admin
                path: item.path.replace(/^\/admin\/?/, ''),
                component: UNAVAILABLE.includes(item.id) ? unavailablePage : (item.tabs ? tabbedPage : (pages[item.id] || modulePage)),
                meta: {
                    navId: item.id,
                    permission: item.permission,
                    // Any one of these opens it (a tabbed screen)
                    permissions: itemPermissions(item),
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
