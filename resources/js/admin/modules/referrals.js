/**
 * Invite Friends (Admin → Invitations): who invited whom, the people who
 * invite the most with their coins, the badges and who holds them. Each
 * counted referral earns its inviter coins, later exchangeable for cash;
 * held referrals wait here for a person to decide.
 */
import { kpi, kpiCount, count, sum, within, historySection, related, formatUGX } from './helpers.js';
import { ranking } from '../components/charts/builders.js';

const WAITING = ['registered', 'review'];
const NOT_COUNTED = ['rejected', 'revoked'];

const memberCols = [
    { key: 'memberName', label: 'Member', sub: r => r.memberId },
    { key: 'status', label: 'Status', format: 'status' },
    { key: 'coins', label: 'Coins', format: 'number', align: 'right' },
    { key: 'joinedAt', label: 'Joined', format: 'relative' },
];

const BADGE_ICONS = [
    ['bi-award', 'Award'], ['bi-star', 'Star'], ['bi-trophy', 'Trophy'], ['bi-gem', 'Gem'],
    ['bi-heart', 'Heart'], ['bi-people', 'People'], ['bi-rocket-takeoff', 'Rocket'], ['bi-fire', 'Flame'],
];

export default {
    /* ================= Who invited whom ================= */
    referrals: {
        type: 'records', entity: 'Referral',
        description: 'Every member who joined through someone’s invitation link. A referral counts once the new member confirms their email; unusual ones wait here for a decision.',
        search: { keys: ['id', 'inviterName', 'inviterId', 'memberName', 'memberId'], placeholder: 'Search inviter or member' },
        tabs: [
            { id: 'review', label: 'Needs a decision', filter: r => r.status === 'review' },
            { id: 'waiting', label: 'Waiting for email', filter: r => r.status === 'registered' },
            { id: 'qualified', label: 'Counted', filter: r => r.status === 'qualified' },
            { id: 'not-counted', label: 'Not counted', filter: r => NOT_COUNTED.includes(r.status) },
            { id: 'flagged', label: 'Flagged', filter: r => r.flagged },
            { id: 'all', label: 'All' },
        ],
        defaultSort: { key: 'joinedAt', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Joined through invitations', rows.length),
            kpiCount('Counted', count(rows, r => r.status === 'qualified')),
            kpiCount('Need a decision', count(rows, r => r.status === 'review')),
            kpiCount('Joined in the last 7 days', count(rows, r => within(r.joinedAt, 7))),
        ],
        columns: [
            { key: 'inviterName', label: 'Invited by', sortable: true, sub: r => r.inviterId },
            { key: 'memberName', label: 'New member', sortable: true, sub: r => `${r.memberId}${r.emailConfirmed ? '' : ' · email not confirmed'}` },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'coins', label: 'Coins', format: 'number', align: 'right', sortable: true },
            { key: 'flags', label: 'Flags', value: r => (r.flags.length ? r.flags.join(', ') : '—') },
            { key: 'joinedAt', label: 'Joined', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Who invited whom', fields: [['Invited by', `${r.inviterName} (${r.inviterId})`], ['New member', `${r.memberName} (${r.memberId})`],
                    ['Email confirmed', r.emailConfirmed, 'bool'], ['Account active', r.memberActive, 'bool'],
                    ['Link opened', r.linkOpenedAt, 'date'], ['Joined', r.joinedAt, 'date'], ['Counted', r.qualifiedAt, 'date'], ['Coins earned', r.coins, 'number']] },
                { title: 'Checks', fields: [['Flags', r.flags.length ? r.flags.join('; ') : 'None']],
                    text: 'Shared networks are common (families, offices, cafés), so a flag only asks a person to look. It never rejects a referral by itself.' },
                { title: 'Decision', fields: [['Status', r.status, 'status'], ['Decided by', r.decidedBy], ['Decided', r.decidedAt, 'date'], ['Reason', r.reason]] },
                historySection,
            ],
        },
        actions: [
            {
                id: 'approve', label: 'Count it', done: 'Counted', tone: 'primary', icon: 'bi-check-circle', permission: 'referrals.manage',
                // The server also requires the new member's email to be confirmed
                when: r => r.status === 'review' && r.emailConfirmed, reason: 'required', reasonLabel: 'Why it’s genuine (kept in the record)',
                message: r => `It counts towards ${r.inviterName}’s badges straight away.`,
            },
            {
                id: 'reject', label: 'Don’t count it', done: 'Not counted', tone: 'danger', icon: 'bi-x-circle', permission: 'referrals.manage',
                when: r => r.status === 'review' || r.status === 'registered', reason: 'required', reasonLabel: 'Reason (kept in the record)',
                message: () => 'The new member’s account isn’t affected. Only the invitation stops counting.',
            },
            {
                id: 'revoke', label: 'Revoke', done: 'Revoked', tone: 'danger', icon: 'bi-arrow-counterclockwise', permission: 'referrals.manage',
                when: r => r.status === 'qualified', reason: 'required', reasonLabel: 'Reason (kept in the record)',
                message: r => `It stops counting: ${r.inviterName} loses its ${r.coins} coins and any badge the new total no longer reaches.`,
            },
        ],
    },

    /* ================= Inviters ================= */
    'referral-inviters': {
        type: 'records', entity: 'Inviter', statusKey: false,
        description: 'Only people who have invited at least one member, with their coins and what happened to each invitation.',
        search: { keys: ['id', 'name'], placeholder: 'Search inviter' },
        tabs: [
            { id: 'all', label: 'All' },
            { id: 'flagged', label: 'With flagged sign-ups', filter: r => r.flagged > 0 },
            { id: 'review', label: 'With decisions waiting', filter: r => r.review > 0 },
        ],
        defaultSort: { key: 'coins', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('People inviting', rows.length),
            kpiCount('Coins held', sum(rows, r => r.coins)),
            kpi('Worth', formatUGX(sum(rows, r => r.coinsValue))),
            kpiCount('Holding a badge', count(rows, r => r.badges.length)),
            kpiCount('With flagged sign-ups', count(rows, r => r.flagged > 0)),
        ],
        charts: (rows) => (rows.length ? [ranking({
            id: 'top-inviters', title: 'Most counted invitations',
            summary: `${rows.slice().sort((a, b) => b.counted - a.counted)[0].name} has the most counted invitations.`,
            rows: rows.map(r => ({ label: r.name, value: r.counted })).sort((a, b) => b.value - a.value).slice(0, 10),
            columnLabel: 'Counted invitations',
        })] : []),
        columns: [
            { key: 'name', label: 'Inviter', sortable: true, sub: r => r.id },
            { key: 'coins', label: 'Coins', format: 'number', align: 'right', sortable: true, sub: r => formatUGX(r.coinsValue) },
            { key: 'joined', label: 'Joined', format: 'number', align: 'right', sortable: true },
            { key: 'counted', label: 'Counted', format: 'number', align: 'right', sortable: true },
            { key: 'waiting', label: 'Waiting', format: 'number', align: 'right', sortable: true },
            { key: 'notCounted', label: 'Not counted', format: 'number', align: 'right', sortable: true },
            { key: 'flagged', label: 'Flagged', format: 'number', align: 'right', sortable: true },
            { key: 'badgeList', label: 'Badges', value: r => r.badgeList || '—' },
            { key: 'lastJoinedAt', label: 'Last sign-up', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Inviter', fields: [['Name', `${r.name} (${r.id})`], ['Coins', r.coins, 'number'], ['Worth', r.coinsValue, 'money'],
                    ['Joined through their link', r.joined, 'number'], ['Counted', r.counted, 'number'],
                    ['Waiting', r.waiting, 'number'], ['Need a decision', r.review, 'number'], ['Not counted', r.notCounted, 'number'],
                    ['Flagged sign-ups', r.flagged, 'number'], ['Badges held', r.badgeList || 'None']] },
                related('People they invited', r.members, memberCols, 'No one yet.'),
            ],
        },
        actions: [],
    },

    /* ================= Badges ================= */
    'referral-badges': {
        type: 'records', entity: 'Badge',
        description: 'Badges thank members for invitations at set numbers. Changing a number or turning a badge off applies to everyone straight away.',
        search: { keys: ['name', 'description', 'key'], placeholder: 'Search badges' },
        tabs: [{ id: 'all', label: 'All' }, { id: 'active', label: 'Active', filter: r => r.status === 'active' }, { id: 'inactive', label: 'Off', filter: r => r.status === 'inactive' }],
        defaultSort: { key: 'threshold', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Active badges', count(rows, r => r.status === 'active')),
            kpiCount('Badges held', rows.reduce((n, r) => n + r.holders, 0)),
        ],
        columns: [
            { key: 'name', label: 'Badge', sortable: true, sub: r => r.description },
            { key: 'threshold', label: 'Counted invitations', format: 'number', align: 'right', sortable: true },
            { key: 'holders', label: 'People holding it', format: 'number', align: 'right', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Badge', fields: [['Name', r.name], ['Description', r.description], ['Icon', r.icon], ['Counted invitations needed', r.threshold, 'number'],
                    ['People holding it', r.holders, 'number'], ['Status', r.status, 'status']] },
                historySection,
            ],
        },
        actions: [
            { id: 'edit', label: 'Edit', done: 'Saved', icon: 'bi-pencil', permission: 'referrals.configure',
              message: () => 'A new number applies to everyone: people below it lose the badge and people above it earn it.',
              fields: (r) => [
                  { key: 'name', label: 'Name', type: 'text', required: true, maxLength: 60, initial: r.name },
                  { key: 'description', label: 'What it means', type: 'text', required: true, maxLength: 255, initial: r.description },
                  { key: 'threshold', label: 'Counted invitations needed', type: 'number', required: true, initial: r.threshold },
                  { key: 'icon', label: 'Icon', type: 'select', options: BADGE_ICONS, initial: r.icon },
                  { key: 'reason', label: 'Reason for the change', type: 'textarea', required: true, minLength: 5 },
              ] },
            { id: 'turn-off', label: 'Turn off', done: 'Turned off', tone: 'danger', icon: 'bi-toggle-off', permission: 'referrals.configure',
              when: r => r.status === 'active', reason: 'required',
              message: r => `${r.holders} ${r.holders === 1 ? 'person' : 'people'} will stop showing it. Their invitations still count.` },
            { id: 'turn-on', label: 'Turn on', done: 'Turned on', tone: 'primary', icon: 'bi-toggle-on', permission: 'referrals.configure',
              when: r => r.status === 'inactive', reason: 'required',
              message: () => 'Everyone who has enough counted invitations earns it straight away.' },
        ],
        create: {
            label: 'New badge', done: 'Badge created', permission: 'referrals.configure',
            message: 'Everyone who already has enough counted invitations earns it straight away.',
            fields: [
                { key: 'name', label: 'Name', type: 'text', required: true, maxLength: 60 },
                { key: 'description', label: 'What it means', type: 'text', required: true, maxLength: 255 },
                { key: 'threshold', label: 'Counted invitations needed', type: 'number', required: true },
                { key: 'icon', label: 'Icon', type: 'select', options: BADGE_ICONS, initial: 'bi-award' },
            ],
        },
    },

    /* ================= Who holds which badge ================= */
    'referral-badge-holders': {
        type: 'records', entity: 'Badge award',
        description: 'Each badge earned, and any taken back because the count no longer reached it.',
        search: { keys: ['memberName', 'memberId', 'badge'], placeholder: 'Search member or badge' },
        tabs: [{ id: 'held', label: 'Held', filter: r => r.status === 'awarded' }, { id: 'revoked', label: 'Taken back', filter: r => r.status === 'revoked' }, { id: 'all', label: 'All' }],
        defaultSort: { key: 'awardedAt', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Badges held', count(rows, r => r.status === 'awarded')),
            kpiCount('Taken back', count(rows, r => r.status === 'revoked')),
        ],
        columns: [
            { key: 'memberName', label: 'Member', sortable: true, sub: r => r.memberId },
            { key: 'badge', label: 'Badge', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'awardedAt', label: 'Earned', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Badge award', fields: [['Member', `${r.memberName} (${r.memberId})`], ['Badge', r.badge], ['Status', r.status, 'status'],
                    ['Earned', r.awardedAt, 'date'], ['Taken back', r.revokedAt, 'date'], ['Reason', r.reason]] },
            ],
        },
        actions: [],
    },
};

/**
 * One row per inviter, built from the referrals and badge awards. Only people
 * with at least one referral appear; coins count only from counted referrals.
 */
export function inviterRows(referrals, awards, coinValue = 0) {
    const byInviter = new Map();
    for (const r of referrals) {
        const row = byInviter.get(r.inviterId) || { id: r.inviterId, name: r.inviterName, coins: 0, joined: 0, counted: 0, waiting: 0, review: 0, notCounted: 0, flagged: 0, lastJoinedAt: null, members: [], badges: [] };
        row.joined++;
        row.coins += r.coins || 0;
        if (r.status === 'qualified') row.counted++;
        if (WAITING.includes(r.status)) row.waiting++;
        if (r.status === 'review') row.review++;
        if (NOT_COUNTED.includes(r.status)) row.notCounted++;
        if (r.flagged) row.flagged++;
        if (!row.lastJoinedAt || r.joinedAt > row.lastJoinedAt) row.lastJoinedAt = r.joinedAt;
        row.members.push(r);
        byInviter.set(r.inviterId, row);
    }
    for (const a of awards) {
        if (a.status !== 'held') continue;
        const row = byInviter.get(a.memberId);
        if (row) row.badges.push(a.badge);
    }
    return [...byInviter.values()].map(r => ({ ...r, coinsValue: r.coins * coinValue, badgeList: r.badges.join(', ') }));
}
