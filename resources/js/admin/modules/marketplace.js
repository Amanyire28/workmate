import {
    count, sum, within, share, kpiCount, kpiRate, statusOptions, statusTabs, setStatus, historySection, related,
    categoryOptions, areaOptions, categoryLabel,
} from './helpers.js';
import { ranking } from '../components/charts/builders.js';
import { professions, categories as liveCategories } from '../data/liveData.js';

// Icons a category can use (Bootstrap Icons)
const CATEGORY_ICONS = [
    ['bi-tools', 'Tools'], ['bi-hammer', 'Hammer'], ['bi-wrench', 'Wrench'], ['bi-bricks', 'Bricks'], ['bi-house', 'House'],
    ['bi-droplet', 'Water drop'], ['bi-lightning-charge', 'Electricity'], ['bi-sun', 'Sun'], ['bi-brush', 'Brush'], ['bi-palette', 'Palette'],
    ['bi-stars', 'Sparkles'], ['bi-flower1', 'Flower'], ['bi-tree', 'Tree'], ['bi-truck', 'Truck'], ['bi-car-front', 'Car'],
    ['bi-scissors', 'Scissors'], ['bi-camera', 'Camera'], ['bi-music-note', 'Music'], ['bi-laptop', 'Laptop'], ['bi-phone', 'Phone'],
    ['bi-shield-check', 'Shield'], ['bi-heart-pulse', 'Health'], ['bi-book', 'Book'], ['bi-cup-hot', 'Cup'], ['bi-basket', 'Basket'],
    ['bi-briefcase', 'Briefcase'],
];
const categoryChoices = () => liveCategories.map(c => [c.id, `${c.label}${c.visible ? '' : ' (hidden)'}`]);

const newest = (list) => [...list].reverse();
const bookingCols = [
    { key: 'id', label: 'Booking' }, { key: 'service', label: 'Service', format: 'category' },
    { key: 'price', label: 'Price', format: 'money' }, { key: 'status', label: 'Status', format: 'status' }, { key: 'createdAt', label: 'Created', format: 'relative' },
];
const txCols = [
    { key: 'id', label: 'Transaction' }, { key: 'amount', label: 'Amount', format: 'money' },
    { key: 'method', label: 'Method' }, { key: 'status', label: 'Status', format: 'status' }, { key: 'createdAt', label: 'Created', format: 'relative' },
];
const disputeCols = [
    { key: 'id', label: 'Case' }, { key: 'category', label: 'Category' }, { key: 'severity', label: 'Severity', format: 'status' },
    { key: 'status', label: 'Status', format: 'status' }, { key: 'openedAt', label: 'Opened', format: 'relative' },
];
const reviewCols = [
    { key: 'rating', label: 'Rating' }, { key: 'comment', label: 'Comment' }, { key: 'status', label: 'Status', format: 'status' }, { key: 'createdAt', label: 'Written', format: 'relative' },
];
const accountActions = (noun) => [
    setStatus('suspend', 'Suspend', 'suspended', {
        tone: 'danger', reason: 'required', permission: 'trust.view', done: 'Suspended', icon: 'bi-slash-circle',
        when: r => r.status === 'active' || r.status === 'inactive',
        confirmTitle: r => `Suspend ${r.name}?`,
        message: r => `${r.name} won’t be able to sign in${noun === 'provider' ? ', appear in search' : ''} or book until reactivated.`,
    }),
    setStatus('restrict', 'Restrict', 'restricted', {
        reason: 'required', permission: 'trust.view', done: 'Restricted', icon: 'bi-shield-exclamation',
        when: r => r.status === 'active',
        message: () => (noun === 'provider' ? 'The provider stays visible but can’t accept new bookings.' : 'The customer can have at most one active booking.'),
    }),
    setStatus('reactivate', 'Reactivate', 'active', {
        tone: 'primary', reason: 'required', permission: 'trust.view', done: 'Reactivated', icon: 'bi-arrow-counterclockwise',
        when: r => r.status === 'suspended' || r.status === 'restricted',
    }),
];

export default {
    /* ================= Customers ================= */
    customers: {
        type: 'records', entity: 'Customer',
        search: { keys: ['id', 'name', 'area', 'phoneMasked'], placeholder: 'Search name, customer ID, area or last 3 phone digits' },
        filters: [
            { key: 'status', label: 'Status', options: statusOptions(['active', 'inactive', 'suspended', 'restricted']) },
            { key: 'area', label: 'Area', options: areaOptions },
            { key: 'channel', label: 'Sign-up channel', options: [['Android app', 'Android app'], ['iPhone app', 'iPhone app'], ['Website', 'Website']] },
        ],
        period: { key: 'registeredAt', label: 'Registered' },
        defaultSort: { key: 'registeredAt', dir: 'desc' },
        exportable: true,
        kpis: (rows) => [
            kpiCount('Registered customers', rows.length),
            kpiCount('Active in last 90 days', count(rows, r => r.status === 'active')),
            kpiCount('New in last 30 days', count(rows, r => within(r.registeredAt, 30))),
            kpiCount('Suspended or restricted', count(rows, r => r.status === 'suspended' || r.status === 'restricted'), { to: '/admin/trust/restricted' }),
        ],
        columns: [
            { key: 'name', label: 'Customer', sortable: true, sub: r => `${r.id} · ${r.phoneMasked}` },
            { key: 'area', label: 'Area', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'bookings', label: 'Bookings', format: 'number', align: 'right', sortable: true },
            { key: 'registeredAt', label: 'Registered', format: 'relative', sortable: true, hideBelow: 'lg' },
            { key: 'presence', label: 'Presence', format: 'status', sortable: true, hideBelow: 'lg' },
            { key: 'lastActiveAt', label: 'Last active', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r, { rel }) => {
                const R = rel();
                return [
                    { title: 'Profile', fields: [
                        ['Area', r.area], ['Phone', r.phoneMasked], ['Email', r.emailMasked], ['Language', r.language],
                        ['Signed up via', r.channel], ['Registered', r.registeredAt, 'date'], ['Last active', r.lastActiveAt, 'relative'], ['Presence', r.presence, 'status'],
                        ['Bookings', r.bookings, 'number'], ['Complaints involved in', r.complaints, 'number'],
                    ] },
                    { note: 'Contact details stay masked. Revealing them should be limited to roles that need them, and each reveal logged.' },
                    related('Bookings', newest(R.bookingsOfCustomer(r.id)), bookingCols, 'No bookings yet.'),
                    { ...related('Transactions', newest(R.txOfCustomer(r.id)), txCols, 'No payments yet.'), permission: 'finance.view' },
                    related('Disputes', newest(R.disputesOfCustomer(r.id)), disputeCols, 'No disputes.'),
                    related('Reviews written', newest(R.reviewsOfCustomer(r.id)), reviewCols, 'No reviews written.'),
                    { title: 'Notification preferences', fields: [['SMS', r.notifications.sms, 'bool'], ['Email', r.notifications.email, 'bool'], ['Push', r.notifications.push, 'bool']] },
                    historySection,
                ];
            },
        },
        actions: accountActions('customer'),
    },

    /* ================= Service providers ================= */
    providers: {
        type: 'records', entity: 'Service provider',
        search: { text: r => `${r.id} ${r.name} ${r.contactName} ${r.area || ''} ${r.services.map(categoryLabel).join(' ')}`, placeholder: 'Search name, provider ID, service or area' },
        filters: [
            { key: 'status', label: 'Status', options: statusOptions(['active', 'inactive', 'suspended', 'restricted', 'deactivated']) },
            { key: 'service', label: 'Service', options: categoryOptions, match: (r, v) => r.services.includes(v) },
            { key: 'type', label: 'Type', options: [['individual', 'Individual'], ['business', 'Business'], ['team', 'Team']] },
            { key: 'area', label: 'Base area', options: areaOptions },
            { key: 'map', label: 'Map location', options: [['set', 'Set'], ['missing', 'Missing']], match: (r, v) => (v === 'set') === r.hasCoordinates },
            { key: 'verification', label: 'Verification', options: statusOptions(['verified', 'pending_review', 'under_review', 'info_requested', 'rejected', 'expired', 'none']) },
            { key: 'blueTick', label: 'Blue tick', options: statusOptions(['awarded', 'eligible', 'approaching', 'under_review', 'suspended', 'removed', 'not_eligible']) },
        ],
        period: { key: 'registeredAt', label: 'Registered' },
        defaultSort: { key: 'completedJobs', dir: 'desc' },
        exportable: true,
        kpis: (rows) => [
            kpiCount('Registered providers', rows.length),
            kpiCount('Active', count(rows, r => r.status === 'active')),
            kpiCount('Missing map location', count(rows, r => !r.hasCoordinates), { hint: 'No base coordinates, so search can’t estimate distance to them.', to: '/admin/location/coverage' }),
            kpiCount('WorkMate Verified', count(rows, r => r.verification === 'verified'), { hint: 'Passed document verification for their profession.', to: '/admin/verification/applications' }),
        ],
        columns: [
            { key: 'name', label: 'Provider', sortable: true, sub: r => `${r.id} · ${r.type}` },
            { key: 'services', label: 'Services', format: 'category', sortValue: r => categoryLabel(r.services[0]) },
            { key: 'status', label: 'Account', format: 'status', sortable: true },
            { key: 'verification', label: 'Verification', format: 'status', sortable: true },
            { key: 'blueTick', label: 'Blue tick', format: 'status', sortable: true, hideBelow: 'lg' },
            { key: 'presence', label: 'Presence', format: 'status', sortable: true, hideBelow: 'lg' },
            { key: 'completedJobs', label: 'Completed jobs', format: 'number', align: 'right', sortable: true },
            { key: 'rating', label: 'Rating', align: 'right', sortable: true, hideBelow: 'lg', value: r => (r.rating ? `${r.rating} (${r.reviewCount})` : null), sortValue: r => r.rating },
        ],
        detail: {
            sections: (r, { rel }) => {
                const R = rel();
                return [
                    { title: 'Profile', fields: [
                        ['Type', r.type], ['Contact person', r.contactName], ['Services', r.services, 'category'], ['Phone', r.phoneMasked],
                        ['Registered base', r.area || 'Not set'], ['Service areas listed', r.serviceAreas, 'number'],
                        ['Base map location', r.hasCoordinates ? 'Set (coordinates hidden)' : 'Missing'],
                        ['Current GPS location', 'Not collected'],
                        ['Listed on the public site', r.publicListing, 'bool'],
                        ['Registered', r.registeredAt, 'date'], ['Last active', r.lastActiveAt, 'relative'], ['Presence', r.presence, 'status'],
                    ] },
                    { title: 'Recognition', fields: [
                        ['Phone number (at sign-up)', r.checks.phone, 'status'],
                        ['Document verification', r.verification === 'none' ? 'Not applied' : r.verification, r.verification === 'none' ? 'text' : 'status'],
                        ['Blue tick', r.blueTick, 'status'],
                    ] },
                    ...(r.verificationApp ? [{ link: { to: `/admin/verification/applications/${r.verificationApp}`, label: `Open verification application ${r.verificationApp}` } }] : []),
                    { link: { to: { path: '/admin/reputation/blue-tick', query: { open: r.id } }, label: 'See blue tick evaluation' } },
                    { note: 'WorkMate Verified means the documents WorkMate requires for this profession were checked and approved. It is not proof of every professional qualification. The blue tick is separate and automatic.' },
                    { title: 'Performance', fields: [
                        ['Completed jobs', r.completedJobs, 'number'], ['Rating', r.rating ? `${r.rating} from ${r.reviewCount} reviews` : 'No reviews yet'],
                        ['Disputes involved in', r.complaints, 'number'],
                        ['Complaint rate', r.completedJobs ? `${(share(r.complaints, r.completedJobs) * 100).toFixed(1)}%` : '—'],
                    ] },
                    { title: 'Earnings and payouts', permission: 'finance.summary', fields: [
                        ['Gross value of completed jobs', r.earned, 'money'], ['Platform commission (planned rate)', Math.round(r.earned * 0.1), 'money'], ['Payout pending', r.pendingPayout, 'money'],
                    ] },
                    { ...related('Payouts', newest(R.payoutsOfProvider(r.id)), [
                        { key: 'id', label: 'Payout' }, { key: 'amount', label: 'Amount', format: 'money' }, { key: 'status', label: 'Status', format: 'status' }, { key: 'periodEnd', label: 'Week ending', format: 'date' },
                    ], 'No payouts yet.'), permission: 'finance.view' },
                    related('Recent bookings', newest(R.bookingsOfProvider(r.id)), bookingCols, 'No bookings yet.'),
                    related('Reviews', newest(R.reviewsOfProvider(r.id)), reviewCols, 'No reviews yet.'),
                    related('Disputes', newest(R.disputesOfProvider(r.id)), disputeCols, 'No disputes.'),
                    related('WorkMate in Action videos', R.videosOfProvider(r.id), [
                        { key: 'caption', label: 'Caption' }, { key: 'status', label: 'Status', format: 'status' }, { key: 'uploadedAt', label: 'Uploaded', format: 'relative' },
                    ], 'No videos.'),
                    historySection,
                ];
            },
        },
        actions: [
            ...accountActions('provider'),
            setStatus('deactivate', 'Deactivate', 'deactivated', {
                tone: 'danger', reason: 'required', permission: 'admin.manage', done: 'Deactivated',
                when: r => r.status !== 'deactivated',
                message: r => `${r.name} is removed from search and can’t take bookings. Their history is kept.`,
            }),
        ],
    },

    /* ================= Provider applications ================= */
    applications: {
        type: 'records', entity: 'Provider application',
        search: { keys: ['id', 'name', 'contactName', 'area'], placeholder: 'Search applicant, application ID or area' },
        tabs: [
            { id: 'queue', label: 'Needs review', filter: r => ['submitted', 'pending_review', 'info_requested'].includes(r.status) },
            ...statusTabs(['submitted', 'pending_review', 'info_requested', 'approved', 'rejected']).slice(1),
            { id: 'all', label: 'All' },
        ],
        filters: [
            { key: 'service', label: 'Service', options: categoryOptions, match: (r, v) => r.services.includes(v) },
            { key: 'type', label: 'Type', options: [['individual', 'Individual'], ['business', 'Business'], ['team', 'Team']] },
        ],
        defaultSort: { key: 'submittedAt', dir: 'asc' },
        kpis: (rows) => {
            const queue = rows.filter(r => ['submitted', 'pending_review', 'info_requested'].includes(r.status));
            const oldest = queue.reduce((a, b) => (!a || b.submittedAt < a.submittedAt ? b : a), null);
            return [
                kpiCount('Awaiting decision', queue.length),
                kpiCount('Waiting over 72 hours', count(queue, r => !within(r.submittedAt, 3))),
                kpiCount('More info requested', count(rows, r => r.status === 'info_requested')),
                { label: 'Oldest in queue', value: oldest ? `${Math.round((Date.now() - new Date(oldest.submittedAt).getTime()) / 86400000)} days` : '—' },
            ];
        },
        columns: [
            { key: 'name', label: 'Applicant', sortable: true, sub: r => `${r.id} · ${r.type}` },
            { key: 'services', label: 'Service', format: 'category' },
            { key: 'area', label: 'Area', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'submittedAt', label: 'Submitted', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Application', fields: [
                    ['Type', r.type], ['Contact person', r.contactName], ['Services', r.services, 'category'], ['Area', r.area],
                    ['Phone', r.phoneMasked], ['Documents provided', r.documents], ['Submitted', r.submittedAt, 'date'], ['Decided', r.decidedAt, 'date'],
                ] },
                { title: 'Checks', fields: [
                    ['Identity (national ID)', r.checks.identity, 'status'], ['Phone number', r.checks.phone, 'status'],
                    ['Trade certificate', r.checks.tradeCertificate, 'status'], ['References', r.checks.references, 'status'],
                ] },
                { note: 'Approving publishes the profile with exactly these check results. Customers see which checks were done, not a general “verified” claim.' },
                historySection,
            ],
        },
        actions: [
            {
                id: 'approve', label: 'Approve', done: 'Approved', tone: 'primary', permission: 'providers.review', icon: 'bi-check2-circle',
                when: r => ['submitted', 'pending_review', 'info_requested'].includes(r.status),
                confirmTitle: r => `Approve ${r.name}?`,
                message: r => `Their profile goes live with: identity ${r.checks.identity}, phone ${r.checks.phone}, trade certificate ${r.checks.tradeCertificate.replace('_', ' ')}.`,
                reason: 'optional', reasonLabel: 'Note for the record',
                apply: () => ({ patch: { status: 'approved', decidedAt: new Date().toISOString() } }),
            },
            {
                id: 'request-info', label: 'Request information', done: 'More information requested', permission: 'providers.review', icon: 'bi-chat-left-text',
                when: r => r.status === 'submitted' || r.status === 'pending_review',
                fields: () => [{ key: 'reason', label: 'What do they need to provide?', type: 'textarea', required: true, minLength: 5, help: 'Sent to the applicant once messaging is connected.' }],
                apply: () => ({ patch: { status: 'info_requested' } }),
            },
            setStatus('reject', 'Reject', 'rejected', {
                tone: 'danger', reason: 'required', permission: 'providers.review', done: 'Rejected', icon: 'bi-x-circle',
                when: r => ['submitted', 'pending_review', 'info_requested'].includes(r.status),
                message: () => 'The applicant is told the reason and can apply again.',
                extra: () => ({ decidedAt: new Date().toISOString() }),
            }),
        ],
    },

    /* ================= Jobs ================= */
    jobs: {
        type: 'records', entity: 'Job request',
        search: { keys: ['id', 'title', 'area', 'customerId'], placeholder: 'Search job ID, title, area or customer ID' },
        tabs: [
            { id: 'all', label: 'All' },
            { id: 'attention', label: 'Needs intervention', filter: r => r.status === 'open' && !within(r.createdAt, 2) && r.matching !== 'searching' },
            ...statusTabs(['open', 'booked', 'expired', 'cancelled']).slice(1),
        ],
        filters: [
            { key: 'category', label: 'Service', options: categoryOptions },
            { key: 'area', label: 'Area', options: areaOptions },
            { key: 'matching', label: 'Matching', options: statusOptions(['searching', 'no_match_yet', 'matched', 'no_match']) },
        ],
        period: { key: 'createdAt', label: 'Created' },
        defaultSort: { key: 'createdAt', dir: 'desc' },
        exportable: true,
        kpis: (rows) => {
            const recent = rows.filter(r => within(r.createdAt, 30));
            return [
                kpiCount('Open now', count(rows, r => r.status === 'open')),
                kpiCount('Created in 30 days', recent.length),
                kpiRate('Became a booking (30 days)', share(count(recent, r => r.status === 'booked' || r.bookingId), recent.length)),
                kpiCount('Expired with no match (30 days)', count(recent, r => r.status === 'expired'), { to: '/admin/location/unmatched' }),
            ];
        },
        columns: [
            { key: 'title', label: 'Job', sortable: true, sub: r => `${r.id} · ${r.area}` },
            { key: 'category', label: 'Service', format: 'category', sortable: true },
            { key: 'customer', label: 'Customer', value: (r, { rel }) => rel().nameOf(r.customerId), hideBelow: 'lg' },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'matching', label: 'Matching', format: 'status', sortable: true },
            { key: 'createdAt', label: 'Created', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => r.title,
            sections: (r, { rel }) => {
                const R = rel();
                const booking = r.bookingId && R.booking(r.bookingId);
                return [
                    { title: 'Request', fields: [
                        ['Customer', `${R.nameOf(r.customerId)} (${r.customerId})`], ['Service', r.category, 'category'], ['Location', `${r.area} (area only)`],
                        ['Budget', r.budget, 'money'], ['Created', r.createdAt, 'date'], ['Matching', r.matching, 'status'],
                        ['Assigned provider', r.providerId ? `${R.nameOf(r.providerId)} (${r.providerId})` : 'None'],
                        ['Related booking', booking ? `${booking.id} (${booking.status.replace('_', ' ')})` : 'None'],
                        ['Failure or cancellation reason', r.failureReason],
                    ] },
                    ...(r.photos?.length ? [{ title: `Photos of the job (${r.photos.length})`, files: r.photos.map((href, i) => ({ label: `Photo ${i + 1}`, href })) }] : []),
                    related('Quotations', R.quotesOfJob(r.id), [
                        { key: 'id', label: 'Quote' }, { key: 'providerId', label: 'Provider' }, { key: 'amount', label: 'Amount', format: 'money' }, { key: 'status', label: 'Status', format: 'status' },
                    ], 'No quotations.'),
                    historySection,
                ];
            },
        },
        actions: [
            {
                id: 'follow-up', label: 'Record follow-up', done: 'Follow-up recorded', icon: 'bi-telephone-outbound', permission: 'marketplace.view',
                when: r => r.status === 'open',
                fields: () => [{ key: 'reason', label: 'What was done?', type: 'textarea', required: true, minLength: 5, help: 'For example: called the customer, suggested nearby providers.' }],
                apply: () => ({ patch: {} }),
            },
            setStatus('cancel', 'Cancel job', 'cancelled', {
                tone: 'danger', reason: 'required', permission: 'marketplace.view', done: 'Job cancelled',
                when: r => r.status === 'open',
                extra: (r, v) => ({ matching: 'cancelled', failureReason: `Cancelled by admin: ${v.reason}` }),
            }),
        ],
    },

    /* ================= Quotations ================= */
    quotations: {
        type: 'records', entity: 'Quotation',
        search: { keys: ['id', 'jobId', 'providerId', 'customerId'], placeholder: 'Search quote, job, provider or customer ID' },
        tabs: statusTabs(['submitted', 'accepted', 'rejected', 'expired', 'withdrawn']),
        filters: [{ key: 'service', label: 'Service', options: categoryOptions }],
        period: { key: 'submittedAt', label: 'Submitted' },
        defaultSort: { key: 'submittedAt', dir: 'desc' },
        exportable: true,
        kpis: (rows) => {
            const recent = rows.filter(r => within(r.submittedAt, 30));
            return [
                kpiCount('Awaiting customer', count(rows, r => r.status === 'submitted')),
                kpiCount('Submitted in 30 days', recent.length),
                kpiRate('Accepted (30 days)', share(count(recent, r => r.status === 'accepted'), recent.length)),
                kpiRate('Expired unanswered (30 days)', share(count(recent, r => r.status === 'expired'), recent.length)),
            ];
        },
        columns: [
            { key: 'id', label: 'Quote', sortable: true, sub: r => `For ${r.jobId}` },
            { key: 'service', label: 'Service', format: 'category' },
            { key: 'provider', label: 'Provider', value: (r, { rel }) => rel().nameOf(r.providerId) },
            { key: 'amount', label: 'Amount', format: 'money', align: 'right', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'submittedAt', label: 'Submitted', format: 'relative', sortable: true },
            { key: 'expiresAt', label: 'Expires', format: 'date', sortable: true, hideBelow: 'lg' },
        ],
        detail: {
            title: r => `Quotation ${r.id}`,
            sections: (r, { rel }) => [
                { title: 'Quotation', fields: [
                    ['Job', r.jobId], ['Customer', `${rel().nameOf(r.customerId)} (${r.customerId})`], ['Provider', `${rel().nameOf(r.providerId)} (${r.providerId})`],
                    ['Service', r.service, 'category'], ['Amount', r.amount, 'money'], ['Submitted', r.submittedAt, 'date'], ['Expires', r.expiresAt, 'date'],
                    ['Related booking', r.bookingId || 'None'],
                ] },
                { note: 'Quotations are the provider’s offer. Admins can view them but not change amounts.' },
            ],
        },
    },

    /* ================= Bookings ================= */
    bookings: {
        type: 'records', entity: 'Booking',
        search: { keys: ['id', 'jobId', 'customerId', 'providerId', 'area'], placeholder: 'Search booking, job, customer or provider ID' },
        tabs: [
            { id: 'all', label: 'All' },
            { id: 'active', label: 'Active', filter: r => ['awaiting_provider', 'accepted', 'scheduled', 'in_progress'].includes(r.status) },
            ...statusTabs(['completed', 'cancelled', 'disputed', 'expired']).slice(1),
        ],
        filters: [
            { key: 'service', label: 'Service', options: categoryOptions },
            { key: 'area', label: 'Area', options: areaOptions },
            { key: 'payment', label: 'Payment', options: [['paid', 'Paid'], ['pending', 'Pending'], ['none', 'No payment']] },
        ],
        period: { key: 'createdAt', label: 'Created' },
        defaultSort: { key: 'createdAt', dir: 'desc' },
        exportable: true,
        notice: 'A completed booking is not necessarily a completed payment: check the Payment column. Changes here are recorded as interventions; history is never rewritten.',
        kpis: (rows) => {
            const recent = rows.filter(r => within(r.createdAt, 30));
            return [
                kpiCount('Active now', count(rows, r => ['accepted', 'scheduled', 'in_progress'].includes(r.status))),
                kpiCount('Completed (30 days)', count(recent, r => r.status === 'completed')),
                kpiRate('Cancelled (30 days)', share(count(recent, r => r.status === 'cancelled'), recent.length), { goodWhen: 'down' }),
                kpiCount('Disputed now', count(rows, r => r.status === 'disputed'), { to: '/admin/trust/disputes' }),
            ];
        },
        columns: [
            { key: 'id', label: 'Booking', sortable: true, sub: r => `${categoryLabel(r.service)} · ${r.area}` },
            { key: 'customer', label: 'Customer', value: (r, { rel }) => rel().nameOf(r.customerId), hideBelow: 'lg' },
            { key: 'provider', label: 'Provider', value: (r, { rel }) => rel().nameOf(r.providerId) },
            { key: 'price', label: 'Agreed price', format: 'money', align: 'right', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'payment', label: 'Payment', format: 'status', value: r => (r.payment === 'none' ? null : r.payment) },
            { key: 'createdAt', label: 'Created', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => `${categoryLabel(r.service)} booking ${r.id}`,
            sections: (r, { rel }) => {
                const R = rel();
                const timeline = [
                    { step: 'Booking created', at: r.createdAt },
                    { step: 'Scheduled for', at: r.scheduledFor },
                    ...(r.completedAt ? [{ step: 'Marked completed', at: r.completedAt }] : []),
                    ...(r.status === 'cancelled' ? [{ step: `Cancelled: ${r.cancelReason}`, at: null }] : []),
                ];
                return [
                    { title: 'Booking', fields: [
                        ['Customer', `${R.nameOf(r.customerId)} (${r.customerId})`], ['Provider', `${R.nameOf(r.providerId)} (${r.providerId})`],
                        ['Service', r.service, 'category'], ['Location', `${r.area} (area only)`], ['Agreed price', r.price, 'money'],
                        ['Job request', r.jobId], ['Payment state', r.payment === 'none' ? 'No payment recorded' : r.payment], ['Dispute', r.disputeId || 'None'],
                    ] },
                    related('Lifecycle', timeline, [{ key: 'step', label: 'Step' }, { key: 'at', label: 'When', format: 'date' }]),
                    { ...related('Payments', R.txOfBooking(r.id), txCols, 'No payment recorded.'), permission: 'finance.view' },
                    { title: 'Interventions', timeline: true },
                ];
            },
        },
        actions: [
            {
                id: 'intervene', label: 'Record intervention', done: 'Intervention recorded', icon: 'bi-journal-plus', permission: 'marketplace.view',
                fields: () => [
                    { key: 'kind', label: 'Type', type: 'select', options: [['Called customer', 'Called customer'], ['Called provider', 'Called provider'], ['Rescheduled', 'Rescheduled'], ['Other', 'Other']] },
                    { key: 'reason', label: 'Details', type: 'textarea', required: true, minLength: 5 },
                ],
                apply: (r, v) => ({ patch: {}, detail: v.kind }),
            },
            setStatus('cancel', 'Cancel booking', 'cancelled', {
                tone: 'danger', reason: 'required', permission: 'marketplace.view', done: 'Booking cancelled by admin',
                when: r => ['awaiting_provider', 'accepted', 'scheduled'].includes(r.status),
                message: () => 'Both the customer and provider will be told once messaging is connected. Any payment needs a separate refund.',
                extra: (r, v) => ({ cancelReason: `Admin: ${v.reason}` }),
            }),
        ],
    },

    /* ================= Typed professions ================= */
    'profession-suggestions': {
        type: 'records', entity: 'Typed profession',
        description: 'Professions applicants typed under “Other”. Customers can’t find anyone by them until you add them to the list or match them to an existing profession.',
        search: { keys: ['id', 'name', 'providerName', 'providerId'], placeholder: 'Search profession or applicant' },
        tabs: [
            { id: 'pending', label: 'Waiting', filter: r => r.status === 'pending' },
            ...statusTabs(['added', 'matched', 'declined']).slice(1),
            { id: 'all', label: 'All' },
        ],
        defaultSort: { key: 'createdAt', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Waiting for a decision', count(rows, r => r.status === 'pending')),
            kpiCount('Typed as a main profession', count(rows, r => r.status === 'pending' && r.main)),
            kpiCount('Added to the list', count(rows, r => r.status === 'added')),
            kpiCount('Matched to an existing one', count(rows, r => r.status === 'matched')),
        ],
        columns: [
            { key: 'name', label: 'Typed profession', sortable: true, sub: r => (r.main ? 'Main profession' : 'Other profession') },
            { key: 'providerName', label: 'Applicant', sortable: true, sub: r => r.providerId },
            { key: 'suggestedCategory', label: 'Their category', format: 'category' },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'createdAt', label: 'Typed', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Typed profession', fields: [['Name', r.name], ['Main profession', r.main, 'bool'], ['Years of experience', r.yearsExperience, 'number'],
                    ['Their category', r.suggestedCategory, 'category'], ['Applicant', `${r.providerName} (${r.providerId})`], ['Typed', r.createdAt, 'date']] },
                { title: 'Decision', fields: [['Status', r.status, 'status'], ['Profession given', r.profession], ['Reason', r.decisionReason], ['Decided by', r.decidedBy], ['Decided', r.decidedAt, 'date']] },
                historySection,
            ],
        },
        actions: [
            {
                id: 'add', label: 'Add to the list', done: 'Added', tone: 'primary', permission: 'providers.review', icon: 'bi-plus-circle',
                when: r => r.status === 'pending',
                message: () => 'Customers can find this profession straight away. Anyone else who typed the same name gets it too.',
                fields: (r) => [
                    { key: 'name', label: 'Profession name', type: 'text', required: true, maxLength: 60, initial: r.name, help: 'Singular, e.g. “Tiler”.' },
                    { key: 'plural_name', label: 'Plural for headings', type: 'text', required: true, maxLength: 80, initial: `${r.name.toLowerCase()}s`, help: 'As in “Tilers near Ntinda”.' },
                    { key: 'category', label: 'Category', type: 'select', required: true, options: categoryOptions, initial: r.suggestedCategory || undefined },
                ],
            },
            {
                id: 'match', label: 'Match to an existing profession', done: 'Matched', permission: 'providers.review', icon: 'bi-arrow-left-right',
                when: r => r.status === 'pending',
                message: () => 'Use this when they meant a profession that’s already in the list.',
                fields: () => [
                    { key: 'profession', label: 'Profession', type: 'select', required: true, options: professions.map(p => [p.id, `${p.label} (${categoryLabel(p.category)})`]) },
                    { key: 'reason', label: 'Note (optional)', type: 'text' },
                ],
            },
            {
                id: 'decline', label: 'Decline', done: 'Declined', tone: 'danger', permission: 'providers.review', icon: 'bi-x-circle',
                when: r => r.status === 'pending', reason: 'required', reasonLabel: 'Reason (shown to the applicant)',
                message: () => 'The applicant sees the reason. Their other professions aren’t affected.',
            },
        ],
    },

    /* ================= Service categories ================= */
    categories: {
        type: 'records', entity: 'Service category', statusKey: false,
        search: { keys: ['id', 'name', 'description'], placeholder: 'Search categories' },
        defaultSort: { key: 'position', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Categories', rows.length),
            kpiCount('Visible to customers', count(rows, r => r.visible)),
            kpiCount('Job requests (30 days)', sum(rows, r => r.jobs30)),
            kpiCount('Categories with under 50 active providers', count(rows, r => r.providers < 50)),
        ],
        charts: (rows) => [ranking({ id: 'cat-supply', title: 'Active providers per category', summary: `${rows.slice().sort((a, b) => b.providers - a.providers)[0]?.name} has the most active providers.`, rows: rows.map(r => ({ label: r.name, value: r.providers })).sort((a, b) => b.value - a.value), columnLabel: 'Active providers' })],
        columns: [
            { key: 'position', label: '#', format: 'number', sortable: true },
            { key: 'name', label: 'Category', sortable: true, sub: r => r.description },
            { key: 'professions', label: 'Professions', format: 'number', align: 'right', sortable: true },
            { key: 'providers', label: 'Active providers', format: 'number', align: 'right', sortable: true },
            { key: 'jobs30', label: 'Jobs (30 days)', format: 'number', align: 'right', sortable: true },
            { key: 'avgPrice', label: 'Average booking', format: 'money', align: 'right', sortable: true },
            { key: 'visible', label: 'Visible', format: 'bool', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { title: 'Category', fields: [['Name', r.name], ['Web address', r.id], ['Description', r.description], ['Icon', r.icon], ['Display order', r.position, 'number'], ['Visible', r.visible, 'bool'],
                    ['Visible professions', r.professions, 'number'], ['Hidden professions', r.hiddenProfessions, 'number'],
                    ['Active providers', r.providers, 'number'], ['Job requests (30 days)', r.jobs30, 'number'], ['Average completed booking', r.avgPrice, 'money']] },
                { title: 'Professions in it', text: 'Add, rename or move professions under Marketplace → Professions.' },
                historySection,
            ],
        },
        actions: [
            { id: 'edit', label: 'Edit', done: 'Saved', permission: 'catalogue.manage', icon: 'bi-pencil',
              message: () => 'Its web address stays the same, so links and existing jobs keep working.',
              fields: (r) => [
                  { key: 'name', label: 'Name', type: 'text', required: true, initial: r.name, maxLength: 60 },
                  { key: 'description', label: 'Short description', type: 'text', initial: r.description || '', maxLength: 255 },
                  { key: 'icon', label: 'Icon', type: 'select', options: CATEGORY_ICONS, initial: r.icon },
              ] },
            { id: 'move-up', label: 'Move up', done: 'Moved up', permission: 'catalogue.manage', icon: 'bi-arrow-up', when: r => r.position > 1, fields: () => [] },
            { id: 'move-down', label: 'Move down', done: 'Moved down', permission: 'catalogue.manage', icon: 'bi-arrow-down', fields: () => [] },
            { id: 'hide', label: 'Hide from customers', done: 'Hidden', permission: 'catalogue.manage', reason: 'required', when: r => r.visible, tone: 'danger', icon: 'bi-eye-slash',
              message: () => 'Customers can’t choose it for new jobs or requests, and new providers can’t pick its professions. Providers who already offer it, and past jobs, aren’t changed.' },
            { id: 'show', label: 'Show to customers', done: 'Shown', permission: 'catalogue.manage', reason: 'optional', when: r => !r.visible, tone: 'primary', icon: 'bi-eye',
              message: (r) => (r.professions ? 'Customers can choose it straight away.' : 'Add a visible profession under Professions first: an empty category can’t be shown.') },
        ],
        create: {
            label: 'New category', done: 'Category created (hidden until you show it)', permission: 'catalogue.manage',
            message: 'It starts hidden, so you can add its professions first. Show it when it’s ready.',
            fields: [
                { key: 'name', label: 'Name', type: 'text', required: true, maxLength: 60 },
                { key: 'description', label: 'Short description', type: 'text', maxLength: 255 },
                { key: 'icon', label: 'Icon', type: 'select', options: CATEGORY_ICONS, initial: 'bi-briefcase' },
            ],
        },
    },

    /* ================= Professions ================= */
    professions: {
        type: 'records', entity: 'Profession', statusKey: false,
        description: 'What providers offer within each category. Hidden professions can’t be chosen by new providers; providers who already offer one keep it.',
        search: { keys: ['id', 'name', 'pluralName'], placeholder: 'Search professions' },
        tabs: [
            { id: 'all', label: 'All' },
            { id: 'visible', label: 'Visible', filter: r => r.visible && r.categoryVisible },
            { id: 'hidden', label: 'Hidden', filter: r => !r.visible || !r.categoryVisible },
        ],
        filters: [{ key: 'category', label: 'Category', options: categoryOptions }],
        defaultSort: { key: 'name', dir: 'asc' },
        kpis: (rows) => [
            kpiCount('Professions', rows.length),
            kpiCount('Visible to customers', count(rows, r => r.visible && r.categoryVisible)),
            kpiCount('With no approved providers', count(rows, r => r.visible && !r.providers)),
        ],
        columns: [
            { key: 'name', label: 'Profession', sortable: true, sub: r => r.pluralName },
            { key: 'category', label: 'Category', format: 'category', sortable: true },
            { key: 'providers', label: 'Approved providers', format: 'number', align: 'right', sortable: true },
            { key: 'visible', label: 'Visible', format: 'bool', sortable: true },
        ],
        detail: {
            title: r => r.name,
            sections: (r) => [
                { title: 'Profession', fields: [['Name', r.name], ['Plural (for headings)', r.pluralName], ['Web address', r.id], ['Category', r.category, 'category'],
                    ['Visible', r.visible, 'bool'], ['Category visible', r.categoryVisible, 'bool'], ['Approved providers', r.providers, 'number']] },
                historySection,
            ],
        },
        actions: [
            { id: 'edit', label: 'Edit', done: 'Saved', permission: 'catalogue.manage', icon: 'bi-pencil',
              message: () => 'Its web address stays the same. Moving it to another category moves it for every provider who offers it.',
              fields: (r) => [
                  { key: 'name', label: 'Name', type: 'text', required: true, initial: r.name, maxLength: 60 },
                  { key: 'plural_name', label: 'Plural, for headings (e.g. “plumbers”)', type: 'text', required: true, initial: r.pluralName, maxLength: 60 },
                  { key: 'category', label: 'Category', type: 'select', options: categoryChoices(), initial: r.category },
              ] },
            { id: 'hide', label: 'Hide', done: 'Hidden', permission: 'catalogue.manage', reason: 'required', when: r => r.visible, tone: 'danger', icon: 'bi-eye-slash',
              message: () => 'New providers can’t choose it and customers won’t see it. Providers who already offer it keep it.' },
            { id: 'show', label: 'Show', done: 'Shown', permission: 'catalogue.manage', reason: 'optional', when: r => !r.visible, tone: 'primary', icon: 'bi-eye' },
        ],
        create: {
            label: 'New profession', done: 'Profession added', permission: 'catalogue.manage',
            // Read when the form opens, so it lists the categories as they are now
            fields: () => [
                { key: 'category', label: 'Category', type: 'select', required: true, options: categoryChoices() },
                { key: 'name', label: 'Name (e.g. “Solar technician”)', type: 'text', required: true, maxLength: 60 },
                { key: 'plural_name', label: 'Plural, for headings (e.g. “solar technicians”)', type: 'text', required: true, maxLength: 60 },
            ],
        },
    },

    /* ================= Provider portfolios ================= */
    portfolios: {
        type: 'records', entity: 'Portfolio image',
        search: { keys: ['id', 'providerName', 'providerId', 'title'], placeholder: 'Search provider or image ID' },
        tabs: statusTabs(['pending', 'approved', 'rejected', 'removed']),
        filters: [{ key: 'service', label: 'Service', options: categoryOptions }],
        defaultSort: { key: 'uploadedAt', dir: 'desc' },
        kpis: (rows) => [
            kpiCount('Waiting for review', count(rows, r => r.status === 'pending')),
            kpiCount('Approved', count(rows, r => r.status === 'approved')),
            kpiCount('Removed or rejected', count(rows, r => r.status === 'removed' || r.status === 'rejected')),
            kpiCount('Uploaded in 7 days', count(rows, r => within(r.uploadedAt, 7))),
        ],
        columns: [
            { key: 'title', label: 'Image', sortable: true, sub: r => r.id },
            { key: 'providerName', label: 'Provider', sortable: true },
            { key: 'service', label: 'Service', format: 'category' },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'uploadedAt', label: 'Uploaded', format: 'relative', sortable: true },
        ],
        detail: {
            sections: (r) => [
                { preview: { icon: 'bi-image', text: 'Image preview unavailable: portfolio uploads aren’t built yet.' } },
                { title: 'Details', fields: [['Provider', `${r.providerName} (${r.providerId})`], ['Service', r.service, 'category'], ['Uploaded', r.uploadedAt, 'date']] },
                historySection,
            ],
        },
        actions: [
            setStatus('approve', 'Approve', 'approved', { tone: 'primary', permission: 'content.view', done: 'Approved', when: r => r.status === 'pending' }),
            setStatus('reject', 'Reject', 'rejected', { tone: 'danger', reason: 'required', permission: 'content.view', done: 'Rejected', when: r => r.status === 'pending' }),
            setStatus('remove', 'Remove', 'removed', { tone: 'danger', reason: 'required', permission: 'content.view', done: 'Removed', when: r => r.status === 'approved' }),
        ],
    },

    /* ================= Reviews ================= */
    reviews: {
        type: 'records', entity: 'Review',
        search: { keys: ['id', 'comment', 'providerId', 'customerId', 'bookingId'], placeholder: 'Search review text or IDs' },
        tabs: statusTabs(['reported', 'published', 'hidden']),
        filters: [
            { key: 'rating', label: 'Rating', options: [5, 4, 3, 2, 1].map(n => [String(n), `${n} star${n > 1 ? 's' : ''}`]) },
            { key: 'service', label: 'Service', options: categoryOptions },
        ],
        period: { key: 'createdAt', label: 'Written' },
        defaultSort: { key: 'createdAt', dir: 'desc' },
        kpis: (rows) => {
            const pub = rows.filter(r => r.status === 'published');
            return [
                kpiCount('Reviews', rows.length),
                { label: 'Average rating', value: pub.length ? (sum(pub, r => r.rating) / pub.length).toFixed(2) : '—', hint: 'Published reviews only.' },
                kpiCount('Reported, awaiting decision', count(rows, r => r.status === 'reported')),
                kpiCount('Hidden', count(rows, r => r.status === 'hidden')),
            ];
        },
        columns: [
            { key: 'comment', label: 'Review', sub: r => `${r.id} · ${r.bookingId}` },
            { key: 'rating', label: 'Rating', align: 'right', sortable: true, value: r => `${r.rating} / 5`, sortValue: r => r.rating },
            { key: 'provider', label: 'Provider', value: (r, { rel }) => rel().nameOf(r.providerId), hideBelow: 'lg' },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'createdAt', label: 'Written', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => `${r.rating}-star review`,
            sections: (r, { rel }) => [
                { text: `“${r.comment}”` },
                { title: 'Details', fields: [
                    ['Provider', `${rel().nameOf(r.providerId)} (${r.providerId})`], ['Customer', `${rel().nameOf(r.customerId)} (${r.customerId})`],
                    ['Booking', r.bookingId], ['Service', r.service, 'category'], ['Reports', r.reports, 'number'], ['Written', r.createdAt, 'date'],
                ] },
                historySection,
            ],
        },
        actions: [
            setStatus('dismiss', 'Keep published', 'published', { tone: 'primary', reason: 'required', reasonLabel: 'Why does it stay?', permission: 'content.view', done: 'Reports dismissed', when: r => r.status === 'reported', extra: () => ({ reports: 0 }) }),
            setStatus('hide', 'Hide review', 'hidden', { tone: 'danger', reason: 'required', permission: 'content.view', done: 'Hidden', when: r => r.status !== 'hidden', message: () => 'The rating stops counting towards the provider’s average.' }),
            setStatus('restore', 'Restore', 'published', { reason: 'required', permission: 'content.view', done: 'Restored', when: r => r.status === 'hidden' }),
        ],
    },
};
