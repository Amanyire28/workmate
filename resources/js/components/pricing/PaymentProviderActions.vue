<template>
  <!-- The provider business reports payments it received, takes a report back (with a reason) or corrects one. The owner or a
       manager only; a payment the customer confirmed is not the provider's to take back. -->
  <div v-if="allowed" class="flex flex-col gap-2">
    <button v-if="left !== 0" type="button" class="wm-btn wm-btn-secondary !min-h-11 w-full" :disabled="busy" @click="openSheet(null)">
      <i class="bi bi-cash-coin" aria-hidden="true"></i>Report a payment
    </button>

    <!-- Take a report back -->
    <div v-for="r in withdrawable" :key="`w-${r.id}`" class="flex flex-col gap-2 rounded-xl bg-[#F8FAFC] px-3 py-2">
      <form v-if="withdrawing === r.id" class="flex flex-col gap-2" @submit.prevent="withdraw(r)">
        <FormField v-model="reason" type="textarea" label="Why are you taking it back?" required :id="`withdraw-reason-${r.id}`" maxlength="300" :min-height="64"
          placeholder="e.g. I entered the wrong amount" />
        <div class="flex gap-2">
          <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" :disabled="busy" @click="withdrawing = null">Back</button>
          <button type="submit" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy || reason.trim().length < 3">Take back</button>
        </div>
      </form>
      <div v-else class="flex items-center justify-between gap-2 text-[13.5px]">
        <span class="text-ink">{{ ugx(r.amount) }} · {{ methodLabel(r.method) }}</span>
        <button type="button" class="wm-btn wm-btn-ghost !min-h-10 !px-3" :disabled="busy" @click="startWithdraw(r)">Take it back</button>
      </div>
    </div>

    <!-- Report the right amount for one that was taken back -->
    <button v-for="r in uncorrected" :key="`c-${r.id}`" type="button" class="wm-btn wm-btn-tint !min-h-11 w-full" :disabled="busy" @click="openSheet(r)">
      Report the right amount for the {{ ugx(r.amount) }} you took back
    </button>

    <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>

    <ReportPaymentSheet :open="sheet" :req="req" :corrects="correcting" :left="left" @close="sheet = false" @updated="emit('updated', $event)" />
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import FormField from '@/components/FormField.vue';
import ReportPaymentSheet from '@/components/pricing/ReportPaymentSheet.vue';
import { api } from '@/api/client.js';
import { appSettings } from '@/stores/catalog.js';
import { ugx, methodLabel, withdrawableReports, uncorrectedReports } from '@/utils/pricing.js';

const props = defineProps({
  // The request as the received-requests API sent it (with its `payments` block)
  req: { type: Object, required: true },
});
const emit = defineEmits(['updated']);

// Owner or manager, a job that was accepted (also once done), made with the booking system, and pricing options on
const allowed = computed(() => appSettings.pricingEnabled && !!props.req.payments && props.req.can_manage_pricing && !props.req.is_legacy
  && ['scheduled', 'in_progress', 'completed'].includes(props.req.status));
const withdrawable = computed(() => withdrawableReports(props.req.payments));
const uncorrected = computed(() => uncorrectedReports(props.req.payments));
// What can still be reported against the final price (null: no final price, nothing to compare with)
const left = computed(() => {
  const p = props.req.payments;

  return p && p.final_price ? Math.max(p.final_price.amount - p.totals.reported, 0) : null;
});

const sheet = ref(false);
const correcting = ref(null);
const withdrawing = ref(null);
const reason = ref('');
const busy = ref(false);
const error = ref('');

function openSheet(corrects) {
  correcting.value = corrects;
  sheet.value = true;
}

function startWithdraw(report) {
  withdrawing.value = report.id;
  reason.value = '';
  error.value = '';
}

async function withdraw(report) {
  if (busy.value) return;
  busy.value = true;
  error.value = '';
  try {
    emit('updated', (await api(`/me/received-requests/${props.req.id}/payments/${report.id}/cancel`, { method: 'POST', body: { reason: reason.value.trim() } })).data);
    withdrawing.value = null;
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>
