import { createRouter, createWebHistory } from 'vue-router';

import HomePage              from '@/pages/HomePage.vue';
import ExplorePage           from '@/pages/ExplorePage.vue';
import RecommendedWorkersPage from '@/pages/RecommendedWorkersPage.vue';
import BrowseServicesPage    from '@/pages/BrowseServicesPage.vue';
import ProviderDetailsPage   from '@/pages/ProviderDetailsPage.vue';
import RequestServicePage    from '@/pages/RequestServicePage.vue';
import PostJobPage           from '@/pages/PostJobPage.vue';
import FindWorkPage          from '@/pages/FindWorkPage.vue';
import JobDetailsPage        from '@/pages/JobDetailsPage.vue';
import ApplyJobPage          from '@/pages/ApplyJobPage.vue';
import MyActivityPage        from '@/pages/MyActivityPage.vue';
import AccountPage           from '@/pages/AccountPage.vue';
import MessagesPage          from '@/pages/MessagesPage.vue';
import ChatPage              from '@/pages/ChatPage.vue';
import { adminRoutes }       from '@/admin/routes.js';
import { auth, loadSession, safeRedirect } from '@/stores/auth.js';
import { loadCatalog } from '@/stores/catalog.js';
import { noteInvitation } from '@/stores/referral.js';

const routes = [
    { path: '/',                     component: HomePage,               meta: { title: 'WorkMate – Find Trusted Service Providers' } },
    { path: '/explore',              component: ExplorePage,            meta: { title: 'Services – WorkMate' } },
    { path: '/workers',              component: RecommendedWorkersPage, meta: { title: 'Top-rated Providers – WorkMate' } },
    { path: '/browse',               component: BrowseServicesPage,     meta: { title: 'Find a Service Provider – WorkMate' } },
    { path: '/provider/:id',         component: ProviderDetailsPage,    meta: { title: 'Provider – WorkMate' } },
    { path: '/provider/:id/request', component: RequestServicePage,     meta: { title: 'Request a Service – WorkMate', requiresAuth: true } },
    { path: '/post-job',             component: PostJobPage,            meta: { title: 'Post a Job – WorkMate', requiresAuth: true } },
    { path: '/find-work',            component: FindWorkPage,           meta: { title: 'Find Work – WorkMate' } },
    { path: '/job/:id',              component: JobDetailsPage,         meta: { title: 'Job Details – WorkMate' } },
    { path: '/job/:id/apply',        component: ApplyJobPage,           meta: { title: 'Apply for a Job – WorkMate', requiresAuth: true } },
    { path: '/activity',             component: MyActivityPage,         meta: { title: 'My Work – WorkMate', requiresAuth: true } },
    { path: '/account',              component: AccountPage,            meta: { title: 'My Account – WorkMate' } },
    { path: '/account/reports',      component: () => import('@/pages/ReportsPage.vue'), meta: { title: 'Reports and complaints – WorkMate', requiresAuth: true } },
    { path: '/account/verification', component: () => import('@/pages/GetVerifiedPage.vue'), meta: { title: 'Get Verified – WorkMate', requiresAuth: true } },
    { path: '/legal',                component: () => import('@/pages/LegalPage.vue'), meta: { title: 'Legal – WorkMate' } },
    { path: '/legal/:key',           component: () => import('@/pages/LegalPage.vue'), meta: { title: 'Legal – WorkMate' } },
    { path: '/get-started',          component: () => import('@/pages/GetStartedPage.vue'), meta: { title: 'Welcome – WorkMate', requiresAuth: true } },
    { path: '/start-earning',        component: () => import('@/pages/StartEarningPage.vue'), meta: { title: 'Start earning – WorkMate', requiresAuth: true } },
    { path: '/account/devices',      component: () => import('@/pages/DevicesPage.vue'), meta: { title: 'Devices – WorkMate', requiresAuth: true } },
    { path: '/account/invite',       component: () => import('@/pages/InviteFriendsPage.vue'), meta: { title: 'Invite Friends – WorkMate', requiresAuth: true } },
    { path: '/account/legal',        component: () => import('@/pages/LegalSettingsPage.vue'), meta: { title: 'Legal and privacy – WorkMate', requiresAuth: true } },
    { path: '/saved',                component: () => import('@/pages/SavedProvidersPage.vue'), meta: { title: 'Saved providers – WorkMate', requiresAuth: true } },
    { path: '/notifications',        component: () => import('@/pages/NotificationsPage.vue'), meta: { title: 'Notifications – WorkMate', requiresAuth: true } },
    { path: '/community',            component: () => import('@/pages/CommunityPage.vue'), meta: { title: 'Community – WorkMate' } },
    { path: '/community/saved',      component: () => import('@/pages/CommunityPage.vue'), meta: { title: 'Saved posts – WorkMate', requiresAuth: true } },
    { path: '/community/people',     component: () => import('@/pages/CommunityPeoplePage.vue'), meta: { title: 'Discover people – WorkMate' } },
    { path: '/community/people/:id', component: () => import('@/pages/CommunityPersonPage.vue'), meta: { title: 'Community – WorkMate' } },
    { path: '/community/post/:id',   component: () => import('@/pages/CommunityPostPage.vue'), meta: { title: 'Community post – WorkMate' } },
    { path: '/messages',             component: MessagesPage,           meta: { title: 'Messages – WorkMate', requiresAuth: true } },
    // A chat fills the screen on phones: no app bar or tab bar, like a messaging app
    { path: '/messages/:id',         component: ChatPage,               meta: { title: 'Chat – WorkMate', requiresAuth: true, immersive: true } },
    // A chat opened from the provider side (customers reach chats by provider id)
    { path: '/messages/c/:conversationId', component: ChatPage,         meta: { title: 'Chat – WorkMate', requiresAuth: true, immersive: true } },
    // Signing in: guestOnly pages send signed-in people on to their account
    { path: '/sign-in',              alias: '/login', component: () => import('@/pages/SignInPage.vue'), meta: { title: 'Sign in – WorkMate', guestOnly: true } },
    { path: '/register',             component: () => import('@/pages/RegisterPage.vue'),       meta: { title: 'Create an account – WorkMate', guestOnly: true } },
    { path: '/forgot-password',      component: () => import('@/pages/ForgotPasswordPage.vue'), meta: { title: 'Reset your password – WorkMate', guestOnly: true } },
    { path: '/reset-password',       component: () => import('@/pages/ResetPasswordPage.vue'),  meta: { title: 'Choose a new password – WorkMate' } },
    // Admin area: own layout, lazy-loaded (see admin/routes.js)
    ...adminRoutes,
    // Catch-all — redirect to home
    { path: '/:pathMatch(.*)*',      redirect: '/' },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
    scrollBehavior(to, from, savedPosition) {
        if (savedPosition) return savedPosition;
        // Filter changes on the same page (query only) shouldn't jump to the top
        if (to.path === from.path) return false;
        return { top: 0 };
    },
});

// Already signed in: skip the sign-in and register pages
router.beforeEach(async (to) => {
    // An invitation link: remember who invited them (the page carries on straight away)
    if (to.query.ref) noteInvitation(to.query.ref);

    // The admin area: signed-in administrators only (the API checks every request too)
    if (to.meta?.layout === 'admin') {
        const user = await loadSession();
        if (!user) {
            return auth.status === 'ready' ? { path: '/sign-in', query: { redirect: to.fullPath } } : { path: '/account' };
        }
        if (!(user.permissions || []).length) return { path: '/account', query: { admin: 'denied' } };
        // Load before the admin screens' code runs, so their filters start with real choices
        const { loadAdminData } = await import('@/admin/data/liveData.js');
        await loadAdminData().catch(() => { /* each page shows the error with a retry */ });
        return true;
    }

    // Site pages show marketplace data from the API; load it before the first one
    await loadCatalog();

    if (to.meta?.guestOnly) {
        const user = await loadSession();
        return user ? safeRedirect(to.query.redirect) : true;
    }

    // Pages that act for an account: sign in first, then come straight back.
    // (The API checks every request too; this is for a smooth experience.)
    if (to.meta?.requiresAuth) {
        const user = await loadSession();
        if (!user && auth.status === 'ready') return { path: '/sign-in', query: { redirect: to.fullPath } };
    }
    return true;
});

// Update document title per route
router.afterEach((to) => {
    if (to.meta?.title) document.title = to.meta.title;
});

export default router;
