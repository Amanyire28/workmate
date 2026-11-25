<template>
  <!-- One request a customer sent this provider. The essentials are always in view (who, what, where, when, which price, what to do
       next); one main action sits at the bottom with chat and "More"; the rest (photos, price history, payments) is under Details. -->
  <li :id="`request-${req.id}`" class="wm-card flex min-w-0 flex-col gap-3">
    <div class="flex items-start justify-between gap-3">
      <div class="flex min-w-0 items-center gap-3">
        <UserAvatar :name="req.customer.name" :src="req.customer.avatar_url || ''" :size="44" />
        <div class="min-w-0">
          <h2 class="truncate text-base font-semibold leading-snug text-ink">{{ req.customer.name }}</h2>
          <p class="wm-meta truncate">{{ req.title || categoryLabel(req.category) }}</p>
        </div>
      </div>
      <span class="wm-pill shrink-0" :class="`wm-pill-${tone}`">{{ providerStatus(req) }}</span>
    </div>

    <p v-if="req.description" class="line-clamp-3 whitespace-pre-line text-[14.5px] text-ink">{{ req.description }}</p>

    <!-- When, and where: the area and landmark; the exact point and directions only once accepted (as the server decides) -->
    <p class="flex items-start gap-2 text-[14px] text-ink">
      <i class="bi bi-calendar3 mt-0.5 text-muted" aria-hidden="true"></i>
      <span>
        <template v-if="req.schedule?.start">{{ formatDay(req.schedule.start) }}, {{ formatTime(req.schedule.start) }}<template v-if="req.schedule.end"> – {{ formatTime(req.schedule.end) }}</template></template>
        <template v-else>{{ req.preferred_time || 'No time given' }}</template>
        <span v-if="req.schedule?.proposed_start" class="block text-[13px] text-[#B45309]">You suggested {{ formatShort(req.schedule.proposed_start) }}.</span>
      </span>
    </p>
    <div class="flex items-start gap-2 text-[14px] text-ink">
      <i class="bi bi-geo-alt mt-0.5 text-muted" aria-hidden="true"></i>
      <div class="min-w-0 flex-1">
        <p>{{ req.location?.area_name || req.area_name }}<template v-if="req.location?.landmark"> · {{ req.location.landmark }}</template></p>
        <p v-if="req.location?.directions" class="text-[13px] text-muted">{{ req.location.directions }}</p>
        <a v-if="req.location?.latitude != null" :href="directionsUrl(req.location.latitude, req.location.longitude)" target="_blank" rel="noopener"
          class="wm-btn wm-btn-tint !min-h-11 !px-3 mt-1.5 inline-flex">
          <i class="bi bi-sign-turn-right" aria-hidden="true"></i>View location / Get directions
        </a>
        <p v-else-if="req.location?.has_pin && req.status === 'open'" class="text-[12.5px] text-muted">The exact spot and directions show once you accept.</p>
      </div>
    </div>

    <!-- Which price this is: an agreed price, or only what the customer has in mind -->
    <p v-if="price" class="flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[14px]">
      <i class="bi bi-cash text-muted" aria-hidden="true"></i>
      <span :class="price.kind === 'agreed' ? 'font-semibold text-success' : 'text-ink'">{{ price.label }}</span>
      <strong class="font-semibold text-ink">{{ price.kind === 'agreed' ? ugx(price.amount) : price.text }}</strong>
    </p>

    <!-- Inspection first: said by the business owner or a manager; nothing is priced by it -->
    <p v-if="req.inspection_required" class="flex items-start gap-2 rounded-xl bg-[#FFFBEB] px-3 py-2 text-[13.5px] text-[#92400E]">
      <i class="bi bi-search mt-0.5" aria-hidden="true"></i>
      <span><strong>You need to see this job before pricing it.</strong><template v-if="req.inspection_note"> {{ req.inspection_note }}</template> The customer has been told.</span>
    </p>
    <p v-if="req.status === 'cancelled' && req.provider_note" class="text-sm text-muted"><i class="bi bi-reply" aria-hidden="true"></i> You said: {{ req.provider_note }}</p>
    <p v-else-if="req.status === 'cancelled' && req.schedule?.cancelled_by === 'customer'" class="text-sm text-muted">The customer cancelled.</p>

    <!-- What to do next, and (separately, in quieter words) what is only waiting on the customer -->
    <div v-if="said.next.text || said.waiting.length" class="flex flex-col gap-1">
      <p v-if="said.next.text" class="flex items-start gap-2 rounded-xl px-3 py-2 text-[13.5px]" :class="NEXT_TONE[said.next.tone]">
        <i :class="['bi mt-0.5', said.next.tone === 'warning' ? 'bi-exclamation-circle' : said.next.tone === 'success' ? 'bi-check-circle' : said.next.tone === 'info' ? 'bi-arrow-right-circle' : 'bi-dash-circle']" aria-hidden="true"></i>
        <span><strong class="font-semibold">Next:</strong> {{ said.next.text }}</span>
      </p>
      <p v-for="w in said.waiting" :key="w" class="flex items-start gap-2 px-1 text-[13px] text-muted">
        <i class="bi bi-hourglass-split mt-0.5" aria-hidden="true"></i><span>{{ w }}</span>
      </p>
    </div>

    <!-- A reply that needs words or a time -->
    <form v-if="mode === 'inspect'" class="flex flex-col gap-2" @submit.prevent="send('inspection', { required: true, note: note.trim() || null })">
      <FormField v-model="note" type="textarea" label="What do you need to see?" optional :id="`inspect-${req.id}`" maxlength="300" :min-height="72"
        placeholder="e.g. I need to look at the pipe before I can give a price" hint="The customer sees this. It doesn’t set a price." />
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" @click="mode = ''">Back</button>
        <button type="submit" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy">Save</button>
      </div>
    </form>

    <form v-else-if="mode === 'decline' || mode === 'cancel'" class="flex flex-col gap-2" @submit.prevent="send(mode, { reason: reason.trim() })">
      <FormField v-model="reason" type="textarea" :label="mode === 'decline' ? 'Why can’t you take it?' : 'Why do you need to cancel?'" :id="`${mode}-${req.id}`" required
        :placeholder="mode === 'decline' ? 'e.g. I’m fully booked this week' : 'e.g. I’m unwell and can’t come that day'" :min-height="72" hint="The customer sees this." />
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" @click="mode = ''">Back</button>
        <button type="submit" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy || reason.trim().length < 3">Send</button>
      </div>
    </form>

    <form v-else-if="mode === 'propose'" class="flex flex-col gap-2" @submit.prevent="send('propose', { start: `${when.date} ${when.time}` })">
      <p class="text-[14px] font-medium text-ink">Suggest another time</p>
      <div class="grid grid-cols-2 gap-2">
        <input v-model="when.date" type="date" class="wm-input" :min="today" required aria-label="Date" />
        <select v-model="when.time" class="wm-input" required aria-label="Time">
          <option value="" disabled>Time</option>
          <option v-for="t in TIME_SLOTS" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </div>
      <p class="text-[12.5px] text-muted">The customer accepts it before it’s booked.</p>
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" @click="mode = ''">Back</button>
        <button type="submit" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy || !when.date || !when.time">Send</button>
      </div>
    </form>

    <!-- Accepting from More: the same "how long will it take?" choice as beside the main Accept button, then accept -->
    <form v-else-if="mode === 'accept'" class="flex flex-col gap-2" @submit.prevent="send('accept', minutes ? { duration_minutes: Number(minutes) } : {})">
      <label class="flex items-center gap-2 text-[13px] text-muted">
        How long will it take?
        <select v-model="minutes" class="wm-input !min-h-11 !py-1 flex-1">
          <option v-for="d in DURATIONS" :key="d.label" :value="d.value">{{ d.value ? d.label : (req.schedule.duration_minutes ? durationLabel(req.schedule.duration_minutes) + ' (customer)' : 'Usual length') }}</option>
        </select>
      </label>
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" @click="mode = ''">Back</button>
        <button type="submit" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy"><i class="bi bi-check2" aria-hidden="true"></i>Accept</button>
      </div>
    </form>

    <div v-else class="flex flex-col gap-2">
      <!-- How long it will take: part of accepting -->
      <label v-if="req.status === 'open' && !req.is_legacy && primary && primary.key === 'accept'" class="flex items-center gap-2 text-[13px] text-muted">
        How long will it take?
        <select v-model="minutes" class="wm-input !min-h-11 !py-1 flex-1">
          <option v-for="d in DURATIONS" :key="d.label" :value="d.value">{{ d.value ? d.label : (req.schedule.duration_minutes ? durationLabel(req.schedule.duration_minutes) + ' (customer)' : 'Usual length') }}</option>
        </select>
      </label>

      <!-- One main action, chat, and everything else -->
      <div class="flex flex-wrap gap-2">
        <!-- On the narrowest phones the main action gets a line of its own so it is never squeezed -->
        <button v-if="primary" type="button" class="wm-btn wm-btn-primary !min-h-11 min-w-0 flex-1 max-[359px]:basis-full" :disabled="busy" @click="run(primary.key)">
          <i :class="['bi', primary.icon]" aria-hidden="true"></i>{{ primary.label }}
        </button>
        <button v-if="req.status !== 'cancelled'" type="button" class="wm-btn wm-btn-secondary !min-h-11 min-w-0 max-[359px]:flex-1" :class="primary ? '' : 'flex-1'" :disabled="busy" @click="$emit('message', req)">
          <i class="bi bi-chat-dots" aria-hidden="true"></i>Chat
        </button>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 min-w-0 max-[359px]:flex-1" :class="!primary && req.status === 'cancelled' ? 'flex-1' : ''" aria-haspopup="dialog" :disabled="busy" @click="moreOpen = true">
          <i class="bi bi-three-dots" aria-hidden="true"></i>More
        </button>
      </div>
    </div>

    <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>

    <!-- Details: photos, the whole message, the price and its history, the payments. Kept mounted so a link to a payment question still finds it. -->
    <div ref="detailsEl" class="border-t border-line pt-1">
      <button type="button" class="flex min-h-11 w-full items-center justify-between gap-2 text-left text-[14px] font-medium text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
        :aria-expanded="detailsOpen ? 'true' : 'false'" :aria-controls="`details-${req.id}`" @click="detailsOpen = !detailsOpen">
        <span>Details<span class="font-normal text-muted"> · {{ detailsSummary }}</span></span>
        <i :class="['bi text-muted', detailsOpen ? 'bi-chevron-up' : 'bi-chevron-down']" aria-hidden="true"></i>
      </button>
      <div v-show="detailsOpen" :id="`details-${req.id}`" class="flex flex-col gap-3 pb-1 pt-1">
        <p v-if="longDescription" class="whitespace-pre-line text-[14px] text-ink">{{ req.description }}</p>
        <RequestPhotos :photos="req.photos || []" :label="`Photos from ${req.customer.name}`" />
        <p class="wm-meta">Received {{ sentLabel(req.received_at) }}</p>
        <!-- The proposed and agreed price: the customer decides; only the owner or a manager proposes -->
        <PriceCard v-if="req.pricing" :pricing="req.pricing" role="provider" />
        <PriceHistory v-if="req.pricing" :path="`/me/received-requests/${req.id}/pricing`" />
        <!-- The final price and the payments reported: a payment counts as paid only once the customer confirms it -->
        <PaymentSummary v-if="req.payments" :payments="req.payments" role="provider" @refresh="emit('refresh')" />
        <PaymentProviderActions ref="payRef" :req="req" hide-report @updated="emit('updated', $event)" />
      </div>
    </div>

    <JobActionsSheet :open="moreOpen" :actions="more" :subtitle="`${req.customer.name} · ${req.title || categoryLabel(req.category)}`" :busy="busy" @close="moreOpen = false" @pick="pickMore" />
    <ProposePriceSheet v-if="canPropose" :open="proposing" :req="req" @close="proposing = false" @updated="emit('updated', $event)" />
  </li>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import UserAvatar from '@/components/UserAvatar.vue';
import RequestPhotos from '@/components/RequestPhotos.vue';
import FormField from '@/components/FormField.vue';
import JobActionsSheet from '@/components/work/JobActionsSheet.vue';
import { api } from '@/api/client.js';
import { categoryLabel } from '@/utils/ui.js';
import { appSettings } from '@/stores/catalog.js';
import { budgetParts, budgetFromApi, ugx } from '@/utils/pricing.js';
import { primaryAction, moreActions, describe, priceLabel, needsDetails, canProposePrice } from '@/utils/workActions.js';
import PriceCard from '@/components/pricing/PriceCard.vue';
import PriceHistory from '@/components/pricing/PriceHistory.vue';
import ProposePriceSheet from '@/components/pricing/ProposePriceSheet.vue';
import PaymentSummary from '@/components/pricing/PaymentSummary.vue';
import PaymentProviderActions from '@/components/pricing/PaymentProviderActions.vue';
import { formatDay, formatTime, formatShort, providerStatus, directionsUrl, TIME_SLOTS, DURATIONS, durationLabel, todayInUganda } from '@/utils/booking.js';

const props = defineProps({
  req: { type: Object, required: true },
});
const emit = defineEmits(['updated', 'message', 'report', 'refresh']);

const route = useRoute();
const busy = ref(false);
const error = ref('');
const mode = ref('');        // '' | decline | cancel | propose | inspect | accept
const reason = ref('');
const note = ref('');
const minutes = ref('');
const when = reactive({ date: '', time: '' });
const today = todayInUganda();
const moreOpen = ref(false);
const proposing = ref(false);
const payRef = ref(null);
const detailsEl = ref(null);

const NEXT_TONE = {
  info: 'bg-[#EFF6FF] text-[#1E3A8A]',
  warning: 'bg-[#FFFBEB] text-[#92400E]',
  success: 'bg-[#F0FDF4] text-[#166534]',
  neutral: 'bg-[#F8FAFC] text-[#475569]',
};

const ctx = computed(() => ({ pricingOn: appSettings.pricingEnabled }));
const primary = computed(() => primaryAction(props.req, ctx.value));
const more = computed(() => moreActions(props.req, ctx.value));
const said = computed(() => describe(props.req, ctx.value, ugx));
const canPropose = computed(() => canProposePrice(props.req, ctx.value));
const tone = computed(() => ({ open: 'info', scheduled: 'success', in_progress: 'info', completed: 'success', cancelled: 'neutral' }[props.req.status] || 'neutral'));

// The budget as the customer meant it (a range or "negotiable" in words once pricing options are on)
const budget = computed(() => budgetParts(budgetFromApi(props.req), appSettings.pricingEnabled));
const price = computed(() => priceLabel(props.req, budget.value.main ? `${budget.value.main}${appSettings.pricingEnabled && budget.value.basis ? ` · ${budget.value.basis}` : ''}` : ''));

/* ---------------- Details ---------------- */
const longDescription = computed(() => (props.req.description || '').length > 160);
const photoCount = computed(() => (props.req.photos || []).length);
const detailsSummary = computed(() => {
  const parts = [];
  if (photoCount.value) parts.push(`${photoCount.value} ${photoCount.value === 1 ? 'photo' : 'photos'}`);
  if (props.req.pricing) parts.push('price');
  if (props.req.payments) parts.push('payments');
  if (!parts.length) parts.push('more about this job');

  return parts.join(', ');
});
// Opens by itself when something there needs a look (a questioned or waiting payment, a price change asked), or when a link points at this job's payment question
const linked = computed(() => String(route.query.request || '') === String(props.req.id) && !!route.query.dispute);
const detailsOpen = ref(needsDetails(props.req, ctx.value) || linked.value);
watch(() => [needsDetails(props.req, ctx.value), linked.value], ([needs, link]) => { if (needs || link) detailsOpen.value = true; });

/* ---------------- Actions ---------------- */
function pickMore(key) {
  moreOpen.value = false;
  run(key);
}

/** One place that turns an action key (from the main button or More) into what the old buttons did */
function run(key) {
  switch (key) {
    case 'accept':
      // From the main Accept button the choice is right beside it; from More it opens in the card first (never skipped, except on legacy requests, which never had it)
      if (!props.req.is_legacy && !(primary.value && primary.value.key === 'accept')) { mode.value = 'accept'; return null; }
      return send('accept', minutes.value ? { duration_minutes: Number(minutes.value) } : {});
    case 'start': return send('start');
    case 'complete': return send('complete');
    case 'decline': return start('decline');
    case 'cancel': return start('cancel');
    case 'propose_time': mode.value = 'propose'; return null;
    case 'send_price': proposing.value = true; return null;
    case 'withdraw_price': return send(`agreements/${props.req.pricing.open.id}/withdraw`);
    case 'inspect': note.value = ''; mode.value = 'inspect'; return null;
    case 'inspect_edit': note.value = props.req.inspection_note || ''; mode.value = 'inspect'; return null;
    case 'inspect_clear': return send('inspection', { required: false });
    case 'report_payment': if (payRef.value) payRef.value.openReport(); return null;
    case 'report_customer': emit('report', props.req); return null;
    case 'review_payment':
      detailsOpen.value = true;
      return nextTick(() => detailsEl.value && detailsEl.value.scrollIntoView({ block: 'nearest', behavior: 'smooth' }));
    default: return null;
  }
}

function sentLabel(iso) {
  const d = new Date(iso);
  return d.toDateString() === new Date().toDateString() ? 'today' : d.toLocaleDateString('en-UG', { day: 'numeric', month: 'short' });
}

function start(next) {
  mode.value = next;
  reason.value = '';
}

/** One action at a time, however many taps; the server checks every one */
async function send(action, body = {}) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    emit('updated', (await api(`/me/received-requests/${props.req.id}/${action}`, { method: 'POST', body })).data);
    mode.value = '';
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>
