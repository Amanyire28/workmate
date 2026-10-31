import { count, within, share, kpiCount, kpiRate, statusOptions, areaOptions, formatNumber, formatPercent } from './helpers.js';
import { dailyLine, bucketBars, ranking, moneyPanels } from '../components/charts/builders.js';

const delta = (d, k) => (d.previous && d.previous[k] ? (d.totals[k] - d.previous[k]) / d.previous[k] : null);
const PLATFORM_KEYS = ['Android browser', 'iPhone browser', 'Installed web app', 'Desktop browser'];
const DEFINITIONS = 'Users are people with an account; installs are devices; launches and sessions are events. Opening the app again never counts as a new user.';

export default {
    /* ================= App usage ================= */
    'app-usage': {
        type: 'analytics', series: 'usage',
        notice: `${DEFINITIONS} Sample data: launch and session tracking isn’t connected yet.`,
        placeholderTitles: ['App launches', 'Active users', 'New registrations and active users', 'New and returning users', 'Platforms', 'Activity by area'],
        analytics: (d) => {
            const t = d.totals;
            const l = d.latest;
            const days = d.daily.length;
            return {
                kpis: [
                    kpiCount('Daily active users', l.dau, { context: `Weekly ${formatNumber(l.wau)} · monthly ${formatNumber(l.mau)}`, hint: 'Distinct people who opened WorkMate. Weekly and monthly figures are sample estimates.' }),
                    kpiCount('App launches', t.launches, { delta: delta(d, 'launches'), context: `${formatNumber(l.launches)} today` }),
                    kpiCount('Sessions', t.sessions, { context: `About ${Math.round((t.avgSessionSec / days) / 60 * 10) / 10} min each` }),
                    kpiCount('Online now', d.presence.online, { context: `${formatNumber(d.presence.offline)} offline · ${formatNumber(d.presence.unknown)} unknown`, to: '/admin/analytics/presence' }),
                    kpiCount('Registered users', d.totalRegistered, { context: `${formatNumber(t.newUsers)} new in the period` }),
                    kpiCount('Returning users per day', Math.round(t.returning / days), { hint: 'Active users who registered before that day.' }),
                    { label: 'Installed devices', value: '', available: false, unavailableReason: 'Browsers don’t report whether a web app is still installed.' },
                    { label: 'Uninstalls', value: '', available: false, unavailableReason: 'Not measurable for a web app.' },
                ],
                charts: [
                    dailyLine(d, { id: 'launches', title: 'App launches', summary: `${formatNumber(t.launches)} launches by about ${formatNumber(Math.round(t.dau / days))} people a day.`, series: [{ key: 'l', label: 'Launches', value: r => r.launches }] }),
                    dailyLine(d, { id: 'active', title: 'Active users', summary: 'Distinct people active per day, week and month (week and month are estimates).', series: [{ key: 'd', label: 'Daily', value: r => r.dau }, { key: 'w', label: 'Weekly', value: r => r.wau }, { key: 'm', label: 'Monthly', value: r => r.mau }] }),
                    moneyPanels(d, { id: 'reg-active', unit: 'number', title: 'New registrations and active users', summary: `${formatNumber(t.newUsers)} registrations; ${formatNumber(Math.round(t.dau / days))} active users a day. Separate scales.`,
                        panels: [{ key: 'r', label: 'New registrations', value: r => r.newUsers }, { key: 'a', label: 'Daily active users', value: r => r.dau }] }),
                    bucketBars(d, { id: 'returning', title: 'New and returning users', stacked: true, summary: `${formatPercent(share(t.returning, t.dau))} of daily activity comes from returning users.`,
                        series: [{ key: 'r', label: 'Returning', value: b => b.returning }, { key: 'n', label: 'New', value: b => b.dau - b.returning }] }),
                    ranking({ id: 'platforms', title: 'Launches by platform', summary: 'How people open WorkMate. “Installed web app” means launched from a home-screen icon.', rows: PLATFORM_KEYS.map(p => ({ label: p, value: t[`platform:${p}`] || 0 })), showShare: true, columnLabel: 'Launches' }),
                    ranking({ id: 'areas', title: 'Activity by area', summary: 'People online or recently active, by registered area. Never exact locations.', rows: d.activeByArea.slice(0, 10), columnLabel: 'People' }),
                ],
            };
        },
    },

    /* ================= Downloads and installs ================= */
    downloads: {
        type: 'analytics', series: 'usage',
        notice: 'WorkMate is a website and installable web app today. Website visits are not installs. Installs below are confirmed by the browser (sample data); there is no native Android or iOS app yet, so there are no store downloads.',
        placeholderTitles: ['Install prompts and installs', 'First launch after install', 'Install source'],
        analytics: (d) => {
            const t = d.totals;
            return {
                kpis: [
                    kpiCount('Confirmed web app installs', t.installs, { delta: delta(d, 'installs'), hint: 'Counted when the browser fires its install-confirmed event.' }),
                    kpiCount('Install prompts shown', t.installPrompts),
                    kpiRate('Prompt acceptance', share(t.installs, t.installPrompts)),
                    kpiCount('First launch after install', t.firstLaunches, { hint: 'First time the app was opened from its installed icon.' }),
                    { label: 'Android store downloads', value: '', available: false, unavailableReason: 'There is no native Android app yet.' },
                    { label: 'iOS store downloads', value: '', available: false, unavailableReason: 'There is no native iOS app yet.' },
                    { label: 'Uninstalls', value: '', available: false, unavailableReason: 'Browsers don’t report web app uninstalls.' },
                    { label: 'Total recorded installs', value: formatNumber(t.installs), context: `In the last ${d.range.days} days` },
                ],
                charts: [
                    bucketBars(d, { id: 'installs', title: 'Install prompts and installs', summary: `${formatNumber(t.installPrompts)} prompts led to ${formatNumber(t.installs)} confirmed installs (${formatPercent(share(t.installs, t.installPrompts))}).`,
                        series: [{ key: 'p', label: 'Prompts shown', value: b => b.installPrompts }, { key: 'i', label: 'Confirmed installs', value: b => b.installs }] }),
                    dailyLine(d, { id: 'first', title: 'First launch after install', summary: `${formatNumber(t.firstLaunches)} installs were followed by a first launch.`, series: [{ key: 'f', label: 'First launches', value: r => r.firstLaunches }] }),
                    ranking({ id: 'sources', title: 'Install source', summary: 'Where people came from before installing (sample attribution).', rows: d.installsBySource.map(s => ({ label: s.label, value: Math.round(t.installs * s.share) })), showShare: true, columnLabel: 'Installs' }),
                ],
                table: {
                    title: 'What each platform can measure', summary: 'Shown so nobody mistakes an unmeasured number for zero.',
                    columns: [{ key: 'platform', label: 'Platform' }, { key: 'downloads', label: 'Downloads' }, { key: 'installs', label: 'Installs' }, { key: 'launches', label: 'Launches' }, { key: 'uninstalls', label: 'Uninstalls' }],
                    rows: [
                        { platform: 'Website', downloads: 'Not applicable', installs: 'Not applicable', launches: 'Page visits only', uninstalls: 'Not applicable' },
                        { platform: 'Installable web app', downloads: 'Not applicable', installs: 'Confirmed by browser', launches: 'Tracked from icon', uninstalls: 'Not reported' },
                        { platform: 'Android app (future)', downloads: 'Play Console', installs: 'Play Console', launches: 'App analytics', uninstalls: 'Play Console' },
                        { platform: 'iOS app (future)', downloads: 'App Store Connect', installs: 'App Store Connect', launches: 'App analytics', uninstalls: 'Limited' },
                    ],
                },
            };
        },
    },

    /* ================= User activity explorer ================= */
    'activity-explorer': {
        type: 'records', entity: 'User activity', statusKey: 'presence',
        description: 'Activity per account. Shows registered area only, never live locations.',
        notice: 'Sample activity: heartbeats and launch events aren’t collected yet. “Unknown” means no activity since tracking began.',
        search: { keys: ['id', 'name', 'area'], placeholder: 'Search name, user ID or area' },
        tabs: [
            { id: 'all', label: 'All' },
            { id: 'online', label: 'Online', filter: r => r.presence === 'online' },
            { id: 'recent', label: 'Recently active', filter: r => r.presence === 'recently_active' },
            { id: 'inactive', label: 'Inactive 30+ days', filter: r => r.lastSeenAt && !within(r.lastSeenAt, 30) },
            { id: 'unknown', label: 'Unknown', filter: r => r.presence === 'unknown' },
        ],
        filters: [
            { key: 'type', label: 'User type', options: [['customer', 'Customers'], ['provider', 'Providers']] },
            { key: 'presence', label: 'Presence', options: statusOptions(['online', 'recently_active', 'offline', 'unknown']) },
            { key: 'platform', label: 'Device', options: PLATFORM_KEYS.map(p => [p, p]) },
            { key: 'area', label: 'Area', options: areaOptions },
        ],
        period: { key: 'registeredAt', label: 'Registered' },
        defaultSort: { key: 'lastSeenAt', dir: 'desc' },
        exportable: true, exportPermission: 'activity.users',
        kpis: (rows) => [
            kpiCount('Online now', count(rows, r => r.presence === 'online'), { context: `${count(rows, r => r.presence === 'online' && r.type === 'provider')} providers` }),
            kpiCount('Recently active', count(rows, r => r.presence === 'recently_active')),
            kpiCount('Active in last 7 days', count(rows, r => r.lastSeenAt && within(r.lastSeenAt, 7))),
            kpiCount('No activity since tracking began', count(rows, r => r.presence === 'unknown')),
        ],
        columns: [
            { key: 'name', label: 'User', sortable: true, sub: r => `${r.id} · ${r.type}` },
            { key: 'accountStatus', label: 'Account', format: 'status', hideBelow: 'lg' },
            { key: 'presence', label: 'Presence', format: 'status', sortable: true },
            { key: 'lastSeenAt', label: 'Last seen', format: 'relative', sortable: true },
            { key: 'launches7', label: 'Launches (7 d)', format: 'number', align: 'right', sortable: true },
            { key: 'platform', label: 'Device', hideBelow: 'lg' },
            { key: 'area', label: 'Area', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Activity', fields: [
                    ['User type', r.type], ['Account', r.accountStatus, 'status'], ['Presence', r.presence, 'status'], ['Last seen', r.lastSeenAt, 'date'],
                    ['Launches (7 days)', r.launches7, 'number'], ['Sessions (7 days)', r.sessions7, 'number'], ['Device', r.platform], ['Registered area', r.area], ['Registered', r.registeredAt, 'date'],
                    ...(r.type === 'provider' ? [['Availability for work', r.availability, 'status'], ['Accepts requests while offline', r.acceptsWhileOffline, 'bool']] : []),
                ] },
                { note: 'Presence (is the app open?) and availability (will they take work?) are separate. A provider can be online but unavailable, or offline but still accepting requests.' },
                { note: 'Only coarse, registered areas are shown here. Exact live coordinates are never part of activity analytics.' },
            ],
        },
    },
};

