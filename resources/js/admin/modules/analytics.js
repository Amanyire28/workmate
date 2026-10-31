import { count, sum, share, kpiCount, kpiMoney, kpiRate, historySection, formatNumber, formatPercent, categoryLabel } from './helpers.js';
import { dailyLine, bucketBars, bucketRates, ranking } from '../components/charts/builders.js';
import { getSeries } from '../data/adminApi.js';
import { toast } from '../stores/prototypeChanges.js';

const inPeriod = (iso, days) => iso && Date.now() - new Date(iso).getTime() <= days * 86400000;
const delta = (d, k) => (d.previous && d.previous[k] ? (d.totals[k] - d.previous[k]) / d.previous[k] : null);
const tally = (rows, key) => {
    const m = new Map();
    for (const r of rows) { const k = typeof key === 'function' ? key(r) : r[key]; m.set(k, (m.get(k) || 0) + 1); }
    return [...m.entries()].map(([label, value]) => ({ label, value })).sort((a, b) => b.value - a.value);
};
const top = (rows, n = 10) => {
    if (rows.length <= n) return rows;
    return [...rows.slice(0, n), { label: 'Other', value: sum(rows.slice(n), r => r.value), muted: true }];
};
const UNAVAILABLE = 'Not recorded yet: needs provider response timestamps from the booking API.';

export default {
    /* ================= Marketplace growth ================= */
    'marketplace-analytics': {
        type: 'analytics', datasets: ['bookings', 'providers'],
        placeholderTitles: ['Registrations', 'Active users', 'Bookings created and completed', 'Repeat bookings'],
        analytics: (d, { sets, days }) => {
            const t = d.totals;
            const bookings = sets.bookings.filter(b => inPeriod(b.createdAt, days));
            const perCustomer = tally(bookings, 'customerId');
            const repeat = count(perCustomer, x => x.value > 1);
            const activeProviders = sets.providers.filter(p => p.status === 'active');
            const workingProviders = new Set(bookings.map(b => b.providerId)).size;
            return {
                kpis: [
                    kpiCount('New customers', t.customersNew, { delta: delta(d, 'customersNew') }),
                    kpiCount('New providers', t.providersNew, { delta: delta(d, 'providersNew') }),
                    kpiRate('Provider activation', share(workingProviders, activeProviders.length), { hint: 'Active providers who got at least one booking in the period.' }),
                    kpiRate('Customers who booked again', share(repeat, perCustomer.length), { hint: 'Of customers who booked in the period, the share with 2 or more bookings.' }),
                ],
                charts: [
                    dailyLine(d, { id: 'reg', title: 'Registrations', summary: `${formatNumber(t.customersNew)} customers and ${formatNumber(t.providersNew)} providers joined.`, series: [{ key: 'c', label: 'Customers', value: r => r.customersNew }, { key: 'p', label: 'Providers', value: r => r.providersNew }] }),
                    dailyLine(d, { id: 'active', title: 'Active users per day', summary: `About ${formatNumber(t.activeUsers)} people used WorkMate each day.`, series: [{ key: 'a', label: 'Active users', value: r => r.activeUsers }] }),
                    bucketBars(d, { id: 'bookings', title: 'Bookings created and completed', summary: `${formatNumber(t.bookingsCreated)} bookings created and ${formatNumber(t.bookingsCompleted)} completed.`, series: [{ key: 'c', label: 'Created', value: b => b.bookingsCreated }, { key: 'd', label: 'Completed', value: b => b.bookingsCompleted }] }),
                    ranking({ id: 'repeat', title: 'Bookings per customer', summary: `${formatNumber(repeat)} customers booked more than once.`, columnLabel: 'Customers',
                        rows: [1, 2, 3].map(n => ({ label: n === 3 ? '3 or more bookings' : `${n} booking${n > 1 ? 's' : ''}`, value: count(perCustomer, x => (n === 3 ? x.value >= 3 : x.value === n)) })) }),
                ],
            };
        },
    },

    /* ================= Customers ================= */
    'customer-analytics': {
        type: 'analytics', datasets: ['customers', 'bookings'],
        placeholderTitles: ['New customers', 'Customers by area', 'Sign-up channel', 'Preferred language'],
        analytics: (d, { sets, days }) => {
            const joined = sets.customers.filter(c => inPeriod(c.registeredAt, days));
            const bookings = sets.bookings.filter(b => inPeriod(b.createdAt, days));
            const booked = new Set(bookings.map(b => b.customerId));
            const completed = bookings.filter(b => b.status === 'completed');
            return {
                kpis: [
                    kpiCount('New customers', joined.length, { delta: delta(d, 'customersNew') }),
                    kpiRate('New customers who booked', share(count(joined, c => booked.has(c.id)), joined.length)),
                    kpiCount('Customers who booked', booked.size),
                    kpiMoney('Average completed booking', completed.length ? sum(completed, b => b.price) / completed.length : 0, { permission: 'finance.summary' }),
                ],
                charts: [
                    dailyLine(d, { id: 'new', title: 'New customers', summary: `${formatNumber(joined.length)} customers registered.`, series: [{ key: 'c', label: 'Customers', value: r => r.customersNew }] }),
                    ranking({ id: 'area', title: 'New customers by area', summary: 'Where new customers live (area they chose).', rows: top(tally(joined, 'area')), columnLabel: 'Customers' }),
                    ranking({ id: 'channel', title: 'Sign-up channel', summary: 'How new customers signed up.', rows: tally(joined, 'channel'), showShare: true, columnLabel: 'Customers' }),
                    ranking({ id: 'lang', title: 'Preferred language', summary: 'Language new customers chose.', rows: tally(joined, 'language'), showShare: true, columnLabel: 'Customers' }),
                ],
            };
        },
    },

    /* ================= Providers ================= */
    'provider-analytics': {
        type: 'analytics', datasets: ['providers', 'bookings'],
        placeholderTitles: ['Active providers by service', 'Completed bookings by service', 'New providers', 'Busiest providers'],
        analytics: (d, { sets, days }) => {
            const active = sets.providers.filter(p => p.status === 'active');
            const bookings = sets.bookings.filter(b => inPeriod(b.createdAt, days));
            const completed = bookings.filter(b => b.status === 'completed');
            const supply = tally(active.flatMap(p => p.services), x => categoryLabel(x));
            const busiest = tally(completed, b => b.providerId).slice(0, 8);
            return {
                kpis: [
                    kpiCount('Active providers', active.length),
                    kpiRate('Bookings cancelled', share(count(bookings, b => b.status === 'cancelled'), bookings.length), { goodWhen: 'down' }),
                    { label: 'Completed jobs per working provider', value: (completed.length / Math.max(1, new Set(completed.map(b => b.providerId)).size)).toFixed(1) },
                    { label: 'Average response time', value: '', available: false, unavailableReason: UNAVAILABLE },
                ],
                charts: [
                    ranking({ id: 'supply', title: 'Active providers by service', summary: `${supply[0]?.label} has the most active providers.`, rows: supply, columnLabel: 'Providers' }),
                    ranking({ id: 'done', title: 'Completed bookings by service', summary: `${formatNumber(completed.length)} bookings completed in the period.`, rows: tally(completed, b => categoryLabel(b.service)), columnLabel: 'Bookings' }),
                    dailyLine(d, { id: 'new', title: 'New providers', summary: `${formatNumber(d.totals.providersNew)} providers registered.`, series: [{ key: 'p', label: 'Providers', value: r => r.providersNew }] }),
                    ranking({ id: 'busiest', title: 'Most completed bookings', summary: 'Providers with the most completed bookings in the period.', columnLabel: 'Bookings',
                        rows: busiest.map(x => ({ label: sets.providers.find(p => p.id === x.label)?.name || x.label, value: x.value })) }),
                ],
            };
        },
    },

    /* ================= Service demand ================= */
    demand: {
        type: 'analytics', datasets: ['jobs', 'providers'],
        placeholderTitles: ['Demand by service', 'Demand by area', 'Jobs created and completed', 'Repeat demand'],
        analytics: (d, { sets, days }) => {
            const jobs = sets.jobs.filter(j => inPeriod(j.createdAt, days));
            const byCat = tally(jobs, j => j.category);
            const active = sets.providers.filter(p => p.status === 'active');
            const repeatPairs = tally(jobs, j => `${j.customerId}|${j.category}`);
            return {
                kpis: [
                    kpiCount('Job requests', jobs.length, { delta: delta(d, 'jobsCreated') }),
                    { label: 'Most requested', value: categoryLabel(byCat[0]?.label) || '—', context: byCat[0] ? `${formatPercent(share(byCat[0].value, jobs.length))} of requests` : '' },
                    kpiRate('Expired with no match', share(count(jobs, j => j.status === 'expired'), jobs.length), { goodWhen: 'down' }),
                    kpiRate('Repeat demand', share(count(repeatPairs, x => x.value > 1), repeatPairs.length), { hint: 'Customers who requested the same service more than once in the period.' }),
                ],
                charts: [
                    ranking({ id: 'cat', title: 'Demand by service', summary: `${categoryLabel(byCat[0]?.label)} is the most requested service.`, rows: byCat.map(x => ({ label: categoryLabel(x.label), value: x.value })), showShare: true, columnLabel: 'Job requests' }),
                    ranking({ id: 'area', title: 'Demand by area', summary: 'Where job requests come from.', rows: top(tally(jobs, 'area')), columnLabel: 'Job requests' }),
                    bucketBars(d, { id: 'jobs', title: 'Jobs created and completed', summary: `${formatNumber(d.totals.jobsCreated)} created, ${formatNumber(d.totals.jobsCompleted)} completed.`, series: [{ key: 'c', label: 'Created', value: b => b.jobsCreated }, { key: 'd', label: 'Completed', value: b => b.jobsCompleted }] }),
                ],
                table: {
                    title: 'Demand and supply by service', summary: 'Job requests in the period per active provider. High numbers point to services worth recruiting for.',
                    columns: [{ key: 'service', label: 'Service' }, { key: 'jobs', label: 'Job requests', format: 'number' }, { key: 'providers', label: 'Active providers', format: 'number' }, { key: 'ratio', label: 'Requests per provider' }],
                    rows: byCat.map(x => {
                        const providers = count(active, p => p.services.includes(x.label));
                        return { service: categoryLabel(x.label), jobs: x.value, providers, ratio: providers ? (x.value / providers).toFixed(1) : 'No providers' };
                    }),
                },
            };
        },
    },

    /* ================= Geographic ================= */
    'geo-analytics': {
        type: 'analytics', datasets: ['location-overview', 'jobs'],
        notice: 'Area-level figures only; no precise locations.',
        placeholderTitles: ['Job requests by area', 'Active providers by area'],
        analytics: (d, { sets, days }) => {
            const areas = sets['location-overview'];
            const jobs = sets.jobs.filter(j => inPeriod(j.createdAt, days));
            const demand = tally(jobs, 'area');
            const rows = areas.map(a => ({ ...a, jobs: demand.find(x => x.label === a.name)?.value || 0 })).sort((a, b) => b.jobs - a.jobs);
            const worst = [...areas].sort((a, b) => b.noMatchRate - a.noMatchRate)[0];
            return {
                kpis: [
                    kpiCount('Areas tracked', areas.length),
                    { label: 'Most job requests', value: rows[0]?.name || '—', context: `${formatNumber(rows[0]?.jobs || 0)} requests` },
                    { label: 'Highest no-match rate', value: worst?.name || '—', context: worst ? formatPercent(worst.noMatchRate) : '' },
                    kpiCount('Active providers missing map location', sum(areas, a => a.missingCoordinates)),
                ],
                charts: [
                    ranking({ id: 'demand', title: 'Job requests by area', summary: `${rows[0]?.name} had the most job requests.`, rows: top(rows.map(r => ({ label: r.name, value: r.jobs }))), columnLabel: 'Job requests' }),
                    ranking({ id: 'supply', title: 'Active providers by area', summary: 'Registered base area of active providers.', rows: top([...areas].sort((a, b) => b.providers - a.providers).map(a => ({ label: a.name, value: a.providers }))), columnLabel: 'Providers' }),
                ],
                table: {
                    title: 'Supply and demand by area', summary: 'More job requests per provider means customers wait longer or go unmatched.',
                    columns: [{ key: 'name', label: 'Area' }, { key: 'town', label: 'Town' }, { key: 'jobs', label: 'Job requests', format: 'number' }, { key: 'providers', label: 'Active providers', format: 'number' }, { key: 'ratio', label: 'Requests per provider' }, { key: 'noMatchRate', label: 'No-match rate', format: 'percent' }],
                    rows: rows.map(r => ({ ...r, ratio: r.providers ? (r.jobs / r.providers).toFixed(1) : 'No providers' })),
                },
            };
        },
    },

    /* ================= Conversion and retention ================= */
    conversion: {
        type: 'analytics',
        placeholderTitles: ['Search to booking', 'Conversion rates', 'Searches with and without results'],
        analytics: (d) => {
            const t = d.totals;
            const withResults = t.searches - t.searchNone;
            const steps = [
                ['Searches', t.searches], ['Searches with results', withResults], ['Provider profile views', t.profileViews],
                ['Enquiries started', t.enquiries], ['Bookings created', t.bookingsCreated], ['Bookings completed', t.bookingsCompleted],
            ];
            return {
                kpis: [
                    kpiRate('Searches with results', share(withResults, t.searches)),
                    kpiRate('Enquiry to booking', share(t.bookingsCreated, t.enquiries)),
                    kpiRate('Booking completion', share(t.bookingsCompleted, t.bookingsCreated)),
                    kpiRate('Cancellation', share(t.bookingsCancelled, t.bookingsCreated), { goodWhen: 'down' }),
                ],
                charts: [
                    ranking({ id: 'funnel', title: 'Search to booking', summary: `${formatPercent(share(t.bookingsCompleted, t.searches))} of searches ended in a completed booking.`, rows: steps.map(([label, value]) => ({ label, value })), columnLabel: 'Count' }),
                    bucketRates(d, { id: 'rates', title: 'Conversion rates', summary: 'Job requests that became bookings, and bookings that were completed.',
                        series: [{ key: 'b', label: 'Became a booking', value: b => share(b.bookingsCreated, b.jobsCreated) }, { key: 'c', label: 'Booking completed', value: b => share(b.bookingsCompleted, b.bookingsCreated) }] }),
                    bucketBars(d, { id: 'results', title: 'Searches with and without results', stacked: true, summary: `${formatNumber(t.searchNone)} searches found no suitable provider.`,
                        series: [{ key: 'r', label: 'With results', value: b => b.searches - b.searchNone }, { key: 'n', label: 'No results', value: b => b.searchNone }] }),
                ],
                table: {
                    title: 'Funnel', summary: 'Step-by-step conversion in the period.',
                    columns: [{ key: 'step', label: 'Step' }, { key: 'value', label: 'Count', format: 'number' }, { key: 'rate', label: 'From previous step', format: 'percent' }],
                    rows: steps.map(([step, value], i) => ({ step, value, rate: i ? share(value, steps[i - 1][1]) : null })),
                },
            };
        },
    },

    /* ================= Report builder ================= */
    'report-builder': {
        type: 'records', entity: 'Saved report', statusKey: false,
        description: 'Saved, export-ready reports built from the daily series.',
        search: { keys: ['name', 'description', 'owner'], placeholder: 'Search reports' },
        defaultSort: { key: 'lastRunAt', dir: 'desc' },
        columns: [
            { key: 'name', label: 'Report', sortable: true, sub: r => r.description },
            { key: 'owner', label: 'Owner', sortable: true },
            { key: 'dataset', label: 'Data' },
            { key: 'lastRunAt', label: 'Last run', format: 'relative', sortable: true },
        ],
        detail: { sections: (r) => [{ title: 'Report', fields: [['Name', r.name], ['Description', r.description], ['Owner', r.owner], ['Data', r.dataset], ['Last run', r.lastRunAt, 'date']] }, historySection] },
        actions: [
            { id: 'run', label: 'Run and download CSV', done: 'Report run', tone: 'primary', permission: 'analytics.view', icon: 'bi-download',
              fields: () => [{ key: 'days', label: 'Period', type: 'select', options: [[30, 'Last 30 days'], [90, 'Last 90 days'], [7, 'Last 7 days']] }],
              message: () => 'Downloads the recorded figures for the chosen period.',
              apply: (r, v) => { downloadReport(r, Number(v.days)); return { patch: { lastRunAt: new Date().toISOString() }, detail: `Last ${v.days} days` }; } },
        ],
        create: {
            label: 'New saved report', done: 'Report saved', permission: 'analytics.view',
            fields: [
                { key: 'name', label: 'Name', type: 'text', required: true },
                { key: 'dataset', label: 'Data', type: 'select', options: [['marketplace', 'Marketplace growth'], ['search', 'Search outcomes'], ['finance', 'Finance'], ['disputes', 'Disputes']] },
                { key: 'description', label: 'Description', type: 'text' },
            ],
            build: (v) => ({ id: `RPT-${Date.now().toString().slice(-4)}`, name: v.name, dataset: v.dataset, description: v.description || '', owner: 'Administrator', lastRunAt: null }),
        },
    },
};

const REPORT_COLUMNS = {
    marketplace: [['customersNew', 'New customers'], ['providersNew', 'New providers'], ['jobsCreated', 'Jobs created'], ['bookingsCreated', 'Bookings created'], ['bookingsCompleted', 'Bookings completed']],
    search: [['searches', 'Searches'], ['searchNearby', 'Nearby match'], ['searchWider', 'Wider-area match'], ['searchNone', 'No match']],
    finance: [['gtv', 'GTV (UGX)'], ['commission', 'Commission (UGX)'], ['fees', 'Payment fees (UGX)'], ['refunds', 'Refunds (UGX)']],
    disputes: [['disputesOpened', 'Disputes opened'], ['disputesResolved', 'Disputes resolved']],
    supply: [['providersNew', 'New providers']],
};

async function downloadReport(report, days) {
    try {
        const d = await getSeries({ days, compare: false });
        const cols = REPORT_COLUMNS[report.dataset] || REPORT_COLUMNS.marketplace;
        const lines = [['Date', ...cols.map(c => c[1])].join(','), ...d.daily.map(r => [r.date.slice(0, 10), ...cols.map(c => r[c[0]])].join(','))];
        const blob = new Blob([`# ${report.name}: figures from the WorkMate database\n${lines.join('\n')}`], { type: 'text/csv;charset=utf-8' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `workmate-${report.id}-demo.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
    } catch (e) {
        toast(`Couldn’t run the report: ${e.message}`, 'warning');
    }
}
