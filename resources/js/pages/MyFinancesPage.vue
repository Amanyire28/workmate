<template>
  <div class="wm-container wm-container-sm wm-page" data-my-finances>
    <BackLink to="/account">Account</BackLink>
    <h1 class="wm-h1 mt-1">My Finances</h1>
    <p class="wm-meta mt-1">What customers paid you, and the commission you owe WorkMate. Only the business owner and people the owner allows can see this page.</p>

    <EmptyState v-if="state === 'loading'" icon="bi-hourglass-split" title="Loading…" />
    <EmptyState v-else-if="state === 'denied'" icon="bi-lock" title="You don’t have access" :message="deniedMessage">
      <router-link to="/account" class="wm-btn wm-btn-secondary">Back to account</router-link>
    </EmptyState>
    <EmptyState v-else-if="state === 'error'" tone="error" icon="bi-exclamation-circle" title="We couldn’t load this" :message="errorMessage"><button type="button" class="wm-btn wm-btn-secondary" @click="start">Try again</button></EmptyState>

    <template v-else>
      <p class="mt-2 text-[14px] font-medium text-ink">{{ access.business }} <span class="font-normal text-muted">· {{ roleWords(access.role) }}</span></p>

      <!-- Tabs -->
      <div class="mt-3 flex gap-1 overflow-x-auto pb-1" role="tablist" aria-label="My Finances">
        <button v-for="t in visibleTabs" :key="t.id" type="button" role="tab" class="wm-hit wm-segment !min-h-11 shrink-0 !px-3" :class="{ 'is-active': tab === t.id }" :aria-selected="tab === t.id" @click="select(t.id)">{{ t.label }}</button>
      </div>

      <!-- The period (not used by the lists that show today's position) -->
      <div v-if="usesPeriod" class="mt-3 flex flex-col gap-2">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Period">
          <button v-for="p in PERIODS" :key="p.id" type="button" class="wm-chip !min-h-10" :class="{ 'is-active': period === p.id }" :aria-pressed="period === p.id" @click="setPeriod(p.id)">{{ p.label }}</button>
        </div>
        <form v-if="period === 'custom'" class="flex flex-wrap items-end gap-2" @submit.prevent="applyDates">
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">From<input v-model="from" type="date" class="h-11 rounded-xl border border-line px-3 font-normal" /></label>
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">To<input v-model="to" type="date" class="h-11 rounded-xl border border-line px-3 font-normal" /></label>
          <button type="submit" class="wm-btn wm-btn-secondary !min-h-11">Show</button>
        </form>
        <p v-if="dateProblem" class="wm-field-error" role="alert">{{ dateProblem }}</p>
      </div>

      <!-- ============ Overview ============ -->
      <div v-if="tab === 'overview'" class="mt-4 flex flex-col gap-5" role="tabpanel">
        <EmptyState v-if="summaryLoaded && !summary.has_history" icon="bi-wallet2" title="Nothing here yet" message="When customers confirm payments to you, they show up here. If commission applies to them, that shows up too." />
        <template v-if="summary">
          <p v-if="summary.period" class="text-[13px] text-muted">{{ summary.period.label }}</p>

          <section class="wm-card flex flex-col gap-3" aria-labelledby="mf-customers">
            <div><h2 id="mf-customers" class="text-[16px] font-semibold text-ink">Customer payments</h2>
              <p class="text-[13.5px] text-muted">What customers paid you directly. WorkMate does not hold this money.</p></div>
            <dl class="grid grid-cols-1 gap-2 sm:grid-cols-2">
              <div class="rounded-xl bg-primary-tint px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Confirmed by customers</dt><dd class="text-[20px] font-bold text-ink">{{ ugx(summary.customer_payments.confirmed_ugx) }}</dd><dd class="text-[12.5px] text-muted">{{ summary.customer_payments.confirmed_count }} {{ summary.customer_payments.confirmed_count === 1 ? 'payment' : 'payments' }} in this period</dd></div>
              <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">You reported in this period</dt><dd class="text-[17px] font-bold text-ink">{{ ugx(summary.customer_payments.reported_ugx) }}</dd><dd class="text-[12.5px] text-muted">Includes ones still waiting. Only confirmed ones count as paid.</dd></div>
            </dl>
            <p class="text-[13px] font-medium text-ink">Today</p>
            <dl class="grid grid-cols-1 gap-2 sm:grid-cols-3">
              <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Waiting for the customer</dt><dd class="text-[16px] font-bold text-ink">{{ ugx(summary.customer_payments.awaiting.amount_ugx) }}</dd><dd class="text-[12.5px] text-muted">{{ summary.customer_payments.awaiting.count }} not counted as paid</dd></div>
              <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Questioned by the customer</dt><dd class="text-[16px] font-bold text-ink">{{ ugx(summary.customer_payments.questioned.amount_ugx) }}</dd><dd class="text-[12.5px] text-muted">{{ summary.customer_payments.questioned.count }} not counted as paid</dd></div>
              <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Still to be paid on agreed jobs</dt><dd class="text-[16px] font-bold text-ink">{{ ugx(summary.customer_payments.remaining_ugx) }}</dd><dd class="text-[12.5px] text-muted">{{ summary.customer_payments.remaining_jobs }} {{ summary.customer_payments.remaining_jobs === 1 ? 'job' : 'jobs' }} with an agreed price</dd></div>
            </dl>
          </section>

          <section class="wm-card flex flex-col gap-3" aria-labelledby="mf-commission">
            <div><h2 id="mf-commission" class="text-[16px] font-semibold text-ink">Commission you owe WorkMate</h2>
              <p class="text-[13.5px] text-muted">A share of payments customers confirmed. It is separate from what customers paid you.</p></div>
            <dl class="grid grid-cols-2 gap-2">
              <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Added in this period</dt><dd class="text-[16px] font-bold text-ink">{{ ugx(summary.commission.period.assessed_ugx) }}</dd></div>
              <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Taken off in this period</dt><dd class="text-[16px] font-bold text-ink">{{ ugx(summary.commission.period.reversed_ugx) }}</dd></div>
              <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Net added in this period</dt><dd class="text-[16px] font-bold text-ink">{{ ugx(summary.commission.period.net_ugx) }}</dd></div>
              <div class="rounded-xl bg-[#F8FAFC] px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Payments to WorkMate verified in this period</dt><dd class="text-[16px] font-bold text-ink">{{ ugx(summary.commission.period.settled_ugx) }}</dd></div>
            </dl>
            <p class="text-[13px] font-medium text-ink">Today</p>
            <dl class="grid grid-cols-2 gap-2">
              <div class="rounded-xl bg-primary-tint px-3.5 py-2.5"><dt class="text-[12.5px] text-muted">Still to pay</dt><dd class="text-[20px] font-bold text-ink">{{ ugx(summary.commission.as_of.outstanding_ugx) }}</dd></div>
              <div class="rounded-xl px-3.5 py-2.5" :class="summary.commission.as_of.overdue_ugx ? 'bg-[#FEE2E2]' : 'bg-[#F8FAFC]'"><dt class="text-[12.5px] text-muted">Overdue</dt><dd class="text-[20px] font-bold text-ink">{{ ugx(summary.commission.as_of.overdue_ugx) }}</dd></div>
            </dl>
            <p v-if="summary.commission.as_of.credit_ugx" class="text-[13px] text-muted">You have paid {{ ugx(summary.commission.as_of.credit_ugx) }} more than is owed. WorkMate will look at it; nothing is refunded automatically.</p>

            <section v-if="cycle" class="flex flex-col gap-2 rounded-xl border border-line px-3.5 py-3" aria-label="Current settlement">
              <div class="flex flex-wrap items-center justify-between gap-2"><p class="text-[14.5px] font-semibold text-ink">Current settlement</p><span class="wm-pill" :class="`wm-pill-${CYCLE_STATUS[cycle.status].tone}`">{{ cycleLabel(cycle.status) }}</span></div>
              <p class="text-[13.5px] text-ink">{{ ugx(cycle.outstanding_ugx) }} to pay · {{ jobsWords(cycle) }} with commission</p>
              <p class="text-[13px] text-muted">{{ dueWords(cycle, shortDate) }}</p>
              <p v-if="earlierTriggerWords(cycle, shortDate)" class="text-[13px] text-muted">{{ earlierTriggerWords(cycle, shortDate) }}</p>
              <div v-if="commissionInfo && commissionInfo.instructions" class="rounded-lg bg-[#F8FAFC] px-3 py-2 text-[13px]"><p class="font-medium text-ink">How to pay WorkMate</p><p class="whitespace-pre-line text-muted">{{ commissionInfo.instructions }}</p></div>
              <button v-if="summary.commission.can_report_payment && canReport(cycle)" type="button" class="wm-btn wm-btn-primary !min-h-12 w-full" @click="sheet = true"><i class="bi bi-cash-coin" aria-hidden="true"></i>Report commission payment</button>
              <p v-else-if="canReport(cycle)" class="text-[12.5px] text-muted">Reporting a payment isn’t available right now.</p>
            </section>
            <p v-else class="text-[13.5px] text-muted">Nothing to settle right now.</p>
          </section>
          <ul class="list-disc pl-5 text-[12.5px] text-muted"><li v-for="n in summary.notes" :key="n">{{ n }}</li></ul>
        </template>
      </div>

      <!-- ============ Commission statement ============ -->
      <div v-else-if="tab === 'statement'" class="mt-4 flex flex-col gap-3" role="tabpanel">
        <section v-if="statement" class="wm-card flex flex-col gap-1" aria-label="Totals">
          <p class="flex justify-between text-[14px]"><span class="text-muted">Opening balance</span><strong class="text-ink">{{ ugx(statement.totals.opening_ugx) }}</strong></p>
          <p class="flex justify-between text-[14px]"><span class="text-muted">Commission added</span><span class="text-ink">{{ ugx(statement.totals.assessed_ugx) }}</span></p>
          <p class="flex justify-between text-[14px]"><span class="text-muted">Taken off</span><span class="text-ink">− {{ ugx(statement.totals.reversed_ugx) }}</span></p>
          <p class="flex justify-between text-[14px]"><span class="text-muted">Payments to WorkMate verified</span><span class="text-ink">− {{ ugx(statement.totals.settled_ugx) }}</span></p>
          <p class="flex justify-between border-t border-line pt-1 text-[14.5px]"><strong class="text-ink">Closing balance</strong><strong class="text-ink">{{ ugx(statement.totals.closing_ugx) }}</strong></p>
        </section>
        <EmptyState v-if="statement && !statement.items.length" icon="bi-journal-text" title="No commission in this period" message="Commission lines appear here when they are added, taken off or paid." />
        <ul v-else-if="statement" class="flex flex-col gap-2">
          <li v-for="l in statement.items" :key="l.id" class="wm-card !p-3">
            <p class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-[14px] text-ink">{{ lineWords(l.type).label }}</strong><span class="text-[14px] font-semibold" :class="l.amount_ugx < 0 ? 'text-success' : 'text-ink'">{{ l.amount_ugx < 0 ? '−' : '+' }}{{ ugx(Math.abs(l.amount_ugx)) }}</span></p>
            <p class="text-[13px] text-muted">{{ shortDate(l.at) }}<template v-if="l.job"> · job #{{ l.job }}</template><template v-if="l.payment_ugx"> · payment {{ ugx(l.payment_ugx) }} at {{ l.rate }}</template><template v-if="l.channel"> · {{ channelLabel(l.channel) }}</template><template v-if="l.payment_reference"> · {{ l.payment_reference }}</template></p>
            <p class="text-[12.5px] text-muted">Balance after: {{ ugx(l.balance_ugx) }}</p>
          </li>
        </ul>
        <Pager :data="statement" @go="loadStatement" />
      </div>

      <!-- ============ Customer payments ============ -->
      <div v-else-if="tab === 'payments'" class="mt-4 flex flex-col gap-3" role="tabpanel">
        <div class="flex flex-wrap gap-2" role="group" aria-label="Payment state">
          <button v-for="s in PAYMENT_FILTERS" :key="s.id" type="button" class="wm-chip !min-h-10" :class="{ 'is-active': payStatus === s.id }" :aria-pressed="payStatus === s.id" @click="payStatus = s.id; loadPayments(1)">{{ s.label }}</button>
        </div>
        <EmptyState v-if="payments && !payments.items.length" icon="bi-cash-coin" title="No payments here" message="Payments you report, and what customers answer, appear here." />
        <ul v-else-if="payments" class="flex flex-col gap-2">
          <li v-for="p in payments.items" :key="p.id" class="wm-card !p-3">
            <p class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-[14px] text-ink">{{ ugx(p.amount_ugx) }} · {{ p.title || `Job #${p.job}` }}</strong><span class="wm-pill !py-0" :class="`wm-pill-${paymentState(p.status).tone}`">{{ paymentState(p.status).label }}</span></p>
            <p class="text-[13px] text-muted">{{ p.customer }} · {{ methodLabel(p.method) }}<template v-if="p.paid_on"> · paid {{ shortDate(p.paid_on) }}</template><template v-if="p.reference"> · {{ p.reference }}</template></p>
            <p class="text-[12.5px] text-muted">{{ paymentState(p.status).why }}<template v-if="p.note"> “{{ p.note }}”</template></p>
            <button v-if="p.dispute" type="button" class="wm-btn wm-btn-tint !min-h-10 mt-1" @click="openDispute = p.dispute.id">Open the payment question</button>
          </li>
        </ul>
        <Pager :data="payments" @go="loadPayments" />
      </div>

      <!-- ============ Settlements ============ -->
      <div v-else-if="tab === 'settlements'" class="mt-4 flex flex-col gap-3" role="tabpanel">
        <EmptyState v-if="cycles && !cycles.items.length" icon="bi-calendar-check" title="No settlements yet" message="A settlement starts when commission is added. It is due after 7 days, or after 3 jobs with commission, whichever comes first." />
        <ul v-else-if="cycles" class="flex flex-col gap-2">
          <li v-for="c in cycles.items" :key="c.id"><button type="button" class="wm-card wm-card-link !p-3 w-full text-left" @click="showCycle(c.id)">
            <span class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-[14px] text-ink">{{ c.jobs }} {{ c.jobs === 1 ? 'job' : 'jobs' }} · {{ ugx(c.payable_ugx) }}</strong><span class="wm-pill !py-0" :class="`wm-pill-${CYCLE_STATUS[c.status].tone}`">{{ cycleLabel(c.status) }}</span></span>
            <span class="block text-[13px] text-muted">Verified {{ ugx(c.credits_ugx) }} · still to pay {{ ugx(Math.max(c.outstanding_ugx, 0)) }}</span>
            <span class="block text-[12.5px] text-muted">{{ dueWords(c, shortDate) || 'Settled.' }}</span></button></li>
        </ul>
        <Pager :data="cycles" @go="loadCycles" />
      </div>

      <!-- ============ Paid to WorkMate ============ -->
      <div v-else-if="tab === 'to-workmate'" class="mt-4 flex flex-col gap-3" role="tabpanel">
        <EmptyState v-if="toWorkmate && !toWorkmate.items.length" icon="bi-bank" title="No payments to WorkMate" message="Commission payments you report appear here, with what WorkMate decided." />
        <ul v-else-if="toWorkmate" class="flex flex-col gap-2">
          <li v-for="p in toWorkmate.items" :key="p.id" class="wm-card !p-3">
            <p class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-[14px] text-ink">{{ ugx(p.amount_ugx) }} · {{ channelLabel(p.channel) }}</strong><span class="wm-pill !py-0" :class="`wm-pill-${commissionPaymentWords(p.status).tone}`">{{ commissionPaymentWords(p.status).label }}</span></p>
            <p class="text-[13px] text-muted">{{ shortDate(p.reported_at) }}<template v-if="p.reference"> · {{ p.reference }}</template> · settlement #{{ p.cycle_id }}</p>
            <p class="text-[12.5px] text-muted">{{ commissionPaymentWords(p.status).why }}</p>
            <p v-if="p.status === 'rejected' && p.decision_note" class="text-[13px] text-ink">Why: “{{ p.decision_note }}”</p>
            <p v-if="p.status === 'verified' && p.excess_ugx" class="text-[12.5px] text-muted">{{ ugx(p.excess_ugx) }} of it was more than was owed and is not applied until WorkMate reviews it.</p>
          </li>
        </ul>
        <Pager :data="toWorkmate" @go="loadToWorkmate" />
      </div>

      <!-- ============ Disputes ============ -->
      <div v-else-if="tab === 'disputes'" class="mt-4 flex flex-col gap-3" role="tabpanel">
        <EmptyState v-if="disputes && !disputes.items.length" icon="bi-chat-left-text" title="No payment questions" message="If a customer says a payment you reported isn’t right, it appears here." />
        <ul v-else-if="disputes" class="flex flex-col gap-2">
          <li v-for="d in disputes.items" :key="d.id"><button type="button" class="wm-card wm-card-link !p-3 w-full text-left" @click="openDispute = d.id">
            <span class="flex flex-wrap items-baseline justify-between gap-2"><strong class="text-[14px] text-ink">{{ ugx(d.amount_ugx) }} · {{ d.title || `Job #${d.job}` }}</strong><span class="wm-pill !py-0 wm-pill-neutral">{{ disputeStage(d.status) }}</span></span>
            <span class="block text-[13px] text-muted">Opened {{ shortDate(d.opened_at) }}<template v-if="d.resolved_at"> · closed {{ shortDate(d.resolved_at) }}</template></span>
            <span v-if="d.financial_correction_required" class="block text-[12.5px] font-medium text-[#92400E]">Financial correction pending</span></button></li>
        </ul>
        <Pager :data="disputes" @go="loadDisputes" />
      </div>

      <!-- ============ Downloads ============ -->
      <div v-else-if="tab === 'downloads'" class="mt-4 flex flex-col gap-4" role="tabpanel">
        <p class="text-[13.5px] text-muted">Statements for the period you chose above. They show the same figures as this page.</p>
        <section class="wm-card flex flex-col gap-2"><h2 class="text-[15px] font-semibold text-ink">Commission statement</h2>
          <p class="text-[13px] text-muted">Opening and closing balance, commission added, taken off and paid to WorkMate.</p>
          <div class="flex flex-wrap gap-2"><a class="wm-btn wm-btn-secondary !min-h-11" :href="statementUrl('commission', 'pdf', period, from, to)" download><i class="bi bi-filetype-pdf" aria-hidden="true"></i>PDF</a>
            <a class="wm-btn wm-btn-secondary !min-h-11" :href="statementUrl('commission', 'csv', period, from, to)" download><i class="bi bi-filetype-csv" aria-hidden="true"></i>CSV</a></div></section>
        <section class="wm-card flex flex-col gap-2"><h2 class="text-[15px] font-semibold text-ink">Customer payments statement</h2>
          <p class="text-[13px] text-muted">What customers confirmed they paid you. This is not WorkMate’s money.</p>
          <div class="flex flex-wrap gap-2"><a class="wm-btn wm-btn-secondary !min-h-11" :href="statementUrl('payments', 'pdf', period, from, to)" download><i class="bi bi-filetype-pdf" aria-hidden="true"></i>PDF</a>
            <a class="wm-btn wm-btn-secondary !min-h-11" :href="statementUrl('payments', 'csv', period, from, to)" download><i class="bi bi-filetype-csv" aria-hidden="true"></i>CSV</a></div></section>

        <section v-if="access.can_manage_access" class="wm-card flex flex-col gap-2" aria-labelledby="mf-access">
          <h2 id="mf-access" class="text-[15px] font-semibold text-ink">Who can see these finances</h2>
          <p class="text-[13px] text-muted">You can always see them. Managers and team members can’t, unless you allow it here.</p>
          <p v-if="!access.members.length" class="text-[13px] text-muted">You have no other team members.</p>
          <ul v-else class="flex flex-col gap-1">
            <li v-for="m in access.members" :key="m.user_id" class="flex items-center justify-between gap-3 rounded-xl bg-[#F8FAFC] px-3 py-2 text-[14px]">
              <span class="text-ink">{{ m.name }} <span class="text-muted">· {{ roleWords(m.role) }}</span></span>
              <label class="inline-flex min-h-11 items-center gap-2 text-[13px] text-ink"><input type="checkbox" class="h-5 w-5 accent-[#1769D2]" :checked="m.can_view_finances" :disabled="accessBusy" :aria-label="`Allow ${m.name} to see the finances`" @change="setAccess(m, $event.target.checked)" />Can see</label>
            </li>
          </ul>
          <p v-if="accessError" class="wm-field-error" role="alert">{{ accessError }}</p>
        </section>
      </div>

      <p v-if="listError" class="wm-notice wm-notice-error mt-3" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ listError }}</span></p>
    </template>

    <!-- A settlement in detail -->
    <BottomSheet :open="!!openCycle" :title="openCycle ? `Settlement #${openCycle.id}` : ''" @close="openCycle = null">
      <div v-if="openCycle" class="flex flex-col gap-3">
        <p class="flex flex-wrap items-center justify-between gap-2"><span class="wm-pill" :class="`wm-pill-${CYCLE_STATUS[openCycle.status].tone}`">{{ cycleLabel(openCycle.status) }}</span><span class="text-[13.5px] text-ink">{{ ugx(Math.max(openCycle.outstanding_ugx, 0)) }} still to pay</span></p>
        <p class="text-[13px] text-muted">{{ dueWords(openCycle, shortDate) || 'Settled.' }} {{ earlierTriggerWords(openCycle, shortDate) }}</p>
        <h3 class="text-[14px] font-semibold text-ink">The jobs behind it</h3>
        <ul class="flex flex-col gap-1.5"><li v-for="o in openCycle.obligations" :key="o.id" class="rounded-lg bg-[#F8FAFC] px-3 py-2 text-[13px]">
          <strong class="text-ink">{{ o.title || `Job #${o.job}` }}</strong><span class="wm-pill !py-0 ml-2" :class="o.status === 'recorded' ? 'wm-pill-warning' : 'wm-pill-neutral'">{{ o.status === 'recorded' ? 'Recorded' : 'Taken off' }}</span>
          <span class="block text-muted">Payment {{ ugx(o.payment_ugx) }} · rate {{ o.rate }} · commission {{ ugx(o.commission_ugx) }}</span></li></ul>
        <h3 class="text-[14px] font-semibold text-ink">Payments to WorkMate for it</h3>
        <p v-if="!openCycle.payments.length" class="text-[13px] text-muted">None yet.</p>
        <ul class="flex flex-col gap-1.5"><li v-for="p in openCycle.payments" :key="p.id" class="rounded-lg bg-[#F8FAFC] px-3 py-2 text-[13px]"><strong class="text-ink">{{ ugx(p.amount_ugx) }}</strong> · {{ commissionPaymentWords(p.status).label }}<template v-if="p.reference"> · {{ p.reference }}</template></li></ul>
      </div>
    </BottomSheet>

    <DisputePanel :open="!!openDispute" :dispute-id="openDispute" @close="openDispute = null" @changed="reloadAll" />
    <ReportCommissionPaymentSheet v-if="commissionInfo && commissionInfo.settlement" :open="sheet" :cycle="cycle" :channels="commissionInfo.settlement.channels" :instructions="commissionInfo.settlement.instructions" @close="sheet = false" @done="sheetDone" />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, defineComponent, h } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import BottomSheet from '@/components/BottomSheet.vue';
import EmptyState from '@/components/EmptyState.vue';
import DisputePanel from '@/components/pricing/DisputePanel.vue';
import ReportCommissionPaymentSheet from '@/components/pricing/ReportCommissionPaymentSheet.vue';
import { api } from '@/api/client.js';
import { shortDate } from '@/utils/pricing.js';
import { CYCLE_STATUS, cycleLabel, channelLabel, dueWords, jobsWords, canReport, earlierTriggerWords, PAYMENT_STATUS } from '@/utils/settlement.js';
import {
  PERIODS, periodProblem, query, statementUrl, TABS, lineWords, paymentState, disputeStage, methodLabel, roleWords, ugx,
} from '@/utils/myFinances.js';

const PAYMENT_FILTERS = [{ id: '', label: 'All' }, { id: 'confirmed', label: 'Confirmed' }, { id: 'pending_confirmation', label: 'Waiting' }, { id: 'disputed', label: 'Questioned' }, { id: 'cancelled', label: 'Taken back' }];
const commissionPaymentWords = (s) => PAYMENT_STATUS[s] || PAYMENT_STATUS.pending;

// A small "older / newer" control for every list
const Pager = defineComponent({
  props: { data: { type: Object, default: null } },
  emits: ['go'],
  setup(props, { emit }) {
    return () => {
      const d = props.data;
      if (!d || d.total <= d.per_page) return null;
      const pages = Math.ceil(d.total / d.per_page);

      return h('div', { class: 'flex items-center justify-between gap-2 pt-1' }, [
        h('button', { type: 'button', class: 'wm-btn wm-btn-secondary !min-h-11', disabled: d.page <= 1, onClick: () => emit('go', d.page - 1) }, 'Previous'),
        h('span', { class: 'text-[13px] text-muted' }, `Page ${d.page} of ${pages}`),
        h('button', { type: 'button', class: 'wm-btn wm-btn-secondary !min-h-11', disabled: d.page >= pages, onClick: () => emit('go', d.page + 1) }, 'Next'),
      ]);
    };
  },
});

const route = useRoute();
const router = useRouter();
const state = ref('loading');
const errorMessage = ref('');
const deniedMessage = ref('');
const access = ref(null);
const tab = ref(TABS.some((t) => t.id === route.query.tab) ? route.query.tab : 'overview');
const period = ref('this_month');
const from = ref('');
const to = ref('');
const dateProblem = ref('');
const listError = ref('');
const summary = ref(null);
const summaryLoaded = ref(false);
const statement = ref(null);
const payments = ref(null);
const payStatus = ref('');
const cycles = ref(null);
const toWorkmate = ref(null);
const disputes = ref(null);
const commissionInfo = ref(null);
const openCycle = ref(null);
const openDispute = ref(null);
const sheet = ref(false);
const accessBusy = ref(false);
const accessError = ref('');

const visibleTabs = TABS;
const usesPeriod = computed(() => ['overview', 'statement', 'payments', 'downloads'].includes(tab.value));
const cycle = computed(() => (summary.value ? summary.value.commission.as_of.current_cycle : null));

async function guarded(run) {
  listError.value = '';
  try {
    return await run();
  } catch (e) {
    listError.value = e.message;
    return null;
  }
}

const loadSummary = () => guarded(async () => { summary.value = (await api(`/me/finance/summary?${query(period.value, from.value, to.value)}`)).data; summaryLoaded.value = true; });
const loadStatement = (page = 1) => guarded(async () => { statement.value = (await api(`/me/finance/statement?${query(period.value, from.value, to.value, { page })}`)).data; });
const loadPayments = (page = 1) => guarded(async () => { payments.value = (await api(`/me/finance/payments?${query(period.value, from.value, to.value, { page, status: payStatus.value })}`)).data; });
const loadCycles = (page = 1) => guarded(async () => { cycles.value = (await api(`/me/finance/cycles?page=${page}`)).data; });
const loadToWorkmate = (page = 1) => guarded(async () => { toWorkmate.value = (await api(`/me/finance/commission-payments?page=${page}`)).data; });
const loadDisputes = (page = 1) => guarded(async () => { disputes.value = (await api(`/me/finance/disputes?page=${page}`)).data; });
const showCycle = (id) => guarded(async () => { openCycle.value = (await api(`/me/finance/cycles/${id}`)).data; });

function loadTab() {
  const loaders = { overview: loadSummary, statement: () => loadStatement(1), payments: () => loadPayments(1), settlements: () => loadCycles(1), 'to-workmate': () => loadToWorkmate(1), disputes: () => loadDisputes(1) };
  if (loaders[tab.value]) loaders[tab.value]();
}

function select(id) {
  tab.value = id;
  router.replace({ query: { ...route.query, tab: id } });
  loadTab();
}

function setPeriod(id) {
  period.value = id;
  dateProblem.value = '';
  if (id !== 'custom') loadTab();
}

function applyDates() {
  dateProblem.value = periodProblem(period.value, from.value, to.value);
  if (!dateProblem.value) loadTab();
}

const reloadAll = () => { loadTab(); };
const sheetDone = async () => { sheet.value = false; await loadSummary(); await loadCommissionInfo(); };

async function loadCommissionInfo() {
  try {
    commissionInfo.value = (await api('/me/commission')).data;
  } catch {
    commissionInfo.value = null;
  }
}

async function setAccess(member, allowed) {
  accessBusy.value = true;
  accessError.value = '';
  try {
    access.value = (await api('/me/finance/access', { method: 'POST', body: { user_id: member.user_id, allowed } })).data;
  } catch (e) {
    accessError.value = e.message;
  } finally {
    accessBusy.value = false;
  }
}

async function start() {
  state.value = 'loading';
  try {
    access.value = (await api('/me/finance/access')).data;
    if (!access.value.allowed) {
      deniedMessage.value = 'Only the business owner, or a team member the owner allows, can see a business’s finances. Ask the owner to give you access.';
      state.value = 'denied';
      return;
    }
    state.value = 'ready';
    loadTab();
    loadCommissionInfo();
    if (route.query.dispute) openDispute.value = Number(route.query.dispute) || null;
    if (route.query.cycle) showCycle(Number(route.query.cycle));
  } catch (e) {
    errorMessage.value = e.message;
    state.value = 'error';
  }
}

onMounted(start);
</script>
