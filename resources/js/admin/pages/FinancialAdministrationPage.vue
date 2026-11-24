<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Financial Administration" description="Where commission stands, reports and exports, adjustment requests, reconciliation exceptions and the financial audit trail." demo />

    <p class="wm-notice wm-notice-info" role="note">
      <i class="bi bi-shield-check" aria-hidden="true"></i>
      <span>Nothing on this page changes the ledger. Approving an adjustment records a decision only: posting it is not enabled until the correction policy is approved. Collected means money WorkMate verified it received, never a provider’s own report.</span>
    </p>

    <div class="wm-segmented grid grid-cols-2 sm:inline-grid sm:grid-cols-5" role="tablist" aria-label="Financial administration">
      <button v-for="t in visibleTabs" :key="t.id" type="button" role="tab" class="wm-segment !min-h-11" :class="{ 'is-active': tab === t.id }" :aria-selected="tab === t.id" @click="setTab(t.id)">{{ t.label }}</button>
    </div>
    <StateBlock v-if="error" kind="error" title="We couldn’t load this" :message="error"><button type="button" class="wm-btn wm-btn-secondary !min-h-10" @click="error = ''; setTab(tab)">Try again</button></StateBlock>

    <!-- Period and provider: used by the overview and the reports -->
    <form v-if="['overview', 'reports'].includes(tab)" class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
      <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">From<input v-model="filters.from" type="date" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
      <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">To<input v-model="filters.to" type="date" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
      <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Provider number <span class="font-normal text-[#64748B]">(optional)</span><input v-model="filters.provider" type="number" min="1" class="h-10 w-32 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
      <button type="submit" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy">Show</button>
      <p v-if="periodError" class="w-full text-[12.5px] text-[#B91C1C]" role="alert">{{ periodError }}</p>
    </form>

    <!-- ================= Overview ================= -->
    <section v-show="tab === 'overview'" class="flex flex-col gap-4" aria-label="Overview">
      <StateBlock v-if="!overview && !error" kind="loading" title="Loading…" />
      <template v-else-if="overview">
        <div>
          <h2 class="text-[15px] font-semibold text-ink">In the period: {{ overview.period.label }}</h2>
          <p class="text-[12.5px] text-[#64748B]">What was recorded between these dates.</p>
          <div class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <MetricCard v-for="c in cards.period" :key="c.key" :label="c.label" :value="formatUGX(c.value)" :hint="c.hint" :primary="c.key === 'collected'" />
          </div>
          <p class="mt-2 text-[13px] text-[#475569]">
            Applied to what was owed: {{ formatUGX(overview.period.applied_ugx) }}<template v-if="overview.period.excess_ugx"> · not applied (exception): {{ formatUGX(overview.period.excess_ugx) }}</template>
            · verified payments: {{ overview.period.collected_payments }}
          </p>
          <p class="text-[13px] text-[#475569]">Adjustments: {{ overview.period.approved_adjustments.count }} approved in the period ({{ formatUGX(overview.period.approved_adjustments.decrease_ugx) }} to decrease, {{ formatUGX(overview.period.approved_adjustments.increase_ugx) }} to increase), <strong class="text-ink">none posted</strong>.</p>
        </div>
        <div>
          <h2 class="text-[15px] font-semibold text-ink">As of now</h2>
          <p class="text-[12.5px] text-[#64748B]">Today’s position. It does not depend on the period above.</p>
          <div class="mt-2 grid grid-cols-2 gap-3 lg:grid-cols-4">
            <MetricCard v-for="c in cards.asOf" :key="c.key" :label="c.label" :value="formatUGX(c.value)" :context="c.count !== undefined ? `${c.count} ${c.count === 1 ? 'item' : 'items'}` : ''" :hint="c.hint" :primary="c.key === 'outstanding'" />
          </div>
          <p v-if="overview.as_of.provider_credit_ugx" class="mt-2 text-[13px] text-[#475569]">Providers hold {{ formatUGX(overview.as_of.provider_credit_ugx) }} in credit (paid more than owed). Nothing is refunded automatically.</p>
          <p class="mt-1 text-[13px] text-[#475569]">{{ overview.as_of.pending_adjustments }} adjustment {{ overview.as_of.pending_adjustments === 1 ? 'request is' : 'requests are' }} waiting for a decision.</p>
        </div>
        <ul class="list-disc pl-5 text-[12.5px] text-[#64748B]"><li v-for="n in overview.notes" :key="n">{{ n }}</li></ul>
      </template>
    </section>

    <!-- ================= Reports ================= -->
    <section v-show="tab === 'reports'" class="flex flex-col gap-3" aria-label="Reports">
      <div class="wm-segmented flex flex-wrap" role="tablist" aria-label="Report">
        <button v-for="r in REPORTS" :key="r.id" type="button" role="tab" class="wm-segment !min-h-10" :class="{ 'is-active': report === r.id }" @click="setReport(r.id)">{{ r.label }}</button>
      </div>
      <p class="text-[13px] text-[#475569]">{{ REPORTS.find((r) => r.id === report).hint }}</p>
      <div class="flex flex-wrap items-center gap-3">
        <a v-if="canExport" :href="csvLink" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" download><i class="bi bi-download" aria-hidden="true"></i>Download CSV</a>
        <span v-if="reportData" class="text-[12.5px] text-[#64748B]">{{ reportData.period }} · {{ reportData.total }} {{ reportData.total === 1 ? 'row' : 'rows' }}</span>
      </div>
      <StateBlock v-if="!reportData && !error" kind="loading" title="Loading…" />
      <StateBlock v-else-if="reportData && !reportData.items.length" title="Nothing in this report" message="Nothing matches this period and provider." />
      <div v-else-if="reportData" class="overflow-x-auto rounded-xl wm-surface">
        <table class="w-full min-w-[720px] text-left text-[13px]">
          <caption class="sr-only">{{ reportData.title }}</caption>
          <thead><tr class="text-[12px] text-[#64748B]"><th v-for="(label, key) in reportData.columns" :key="key" scope="col" class="p-3 font-medium" :class="String(label).includes('(UGX)') ? 'text-right' : ''">{{ label }}</th></tr></thead>
          <tbody class="divide-y divide-[#F1F5F9]">
            <tr v-for="(row, i) in reportData.items" :key="i">
              <td v-for="(label, key) in reportData.columns" :key="key" class="p-3" :class="String(label).includes('(UGX)') ? 'text-right text-ink' : 'text-[#475569]'">
                <template v-if="String(label).includes('(UGX)')">{{ row[key] === null || row[key] === undefined ? '—' : formatUGX(row[key]) }}</template>
                <template v-else>{{ row[key] === null || row[key] === undefined || row[key] === '' ? '—' : row[key] }}</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="reportData && reportData.total > reportData.per_page" class="flex items-center justify-between text-[13px]">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="reportData.page <= 1 || busy" @click="loadReport(reportData.page - 1)">Previous</button>
        <span class="text-[#64748B]">Page {{ reportData.page }} of {{ Math.ceil(reportData.total / reportData.per_page) }}</span>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="reportData.page * reportData.per_page >= reportData.total || busy" @click="loadReport(reportData.page + 1)">Next</button>
      </div>
    </section>

    <!-- ================= Adjustments ================= -->
    <section v-show="tab === 'adjustments'" class="flex flex-col gap-3" aria-label="Adjustments">
      <p class="wm-notice wm-notice-warning"><i class="bi bi-info-circle" aria-hidden="true"></i><span>{{ adjustments ? adjustments.posting.note : 'Approving records the decision only.' }}</span></p>
      <div v-if="canRequest && !adjForm.open"><button type="button" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" @click="openAdjustment"><i class="bi bi-plus-lg" aria-hidden="true"></i>Propose an adjustment</button></div>

      <form v-if="adjForm.open" class="wm-surface flex flex-col gap-3 rounded-xl p-4" novalidate @submit.prevent="submitAdjustment">
        <h2 class="text-[15px] font-semibold text-ink">Propose an adjustment</h2>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Provider number<input v-model="adj.provider_id" type="number" min="1" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /><span v-if="adjErrors.provider_id" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ adjErrors.provider_id }}</span></label>
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">What kind<select v-model="adj.type" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal"><option value="">Choose…</option><option v-for="t in ADJUSTMENT_TYPES" :key="t.id" :value="t.id">{{ t.label }}</option></select><span v-if="adjErrors.type" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ adjErrors.type }}</span></label>
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Proposed effect<select v-model="adj.direction" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal"><option v-for="d in DIRECTIONS" :key="d.id" :value="d.id">{{ d.label }}</option></select></label>
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Amount (UGX)<input v-model="adj.amount_ugx" type="text" inputmode="numeric" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /><span v-if="adjErrors.amount_ugx" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ adjErrors.amount_ugx }}</span></label>
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">It is about<select v-model="adj.subject" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal"><option v-for="s in SUBJECTS" :key="s.id" :value="s.id">{{ s.label }}</option></select></label>
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Its number<input v-model="adj.subject_id" type="number" min="1" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /><span v-if="adjErrors.subject_id" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ adjErrors.subject_id }}</span></label>
        </div>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Reason <span class="font-normal text-[#64748B]">(internal; the approver reads it)</span><textarea v-model="adj.reason" rows="3" maxlength="2000" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5 font-normal"></textarea><span v-if="adjErrors.reason" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ adjErrors.reason }}</span></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">References <span class="font-normal text-[#64748B]">(statement line, transaction id, case number; optional)</span><input v-model="adj.reference" type="text" maxlength="200" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">A sentence the provider could read <span class="font-normal text-[#64748B]">(optional; only used if it is ever posted)</span><input v-model="adj.provider_reason" type="text" maxlength="300" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <p v-if="formError" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ formError }}</span></p>
        <div class="flex gap-2"><button type="button" class="wm-btn wm-btn-secondary !min-h-11 !rounded-lg" :disabled="busy" @click="adjForm.open = false">Cancel</button><button type="submit" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" :disabled="busy">Send for approval</button></div>
      </form>

      <div class="wm-segmented flex flex-wrap" role="tablist" aria-label="Status">
        <button v-for="s in ADJ_FILTERS" :key="s.id" type="button" role="tab" class="wm-segment !min-h-10" :class="{ 'is-active': adjStatus === s.id }" @click="adjStatus = s.id; loadAdjustments(1)">{{ s.label }} <span class="text-[12px] opacity-70">{{ s.id ? (adjustments && adjustments.counts[s.id]) || 0 : '' }}</span></button>
      </div>
      <StateBlock v-if="!adjustments && !error" kind="loading" title="Loading…" />
      <StateBlock v-else-if="adjustments && !adjustments.items.length" title="No requests" message="Adjustment requests appear here." />
      <ul v-else-if="adjustments" class="flex flex-col gap-3">
        <li v-for="a in adjustments.items" :key="a.id" class="wm-surface flex flex-col gap-1.5 rounded-xl p-4">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="text-[14.5px] font-semibold text-ink">#{{ a.id }} · {{ a.provider || 'Provider' }} · {{ a.direction === 'decrease' ? 'decrease' : 'increase' }} by {{ formatUGX(a.amount_ugx) }}</p>
            <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="BADGE[a.status]">{{ statusWords(a.status) }}</span>
          </div>
          <p class="text-[13px] text-[#475569]">{{ typeLabel(a.type) }} · proposed by {{ a.requested_by }} {{ formatDateTime(a.requested_at) }}</p>
          <p class="text-[13px] text-[#475569]">About: <template v-for="(label, key) in REF_LABELS"><span v-if="a[key]" :key="key" class="mr-2">{{ label }} #{{ a[key] }}</span></template><span v-if="a.reference">· {{ a.reference }}</span></p>
          <p class="text-[13px] text-ink">“{{ a.reason }}”</p>
          <p v-if="a.status !== 'pending'" class="text-[13px] text-ink">{{ a.status === 'approved' ? 'Approved' : 'Rejected' }} by {{ a.decided_by }} {{ formatDateTime(a.decided_at) }}: “{{ a.decision_reason }}”. <strong v-if="a.status === 'approved'">Not posted to the ledger.</strong></p>
          <div v-else-if="canApprove" class="flex flex-wrap gap-2 pt-1">
            <button type="button" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg" @click="beginDecision(a, 'approve')">Approve…</button>
            <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="beginDecision(a, 'reject')">Reject…</button>
          </div>
          <form v-if="deciding && deciding.id === a.id" class="flex flex-col gap-2 rounded-lg border border-[#E2E8F0] p-3" novalidate @submit.prevent="submitDecision(a)">
            <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Reason for {{ deciding.mode === 'approve' ? 'approving' : 'rejecting' }} <span class="font-normal text-[#64748B]">(kept in the audit log)</span><textarea v-model="deciding.reason" rows="2" maxlength="500" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5 font-normal"></textarea></label>
            <p v-if="deciding.error" class="text-[12.5px] text-[#B91C1C]" role="alert">{{ deciding.error }}</p>
            <div class="flex gap-2"><button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy" @click="deciding = null">Back</button><button type="submit" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg" :disabled="busy">{{ deciding.mode === 'approve' ? 'Approve (records the decision only)' : 'Reject' }}</button></div>
          </form>
        </li>
      </ul>
    </section>

    <!-- ================= Exceptions ================= -->
    <section v-show="tab === 'exceptions'" class="flex flex-col gap-3" aria-label="Exceptions">
      <template v-if="!openException">
        <div class="wm-segmented flex flex-wrap" role="tablist" aria-label="Status">
          <button v-for="s in EXC_FILTERS" :key="s.id" type="button" role="tab" class="wm-segment !min-h-10" :class="{ 'is-active': excStatus === s.id }" @click="excStatus = s.id; loadExceptions(1)">{{ s.label }} <span class="text-[12px] opacity-70">{{ s.id ? (exceptions && exceptions.counts[s.id]) || 0 : '' }}</span></button>
        </div>
        <StateBlock v-if="!exceptions && !error" kind="loading" title="Loading…" />
        <StateBlock v-else-if="exceptions && !exceptions.items.length" title="No exceptions" message="Overpayments and excess credits appear here for investigation." />
        <ul v-else-if="exceptions" class="flex flex-col gap-2">
          <li v-for="e in exceptions.items" :key="e.id"><button type="button" class="wm-surface flex w-full flex-col gap-0.5 rounded-xl p-3.5 text-left hover:shadow-md" @click="showException(e.id)">
            <span class="flex flex-wrap items-center justify-between gap-2"><span class="text-[14.5px] font-semibold text-ink">#{{ e.id }} · {{ e.provider || 'Provider' }} · {{ formatUGX(e.amount_ugx) }}</span><span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="BADGE[e.status]">{{ statusWords(e.status) }}</span></span>
            <span class="text-[13px] text-[#475569]">{{ EXCEPTION_KINDS[e.kind] }}<template v-if="e.assigned_to"> · with {{ e.assigned_to }}</template></span></button></li>
        </ul>
      </template>
      <template v-else>
        <div><button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" @click="openException = null"><i class="bi bi-arrow-left" aria-hidden="true"></i>All exceptions</button></div>
        <section class="wm-surface flex flex-col gap-1.5 rounded-xl p-4">
          <div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-[16px] font-semibold text-ink">Exception #{{ openException.id }} · {{ openException.provider }}</h2><span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="BADGE[openException.status]">{{ statusWords(openException.status) }}</span></div>
          <p class="text-[13.5px] text-ink">{{ EXCEPTION_KINDS[openException.kind] }}: <strong>{{ formatUGX(openException.amount_ugx) }}</strong></p>
          <p class="text-[13px] text-[#475569]">{{ openException.detail }}</p>
          <p v-if="openException.pending_decision" class="wm-notice wm-notice-warning"><i class="bi bi-hourglass-split" aria-hidden="true"></i><span>{{ openException.pending_decision }}</span></p>
          <p v-if="openException.status === 'resolved'" class="text-[13px] text-ink">Resolved: {{ openException.resolution === 'no_financial_change' ? 'no financial change' : openException.resolution.replace(/_/g, ' ') }}<template v-if="openException.resolved_by"> by {{ openException.resolved_by }}</template> {{ formatDateTime(openException.resolved_at) }}<template v-if="openException.resolution_note">: “{{ openException.resolution_note }}”</template></p>
        </section>
        <section v-if="openException.ledger.length" class="wm-surface rounded-xl p-4"><h3 class="text-[14px] font-semibold text-ink">Related ledger lines</h3>
          <ul class="mt-2 divide-y divide-[#F1F5F9] text-[13px]"><li v-for="(l, i) in openException.ledger" :key="i" class="flex justify-between gap-3 py-1.5"><span class="text-[#475569]">{{ l.type }} · {{ l.reference }} · {{ formatDateTime(l.at) }}</span><span class="font-medium" :class="l.amount_ugx < 0 ? 'text-[#166534]' : 'text-ink'">{{ l.amount_ugx < 0 ? '−' : '+' }}{{ formatUGX(Math.abs(l.amount_ugx)) }}</span></li></ul></section>
        <section v-if="openException.payment" class="wm-surface rounded-xl p-4 text-[13px] text-[#475569]"><h3 class="text-[14px] font-semibold text-ink">Related payment</h3>
          <p>Reported {{ formatUGX(openException.payment.amount_ugx) }}, verified {{ formatUGX(openException.payment.verified_amount_ugx) }}, applied {{ formatUGX(openException.payment.credited_ugx) }} · {{ openException.payment.channel }}<template v-if="openException.payment.reference"> · {{ openException.payment.reference }}</template></p></section>
        <section class="wm-surface rounded-xl p-4"><h3 class="text-[14px] font-semibold text-ink">Investigation history</h3>
          <p v-if="!openException.events.length" class="mt-1 text-[13px] text-[#64748B]">Nothing yet.</p>
          <ol class="mt-2 flex flex-col gap-1.5 border-l border-[#E2E8F0] pl-3 text-[13px]"><li v-for="(v, i) in openException.events" :key="i"><span class="text-ink">{{ v.event === 'taken' ? `${v.by} took it` : v.event === 'resolved' ? `${v.by} resolved it` : `${v.by} noted` }}</span> <span class="text-[#64748B]">· {{ formatDateTime(v.at) }}</span><span v-if="v.note" class="block text-[#475569]">{{ v.note }}</span><span v-if="v.reference" class="block text-[#64748B]">Ref: {{ v.reference }}</span></li></ol></section>
        <section v-if="openException.adjustments.length" class="wm-surface rounded-xl p-4 text-[13px] text-[#475569]"><h3 class="text-[14px] font-semibold text-ink">Adjustment requests about it</h3>
          <ul class="mt-1"><li v-for="a in openException.adjustments" :key="a.id">#{{ a.id }} · {{ a.direction }} {{ formatUGX(a.amount_ugx) }} · {{ statusWords(a.status) }}</li></ul></section>

        <section v-if="openException.status !== 'resolved'" class="wm-surface flex flex-col gap-3 rounded-xl p-4" aria-label="Actions">
          <div v-if="canReview && openException.status === 'open'"><button type="button" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" :disabled="busy" @click="takeException">Take this exception</button></div>
          <form v-if="canReview" class="flex flex-col gap-2" novalidate @submit.prevent="addNote">
            <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Add a note<textarea v-model="note.text" rows="2" maxlength="2000" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5 font-normal"></textarea></label>
            <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Reference <span class="font-normal text-[#64748B]">(optional)</span><input v-model="note.reference" type="text" maxlength="200" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
            <div><button type="submit" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy">Save note</button></div>
          </form>
          <form v-if="canResolve" class="flex flex-col gap-2 border-t border-[#E2E8F0] pt-3" novalidate @submit.prevent="resolveException">
            <h3 class="text-[14px] font-semibold text-ink">Close with no financial change</h3>
            <p class="text-[12.5px] text-[#64748B]">This changes no balance and decides no refund, transfer or credit. Use it only when you have documented why nothing needs to change.</p>
            <textarea v-model="resolution.note" rows="3" maxlength="500" class="rounded-lg border border-[#CBD5E1] px-2 py-1.5" aria-label="Why no financial change is needed"></textarea>
            <label class="flex items-start gap-2 text-[13px] text-ink"><input v-model="resolution.ack" type="checkbox" class="mt-0.5 h-4 w-4 accent-[#1769D2]" />I understand this closes the exception without any financial change.</label>
            <div><button type="submit" class="wm-btn wm-btn-primary !min-h-10 !rounded-lg" :disabled="busy">Close exception</button></div>
          </form>
          <p v-else class="text-[12.5px] text-[#64748B]">Closing an exception needs a Super Administrator.</p>
          <p v-if="actionError" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ actionError }}</span></p>
        </section>
      </template>
    </section>

    <!-- ================= Audit ================= -->
    <section v-show="tab === 'audit'" class="flex flex-col gap-3" aria-label="Audit trail">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="loadAudit(1)">
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Action starts with<input v-model="auditFilters.action" type="text" maxlength="60" placeholder="e.g. finance.adjustment" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Provider number<input v-model="auditFilters.provider" type="number" min="1" class="h-10 w-28 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <button type="submit" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy">Search</button>
      </form>
      <StateBlock v-if="!audit && !error" kind="loading" title="Loading…" />
      <StateBlock v-else-if="audit && !audit.items.length" title="Nothing recorded" message="Financial actions are listed here as they happen." />
      <ul v-else-if="audit" class="flex flex-col gap-2">
        <li v-for="a in audit.items" :key="a.id" class="wm-surface rounded-xl p-3.5 text-[13px]">
          <p class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-ink">{{ a.action }}</strong><span class="text-[#64748B]">{{ formatDateTime(a.at) }}</span></p>
          <p class="text-[#475569]">by {{ a.by }}<template v-if="a.subject"> · {{ a.subject }}</template></p>
          <p v-if="a.reason" class="text-ink">“{{ a.reason }}”</p>
        </li>
      </ul>
      <div v-if="audit && audit.total > audit.per_page" class="flex items-center justify-between text-[13px]">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="audit.page <= 1" @click="loadAudit(audit.page - 1)">Newer</button><span class="text-[#64748B]">Page {{ audit.page }}</span>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="audit.page * audit.per_page >= audit.total" @click="loadAudit(audit.page + 1)">Older</button></div>
    </section>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import StateBlock from '../components/StateBlock.vue';
import MetricCard from '../components/MetricCard.vue';
import { api } from '@/api/client.js';
import { newClientToken } from '@/utils/photos.js';
import { can } from '../permissions.js';
import { toast } from '../stores/prototypeChanges.js';
import { formatUGX, formatDateTime } from '../utils/format.js';
import {
  TABS, REPORTS, ADJUSTMENT_TYPES, DIRECTIONS, SUBJECTS, EXCEPTION_KINDS, typeLabel, statusWords, defaultPeriod, periodProblem, query, csvUrl, blankAdjustment, validateAdjustment, hasErrors, adjustmentBody, overviewCards,
} from '../utils/finance.js';

const PERMISSION = { overview: 'finance.overview.view', reports: 'finance.reports.view', adjustments: 'finance.reports.view', exceptions: 'finance.exceptions.review', audit: 'finance.reports.view' };
const BADGE = {
  pending: 'bg-[#FEF3C7] text-[#92400E]', approved: 'bg-[#DBEAFE] text-[#1E40AF]', rejected: 'bg-[#FEE2E2] text-[#991B1B]',
  open: 'bg-[#FEF3C7] text-[#92400E]', under_review: 'bg-[#DBEAFE] text-[#1E40AF]', resolved: 'bg-[#DCFCE7] text-[#166534]',
};
const REF_LABELS = { cycle_id: 'settlement', assessment_id: 'obligation', commission_payment_id: 'commission payment', exception_id: 'exception', payment_report_id: 'customer payment', dispute_id: 'dispute' };
const ADJ_FILTERS = [{ id: '', label: 'All' }, { id: 'pending', label: 'Waiting' }, { id: 'approved', label: 'Approved' }, { id: 'rejected', label: 'Rejected' }];
const EXC_FILTERS = [{ id: '', label: 'All' }, { id: 'open', label: 'Open' }, { id: 'under_review', label: 'Investigating' }, { id: 'resolved', label: 'Resolved' }];

const canRequest = computed(() => can('finance.adjustments.request'));
const canApprove = computed(() => can('finance.adjustments.approve'));
const canReview = computed(() => can('finance.exceptions.review'));
const canResolve = computed(() => can('finance.exceptions.resolve'));
const canExport = computed(() => can('finance.reports.view'));
const visibleTabs = computed(() => TABS.filter((t) => can(PERMISSION[t.id])));

const tab = ref('overview');
const error = ref('');
const busy = ref(false);
const periodError = ref('');
const filters = reactive({ ...defaultPeriod(), provider: '' });

/* overview */
const overview = ref(null);
const cards = computed(() => overviewCards(overview.value));
async function loadOverview() {
  try {
    overview.value = (await api(`/admin/financial/overview?${query(filters)}`)).data;
  } catch (e) {
    error.value = e.message;
  }
}

/* reports */
const report = ref('obligations');
const reportData = ref(null);
const csvLink = computed(() => csvUrl(report.value, filters));
async function loadReport(page = 1) {
  busy.value = true;
  try {
    reportData.value = (await api(`/admin/financial/reports/${report.value}?${query(filters, { page })}`)).data;
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
function setReport(id) { report.value = id; reportData.value = null; loadReport(1); }

function apply() {
  periodError.value = periodProblem(filters.from, filters.to);
  if (periodError.value) return;
  overview.value = null;
  reportData.value = null;
  if (tab.value === 'overview') loadOverview(); else loadReport(1);
}

/* adjustments */
const adjustments = ref(null);
const adjStatus = ref('');
const adjForm = reactive({ open: false });
const adj = reactive(blankAdjustment());
const adjErrors = reactive({ provider_id: '', type: '', amount_ugx: '', subject_id: '', reason: '' });
const formError = ref('');
const deciding = ref(null);
let token = newClientToken();

async function loadAdjustments(page = 1) {
  try {
    adjustments.value = (await api(`/admin/financial/adjustments?${query({ status: adjStatus.value, page })}`)).data;
  } catch (e) {
    error.value = e.message;
  }
}
function openAdjustment() {
  Object.assign(adj, blankAdjustment());
  Object.assign(adjErrors, { provider_id: '', type: '', amount_ugx: '', subject_id: '', reason: '' });
  formError.value = '';
  token = newClientToken();
  adjForm.open = true;
}
async function submitAdjustment() {
  if (busy.value) return;
  Object.assign(adjErrors, validateAdjustment(adj));
  if (hasErrors(adjErrors)) return;
  busy.value = true;
  formError.value = '';
  try {
    await api('/admin/financial/adjustments', { method: 'POST', body: adjustmentBody(adj, token) });
    toast('Adjustment sent for approval. Nothing about what is owed has changed.', 'success');
    adjForm.open = false;
    await loadAdjustments(1);
  } catch (e) {
    formError.value = e.message;
  } finally {
    busy.value = false;
  }
}
function beginDecision(a, mode) { deciding.value = { id: a.id, mode, reason: '', error: '' }; }
async function submitDecision(a) {
  const d = deciding.value;
  if (!d || busy.value) return;
  if (d.reason.trim().length < 10) { d.error = 'Write the reason (at least 10 characters).'; return; }
  busy.value = true;
  try {
    await api(`/admin/financial/adjustments/${a.id}/${d.mode}`, { method: 'POST', body: { reason: d.reason.trim() } });
    toast(d.mode === 'approve' ? 'Approved. The decision is recorded; nothing was posted.' : 'Rejected.', 'success');
    deciding.value = null;
    await loadAdjustments(1);
  } catch (e) {
    d.error = e.message;
  } finally {
    busy.value = false;
  }
}

/* exceptions */
const exceptions = ref(null);
const excStatus = ref('');
const openException = ref(null);
const actionError = ref('');
const note = reactive({ text: '', reference: '' });
const resolution = reactive({ note: '', ack: false });

async function loadExceptions(page = 1) {
  try {
    exceptions.value = (await api(`/admin/financial/exceptions?${query({ status: excStatus.value, page })}`)).data;
  } catch (e) {
    error.value = e.message;
  }
}
async function showException(id) {
  actionError.value = '';
  Object.assign(note, { text: '', reference: '' });
  Object.assign(resolution, { note: '', ack: false });
  try {
    openException.value = (await api(`/admin/financial/exceptions/${id}`)).data;
  } catch (e) {
    error.value = e.message;
  }
}
async function exceptionAction(path, body, ok) {
  if (busy.value) return;
  busy.value = true;
  actionError.value = '';
  try {
    openException.value = (await api(`/admin/financial/exceptions/${openException.value.id}/${path}`, { method: 'POST', body })).data;
    toast(ok, 'success');
    Object.assign(note, { text: '', reference: '' });
  } catch (e) {
    actionError.value = e.message;
  } finally {
    busy.value = false;
  }
}
const takeException = () => exceptionAction('take', {}, 'You have this exception.');
function addNote() {
  if (note.text.trim().length < 5) { actionError.value = 'Write the note (at least 5 characters).'; return; }
  exceptionAction('notes', { note: note.text.trim(), reference: note.reference.trim() || null }, 'Note saved.');
}
function resolveException() {
  if (!resolution.ack) { actionError.value = 'Confirm that closing this changes no balance.'; return; }
  if (resolution.note.trim().length < 20) { actionError.value = 'Document why no financial change is needed (at least 20 characters).'; return; }
  exceptionAction('resolve', { note: resolution.note.trim(), acknowledged: true }, 'Exception closed with no financial change.');
}

/* audit */
const audit = ref(null);
const auditFilters = reactive({ action: '', provider: '' });
async function loadAudit(page = 1) {
  busy.value = true;
  try {
    audit.value = (await api(`/admin/financial/audit?${query(auditFilters, { page })}`)).data;
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

function setTab(id) {
  tab.value = id;
  openException.value = null;
  if (id === 'overview' && !overview.value) loadOverview();
  if (id === 'reports' && !reportData.value) loadReport(1);
  if (id === 'adjustments') loadAdjustments(1);
  if (id === 'exceptions') loadExceptions(1);
  if (id === 'audit' && !audit.value) loadAudit(1);
}

onMounted(() => {
  const first = visibleTabs.value[0];
  setTab(first ? first.id : 'overview');
});
</script>
