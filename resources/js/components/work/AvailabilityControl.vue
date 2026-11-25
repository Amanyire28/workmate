<template>
  <!-- What the provider takes, as one small button showing it now; the two choices and time off open in a sheet.
       The two switches are exactly the ones that were on the page: immediate work and bookings for later, kept apart. -->
  <span>
    <button type="button" class="inline-flex min-h-11 max-w-full items-center gap-2 rounded-full border border-line bg-white px-3.5 text-[14px] font-semibold text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      aria-haspopup="dialog" :aria-label="`Availability: ${loaded ? status.label : 'loading'}. Change`" @click="show = true">
      <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="loaded ? DOT[status.tone] : 'bg-[#CBD5E1]'" aria-hidden="true"></span>
      <span class="truncate">{{ loaded ? status.label : 'Availability' }}</span>
      <i class="bi bi-chevron-down text-[11px] text-muted" aria-hidden="true"></i>
    </button>

    <BottomSheet :open="show" title="Your availability" @close="show = false">
      <p v-if="error" class="wm-notice wm-notice-error mb-3" role="alert">
        <i class="bi bi-exclamation-circle" aria-hidden="true"></i>
        <span>{{ error }} <button type="button" class="wm-link" @click="reload">Try again</button></span>
      </p>

      <div class="wm-card !p-0 overflow-hidden">
        <label class="flex cursor-pointer items-center gap-3 border-b border-line px-4 py-3">
          <span class="min-w-0 flex-1">
            <span class="block text-[15px] font-medium text-ink">Take immediate work</span>
            <span class="block text-[13px] text-muted">Customers can ask you to come as soon as possible.</span>
          </span>
          <input type="checkbox" class="h-6 w-6 shrink-0 accent-[var(--color-primary)]" :checked="settings.accepts_immediate" :disabled="saving || !loaded" @change="save({ accepts_immediate: $event.target.checked })" />
        </label>
        <label class="flex cursor-pointer items-center gap-3 px-4 py-3">
          <span class="min-w-0 flex-1">
            <span class="block text-[15px] font-medium text-ink">Take bookings for later</span>
            <span class="block text-[13px] text-muted">Customers can book you for a date and time, even while you’re busy.</span>
          </span>
          <input type="checkbox" class="h-6 w-6 shrink-0 accent-[var(--color-primary)]" :checked="settings.accepts_bookings" :disabled="saving || !loaded" @change="save({ accepts_bookings: $event.target.checked })" />
        </label>
      </div>
      <p v-if="paused" class="mt-2 text-[13px] text-[#B45309]">
        <i class="bi bi-pause-circle mr-1" aria-hidden="true"></i>New requests are paused. Turn either on to receive requests again. Your existing jobs stay here.
      </p>

      <h3 class="mt-5 text-[15px] font-semibold text-ink"><i class="bi bi-calendar-x mr-1.5 text-muted" aria-hidden="true"></i>Time off<span v-if="settings.time_off.length" class="ml-1 font-normal text-muted">({{ settings.time_off.length }})</span></h3>
      <p class="mt-1 text-[13px] text-muted">Customers can’t book you during these times.</p>
      <ul v-if="settings.time_off.length" class="mt-2 divide-y divide-line">
        <li v-for="t in settings.time_off" :key="t.id" class="flex items-center gap-2 py-2">
          <span class="min-w-0 flex-1 text-[14px] text-ink">{{ formatShort(t.starts_at) }} – {{ formatShort(t.ends_at) }}<span v-if="t.note" class="text-muted"> · {{ t.note }}</span></span>
          <button type="button" class="wm-icon-btn !h-11 !w-11 text-muted" :aria-label="`Remove time off from ${formatShort(t.starts_at)}`" :disabled="saving" @click="removeTimeOff(t)">
            <i class="bi bi-trash3" aria-hidden="true"></i>
          </button>
        </li>
      </ul>
      <form class="mt-3 grid grid-cols-2 gap-2" @submit.prevent="addTimeOff">
        <label class="flex flex-col gap-1 text-[13px] text-muted">From<input v-model="off.fromDate" type="date" class="wm-input" :min="today" required /></label>
        <label class="flex flex-col gap-1 text-[13px] text-muted">Until<input v-model="off.toDate" type="date" class="wm-input" :min="off.fromDate || today" required /></label>
        <input v-model="off.note" type="text" maxlength="120" class="wm-input col-span-2" placeholder="Note (optional), e.g. Family event" aria-label="Note (optional)" />
        <button type="submit" class="wm-btn wm-btn-secondary col-span-2 !min-h-11" :disabled="saving || !off.fromDate || !off.toDate">Add whole days off</button>
      </form>
    </BottomSheet>
  </span>
</template>

<script setup>
import { ref, reactive, computed, onMounted, watch } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import { api } from '@/api/client.js';
import { WORK_STATUS, formatShort, todayInUganda } from '@/utils/booking.js';

const emit = defineEmits(['state']);

const DOT = { success: 'bg-[#16A34A]', warning: 'bg-[#D97706]', neutral: 'bg-[#94A3B8]' };
const settings = reactive({ accepts_immediate: true, accepts_bookings: true, work_status: 'available', time_off: [] });
const loaded = ref(false);
const show = ref(false);
const saving = ref(false);
const error = ref('');
const today = todayInUganda();
const off = reactive({ fromDate: '', toDate: '', note: '' });

const status = computed(() => WORK_STATUS[settings.work_status] || WORK_STATUS.available);
// Both off: new requests are paused. Only said once the real settings are known.
const paused = computed(() => loaded.value && !settings.accepts_immediate && !settings.accepts_bookings);
watch(paused, (p) => emit('state', { paused: p, loaded: loaded.value }), { immediate: true });

async function call(fn) {
  saving.value = true;
  error.value = '';
  try {
    Object.assign(settings, (await fn()).data);
    loaded.value = true;
  } catch (e) {
    error.value = e.message;
  } finally {
    saving.value = false;
  }
}

const reload = () => call(() => api('/me/provider/schedule'));
onMounted(reload);

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

defineExpose({ open: () => { show.value = true; } });
</script>
