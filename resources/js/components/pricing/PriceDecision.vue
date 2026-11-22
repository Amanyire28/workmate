<template>
  <!-- The customer's answer to a proposed price: accept it (the amount is on the button), ask for a change, or say no -->
  <div v-if="open && open.status === 'pending_customer'" class="flex flex-col gap-2">
    <!-- Ask for a change -->
    <form v-if="mode === 'change'" class="flex flex-col gap-2 rounded-xl bg-[#F8FAFC] p-3" @submit.prevent="changeRequest">
      <p class="text-[14px] font-medium text-ink">Ask {{ providerName }} for a change</p>
      <FormField v-model="note" type="textarea" label="What would you like changed?" optional :id="`change-note-${req.id}`" maxlength="300" :min-height="72"
        placeholder="e.g. Can you do it for less, or leave out the tap?" />
      <FormField v-model="suggested" type="number" inputmode="numeric" min="0" step="1000" label="Price you would like" optional prefix="UGX"
        placeholder="e.g. 120000" :error-message="suggestedError" hint="Only a suggestion. Nothing is agreed until you accept a price." />
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-12 flex-1" :disabled="busy" @click="mode = ''">Back</button>
        <button type="submit" class="wm-btn wm-btn-primary !min-h-12 flex-1" :disabled="busy">Send</button>
      </div>
    </form>

    <!-- Say no -->
    <div v-else-if="mode === 'reject'" class="flex flex-col gap-2 rounded-xl bg-[#F8FAFC] p-3">
      <p class="text-[14px] text-ink">Turn down {{ ugx(open.amount) }}? {{ providerName }} can send a different price.</p>
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-12 flex-1" :disabled="busy" @click="mode = ''">Back</button>
        <button type="button" class="wm-btn wm-btn-primary !min-h-12 flex-1 !bg-[#B91C1C] !border-[#B91C1C]" :disabled="busy" @click="reject">Yes, turn it down</button>
      </div>
    </div>

    <template v-else>
      <button type="button" class="wm-btn wm-btn-primary !min-h-12 w-full" :disabled="busy" @click="accept">
        <i class="bi bi-check2-circle" aria-hidden="true"></i>Accept price · {{ ugx(open.amount) }}
      </button>
      <div class="flex gap-2">
        <button type="button" class="wm-btn wm-btn-secondary !min-h-12 flex-1" :disabled="busy" @click="mode = 'change'">Request a change</button>
        <button type="button" class="wm-btn wm-btn-ghost !min-h-12 flex-1" :disabled="busy" @click="mode = 'reject'">No thanks</button>
      </div>
    </template>

    <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import FormField from '@/components/FormField.vue';
import { api } from '@/api/client.js';
import { ugx, PRICE_STALE_CODES, MIN_AMOUNT } from '@/utils/pricing.js';

const props = defineProps({
  // The request as the API sent it (a customer's own request, with its `pricing` block)
  req: { type: Object, required: true },
  providerName: { type: String, default: 'the provider' },
});
const emit = defineEmits(['updated', 'refresh']);

const open = computed(() => (props.req.pricing && props.req.pricing.open) || null);
const mode = ref('');          // '' | change | reject
const note = ref('');
const suggested = ref('');
const suggestedError = ref('');
const busy = ref(false);
const error = ref('');

/** One answer at a time, however many taps; the server checks every one. A price that changed meanwhile is shown again, never accepted. */
async function answer(action, body) {
  if (busy.value || !open.value) return;
  busy.value = true;
  error.value = '';
  try {
    emit('updated', (await api(`/me/requests/${props.req.id}/agreements/${open.value.id}/${action}`, { method: 'POST', body })).data);
    mode.value = '';
  } catch (e) {
    error.value = e.message;
    // The price changed or ended while they were looking: show them the latest
    if (PRICE_STALE_CODES.includes(e.code)) {
      mode.value = '';
      emit('refresh');
    }
  } finally {
    busy.value = false;
  }
}

// The amount on the button is what is sent, so the server can tell if the price moved on
const accept = () => answer('accept', { amount: open.value.amount });
const reject = () => answer('reject', {});

function changeRequest() {
  suggestedError.value = '';
  const wanted = String(suggested.value).trim();
  if (wanted !== '' && (!/^\d+$/.test(wanted) || Number(wanted) < MIN_AMOUNT)) {
    suggestedError.value = `Enter an amount of at least ${ugx(MIN_AMOUNT)}, or leave it empty.`;
    return;
  }
  answer('changes', { note: note.value.trim() || null, suggested_amount: wanted === '' ? null : Number(wanted) });
}
</script>
