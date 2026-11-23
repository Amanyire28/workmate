<template>
  <div class="flex flex-col gap-4">
    <AdminPageHeader title="Commission Estimates" description="What WorkMate could earn from payments that customers confirmed, using the rate rules below. A simulation only." demo />

    <p class="wm-notice wm-notice-warning" role="note">
      <i class="bi bi-shield-check" aria-hidden="true"></i>
      <span><strong class="font-semibold">{{ ESTIMATE_LABEL }}.</strong> The estimates, rate rules and calculations here are a simulation: nothing is charged and none of it is shown to providers or customers. Only the Obligations tab shows recorded amounts, and only when Obligations are switched on.</span>
    </p>

    <!-- How the two switches stand -->
    <div class="flex flex-wrap items-center gap-2 text-[13px]" aria-label="Switches">
      <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1" :class="summary.shadow_enabled ? 'border-[#86EFAC] bg-[#F0FDF4] text-[#166534]' : 'border-[#E2E8F0] bg-[#F8FAFC] text-[#475569]'">
        <i :class="['bi', summary.shadow_enabled ? 'bi-toggle-on' : 'bi-toggle-off']" aria-hidden="true"></i>Estimates {{ summary.shadow_enabled ? 'on' : 'off' }}
      </span>
      <span class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1" :class="summary.pricing_enabled ? 'border-[#86EFAC] bg-[#F0FDF4] text-[#166534]' : 'border-[#E2E8F0] bg-[#F8FAFC] text-[#475569]'">
        <i :class="['bi', summary.pricing_enabled ? 'bi-toggle-on' : 'bi-toggle-off']" aria-hidden="true"></i>Pricing options {{ summary.pricing_enabled ? 'on' : 'off' }}
      </span>
      <span class="text-[#64748B]">Safety ceiling for any rate: {{ bpToPercent(summary.ceiling_bp || 0) }}. Both switches are in Settings → Pricing.</span>
    </div>
    <p v-if="loaded && !summary.shadow_enabled" class="wm-notice wm-notice-info">
      <i class="bi bi-info-circle" aria-hidden="true"></i>
      <span>Estimates are switched off, so no new payment is being calculated. You can still prepare rate rules; nothing here affects anyone.</span>
    </p>

    <div class="wm-segmented grid grid-cols-2 sm:inline-grid sm:grid-cols-5" role="tablist" aria-label="Commission estimates">
      <button v-for="t in TABS" :id="`ce-tab-${t.id}`" :key="t.id" type="button" role="tab" class="wm-segment !min-h-11" :class="{ 'is-active': tab === t.id }"
        :aria-selected="tab === t.id" :aria-controls="`ce-panel-${t.id}`" :tabindex="tab === t.id ? 0 : -1" @click="tab = t.id">{{ t.label }}</button>
    </div>

    <StateBlock v-if="error" kind="error" title="We couldn’t load this" :message="error"><button type="button" class="wm-btn wm-btn-secondary !min-h-10" @click="reload">Try again</button></StateBlock>

    <!-- ================= Overview ================= -->
    <section v-show="tab === 'overview'" id="ce-panel-overview" role="tabpanel" aria-labelledby="ce-tab-overview" class="flex flex-col gap-4">
      <form class="flex flex-wrap items-end gap-3" @submit.prevent="loadSummary">
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">From<input v-model="range.from" type="date" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">To<input v-model="range.to" type="date" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">By
          <select v-model="range.granularity" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal"><option value="month">Month</option><option value="day">Day</option></select>
        </label>
        <button type="submit" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy">Show</button>
      </form>

      <StateBlock v-if="!loaded && !error" kind="loading" title="Loading estimates…" />
      <template v-else-if="loaded">
        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <MetricCard label="Confirmed payments counted" :value="formatNumber(summary.totals.eligible_payments)" :hint="`Customer-confirmed payments that could be estimated. ${ESTIMATE_LABEL}.`" />
          <MetricCard label="Payment volume counted" :value="formatUGX(summary.totals.eligible_volume_ugx)" hint="The confirmed payments' total. Money customers paid providers directly: not WorkMate revenue." />
          <MetricCard label="Estimated commission" :value="formatUGX(summary.totals.estimated_commission_ugx)" :context="ESTIMATE_LABEL" :primary="true" hint="What the rate rules would have produced. An estimate: not collected, not owed, not revenue." />
          <MetricCard label="Not counted" :value="formatNumber(summary.excluded.payments)" hint="Payments that could not be estimated, with the reasons below." />
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <section class="rounded-xl wm-surface p-4" aria-labelledby="ce-by-period">
            <h2 id="ce-by-period" class="text-[15px] font-semibold text-ink">Estimated commission by {{ range.granularity === 'day' ? 'day' : 'month' }}</h2>
            <p class="text-[12px] text-[#64748B]">{{ ESTIMATE_LABEL }}</p>
            <p v-if="!summary.by_period.length" class="mt-3 text-[13px] text-[#64748B]">Nothing in this period.</p>
            <ul v-else class="mt-3 flex flex-col gap-2">
              <li v-for="r in summary.by_period" :key="r.period" class="text-[13px]">
                <div class="flex justify-between gap-3"><span class="font-medium text-ink">{{ r.period }}</span><span class="text-ink">{{ formatUGX(r.commission_ugx) }} <span class="text-[#64748B]">· {{ r.payments }} {{ r.payments === 1 ? 'payment' : 'payments' }}</span></span></div>
                <div class="mt-1 h-2 rounded-full bg-[#E2E8F0]" aria-hidden="true"><div class="h-2 rounded-full bg-[#1769D2]" :style="{ width: barPercent(r.commission_ugx, maxOf(summary.by_period)) + '%' }"></div></div>
              </li>
            </ul>
          </section>

          <section class="rounded-xl wm-surface p-4" aria-labelledby="ce-excluded">
            <h2 id="ce-excluded" class="text-[15px] font-semibold text-ink">Payments not counted, and why</h2>
            <p v-if="!Object.keys(summary.excluded.by_reason).length" class="mt-3 text-[13px] text-[#64748B]">None in this period.</p>
            <ul v-else class="mt-3 divide-y divide-[#F1F5F9]">
              <li v-for="(r, key) in summary.excluded.by_reason" :key="key" class="flex items-start justify-between gap-3 py-2 text-[13px]">
                <span class="text-ink">{{ reasonLabel(key) }}</span>
                <span class="shrink-0 text-right text-[#475569]">{{ r.payments }} · {{ formatUGX(r.amount_ugx) }}</span>
              </li>
            </ul>
          </section>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <section v-for="g in GROUPS" :key="g.key" class="rounded-xl wm-surface p-4" :aria-labelledby="`ce-${g.key}`">
            <h2 :id="`ce-${g.key}`" class="text-[15px] font-semibold text-ink">{{ g.title }}</h2>
            <p class="text-[12px] text-[#64748B]">{{ ESTIMATE_LABEL }}</p>
            <p v-if="!summary[g.key].length" class="mt-3 text-[13px] text-[#64748B]">Nothing in this period.</p>
            <table v-else class="mt-3 w-full text-left text-[13px]">
              <caption class="sr-only">{{ g.title }}. {{ ESTIMATE_LABEL }}</caption>
              <thead><tr class="text-[12px] text-[#64748B]"><th scope="col" class="pb-1 font-medium">{{ g.col }}</th><th scope="col" class="pb-1 text-right font-medium">Payments</th><th scope="col" class="pb-1 text-right font-medium">Volume</th><th scope="col" class="pb-1 text-right font-medium">Estimate</th></tr></thead>
              <tbody class="divide-y divide-[#F1F5F9]">
                <tr v-for="r in summary[g.key]" :key="r.name"><td class="py-1.5 text-ink">{{ r.name }}</td><td class="py-1.5 text-right">{{ r.payments }}</td><td class="py-1.5 text-right">{{ formatUGX(r.amount_ugx) }}</td><td class="py-1.5 text-right font-medium text-ink">{{ formatUGX(r.commission_ugx) }}</td></tr>
              </tbody>
            </table>
          </section>

          <section class="rounded-xl wm-surface p-4" aria-labelledby="ce-by-policy">
            <h2 id="ce-by-policy" class="text-[15px] font-semibold text-ink">Rate rules applied</h2>
            <p class="text-[12px] text-[#64748B]">The rule and version each estimate used. {{ ESTIMATE_LABEL }}</p>
            <p v-if="!summary.by_policy.length" class="mt-3 text-[13px] text-[#64748B]">No rule has been applied in this period.</p>
            <table v-else class="mt-3 w-full text-left text-[13px]">
              <caption class="sr-only">Rate rules applied. {{ ESTIMATE_LABEL }}</caption>
              <thead><tr class="text-[12px] text-[#64748B]"><th scope="col" class="pb-1 font-medium">Rule</th><th scope="col" class="pb-1 text-right font-medium">Rate</th><th scope="col" class="pb-1 text-right font-medium">Payments</th><th scope="col" class="pb-1 text-right font-medium">Estimate</th></tr></thead>
              <tbody class="divide-y divide-[#F1F5F9]">
                <tr v-for="r in summary.by_policy" :key="`${r.id}-${r.version}`">
                  <td class="py-1.5 text-ink">{{ TIER_LABEL[r.tier] || r.tier }} <span class="text-[#64748B]">v{{ r.version }}</span></td>
                  <td class="py-1.5 text-right">{{ bpToPercent(r.rate_bp) }}</td><td class="py-1.5 text-right">{{ r.payments }}</td><td class="py-1.5 text-right font-medium text-ink">{{ formatUGX(r.commission_ugx) }}</td>
                </tr>
              </tbody>
            </table>
          </section>
        </div>
      </template>
    </section>

    <!-- ================= Rate rules ================= -->
    <section v-show="tab === 'rules'" id="ce-panel-rules" role="tabpanel" aria-labelledby="ce-tab-rules" class="flex flex-col gap-4">
      <div class="rounded-xl wm-surface p-4 text-[13px] text-[#475569]">
        <p class="font-semibold text-ink">Which rule applies?</p>
        <p class="mt-1">The most specific one in force when the customer confirmed the payment: <strong class="text-ink">Provider → Profession → Category → Place → District → Default.</strong> A step is skipped when the job doesn’t have what it needs (for example, when several of a provider’s professions fit, the profession rule isn’t used). Two rules that cover the same thing at the same time are never allowed, and a rule can’t start in the past, so it can never change an earlier estimate.</p>
      </div>

      <div v-if="canManage && !form.open" class="flex"><button type="button" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" @click="openForm(null)"><i class="bi bi-plus-lg" aria-hidden="true"></i>New rate rule</button></div>
      <p v-else-if="!canManage" class="wm-notice wm-notice-info"><i class="bi bi-lock" aria-hidden="true"></i><span>Your role can look at the rate rules but not change them.</span></p>

      <!-- The rule form: preview first, then activate -->
      <form v-if="form.open" class="flex flex-col gap-4 rounded-xl wm-surface p-4" novalidate @submit.prevent="runPreview">
        <h2 class="text-[15px] font-semibold text-ink">{{ form.replaces ? `Replace: ${form.replaces.scope} (version ${form.replaces.version})` : 'New rate rule' }}</h2>
        <p v-if="form.replaces" class="text-[13px] text-[#475569]">The new version starts at the time you choose, and the current one ends at that moment. Estimates already made keep the rate they used.</p>

        <fieldset v-if="!form.replaces" class="grid grid-cols-1 gap-2 sm:grid-cols-3" :disabled="!!preview">
          <legend class="mb-1 text-[13px] font-medium text-ink">What does the rate apply to?</legend>
          <label v-for="s in SCOPES" :key="s.id" class="flex cursor-pointer items-start gap-2 rounded-lg border p-2.5 text-[13px]" :class="rule.scope_type === s.id ? 'border-primary bg-[#EFF6FF]' : 'border-[#E2E8F0]'">
            <input v-model="rule.scope_type" type="radio" name="scope" :value="s.id" class="mt-0.5 h-4 w-4 accent-[#1769D2]" /><span><span class="block font-medium text-ink">{{ s.label }}</span><span class="block text-[12px] text-[#64748B]">{{ s.hint }}</span></span>
          </label>
        </fieldset>
        <div v-if="!form.replaces && rule.scope_type !== 'global'" class="flex flex-col gap-1">
          <label :for="`${uid}-target`" class="text-[13px] font-medium text-ink">{{ targetLabel }}</label>
          <input v-if="rule.scope_type === 'provider'" :id="`${uid}-target`" v-model="rule.scope_ref" type="number" min="1" inputmode="numeric" placeholder="Provider ID (from the provider’s record)" class="h-10 rounded-lg border border-[#CBD5E1] px-2" :disabled="!!preview" />
          <select v-else-if="rule.scope_type === 'district'" :id="`${uid}-target`" v-model="rule.scope_key" class="h-10 rounded-lg border border-[#CBD5E1] px-2" :disabled="!!preview"><option value="">Choose…</option><option v-for="d in options.districts" :key="d" :value="d.toLowerCase()">{{ d }}</option></select>
          <select v-else :id="`${uid}-target`" v-model="rule.scope_ref" class="h-10 rounded-lg border border-[#CBD5E1] px-2" :disabled="!!preview"><option value="">Choose…</option><option v-for="o in targetOptions" :key="o.id" :value="o.id">{{ o.name }}<template v-if="o.district"> — {{ o.district }}</template></option></select>
          <p v-if="errors.scope" class="text-[12.5px] text-[#B91C1C]" role="alert">{{ errors.scope }}</p>
        </div>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Rate (% of each confirmed payment)
            <input v-model="rule.percent" type="text" inputmode="decimal" placeholder="e.g. 5 or 2.5" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" :disabled="!!preview" :aria-invalid="errors.percent ? 'true' : undefined" />
            <span v-if="errors.percent" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ errors.percent }}</span>
            <span v-else class="text-[12px] font-normal text-[#64748B]">Highest allowed: {{ bpToPercent(options.ceiling_bp || 0) }}</span>
          </label>
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Starts
            <input v-model="rule.from" type="datetime-local" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" :disabled="!!preview" :aria-invalid="errors.from ? 'true' : undefined" />
            <span v-if="errors.from" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ errors.from }}</span>
          </label>
          <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Ends <span class="font-normal text-[#64748B]">(optional)</span>
            <input v-model="rule.to" type="datetime-local" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" :disabled="!!preview" :aria-invalid="errors.to ? 'true' : undefined" />
            <span v-if="errors.to" class="text-[12.5px] font-normal text-[#B91C1C]" role="alert">{{ errors.to }}</span>
          </label>
        </div>

        <p v-if="formError" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ formError }}</span></p>

        <!-- The preview: what the rate would mean, and the clashes -->
        <div v-if="preview" class="flex flex-col gap-3 rounded-xl border border-[#BFDBFE] bg-[#EFF6FF] p-4" aria-live="polite">
          <p class="text-[14px] font-semibold text-ink">Preview: {{ preview.scope }} at {{ preview.percent }}</p>
          <table class="w-full text-left text-[13px]">
            <caption class="sr-only">Examples. {{ ESTIMATE_LABEL }}</caption>
            <thead><tr class="text-[12px] text-[#475569]"><th scope="col" class="pb-1 font-medium">A confirmed payment of</th><th scope="col" class="pb-1 text-right font-medium">Estimated commission</th><th scope="col" class="pb-1 text-right font-medium">Provider’s remainder</th></tr></thead>
            <tbody><tr v-for="e in preview.examples" :key="e.amount_ugx"><td class="py-1 text-ink">{{ formatUGX(e.amount_ugx) }}</td><td class="py-1 text-right font-medium text-ink">{{ formatUGX(e.commission_ugx) }}</td><td class="py-1 text-right">{{ formatUGX(e.provider_remainder_ugx) }}</td></tr></tbody>
          </table>
          <p class="text-[12.5px] text-[#475569]">{{ preview.label }}. {{ preview.note }}</p>
          <p v-if="!preview.valid" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>Another rule for the same scope is in force at the same time (rule {{ preview.conflicts[0].id }}, {{ bpToPercent(preview.conflicts[0].rate_bp) }}). Change the dates, or replace that rule instead.</span></p>
          <template v-else>
            <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Type the rate again to confirm it ({{ preview.percent }})
              <input v-model="rule.confirm" type="text" inputmode="decimal" class="h-10 rounded-lg border border-[#CBD5E1] bg-white px-2 font-normal" autocomplete="off" />
            </label>
            <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Why (kept in the audit log)
              <textarea v-model="rule.reason" rows="2" maxlength="300" class="rounded-lg border border-[#CBD5E1] bg-white px-2 py-1.5 font-normal"></textarea>
            </label>
          </template>
        </div>

        <div class="flex flex-wrap gap-2">
          <button type="button" class="wm-btn wm-btn-secondary !min-h-11 !rounded-lg" :disabled="busy" @click="closeForm">Cancel</button>
          <button v-if="preview" type="button" class="wm-btn wm-btn-secondary !min-h-11 !rounded-lg" :disabled="busy" @click="preview = null">Change the rule</button>
          <button v-if="!preview" type="submit" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" :disabled="busy">Preview</button>
          <button v-else type="button" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" :disabled="busy || !canActivate" @click="activate">Activate this rate rule</button>
        </div>
      </form>

      <StateBlock v-if="!policiesLoaded && !error" kind="loading" title="Loading rate rules…" />
      <StateBlock v-else-if="policiesLoaded && !policies.length" title="No rate rules yet" message="Until an administrator creates one, no payment can be estimated: no rate is invented." />
      <ul v-else class="flex flex-col gap-3">
        <li v-for="p in policies" :key="p.id" class="rounded-xl wm-surface p-4">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div class="min-w-0"><p class="text-[14.5px] font-semibold text-ink">{{ p.scope }}</p><p class="text-[13px] text-[#475569]">Version {{ p.version }} · {{ formatDateTime(p.effective_from) }} → {{ p.effective_to ? formatDateTime(p.effective_to) : 'no end' }}</p></div>
            <div class="flex items-center gap-2"><span class="text-[18px] font-bold text-ink">{{ p.percent }}</span>
              <span class="rounded-full px-2.5 py-0.5 text-[12px] font-medium" :class="p.state === 'active' ? 'bg-[#DCFCE7] text-[#166534]' : p.state === 'scheduled' ? 'bg-[#DBEAFE] text-[#1E40AF]' : 'bg-[#F1F5F9] text-[#475569]'">{{ STATE_LABEL[p.state] || p.state }}</span></div>
          </div>
          <p class="mt-1 text-[12.5px] text-[#64748B]">“{{ p.reason }}” <template v-if="p.created_by">· by {{ p.created_by }}</template></p>
          <p v-if="p.retired_at" class="text-[12.5px] text-[#64748B]">Ended {{ formatDateTime(p.retired_at) }}<template v-if="p.retired_reason">: {{ p.retired_reason }}</template></p>
          <div v-if="canManage && !p.retired_at && !p.replaced_by_id" class="mt-2 flex flex-wrap gap-2">
            <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg !text-[13px]" @click="openForm(p)">Replace with a new version</button>
            <button type="button" class="wm-btn wm-btn-ghost !min-h-10 !rounded-lg !text-[13px] text-[#B91C1C]" @click="retiring = p">End this rule now</button>
          </div>
        </li>
      </ul>
    </section>

    <!-- ================= Which rate? ================= -->
    <section v-show="tab === 'check'" id="ce-panel-check" role="tabpanel" aria-labelledby="ce-tab-check" class="flex flex-col gap-4">
      <form class="grid grid-cols-1 gap-3 rounded-xl wm-surface p-4 sm:grid-cols-2" @submit.prevent="runCheck">
        <p class="text-[13px] text-[#475569] sm:col-span-2">Pick a job and see which rate rule would apply, and why. Nothing is saved or changed.</p>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Service category<select v-model="check.category" required class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal"><option value="">Choose…</option><option v-for="c in options.categories" :key="c.id" :value="c.slug">{{ c.name }}</option></select></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Place <span class="font-normal text-[#64748B]">(optional)</span><select v-model="check.area" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal"><option value="">Not given</option><option v-for="a in options.areas" :key="a.id" :value="a.slug">{{ a.name }}</option></select></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Profession <span class="font-normal text-[#64748B]">(optional)</span><select v-model="check.profession" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal"><option value="">Not given</option><option v-for="p in options.professions" :key="p.id" :value="p.slug">{{ p.name }}</option></select></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Provider ID <span class="font-normal text-[#64748B]">(optional)</span><input v-model="check.provider" type="number" min="1" inputmode="numeric" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Payment confirmed at <span class="font-normal text-[#64748B]">(default: now)</span><input v-model="check.at" type="datetime-local" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Payment amount (UGX) <span class="font-normal text-[#64748B]">(optional)</span><input v-model="check.amount" type="number" min="100" inputmode="numeric" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" /></label>
        <div class="sm:col-span-2"><button type="submit" class="wm-btn wm-btn-primary !min-h-11 !rounded-lg" :disabled="busy || !check.category">Which rate applies?</button></div>
      </form>
      <p v-if="checkError" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ checkError }}</span></p>
      <section v-if="checked" class="rounded-xl wm-surface p-4" aria-live="polite">
        <template v-if="checked.status === 'resolved'">
          <p class="text-[15px] font-semibold text-ink">{{ checked.policy.percent }} · {{ TIER_LABEL[checked.tier] }} rule <span class="font-normal text-[#64748B]">({{ checked.policy.scope }}, version {{ checked.policy.version }})</span></p>
          <p v-if="checked.example" class="mt-1 text-[13px] text-ink">A payment of {{ formatUGX(checked.example.amount_ugx) }} → estimated commission <strong>{{ formatUGX(checked.example.commission_ugx) }}</strong>, provider’s remainder {{ formatUGX(checked.example.provider_remainder_ugx) }}. <span class="text-[#64748B]">{{ ESTIMATE_LABEL }}</span></p>
        </template>
        <p v-else class="text-[15px] font-semibold text-[#92400E]">No rate: {{ reasonLabel(checked.reason) }}. No rate is invented.</p>
        <ol class="mt-3 flex flex-col gap-1 text-[13px]">
          <li v-for="t in checked.trace" :key="t.tier" class="flex gap-2"><span class="w-24 shrink-0 font-medium text-ink">{{ TIER_LABEL[t.tier] }}</span>
            <span class="text-[#475569]">{{ t.result === 'matched' ? `Used (${bpToPercent(t.rate_bp)}, version ${t.version})` : t.result === 'none' ? 'No rule in force' : t.result === 'ambiguous' ? 'Two rules overlap: none chosen' : `Skipped: ${String(t.why || '').replace(/_/g, ' ')}` }}</span></li>
        </ol>
      </section>
    </section>

    <!-- ================= Calculations ================= -->
    <!-- ================= Obligations (B6): recorded, read only ================= -->
    <section v-show="tab === 'obligations'" id="ce-panel-obligations" role="tabpanel" aria-labelledby="ce-tab-obligations" class="flex flex-col gap-3">
      <p class="wm-notice wm-notice-warning" role="note"><i class="bi bi-journal-text" aria-hidden="true"></i>
        <span><strong class="font-semibold">Recorded obligations, not estimates.</strong> These are what a provider owes WorkMate because the customer confirmed a payment (after the chosen start). They are only recorded when Obligations are switched on in Settings, nothing is collected by any system yet, and this page can’t change them.</span></p>
      <StateBlock v-if="!obligationsLoaded && !error" kind="loading" title="Loading…" />
      <template v-else>
        <p v-if="!obligations.enabled" class="wm-notice wm-notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><span>Obligations are switched off, so nothing new is being recorded.</span></p>
        <p v-else-if="!obligations.starts_at" class="wm-notice wm-notice-info"><i class="bi bi-info-circle" aria-hidden="true"></i><span>No start moment is set, so nothing is assessed yet. Payments before the start are never charged.</span></p>
        <p v-else class="text-[13px] text-[#475569]">Payments the customer confirmed from {{ formatDateTime(obligations.starts_at) }} are assessed.</p>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <MetricCard label="Obligations recorded" :value="formatNumber(obligations.totals.assessments)" hint="One per customer-confirmed payment." />
          <MetricCard label="Commission assessed" :value="formatUGX(obligations.totals.assessed_ugx)" hint="Before any reversals." />
          <MetricCard label="Outstanding" :value="formatUGX(obligations.totals.outstanding_ugx)" :primary="true" hint="Assessed minus reversed. Settlement is a later phase." />
        </div>
        <StateBlock v-if="!obligations.items.length" title="No obligations yet" message="They appear when a customer confirms a payment after the start moment, with Obligations switched on." />
        <div v-else class="overflow-x-auto rounded-xl wm-surface">
          <table class="w-full min-w-[760px] text-left text-[13px]">
            <caption class="sr-only">Recorded commission obligations</caption>
            <thead><tr class="text-[12px] text-[#64748B]"><th scope="col" class="p-3 font-medium">Recorded</th><th scope="col" class="p-3 font-medium">Provider</th><th scope="col" class="p-3 text-right font-medium">Payment</th><th scope="col" class="p-3 text-right font-medium">Rate</th><th scope="col" class="p-3 text-right font-medium">Commission</th><th scope="col" class="p-3 text-right font-medium">Outstanding</th><th scope="col" class="p-3 font-medium">Rule · job · case</th></tr></thead>
            <tbody class="divide-y divide-[#F1F5F9]">
              <tr v-for="o in obligations.items" :key="o.id">
                <td class="p-3 text-[#475569]">{{ formatDateTime(o.assessed_at) }}</td><td class="p-3 text-ink">{{ o.provider || '—' }}</td>
                <td class="p-3 text-right">{{ formatUGX(o.payment_ugx) }}</td><td class="p-3 text-right">{{ o.rate }}</td><td class="p-3 text-right">{{ formatUGX(o.commission_ugx) }}</td>
                <td class="p-3 text-right font-medium text-ink">{{ formatUGX(o.outstanding_ugx) }} <span v-if="o.status === 'reversed'" class="text-[12px] font-normal text-[#64748B]">(reversed)</span></td>
                <td class="p-3 text-[#475569]">v{{ o.policy_version }} · job #{{ o.request_id }}<template v-if="o.dispute_id"> · case #{{ o.dispute_id }}</template></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="obligations.total > obligations.per_page" class="flex items-center justify-between text-[13px]">
          <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="obligations.page <= 1 || busy" @click="loadObligations(obligations.page - 1)">Newer</button>
          <span class="text-[#64748B]">Page {{ obligations.page }} of {{ Math.ceil(obligations.total / obligations.per_page) }}</span>
          <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="obligations.page * obligations.per_page >= obligations.total || busy" @click="loadObligations(obligations.page + 1)">Older</button>
        </div>
      </template>
    </section>

    <section v-show="tab === 'calculations'" id="ce-panel-calculations" role="tabpanel" aria-labelledby="ce-tab-calculations" class="flex flex-col gap-3">
      <div class="flex flex-wrap items-end gap-3">
        <label class="flex flex-col gap-1 text-[13px] font-medium text-ink">Show<select v-model="filters.status" class="h-10 rounded-lg border border-[#CBD5E1] px-2 font-normal" @change="loadSnapshots(1)"><option value="">All</option><option value="calculated">Estimated</option><option value="excluded">Not counted</option></select></label>
        <p class="pb-2 text-[12.5px] text-[#64748B]">Newest first. {{ ESTIMATE_LABEL }}. No customer or contact details are shown.</p>
      </div>
      <StateBlock v-if="!snapshotsLoaded && !error" kind="loading" title="Loading…" />
      <StateBlock v-else-if="!snapshots.items.length" title="No calculations yet" message="A payment appears here once the customer confirms it and estimates are switched on." />
      <div v-else class="overflow-x-auto rounded-xl wm-surface">
        <table class="w-full min-w-[720px] text-left text-[13px]">
          <caption class="sr-only">Estimated commissions. {{ ESTIMATE_LABEL }}</caption>
          <thead><tr class="text-[12px] text-[#64748B]"><th scope="col" class="p-3 font-medium">Confirmed</th><th scope="col" class="p-3 font-medium">Provider</th><th scope="col" class="p-3 font-medium">Category</th><th scope="col" class="p-3 text-right font-medium">Payment</th><th scope="col" class="p-3 text-right font-medium">Rate</th><th scope="col" class="p-3 text-right font-medium">Estimate</th><th scope="col" class="p-3 font-medium">Rule / result</th></tr></thead>
          <tbody class="divide-y divide-[#F1F5F9]">
            <tr v-for="r in snapshots.items" :key="r.id">
              <td class="p-3 text-[#475569]">{{ formatDateTime(r.confirmed_at) }}</td><td class="p-3 text-ink">{{ r.provider || '—' }}</td><td class="p-3">{{ r.category || '—' }}</td>
              <td class="p-3 text-right">{{ formatUGX(r.amount_ugx) }}</td><td class="p-3 text-right">{{ r.percent || '—' }}</td><td class="p-3 text-right font-medium text-ink">{{ r.commission_ugx === null ? '—' : formatUGX(r.commission_ugx) }}</td>
              <td class="p-3 text-[#475569]"><template v-if="r.status === 'calculated'">{{ TIER_LABEL[r.tier] || r.tier }} v{{ r.policy_version }}</template><template v-else>{{ reasonLabel(r.exclusion_reason) }}</template></td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-if="snapshots.total > snapshots.per_page" class="flex items-center justify-between text-[13px]">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="snapshots.page <= 1 || busy" @click="loadSnapshots(snapshots.page - 1)">Newer</button>
        <span class="text-[#64748B]">Page {{ snapshots.page }} of {{ Math.ceil(snapshots.total / snapshots.per_page) }} · {{ snapshots.total }} in all</span>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="snapshots.page * snapshots.per_page >= snapshots.total || busy" @click="loadSnapshots(snapshots.page + 1)">Older</button>
      </div>
    </section>

    <ActionDialog :open="!!retiring" :title="`End: ${retiring?.scope} (version ${retiring?.version})?`" message="It stops applying from now on. Estimates already made keep the rate they used. This can’t be undone, but a new rule can be created."
      confirm-label="End this rule" tone="danger" :busy="busy" :error="dialogError" :fields="[{ key: 'reason', label: 'Why (kept in the audit log)', type: 'textarea', required: true, minLength: 5 }]" @close="retiring = null" @confirm="retire" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, useId } from 'vue';
import AdminPageHeader from '../components/AdminPageHeader.vue';
import ActionDialog from '../components/ActionDialog.vue';
import StateBlock from '../components/StateBlock.vue';
import MetricCard from '../components/MetricCard.vue';
import { api, fieldErrors } from '@/api/client.js';
import { can } from '../permissions.js';
import { toast } from '../stores/prototypeChanges.js';
import { formatNumber, formatUGX, formatDateTime } from '../utils/format.js';
import {
  ESTIMATE_LABEL, bpToPercent, percentToBp, SCOPES, TIER_LABEL, STATE_LABEL, reasonLabel, blankRule, validateRule, hasErrors, ruleBody, localInput, barPercent,
} from '../utils/commission.js';

const TABS = [{ id: 'overview', label: 'Overview' }, { id: 'rules', label: 'Rate rules' }, { id: 'check', label: 'Which rate?' }, { id: 'calculations', label: 'Calculations' }, { id: 'obligations', label: 'Obligations' }];
const GROUPS = [{ key: 'by_category', title: 'By service category', col: 'Category' }, { key: 'by_profession', title: 'By profession', col: 'Profession' }];

const uid = useId();
const canManage = computed(() => can('commission.manage'));
const tab = ref('overview');
const busy = ref(false);
const error = ref('');

/* ---- Overview ---- */
const day = (d) => localInput(d).slice(0, 10);
const range = reactive({ from: day(new Date(Date.now() - 89 * 86400000)), to: day(new Date()), granularity: 'month' });
const summary = ref({
  shadow_enabled: false, pricing_enabled: false, ceiling_bp: 0,
  totals: { eligible_payments: 0, eligible_volume_ugx: 0, estimated_commission_ugx: 0 }, excluded: { payments: 0, by_reason: {} },
  by_period: [], by_category: [], by_profession: [], by_policy: [],
});
const loaded = ref(false);
const maxOf = (rows) => rows.reduce((m, r) => Math.max(m, r.commission_ugx), 0);

async function loadSummary() {
  busy.value = true;
  try {
    summary.value = (await api(`/admin/commission/summary?from=${range.from}&to=${range.to}&granularity=${range.granularity}`)).data;
    loaded.value = true;
    error.value = '';
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

/* ---- Rate rules ---- */
const policies = ref([]);
const policiesLoaded = ref(false);
const options = ref({ ceiling_bp: 0, categories: [], professions: [], areas: [], districts: [] });
const form = reactive({ open: false, replaces: null });
const rule = reactive(blankRule());
const errors = reactive({ scope: '', percent: '', from: '', to: '' });
const formError = ref('');
const preview = ref(null);
const retiring = ref(null);
const dialogError = ref('');

const targetLabel = computed(() => ({ provider: 'Provider', profession: 'Profession', category: 'Service category', area: 'Place', district: 'District' }[rule.scope_type] || ''));
const targetOptions = computed(() => ({ profession: options.value.professions, category: options.value.categories, area: options.value.areas }[rule.scope_type] || []));
const canActivate = computed(() => !!preview.value && preview.value.valid && percentToBp(rule.confirm) === preview.value.rate_bp && rule.reason.trim().length >= 5);

async function loadPolicies() {
  try {
    const [list, opts] = await Promise.all([api('/admin/commission/policies'), api('/admin/commission/options')]);
    policies.value = list.data;
    options.value = opts.data;
    policiesLoaded.value = true;
  } catch (e) {
    error.value = e.message;
  }
}

function openForm(replaces) {
  Object.assign(rule, blankRule(), { from: localInput(new Date(Date.now() + 5 * 60000)) });
  Object.assign(errors, { scope: '', percent: '', from: '', to: '' });
  formError.value = '';
  preview.value = null;
  form.replaces = replaces;
  form.open = true;
  if (replaces) rule.scope_type = replaces.scope_type;
}

function closeForm() {
  form.open = false;
  preview.value = null;
}

async function runPreview() {
  formError.value = '';
  Object.assign(errors, validateRule(rule, options.value.ceiling_bp));
  if (hasErrors(errors)) return;
  busy.value = true;
  try {
    preview.value = (await api('/admin/commission/policies/simulate', { method: 'POST', body: { ...ruleBody(rule), ...(form.replaces ? { replaces: form.replaces.id } : {}) } })).data;
    rule.confirm = '';
    rule.reason = '';
  } catch (e) {
    formError.value = e.message;
  } finally {
    busy.value = false;
  }
}

async function activate() {
  if (!canActivate.value || busy.value) return;
  busy.value = true;
  formError.value = '';
  try {
    const body = { ...ruleBody(rule), token: preview.value.token, confirm_rate_bp: percentToBp(rule.confirm), reason: rule.reason.trim() };
    await api(form.replaces ? `/admin/commission/policies/${form.replaces.id}/replace` : '/admin/commission/policies', { method: 'POST', body });
    toast('Rate rule saved. It estimates only: nothing is charged.', 'success');
    closeForm();
    await Promise.all([loadPolicies(), loadSummary()]);
  } catch (e) {
    const fields = fieldErrors(e);
    formError.value = Object.keys(fields).length ? Object.values(fields).join(' ') : e.message;
    // A preview that is no longer good (it expired, or the rule changed): go back and preview again
    if (['preview_required', 'policy_overlap'].includes(e.code)) preview.value = null;
  } finally {
    busy.value = false;
  }
}

async function retire({ reason }) {
  busy.value = true;
  dialogError.value = '';
  try {
    await api(`/admin/commission/policies/${retiring.value.id}/retire`, { method: 'POST', body: { reason } });
    toast('Rate rule ended.', 'success');
    retiring.value = null;
    await loadPolicies();
  } catch (e) {
    dialogError.value = e.message;
  } finally {
    busy.value = false;
  }
}

/* ---- Which rate? ---- */
const check = reactive({ category: '', area: '', profession: '', provider: '', at: '', amount: '' });
const checked = ref(null);
const checkError = ref('');

async function runCheck() {
  busy.value = true;
  checkError.value = '';
  try {
    const q = new URLSearchParams({ category: check.category });
    for (const k of ['area', 'profession', 'provider', 'amount']) if (check[k]) q.set(k, check[k]);
    if (check.at) q.set('at', new Date(check.at).toISOString());
    checked.value = (await api(`/admin/commission/resolve?${q}`)).data;
  } catch (e) {
    checked.value = null;
    checkError.value = e.message;
  } finally {
    busy.value = false;
  }
}

/* ---- Calculations ---- */
const filters = reactive({ status: '' });
const snapshots = ref({ items: [], page: 1, per_page: 25, total: 0 });
const snapshotsLoaded = ref(false);

async function loadSnapshots(page = 1) {
  busy.value = true;
  try {
    snapshots.value = (await api(`/admin/commission/snapshots?page=${page}${filters.status ? `&status=${filters.status}` : ''}`)).data;
    snapshotsLoaded.value = true;
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

const obligations = ref({ enabled: false, starts_at: null, totals: { assessments: 0, assessed_ugx: 0, outstanding_ugx: 0 }, items: [], page: 1, per_page: 25, total: 0 });
const obligationsLoaded = ref(false);

async function loadObligations(page = 1) {
  busy.value = true;
  try {
    obligations.value = (await api(`/admin/commission/assessments?page=${page}`)).data;
    obligationsLoaded.value = true;
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

function reload() {
  error.value = '';
  loadObligations(1);
  loadSummary();
  loadPolicies();
  loadSnapshots(1);
}

onMounted(reload);
</script>
