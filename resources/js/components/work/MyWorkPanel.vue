<template>
  <!-- The provider's workspace at a glance: where their work stands, what they take, time off -->
  <section class="flex flex-col gap-3" aria-label="Your work">
    <!-- Stages: tap one to see it -->
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2" role="group" aria-label="Filter by stage">
      <button v-for="s in STAGES" :key="s.id" type="button"
        class="flex flex-col items-start rounded-2xl border px-3.5 py-2.5 text-left transition-colors"
        :class="stage === s.id ? 'border-primary bg-primary-tint' : 'border-line bg-white hover:bg-[#F8FAFC]'"
        :aria-pressed="stage === s.id" @click="$emit('update:stage', stage === s.id ? '' : s.id)">
        <span class="text-[22px] font-bold leading-tight text-ink">{{ counts[s.id] }}</span>
        <span class="text-[13px] text-muted">{{ s.label }}</span>
      </button>
    </div>

    <!-- What they take: immediate work, bookings for later (both off is paused) -->
    <div class="wm-card !p-0 overflow-hidden">
      <div class="flex items-center gap-3 px-4 py-3 border-b border-line">
        <span class="wm-pill shrink-0" :class="`wm-pill-${status.tone}`">{{ status.label }}</span>
        <p class="text-[13px] text-muted">What customers see now.</p>
      </div>
      <label class="flex items-center gap-3 px-4 py-3 cursor-pointer border-b border-line">
        <span class="min-w-0 flex-1">
          <span class="block text-[15px] font-medium text-ink">Take immediate work</span>
          <span class="block text-[13px] text-muted">Customers can ask you to come as soon as possible.</span>
        </span>
        <input type="checkbox" class="h-6 w-6 shrink-0 accent-[var(--color-primary)]" :checked="settings.accepts_immediate" :disabled="saving" @change="save({ accepts_immediate: $event.target.checked })" />
      </label>
      <label class="flex items-center gap-3 px-4 py-3 cursor-pointer">
        <span class="min-w-0 flex-1">
          <span class="block text-[15px] font-medium text-ink">Take bookings for later</span>
          <span class="block text-[13px] text-muted">Customers can book you for a date and time, even while you’re busy.</span>
        </span>
        <input type="checkbox" class="h-6 w-6 shrink-0 accent-[var(--color-primary)]" :checked="settings.accepts_bookings" :disabled="saving" @change="save({ accepts_bookings: $event.target.checked })" />
      </label>
      <p v-if="!settings.accepts_immediate && !settings.accepts_bookings" class="px-4 pb-3 text-[13px] text-[#B45309]">
        <i class="bi bi-pause-circle mr-1" aria-hidden="true"></i>New requests are paused. Turn either on to receive requests again.
      </p>
    </div>

    <!-- Time off -->
    <details class="wm-card !py-3" :open="settings.time_off.length > 0">
      <summary class="flex cursor-pointer items-center justify-between gap-2 text-[15px] font-medium text-ink min-h-9">
        <span><i class="bi bi-calendar-x mr-1.5 text-muted" aria-hidden="true"></i>Time off<span v-if="settings.time_off.length" class="ml-1 text-muted font-normal">({{ settings.time_off.length }})</span></span>
        <i class="bi bi-chevron-down text-muted text-sm" aria-hidden="true"></i>
      </summary>
      <p class="mt-2 text-[13px] text-muted">Customers can’t book you during these times.</p>
      <ul v-if="settings.time_off.length" class="mt-2 divide-y divide-line">
        <li v-for="t in settings.time_off" :key="t.id" class="flex items-center gap-2 py-2">
          <span class="min-w-0 flex-1 text-[14px] text-ink">{{ formatShort(t.starts_at) }} – {{ formatShort(t.ends_at) }}<span v-if="t.note" class="text-muted"> · {{ t.note }}</span></span>
          <button type="button" class="wm-icon-btn !w-9 !h-9 text-muted" :aria-label="`Remove time off from ${formatShort(t.starts_at)}`" :disabled="saving" @click="removeTimeOff(t)">
            <i class="bi bi-trash3" aria-hidden="true"></i>
          </button>
        </li>
      </ul>
      <form class="mt-3 grid grid-cols-2 gap-2" @submit.prevent="addTimeOff">
        <label class="flex flex-col gap-1 text-[13px] text-muted">From<input v-model="off.fromDate" type="date" class="wm-input" :min="today" required /></label>
        <label class="flex flex-col gap-1 text-[13px] text-muted">Until<input v-model="off.toDate" type="date" class="wm-input" :min="off.fromDate || today" required /></label>
        <input v-model="off.note" type="text" maxlength="120" class="wm-input col-span-2" placeholder="Note (optional), e.g. Family event" />
        <button type="submit" class="wm-btn wm-btn-secondary col-span-2 !min-h-11" :disabled="saving || !off.fromDate || !off.toDate">Add whole days off</button>
      </form>
    </details>

    <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
  </section>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue';
import { api } from '@/api/client.js';
import { WORK_STATUS, formatShort, todayInUganda } from '@/utils/booking.js';

const props = defineProps({
  // The provider's received requests, to count by stage
  requests: { type: Array, default: () => [] },
  stage: { type: String, default: '' },
});
defineEmits(['update:stage']);

const STAGES = [
  { id: 'new', label: 'New requests' },
  { id: 'upcoming', label: 'Upcoming' },
  { id: 'working', label: 'In progress' },
  { id: 'done', label: 'Completed' },
];

const settings = reactive({ accepts_immediate: true, accepts_bookings: true, work_status: 'available', time_off: [] });
const saving = ref(false);
const error = ref('');
const today = todayInUganda();
const off = reactive({ fromDate: '', toDate: '', note: '' });

const status = computed(() => WORK_STATUS[settings.work_status] || WORK_STATUS.available);
const counts = computed(() => ({
  new: props.requests.filter(r => r.status === 'open').length,
  upcoming: props.requests.filter(r => r.status === 'scheduled').length,
  working: props.requests.filter(r => r.status === 'in_progress').length,
  done: props.requests.filter(r => r.status === 'completed').length,
}));

async function call(fn) {
  saving.value = true;
  error.value = '';
  try {
    Object.assign(settings, (await fn()).data);
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
}

onMounted(() => call(() => api('/me/provider/schedule')));

function save(patch) {
  call(() => api('/me/provider/availability', { method: 'PATCH', body: patch }));
}

function addTimeOff() {
  const body = { starts_at: `${off.fromDate} 00:00`, ends_at: `${off.toDate} 23:59`, note: off.note.trim() || null };
  call(async () => {
    const res = await api('/me/provider/time-off', { method: 'POST', body });
    Object.assign(off, { fromDate: '', toDate: '', note: '' });
    return res;
  });
}

function removeTimeOff(t) {
  call(() => api(`/me/provider/time-off/${t.id}`, { method: 'DELETE' }));
}
</script>
