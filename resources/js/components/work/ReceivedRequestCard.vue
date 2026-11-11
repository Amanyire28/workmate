<template>
  <!-- One request a customer sent this provider, with what they can do next -->
  <li class="wm-card flex flex-col gap-3">
    <div class="flex items-start justify-between gap-3">
      <div class="flex items-center gap-3 min-w-0">
        <UserAvatar :name="req.customer.name" :src="req.customer.avatar_url || ''" :size="44" />
        <div class="min-w-0">
          <h2 class="text-base font-semibold text-ink leading-snug truncate">{{ req.customer.name }}</h2>
          <p class="wm-meta truncate">{{ req.title || categoryLabel(req.category) }}</p>
        </div>
      </div>
      <span class="wm-pill shrink-0" :class="`wm-pill-${tone}`">{{ providerStatus(req) }}</span>
    </div>

    <p class="text-sm text-ink whitespace-pre-line">{{ req.description }}</p>
    <RequestPhotos :photos="req.photos || []" :label="`Photos from ${req.customer.name}`" />

    <!-- When -->
    <p class="flex items-start gap-2 text-[14px] text-ink">
      <i class="bi bi-calendar3 text-muted mt-0.5" aria-hidden="true"></i>
      <span>
        <template v-if="req.schedule?.start">{{ formatDay(req.schedule.start) }}, {{ formatTime(req.schedule.start) }}<template v-if="req.schedule.end"> – {{ formatTime(req.schedule.end) }}</template></template>
        <template v-else>{{ req.preferred_time || 'No time given' }}</template>
        <span v-if="req.schedule?.proposed_start" class="block text-[13px] text-[#B45309]">You suggested {{ formatShort(req.schedule.proposed_start) }}; waiting for the customer.</span>
      </span>
    </p>

    <!-- Where: the area and landmark; the exact point and directions once accepted -->
    <div class="flex items-start gap-2 text-[14px] text-ink">
      <i class="bi bi-geo-alt text-muted mt-0.5" aria-hidden="true"></i>
      <div class="min-w-0 flex-1">
        <p>{{ req.location?.area_name || req.area_name }}<template v-if="req.location?.landmark"> · {{ req.location.landmark }}</template></p>
        <p v-if="req.location?.directions" class="text-[13px] text-muted">{{ req.location.directions }}</p>
        <a v-if="req.location?.latitude != null" :href="directionsUrl(req.location.latitude, req.location.longitude)" target="_blank" rel="noopener"
          class="wm-btn wm-btn-tint !min-h-10 !px-3 mt-1.5 inline-flex">
          <i class="bi bi-sign-turn-right" aria-hidden="true"></i>View location / Get directions
        </a>
        <p v-else-if="req.location?.has_pin && req.status === 'open'" class="text-[12.5px] text-muted">The exact spot and directions show once you accept.</p>
      </div>
    </div>

    <p class="wm-meta flex flex-wrap gap-x-4 gap-y-1">
      <span v-if="req.budget" class="inline-flex items-center gap-1"><i class="bi bi-cash" aria-hidden="true"></i>Budget {{ formatPrice(req.budget) }}</span>
      <span>Received {{ sentLabel(req.received_at) }}</span>
    </p>
    <p v-if="req.status === 'cancelled' && req.provider_note" class="text-sm text-muted"><i class="bi bi-reply" aria-hidden="true"></i> You said: {{ req.provider_note }}</p>
    <p v-else-if="req.status === 'cancelled' && req.schedule?.cancelled_by === 'customer'" class="text-sm text-muted">The customer cancelled.</p>

    <!-- A reply that needs words or a time -->
    <form v-if="mode === 'decline' || mode === 'cancel'" class="flex flex-col gap-2" @submit.prevent="send(mode, { reason: reason.trim() })">
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

    <div v-else class="mt-auto pt-3 border-t border-line flex flex-col gap-2">
      <!-- New: how long, then accept -->
      <template v-if="req.status === 'open'">
        <label v-if="!req.is_legacy" class="flex items-center gap-2 text-[13px] text-muted">
          How long will it take?
          <select v-model="minutes" class="wm-input !min-h-9 !py-1 flex-1">
            <option v-for="d in DURATIONS" :key="d.label" :value="d.value">{{ d.value ? d.label : (req.schedule.duration_minutes ? durationLabel(req.schedule.duration_minutes) + ' (customer)' : 'Usual length') }}</option>
          </select>
        </label>
        <div class="flex flex-wrap gap-2">
          <button type="button" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy" @click="send('accept', minutes ? { duration_minutes: Number(minutes) } : {})">
            <i class="bi bi-check2" aria-hidden="true"></i>Accept
          </button>
          <button v-if="!req.is_legacy" type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" :disabled="busy" @click="mode = 'propose'">Suggest another time</button>
          <button type="button" class="wm-btn wm-btn-ghost !min-h-11" :disabled="busy" @click="start('decline')">Decline</button>
        </div>
      </template>
      <div v-else-if="req.status === 'scheduled'" class="flex flex-wrap gap-2">
        <button type="button" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy" @click="send('start')"><i class="bi bi-play-fill" aria-hidden="true"></i>Start work</button>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" :disabled="busy" @click="send('complete')"><i class="bi bi-check2-all" aria-hidden="true"></i>Mark as done</button>
        <button type="button" class="wm-btn wm-btn-ghost !min-h-11" :disabled="busy" @click="mode = 'propose'">Change time</button>
        <button type="button" class="wm-btn wm-btn-ghost !min-h-11 text-[#B91C1C]" :disabled="busy" @click="start('cancel')">Cancel booking</button>
      </div>
      <button v-else-if="req.status === 'in_progress'" type="button" class="wm-btn wm-btn-primary !min-h-11" :disabled="busy" @click="send('complete')">
        <i class="bi bi-check2-all" aria-hidden="true"></i>Mark as done
      </button>

      <div class="flex flex-wrap gap-2">
        <button v-if="req.status !== 'cancelled'" type="button" class="wm-btn wm-btn-ghost !min-h-11" :disabled="busy" @click="$emit('message', req)">
          <i class="bi bi-chat-dots" aria-hidden="true"></i>Open chat
        </button>
        <button type="button" class="wm-btn wm-btn-ghost !min-h-11 text-muted" @click="$emit('report', req)">
          <i class="bi bi-flag" aria-hidden="true"></i>Report
        </button>
      </div>
    </div>

    <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
  </li>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import RequestPhotos from '@/components/RequestPhotos.vue';
import FormField from '@/components/FormField.vue';
import { api } from '@/api/client.js';
import { formatPrice, categoryLabel } from '@/utils/ui.js';
import { formatDay, formatTime, formatShort, providerStatus, directionsUrl, TIME_SLOTS, DURATIONS, durationLabel, todayInUganda } from '@/utils/booking.js';

const props = defineProps({
  req: { type: Object, required: true },
});
const emit = defineEmits(['updated', 'message', 'report']);

const busy = ref(false);
const error = ref('');
const mode = ref('');        // '' | decline | cancel | propose
const reason = ref('');
const minutes = ref('');
const when = reactive({ date: '', time: '' });
const today = todayInUganda();

const tone = computed(() => ({ open: 'info', scheduled: 'success', in_progress: 'info', completed: 'success', cancelled: 'neutral' }[props.req.status] || 'neutral'));

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
