import { reactive } from 'vue';
import { api } from '@/api/client.js';

/**
 * An invitation link (?ref=WM-XXXXXX) opened in this browser. The server
 * remembers it in a cookie for the set number of days and credits the
 * inviter if this person signs up; here we only keep the inviter's short
 * name to say "Invited by Robert S." on the sign-up page.
 */
export const invitation = reactive({ inviter: null });

let noted = '';
export async function noteInvitation(code) {
    if (!code || code === noted) return;
    noted = code;
    try {
        const r = (await api('/referrals/visit', { method: 'POST', body: { code: String(code).slice(0, 20) } })).data;
        if (r.inviter) invitation.inviter = r.inviter;
    } catch { /* an invitation is a bonus: nothing else depends on it */ }
}
