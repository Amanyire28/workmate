<template>
  <!-- What's happening with a customer's request, in plain words, and what they can do next -->
  <div class="flex flex-col gap-2">
    <div class="rounded-xl px-3.5 py-2.5" :class="TONES[status.tone]">
      <p class="text-[14.5px] font-semibold">{{ status.title }}</p>
      <p v-if="status.text" class="text-[13px] opacity-90">{{ status.text }}</p>
    </div>

    <!-- The provider needs to see the job before giving a final price -->
    <p v-if="req.inspection_required" class="flex items-start gap-2 rounded-xl bg-[#FFFBEB] px-3 py-2 text-[13.5px] text-[#92400E]">
      <i class="bi bi-search mt-0.5" aria-hidden="true"></i>
      <span><strong>The provider needs to see the job before giving a final price.</strong><template v-if="req.inspection_note"> “{{ req.inspection_note }}”</template> Message them to arrange it.</span>
    </p>

    <p v-if="req.schedule?.start" class="flex items-center gap-2 text-[14px] text-ink">
      <i class="bi bi-calendar-check text-muted" aria-hidden="true"></i>{{ formatDay(req.schedule.start) }}, {{ formatTime(req.schedule.start) }}
    </p>
    <p v-else-if="req.preferred_time" class="flex items-center gap-2 text-[14px] text-ink">
      <i class="bi bi-calendar3 text-muted" aria-hidden="true"></i>{{ req.preferred_time }}
    </p>

    <!-- The proposed and agreed price (Phase B2): only "Accept price" makes it the agreed price -->
    <PriceCard v-if="req.pricing" :pricing="req.pricing" role="customer" />
    <PriceDecision :req="req" :provider-name="req.provider && req.provider.display_name" @updated="emit('updated', $event)" @refresh="emit('refresh')" />
    <PriceHistory v-if="req.pricing" :path="`/me/requests/${req.id}/pricing`" />

    <!-- A time the provider suggested -->
    <div v-if="status.key === 'proposed'" class="flex flex-col gap-2 rounded-xl border border-[#F59E0B]/30 bg-[#FFFBEB] p-3">
      <p class="text-[14px] text-ink">Suggested time: <strong>{{ formatDay(req.schedule.proposed_start) }}, {{ formatTime(req.schedule.proposed_start) }}</strong></p>
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy" @click="answer(true)">Accept this time</button>
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" :disabled="busy" @click="answer(false)">No, thanks</button>
      </div>
    </div>

    <button v-if="canCancel && !confirming" type="button" class="wm-btn wm-btn-ghost !min-h-10 self-start text-[#B91C1C]" :disabled="busy" @click="confirming = true">
      <i class="bi bi-x-circle" aria-hidden="true"></i>{{ req.status === 'scheduled' ? 'Cancel booking' : 'Cancel request' }}
    </button>
    <div v-if="confirming" class="flex flex-col gap-2 rounded-xl bg-[#F8FAFC] p-3">
      <p class="text-[14px] text-ink">Cancel this {{ req.status === 'scheduled' ? 'booking' : 'request' }}? The provider will be told.</p>
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" :disabled="busy" @click="confirming = false">Keep it</button>
        <button type="button" class="wm-btn wm-btn-primary !min-h-11 flex-1 !bg-[#B91C1C] !border-[#B91C1C]" :disabled="busy" @click="cancel">Yes, cancel</button>
      </div>
    </div>

    <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { api } from '@/api/client.js';
import { customerStatus, formatDay, formatTime } from '@/utils/booking.js';
import PriceCard from '@/components/pricing/PriceCard.vue';
import PriceDecision from '@/components/pricing/PriceDecision.vue';
import PriceHistory from '@/components/pricing/PriceHistory.vue';

const props = defineProps({
  // The request as the API returns it (status, schedule, preferred_time)
  req: { type: Object, required: true },
});
const emit = defineEmits(['updated', 'refresh']);

const TONES = {
  info: 'bg-primary-tint text-primary',
  success: 'bg-success-tint text-success',
  warning: 'bg-[#FFFBEB] text-[#92400E]',
  neutral: 'bg-[#F1F5F9] text-[#475569]',
};

const busy = ref(false);
const error = ref('');
const confirming = ref(false);
const status = computed(() => customerStatus(props.req));
// Requests made before bookings existed keep their original steps (no cancelling here)
const canCancel = computed(() => !props.req.is_legacy && (props.req.status === 'scheduled' || (props.req.status === 'open' && props.req.kind === 'direct')));

async function run(path, body) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    emit('updated', (await api(path, { method: 'POST', body })).data);
    confirming.value = false;
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}

const answer = (accept) => run(`/me/requests/${props.req.id}/proposal`, { accept });
const cancel = () => run(`/me/requests/${props.req.id}/cancel`, {});
</script>
