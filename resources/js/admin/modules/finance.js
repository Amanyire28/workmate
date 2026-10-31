import { count, sum, within, share, kpiCount, kpiMoney, kpiRate, statusTabs, setStatus, historySection, formatUGXCompact, formatPercent, me } from './helpers.js';
import { moneyPanels, bucketBars, ranking } from '../components/charts/builders.js';

const NO_MONEY = 'Payments aren’t connected yet, so there are no transactions, refunds or payouts, and nothing here moves money.';
const methods = [['MTN Mobile Money', 'MTN Mobile Money'], ['Airtel Money', 'Airtel Money'], ['Card', 'Card']];

export default {
    /* ================= Financial overview ================= */
    'finance-overview': {
        type: 'analytics',
        datasets: ['payouts', 'refunds'],
        description: 'Gross transaction value, commission, refunds, fees and net revenue, kept as separate figures.',
        placeholderTitles: ['Transaction value and commission', 'Refunds and payment fees', 'Commission by service', 'Payout status'],
        analytics: (d, { sets }) => {
            const t = d.totals;
            const p = d.previous;
            const ch = (k) => (p && p[k] ? (t[k] - p[k]) / p[k] : null);
            const refundCommission = Math.round(t.refunds * d.commissionRate);
            const net = t.commission - t.fees - refundCommission;
            const pending = sets.payouts.filter(x => x.status === 'pending');
            return {
                kpis: [
                    kpiMoney('Gross transaction value', t.gtv, { delta: ch('gtv'), hint: 'Everything customers paid for completed bookings. Not WorkMate’s revenue.' }),
                    kpiMoney('Platform commission', t.commission, { delta: ch('commission'), hint: `Commission at the planned rate of ${formatPercent(d.commissionRate)}.` }),
                    kpiMoney('Net platform revenue', net, { hint: 'Commission minus payment-provider fees minus commission returned on refunds.' }),
                    kpiMoney('Pending provider payouts', sum(pending, x => x.amount), { context: `${pending.length} payouts`, to: '/admin/finance/payouts' }),
                ],
                charts: [
                    moneyPanels(d, { id: 'gtv', title: 'Transaction value and commission', summary: `${formatUGXCompact(t.gtv)} gross transaction value; ${formatUGXCompact(t.commission)} commission. Separate scales, separate figures.`,
                        panels: [{ key: 'g', label: 'Gross transaction value', value: r => r.gtv }, { key: 'c', label: 'Platform commission', value: r => r.commission }] }),
                    bucketBars(d, { id: 'costs', title: 'Refunds and payment fees', unit: 'money', summary: `${formatUGXCompact(t.refunds)} refunded and ${formatUGXCompact(t.fees)} paid in payment-provider fees.`,
                        series: [{ key: 'r', label: 'Refunds', value: b => b.refunds }, { key: 'f', label: 'Payment fees', value: b => b.fees }] }),
                    ranking({ id: 'payouts', title: 'Payout status', summary: `${sets.payouts.filter(x => x.status === 'failed').length} failed payouts need attention.`,
                        rows: ['paid', 'pending', 'held', 'failed'].map(s => ({ label: s[0].toUpperCase() + s.slice(1), value: count(sets.payouts, x => x.status === s), muted: s === 'paid' })), columnLabel: 'Payouts' }),
                    ranking({ id: 'refunds', title: 'Refund requests by status', summary: `${count(sets.refunds, x => x.status === 'requested')} refund requests are waiting for a decision.`,
                        rows: ['requested', 'approved', 'processed', 'rejected'].map(s => ({ label: s[0].toUpperCase() + s.slice(1), value: count(sets.refunds, x => x.status === s) })), columnLabel: 'Refunds' }),
                ],
                table: {
                    title: 'Definitions', summary: 'How each figure is calculated, so they are never mixed up.',
                    columns: [{ key: 'figure', label: 'Figure' }, { key: 'value', label: 'This period', format: 'money' }],
                    rows: [
                        { figure: 'Gross transaction value (paid by customers)', value: t.gtv },
                        { figure: 'Platform commission', value: t.commission },
                        { figure: 'Payment-provider fees', value: t.fees },
                        { figure: 'Refunds paid back to customers', value: t.refunds },
                        { figure: 'Commission returned on refunds', value: refundCommission },
                        { figure: 'Net platform revenue', value: net },
                    ],
                },
            };
        },
    },

    /* ================= Transactions ================= */
    transactions: {
        type: 'records', entity: 'Transaction',
        search: { keys: ['id', 'bookingId', 'customerId', 'providerRef'], placeholder: 'Search transaction, booking, customer or payment reference' },
        tabs: statusTabs(['settled', 'pending', 'failed', 'refunded']),
        filters: [
            { key: 'method', label: 'Method', options: methods },
            { key: 'reconciliation', label: 'Reconciliation', options: [['matched', 'Matched'], ['unmatched', 'Unmatched'], ['duplicate', 'Duplicate']] },
        ],
        period: { key: 'createdAt', label: 'Created' },
        defaultSort: { key: 'createdAt', dir: 'desc' },
        exportable: true, exportPermission: 'finance.view',
        notice: 'Card numbers and mobile money PINs are never stored or shown. Payments are reversed or refunded, never deleted.',
        kpis: (rows) => {
            const recent = rows.filter(r => within(r.createdAt, 30));
            return [
                kpiMoney('Settled (30 days)', sum(recent.filter(r => r.status === 'settled' && r.reconciliation !== 'duplicate'), r => r.amount)),
                kpiCount('Pending', count(rows, r => r.status === 'pending')),
                kpiRate('Payment failure rate (30 days)', share(count(recent, r => r.status === 'failed'), recent.length), { goodWhen: 'down' }),
                kpiCount('Need reconciliation', count(rows, r => r.reconciliation === 'unmatched' || r.reconciliation === 'duplicate'), { to: '/admin/finance/reconciliation' }),
            ];
        },
        columns: [
            { key: 'id', label: 'Transaction', sortable: true, sub: r => `${r.bookingId} · ${r.providerRef}` },
            { key: 'payer', label: 'Payer', value: (r, { rel }) => rel().nameOf(r.customerId), hideBelow: 'lg' },
            { key: 'amount', label: 'Amount', format: 'money', align: 'right', sortable: true },
            { key: 'commission', label: 'Commission', format: 'money', align: 'right', sortable: true, hideBelow: 'lg' },
            { key: 'method', label: 'Method', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'createdAt', label: 'Created', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => `Payment ${r.id}`,
            sections: (r, { rel }) => [
                { title: 'Payment', fields: [
                    ['Amount', r.amount, 'money'], ['Currency', 'UGX'], ['Method', r.method], ['Payment provider reference', r.providerRef], ['Status', r.status, 'status'],
                    ['Failure reason', r.failureReason], ['Created', r.createdAt, 'date'], ['Settled', r.settledAt, 'date'],
                ] },
                { title: 'Split', fields: [['Platform commission', r.commission, 'money'], ['Payment-provider fee', r.fee, 'money'], ['Due to provider', r.amount - r.commission, 'money'], ['Refunded', r.refunded, 'money']] },
                { title: 'Related', fields: [['Booking', r.bookingId], ['Payer', `${rel().nameOf(r.customerId)} (${r.customerId})`], ['Provider', `${rel().nameOf(r.providerId)} (${r.providerId})`], ['Reconciliation', r.reconciliation, 'status']] },
                historySection,
            ],
        },
        actions: [
            { id: 'flag', label: 'Flag for reconciliation', done: 'Flagged for reconciliation', permission: 'finance.view', reason: 'required',
              when: r => r.status === 'settled' && r.reconciliation === 'matched', apply: () => ({ patch: { reconciliation: 'unmatched' } }) },
        ],
    },

    /* ================= Commissions ================= */
    commissions: {
        type: 'records', entity: 'Commission', statusKey: false,
        notice: 'The commission rate is set in Business Rules and applies to new bookings only. Figures here are for the last 30 days.',
        search: { keys: ['name'], placeholder: 'Search service' },
        defaultSort: { key: 'commission30', dir: 'desc' },
        exportable: true, exportPermission: 'finance.view',
        kpis: (rows) => [
            kpiMoney('Commission (30 days)', sum(rows, r => r.commission30)),
            kpiMoney('Payment fees (30 days)', sum(rows, r => r.fees30)),
            kpiMoney('Gross transaction value (30 days)', sum(rows, r => r.gtv30)),
            kpiRate('Current rate', rows[0]?.rate || 0),
        ],
        charts: (rows) => [ranking({ id: 'comm', title: 'Commission by service (30 days)', unit: 'money', summary: `${[...rows].sort((a, b) => b.commission30 - a.commission30)[0]?.name} brings in the most commission.`, rows: [...rows].sort((a, b) => b.commission30 - a.commission30).map(r => ({ label: r.name, value: r.commission30 })), columnLabel: 'Commission (UGX)' })],
        columns: [
            { key: 'name', label: 'Service', sortable: true },
            { key: 'rate', label: 'Rate', format: 'percent', align: 'right' },
            { key: 'transactions', label: 'Payments', format: 'number', align: 'right', sortable: true },
            { key: 'gtv30', label: 'GTV', format: 'money', align: 'right', sortable: true },
            { key: 'commission30', label: 'Commission', format: 'money', align: 'right', sortable: true },
            { key: 'fees30', label: 'Payment fees', format: 'money', align: 'right', sortable: true, hideBelow: 'lg' },
        ],
        detail: { sections: (r) => [{ title: r.name, fields: [['Rate', r.rate, 'percent'], ['Payments (30 days)', r.transactions, 'number'], ['Gross transaction value', r.gtv30, 'money'], ['Commission', r.commission30, 'money'], ['Payment fees', r.fees30, 'money'], ['Commission after fees', r.commission30 - r.fees30, 'money']] }] },
    },

    /* ================= Refunds ================= */
    refunds: {
        type: 'records', entity: 'Refund',
        notice: NO_MONEY, noticeTone: 'warning',
        search: { keys: ['id', 'transactionId', 'bookingId', 'customerId', 'reason'], placeholder: 'Search refund, transaction, booking or customer ID' },
        tabs: statusTabs(['requested', 'approved', 'processed', 'rejected']),
        filters: [{ key: 'reason', label: 'Reason', options: ['Provider did not attend', 'Work not completed', 'Charged twice', 'Price higher than agreed'].map(r => [r, r]) }],
        period: { key: 'requestedAt', label: 'Requested' },
        defaultSort: { key: 'requestedAt', dir: 'desc' },
        exportable: true, exportPermission: 'finance.view',
        kpis: (rows) => [
            kpiCount('Waiting for a decision', count(rows, r => r.status === 'requested')),
            kpiMoney('Requested amount waiting', sum(rows.filter(r => r.status === 'requested'), r => r.amount)),
            kpiCount('Approved, not yet paid', count(rows, r => r.status === 'approved')),
            kpiMoney('Refunded (30 days)', sum(rows.filter(r => r.status === 'processed' && within(r.decidedAt, 30)), r => r.amount)),
        ],
        columns: [
            { key: 'id', label: 'Refund', sortable: true, sub: r => `${r.bookingId} · ${r.transactionId}` },
            { key: 'customer', label: 'Customer', value: (r, { rel }) => rel().nameOf(r.customerId), hideBelow: 'lg' },
            { key: 'amount', label: 'Amount', format: 'money', align: 'right', sortable: true },
            { key: 'reason', label: 'Reason' },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'requestedAt', label: 'Requested', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => `Refund ${r.id}`,
            sections: (r, { rel }) => {
                const tx = rel().transaction(r.transactionId);
                return [
                    { title: 'Request', fields: [['Amount', r.amount, 'money'], ['Of original payment', tx?.amount, 'money'], ['Reason', r.reason], ['Customer', `${rel().nameOf(r.customerId)} (${r.customerId})`],
                        ['Booking', r.bookingId], ['Transaction', r.transactionId], ['Requested', r.requestedAt, 'date'], ['Decided', r.decidedAt, 'date'], ['Decided by', r.decidedBy]] },
                    historySection,
                ];
            },
        },
        actions: [
            { id: 'approve', label: 'Approve refund', done: 'Refund approved', tone: 'primary', permission: 'finance.view', icon: 'bi-check2-circle',
              when: r => r.status === 'requested', reason: 'optional', reasonLabel: 'Note',
              confirmTitle: r => `Approve a refund of UGX ${r.amount.toLocaleString('en-UG')}?`,
              message: () => 'This records the decision. Sending the money needs the payment integration, so the refund stays “Approved” until then.',
              apply: () => ({ patch: { status: 'approved', decidedAt: new Date().toISOString(), decidedBy: me() } }) },
            setStatus('reject', 'Reject refund', 'rejected', { tone: 'danger', reason: 'required', permission: 'finance.view', done: 'Refund rejected', when: r => r.status === 'requested',
                extra: () => ({ decidedAt: new Date().toISOString(), decidedBy: me() }) }),
        ],
    },

    /* ================= Provider payouts ================= */
    payouts: {
        type: 'records', entity: 'Payout',
        notice: NO_MONEY, noticeTone: 'warning',
        search: { keys: ['id', 'providerId'], placeholder: 'Search payout or provider ID' },
        tabs: statusTabs(['pending', 'held', 'failed', 'paid']),
        period: { key: 'requestedAt', label: 'Requested' },
        defaultSort: { key: 'requestedAt', dir: 'desc' },
        exportable: true, exportPermission: 'finance.view',
        kpis: (rows) => [
            kpiMoney('Pending', sum(rows.filter(r => r.status === 'pending'), r => r.amount), { context: `${count(rows, r => r.status === 'pending')} payouts` }),
            kpiCount('Failed', count(rows, r => r.status === 'failed')),
            kpiCount('On hold', count(rows, r => r.status === 'held')),
            kpiMoney('Paid (30 days)', sum(rows.filter(r => r.status === 'paid' && within(r.completedAt, 30)), r => r.amount)),
        ],
        columns: [
            { key: 'id', label: 'Payout', sortable: true, sub: r => `Week ending ${new Date(r.periodEnd).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}` },
            { key: 'provider', label: 'Payee', value: (r, { rel }) => rel().nameOf(r.providerId) },
            { key: 'amount', label: 'Amount', format: 'money', align: 'right', sortable: true },
            { key: 'transactionCount', label: 'Payments', format: 'number', align: 'right', hideBelow: 'lg' },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'reconciliation', label: 'Reconciliation', format: 'status', hideBelow: 'lg' },
            { key: 'requestedAt', label: 'Requested', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => `Payout ${r.id}`,
            sections: (r, { rel }) => [
                { title: 'Payout', fields: [['Payee', `${rel().nameOf(r.providerId)} (${r.providerId})`], ['Amount (after commission)', r.amount, 'money'], ['Period', `${new Date(r.periodStart).toLocaleDateString('en-GB')} – ${new Date(r.periodEnd).toLocaleDateString('en-GB')}`],
                    ['Requested', r.requestedAt, 'date'], ['Completed', r.completedAt, 'date'], ['Failure reason', r.failureReason], ['Reconciliation', r.reconciliation, 'status']] },
                { title: 'Payments included', table: { rows: r.transactions.map(id => rel().transaction(id)).filter(Boolean), columns: [{ key: 'id', label: 'Transaction' }, { key: 'bookingId', label: 'Booking' }, { key: 'amount', label: 'Amount', format: 'money' }, { key: 'commission', label: 'Commission', format: 'money' }] } },
                historySection,
            ],
        },
        actions: [
            setStatus('hold', 'Hold payout', 'held', { tone: 'danger', reason: 'required', permission: 'finance.view', done: 'Payout held', when: r => r.status === 'pending' }),
            setStatus('release', 'Release hold', 'pending', { tone: 'primary', reason: 'required', permission: 'finance.view', done: 'Hold released', when: r => r.status === 'held' }),
            setStatus('retry', 'Queue for retry', 'pending', { reason: 'required', reasonLabel: 'What was fixed?', permission: 'finance.view', done: 'Queued for retry', when: r => r.status === 'failed', message: () => 'Make sure the payee’s mobile money details were corrected first.' }),
        ],
    },

    /* ================= Reconciliation ================= */
    reconciliation: {
        type: 'records', entity: 'Reconciliation exception',
        description: 'Differences between WorkMate’s records and the payment providers’ settlement reports.',
        search: { keys: ['id', 'reference', 'transactionId'], placeholder: 'Search reference or transaction' },
        tabs: statusTabs(['open', 'resolved']),
        filters: [{ key: 'kind', label: 'Kind', options: [['Unmatched reference', 'Unmatched reference'], ['Duplicate reference', 'Duplicate reference'], ['Failed payout', 'Failed payout']] }],
        defaultSort: { key: 'detectedAt', dir: 'desc' },
        exportable: true, exportPermission: 'finance.view',
        kpis: (rows) => [
            kpiCount('Open exceptions', count(rows, r => r.status === 'open')),
            kpiMoney('Amount in question', sum(rows.filter(r => r.status === 'open'), r => r.difference)),
            kpiCount('Duplicate references', count(rows, r => r.kind === 'Duplicate reference' && r.status === 'open')),
            kpiCount('Failed payouts', count(rows, r => r.kind === 'Failed payout' && r.status === 'open')),
        ],
        columns: [
            { key: 'kind', label: 'Exception', sortable: true, sub: r => r.id },
            { key: 'reference', label: 'Reference' },
            { key: 'amount', label: 'Amount', format: 'money', align: 'right', sortable: true },
            { key: 'status', label: 'Status', format: 'status', sortable: true },
            { key: 'detectedAt', label: 'Detected', format: 'relative', sortable: true },
        ],
        detail: {
            title: r => `${r.kind}: ${r.reference}`,
            sections: (r) => [
                { title: 'Exception', fields: [['Kind', r.kind], ['Reference', r.reference], ['Transaction', r.transactionId], ['Amount', r.amount, 'money'], ['Difference', r.difference, 'money'], ['Detected', r.detectedAt, 'date']] },
                historySection,
            ],
        },
        actions: [
            { id: 'resolve', label: 'Resolve', done: 'Exception resolved', tone: 'primary', permission: 'finance.view', when: r => r.status === 'open',
              fields: () => [
                  { key: 'outcome', label: 'Outcome', type: 'select', options: [['Matched manually', 'Matched manually'], ['Duplicate reversed', 'Duplicate reversed'], ['Provider confirmed payment', 'Provider confirmed payment'], ['Written off', 'Written off']] },
                  { key: 'reason', label: 'Evidence and notes', type: 'textarea', required: true, minLength: 5 },
              ],
              apply: (r, v) => ({ patch: { status: 'resolved' }, detail: `Outcome: ${v.outcome}` }) },
        ],
    },

    /* ================= Financial reports ================= */
    'finance-reports': {
        type: 'records', entity: 'Monthly statement',
        description: 'Monthly statements, ready to export.',
        search: { keys: ['name', 'id'], placeholder: 'Search month' },
        defaultSort: { key: 'id', dir: 'desc' },
        exportable: true, exportPermission: 'finance.view',
        kpis: (rows) => [
            kpiMoney('GTV (all months shown)', sum(rows, r => r.gtv)),
            kpiMoney('Commission', sum(rows, r => r.commission)),
            kpiMoney('Refunds', sum(rows, r => r.refunds)),
            kpiMoney('Net platform revenue', sum(rows, r => r.net)),
        ],
        columns: [
            { key: 'name', label: 'Month', sortable: true, sortValue: r => r.id },
            { key: 'bookings', label: 'Completed bookings', format: 'number', align: 'right' },
            { key: 'gtv', label: 'GTV', format: 'money', align: 'right', sortable: true },
            { key: 'commission', label: 'Commission', format: 'money', align: 'right' },
            { key: 'refunds', label: 'Refunds', format: 'money', align: 'right', hideBelow: 'lg' },
            { key: 'net', label: 'Net revenue', format: 'money', align: 'right' },
            { key: 'status', label: 'Status', format: 'status' },
        ],
        detail: {
            sections: (r) => [
                { title: r.name, fields: [['Completed bookings', r.bookings, 'number'], ['Gross transaction value', r.gtv, 'money'], ['Platform commission', r.commission, 'money'], ['Payment fees', r.fees, 'money'],
                    ['Refunds', r.refunds, 'money'], ['Net platform revenue', r.net, 'money'], ['Days included', r.days, 'number']] },
                { note: 'Net revenue = commission − payment fees − commission returned on refunds. Partial months cover only the days recorded.' },
            ],
        },
    },
};

