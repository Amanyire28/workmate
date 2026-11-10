import { api } from '@/api/client.js';

/**
 * Admin actions that are connected to the Laravel API. Each entry gives a
 * module's action a `run` step; actions without one aren't offered (the
 * workspace says how many aren't connected yet). The server checks the
 * permission and records every change in the audit log.
 *
 * Record ids carry a prefix (C-12, P-3, A-5…); the numeric part is the
 * database id the API expects.
 */

const num = (id) => Number(String(id).replace(/^[A-Z]+-/, ''));
const post = (path, body) => api(path, { method: 'POST', body });
const patch = (path, body) => api(path, { method: 'PATCH', body });

const suspendAccount = (r, v) => post(`/admin/users/${num(r.id)}/suspend`, { reason: v.reason });
const reactivateAccount = (r, v) => post(`/admin/users/${num(r.id)}/reactivate`, { reason: v.reason });
const review = (status) => (r, v) => patch(`/admin/reviews/${num(r.id)}`, { status, reason: v.reason });

export const CONNECTED = {
    customers: {
        suspend: suspendAccount,
        reactivate: reactivateAccount,
    },
    providers: {
        // A provider's suspension ends their listing; their customer account stays
        suspend: (r, v) => post(`/admin/providers/${num(r.id)}/suspend`, { reason: v.reason }),
        reactivate: (r, v) => post(`/admin/providers/${num(r.id)}/reinstate`, { reason: v.reason }),
    },
    applications: {
        approve: (r, v) => post(`/admin/provider-applications/${num(r.id)}/approve`, { internal_note: v.reason || null }),
        'request-info': (r, v) => post(`/admin/provider-applications/${num(r.id)}/request-information`, { reason: v.reason }),
        reject: (r, v) => post(`/admin/provider-applications/${num(r.id)}/reject`, { reason: v.reason }),
    },
    'profession-suggestions': {
        add: (r, v) => post(`/admin/profession-suggestions/${num(r.id)}/add`, { name: v.name, plural_name: v.plural_name, category: v.category }),
        match: (r, v) => post(`/admin/profession-suggestions/${num(r.id)}/match`, { profession: v.profession, reason: v.reason || null }),
        decline: (r, v) => post(`/admin/profession-suggestions/${num(r.id)}/decline`, { reason: v.reason }),
    },
    referrals: {
        approve: (r, v) => post(`/admin/referrals/${r.referralId}/approve`, { reason: v.reason }),
        reject: (r, v) => post(`/admin/referrals/${r.referralId}/reject`, { reason: v.reason }),
        revoke: (r, v) => post(`/admin/referrals/${r.referralId}/revoke`, { reason: v.reason }),
    },
    'referral-badges': {
        create: (v) => post('/admin/milestones', { name: v.name, description: v.description, threshold: v.threshold, icon: v.icon }),
        edit: (r, v) => patch(`/admin/milestones/${r.milestoneId}`, { name: v.name, description: v.description, threshold: v.threshold, icon: v.icon, reason: v.reason }),
        'turn-off': (r, v) => patch(`/admin/milestones/${r.milestoneId}`, { is_active: false, reason: v.reason }),
        'turn-on': (r, v) => patch(`/admin/milestones/${r.milestoneId}`, { is_active: true, reason: v.reason }),
    },
    reviews: {
        dismiss: review('published'),
        hide: review('hidden'),
        restore: review('published'),
    },
    categories: {
        create: (v) => post('/admin/categories', { name: v.name, description: v.description || null, icon: v.icon }),
        edit: (r, v) => patch(`/admin/categories/${r.id}`, { name: v.name, description: v.description || null, icon: v.icon }),
        'move-up': (r) => post(`/admin/categories/${r.id}/move`, { direction: 'up' }),
        'move-down': (r) => post(`/admin/categories/${r.id}/move`, { direction: 'down' }),
        hide: (r, v) => post(`/admin/categories/${r.id}/hide`, { reason: v.reason }),
        show: (r, v) => post(`/admin/categories/${r.id}/show`, { reason: v.reason || null }),
    },
    professions: {
        create: (v) => post('/admin/professions', { category: v.category, name: v.name, plural_name: v.plural_name }),
        edit: (r, v) => patch(`/admin/professions/${r.id}`, { name: v.name, plural_name: v.plural_name, category: v.category }),
        hide: (r, v) => post(`/admin/professions/${r.id}/hide`, { reason: v.reason }),
        show: (r, v) => post(`/admin/professions/${r.id}/show`, { reason: v.reason || null }),
    },
    reports: {
        assign: (r) => post(`/admin/reports/${num(r.id)}/assign`, {}),
        'request-info': (r, v) => post(`/admin/reports/${num(r.id)}/request-information`, { question: v.question }),
        resolve: (r, v) => post(`/admin/reports/${num(r.id)}/resolve`, { outcome: v.outcome, message: v.message, warning: v.warning || null, reason: v.reason }),
        note: (r, v) => post(`/admin/reports/${num(r.id)}/note`, { note: v.note }),
        // The reported community post or comment
        'remove-content': (r, v) => (r.community.kind === 'post'
            ? post(`/admin/community/posts/${r.community.postId}/remove`, { reason: v.reason })
            : post(`/admin/community/comments/${r.community.commentId}/remove`, { reason: v.reason })),
        'restore-content': (r, v) => post(`/admin/community/posts/${r.community.postId}/restore`, { reason: v.reason }),
        // Reported work on a provider's profile
        'remove-work': (r, v) => post(`/admin/work/${r.work.id}/remove`, { reason: v.reason }),
        'restore-work': (r, v) => post(`/admin/work/${r.work.id}/restore`, { reason: v.reason }),
    },
    restricted: {
        lift: (r, v) => (r.id.startsWith('P-')
            ? post(`/admin/providers/${num(r.id)}/reinstate`, { reason: v.reason })
            : reactivateAccount(r, v)),
    },
    'admin-users': {
        // One role per administrator in this screen: add the new one, then remove the old
        role: async (r, v) => {
            if (v.role === r.role) return;
            await api(`/admin/users/${num(r.id)}/roles/${v.role}`, { method: 'PUT', body: { reason: v.reason } });
            if (r.role) await api(`/admin/users/${num(r.id)}/roles/${r.role}`, { method: 'DELETE', body: { reason: v.reason } });
        },
        deactivate: suspendAccount,
        reactivate: reactivateAccount,
    },
};

/** Adds `run` to each connected action, in place */
export function connectActions(modules) {
    for (const [moduleId, runs] of Object.entries(CONNECTED)) {
        const def = modules[moduleId];
        if (!def?.actions) continue;
        def.actions = def.actions.map(a => (runs[a.id] ? { ...a, run: runs[a.id] } : a));
        // The screen's "New …" button
        if (runs.create && def.create) def.create = { ...def.create, run: runs.create };
    }
    return modules;
}
