import {
    count, sum, share, kpiCount, kpiRate, statusTabs, setStatus, historySection,
    categoryOptions, areaOptions, categoryLabel, formatPercent, formatNumber,
} from './helpers.js';
import { ranking, bucketBars, bucketRates } from '../components/charts/builders.js';

const towns = [['Kampala', 'Kampala'], ['Kira', 'Kira'], ['Mukono', 'Mukono'], ['Wakiso', 'Wakiso'], ['Entebbe', 'Entebbe']];
const PRIVACY = 'Area-level figures only. Precise provider or customer locations are never shown in the admin.';

export default {
    /* ================= Location overview ================= */
    'location-overview': {
        type: 'records', entity: 'Area', statusKey: false,
        notice: PRIVACY,
        search: { keys: ['name', 'town'], placeholder: 'Search areas' },
        filters: [{ key: 'town', label: 'Town', options: towns }],
        defaultSort: { key: 'searches30', dir: 'desc' },
        kpis: (rows) => {
            const searches = sum(rows, r => r.searches30);
            return [
                kpiCount('Areas tracked', rows.length),
                kpiCount('Searches (30 days)', searches),
                kpiRate('No-match rate (30 days)', share(sum(rows, r => r.searches30 * r.noMatchRate), searches), { goodWhen: 'down' }),
                kpiCount('Active providers missing map location', sum(rows, r => r.missingCoordinates), { to: '/admin/location/coverage' }),
            ];
        },
        charts: (rows) => {
            const worst = [...rows].sort((a, b) => b.noMatchRate - a.noMatchRate);
            return [
                ranking({ id: 'nomatch', title: 'No-match rate by area', summary: `${worst[0].name} has the highest share of searches with no suitable provider (${formatPercent(worst[0].noMatchRate)}).`, rows: worst.slice(0, 10).map(r => ({ label: r.name, value: r.noMatchRate })), unit: 'percent', columnLabel: 'No-match rate' }),
                ranking({ id: 'supply', title: 'Active providers by area', summary: 'Registered base area of active providers.', rows: [...rows].sort((a, b) => b.providers - a.providers).slice(0, 10).map(r => ({ label: r.name, value: r.providers })), columnLabel: 'Providers' }),
            ];
        },
        columns: [
            { key: 'name', label: 'Area', sortable: true, sub: r => r.town },
            { key: 'providers', label: 'Active providers', format: 'number', align: 'right', sortable: true },
            { key: 'customers', label: 'Customers', format: 'number', align: 'right', sortable: true, hideBelow: 'lg' },
            { key: 'jobs30', label: 'Jobs (30 d)', format: 'number', align: 'right', sortable: true },
            { key: 'searches30', label: 'Searches (30 d)', format: 'number', align: 'right', sortable: true },
            { key: 'nearbyMatchRate', label: 'Nearby match', format: 'percent', align: 'right', sortable: true },
            { key: 'noMatchRate', label: 'No match', format: 'percent', align: 'right', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: r.name, fields: [
                    ['Town', r.town], ['Active providers based here', r.providers, 'number'], ['…of which missing a map location', r.missingCoordinates, 'number'],
                    ['Registered customers', r.customers, 'number'], ['Job requests (30 days)', r.jobs30, 'number'], ['Searches (30 days)', r.searches30, 'number'],
                    ['Matched nearby', r.nearbyMatchRate, 'percent'], ['Matched after widening', r.expandedRate, 'percent'], ['No suitable provider', r.noMatchRate, 'percent'],
                ] },
                { note: 'Search logging isn’t connected yet, so there are no search figures per area.' },
                historySection,
            ],
        },
        actions: [
            { id: 'recruit', label: 'Flag for provider recruitment', done: 'Flagged for recruitment', permission: 'location.view', icon: 'bi-flag',
              fields: () => [{ key: 'reason', label: 'Which services are needed?', type: 'textarea', required: true, minLength: 3 }], apply: () => ({ patch: {} }) },
        ],
    },

    /* ================= Provider coverage ================= */
    coverage: {
        type: 'records', entity: 'Coverage',
        description: 'Supply and demand for each service in each area, to find gaps worth recruiting for.',
        notice: PRIVACY,
        search: { keys: ['area', 'town', 'category'], placeholder: 'Search area or service' },
        tabs: [{ id: 'problems', label: 'Gaps and thin coverage', filter: r => r.status === 'gap' || r.status === 'thin' }, ...statusTabs(['gap', 'thin', 'covered', 'no_demand'])],
        filters: [
            { key: 'category', label: 'Service', options: categoryOptions },
            { key: 'area', label: 'Area', options: areaOptions },
            { key: 'town', label: 'Town', options: towns },
        ],
        defaultSort: { key: 'demand30', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Coverage gaps', count(rows, r => r.status === 'gap'), { hint: 'Demand in the last 30 days but no active provider based in the area.' }),
            kpiCount('Thin coverage', count(rows, r => r.status === 'thin'), { hint: 'More than 6 job requests per active provider in 30 days.' }),
            kpiCount('Covered', count(rows, r => r.status === 'covered')),
            kpiCount('Unserved job requests (30 days)', sum(rows.filter(r => r.status === 'gap'), r => r.demand30)),
        ],
        columns: [
            { key: 'area', label: 'Area', sortable: true, sub: r => r.town },
            { key: 'category', label: 'Service', format: 'category', sortable: true },
            { key: 'supply', label: 'Active providers', format: 'number', align: 'right', sortable: true },
            { key: 'demand30', label: 'Job requests (30 d)', format: 'number', align: 'right', sortable: true },
            { key: 'status', label: 'Coverage', format: 'status', sortable: true },
        ],
        detail: {
            title: r => `${r.area}: ${categoryLabel(r.category)}`,
            sections: (r) => [
                { title: 'Coverage', fields: [['Area', `${r.area}, ${r.town}`], ['Service', r.category, 'category'], ['Active providers based here', r.supply, 'number'], ['Job requests (30 days)', r.demand30, 'number'],
                    ['Requests per provider', r.supply ? (r.demand30 / r.supply).toFixed(1) : '—']] },
                { note: 'Supply counts providers by registered base only. Providers from nearby areas who list this area in their service areas can still serve it.' },
                historySection,
            ],
        },
        actions: [
            { id: 'recruit', label: 'Flag for recruitment', done: 'Flagged for recruitment', permission: 'location.view', icon: 'bi-flag', reason: 'optional',
              when: r => r.status === 'gap' || r.status === 'thin', apply: () => ({ patch: {} }) },
        ],
    },

    /* ================= Search analytics ================= */
    'search-analytics': {
        type: 'analytics',
        datasets: ['location-overview'],
        notice: 'Search logging isn’t connected yet, so no searches are recorded.',
        placeholderTitles: ['Search outcomes', 'Match rates', 'Location errors', 'Search widened most often'],
        analytics: (d, { sets }) => {
            const t = d.totals;
            const p = d.previous;
            const ch = (k) => (p && p[k] ? (t[k] - p[k]) / p[k] : null);
            const areas = sets['location-overview'];
            return {
                kpis: [
                    kpiCount('Searches', t.searches, { delta: ch('searches') }),
                    kpiRate('Matched nearby', share(t.searchNearby, t.searches), { hint: `Within the initial radius.` }),
                    kpiRate('Needed a wider search', share(t.searchWider, t.searches)),
                    kpiRate('No suitable provider', share(t.searchNone, t.searches), { goodWhen: 'down', to: '/admin/location/unmatched' }),
                ],
                charts: [
                    bucketBars(d, { id: 'outcomes', title: 'Search outcomes', stacked: true, summary: `${formatNumber(t.searches)} searches: ${formatPercent(share(t.searchNearby, t.searches))} nearby, ${formatPercent(share(t.searchWider, t.searches))} after widening, ${formatPercent(share(t.searchNone, t.searches))} no match.`,
                        series: [{ key: 'n', label: 'Nearby match', value: b => b.searchNearby }, { key: 'w', label: 'Wider-area match', value: b => b.searchWider }, { key: 'x', label: 'No match', value: b => b.searchNone }] }),
                    bucketRates(d, { id: 'rates', title: 'Match rates', summary: 'Share of searches matched nearby versus with no suitable provider.',
                        series: [{ key: 'n', label: 'Matched nearby', value: b => share(b.searchNearby, b.searches) }, { key: 'x', label: 'No match', value: b => share(b.searchNone, b.searches) }] }),
                    bucketBars(d, { id: 'gps', title: 'Location errors', summary: `${formatNumber(t.gpsDenied)} searches where the customer declined location access and ${formatNumber(t.gpsTimeout)} where it timed out; they fell back to a chosen area.`,
                        series: [{ key: 'd', label: 'Permission declined', value: b => b.gpsDenied }, { key: 't', label: 'Timed out', value: b => b.gpsTimeout }] }),
                    ranking({ id: 'expanded', title: 'Search widened most often', summary: 'Share of searches in each area that needed the wider radius or service areas.', rows: [...areas].sort((a, b) => b.expandedRate - a.expandedRate).slice(0, 10).map(a => ({ label: a.name, value: a.expandedRate })), unit: 'percent', columnLabel: 'Widened' }),
                ],
            };
        },
    },

    /* ================= Unmatched searches ================= */
    unmatched: {
        type: 'records', entity: 'Unmatched search',
        description: 'Searches that found no suitable provider, grouped by what was searched and where.',
        search: { keys: ['term', 'area', 'town'], placeholder: 'Search term or area' },
        tabs: statusTabs(['open', 'resolved']),
        filters: [{ key: 'town', label: 'Town', options: towns }, { key: 'category', label: 'Service', options: categoryOptions }],
        defaultSort: { key: 'count', dir: 'desc' },
        exportable: true,
        kpis: (rows) => [
            kpiCount('Search groups', rows.length),
            kpiCount('Unmatched searches', sum(rows, r => r.count)),
            kpiCount('No matching category', count(rows, r => !r.category), { hint: 'Customers searched for a service WorkMate doesn’t list yet.' }),
            kpiCount('Handled', count(rows, r => r.status === 'resolved')),
        ],
        columns: [
            { key: 'term', label: 'Searched for', sortable: true, sub: r => (r.category ? `Category: ${categoryLabel(r.category)}` : 'No matching category') },
            { key: 'area', label: 'Area', sortable: true, sub: r => r.town },
            { key: 'count', label: 'Searches', format: 'number', align: 'right', sortable: true },
            { key: 'expandedTo', label: 'Widened to', hideBelow: 'lg' },
            { key: 'lastSeenAt', label: 'Last seen', format: 'relative', sortable: true },
            { key: 'status', label: 'Status', format: 'status' },
        ],
        detail: {
            title: r => `“${r.term}” near ${r.area}`,
            sections: (r) => [
                { title: 'Searches', fields: [['Searched for', r.term], ['Matched category', r.category ? categoryLabel(r.category) : 'None'], ['Area', `${r.area}, ${r.town}`], ['Times searched', r.count, 'number'], ['Search widened to', r.expandedTo], ['Last seen', r.lastSeenAt, 'date'], ['Suggested next step', r.suggestion]] },
                historySection,
            ],
        },
        actions: [
            setStatus('resolve', 'Mark handled', 'resolved', { tone: 'primary', reason: 'required', reasonLabel: 'What was done?', permission: 'location.view', done: 'Marked handled', when: r => r.status === 'open' }),
            setStatus('reopen', 'Reopen', 'open', { reason: 'optional', permission: 'location.view', done: 'Reopened', when: r => r.status === 'resolved' }),
        ],
    },

    /* ================= Matching configuration ================= */
    matching: {
        type: 'settings',
        editPermission: 'location.configure',
        description: 'How nearby search finds and orders providers. Each setting explains its effect.',
        effectNote: 'Settings marked Live apply to everyone’s nearby search as soon as you save.',
        sections: [
            { title: 'Search radius', description: 'Search starts close to the customer and widens only when there are too few results.', fields: [
                { key: 'nearbyKm', label: 'Initial search radius', type: 'number', unit: 'km', min: 1, max: 20, help: 'Providers within this straight-line distance appear first, as “Closest to you”.' },
                { key: 'extendedKm', label: 'Expansion radius', type: 'number', unit: 'km', min: 2, max: 50, help: 'Used when the initial radius finds too few providers.' },
                { key: 'maxKm', label: 'Maximum search distance', type: 'number', unit: 'km', min: 5, max: 100, help: 'Providers further than this are never shown by distance, only through service areas.' },
                { key: 'minNearbyResults', label: 'Widen when nearby results are fewer than', type: 'number', min: 1, max: 10, help: 'Fewer close matches than this triggers the expansion step.' },
            ] },
            { title: 'Fallback rules', fields: [
                { key: 'useServiceAreas', label: 'Include providers who travel to the customer’s place', type: 'toggle', help: 'A provider based in Mukono who lists Ntinda can appear for Ntinda searches.' },
                { key: 'sameTownFallback', label: 'Include providers based in the same town or district', type: 'toggle', help: 'Beyond the expansion radius but within the maximum distance, as “More professionals around Kampala”.' },
                { key: 'noCoordinates', label: 'Providers without a map location', type: 'select', wide: true,
                  unavailable: 'Every provider now has to choose a place, which gives them a map location, so this no longer applies.', options: [['town_only', 'Include by town, without a distance'], ['exclude', 'Exclude from nearby search'], ['area_centre', 'Estimate from their area’s centre (labelled as estimate)']], help: 'WorkMate never invents a distance; an estimate is always labelled.' },
            ] },
            { title: 'Distance and location', fields: [
                { key: 'distancePolicy', label: 'Distance calculation', type: 'select',
                  unavailable: 'Road distance needs a paid routing service. Straight-line distance is used.', options: [['straight_line', 'Straight line (Haversine)'], ['road', 'Road distance (needs a routing service)']], help: 'Distances are to the provider’s registered base, never their live position.' },
                { key: 'gpsAreaMatchKm', label: 'Match a GPS position to an area within', type: 'number', unit: 'km', min: 1, max: 20, help: 'Used to check service areas for customers who share their location.' },
                { key: 'gpsMaxAgeMin', label: 'Refresh a shared location older than', type: 'number', unit: 'min', min: 1, max: 60 },
            ] },
            { title: 'Ranking factors', description: 'Within each distance group, providers are ordered by distance first; these break ties.', fields: [
                { key: 'rankAvailability', label: 'Prefer providers available now', type: 'toggle' },
                { key: 'rankVerification', label: 'Prefer providers with the tick', type: 'toggle' },
                { key: 'rankRating', label: 'Prefer higher-rated providers', type: 'toggle', help: 'Off by default so new providers without reviews aren’t buried.' },
            ] },
        ],
        validate: (v) => {
            const e = {};
            if (v.extendedKm <= v.nearbyKm) e.extendedKm = 'Must be larger than the initial radius.';
            if (v.maxKm < v.extendedKm) e.maxKm = 'Must be at least the expansion radius.';
            return e;
        },
    },
};
