<template>
  <BottomSheet :open="open" :title="sent ? 'Report sent' : `Report ${providerName}`" @close="close">
    <!-- Confirmation -->
    <div v-if="sent" class="flex flex-col items-center text-center gap-3 py-4" role="status">
      <span class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-success-tint text-success text-3xl" aria-hidden="true">
        <i class="bi bi-shield-check"></i>
      </span>
      <p class="text-[15px] text-ink max-w-[340px]">
        Thank you. The WorkMate safety team will review this report. {{ providerName }} won’t be told who reported them.
      </p>
      <p class="wm-meta max-w-[340px]">
        If you’re in danger or have lost money, contact the police as well.
      </p>
    </div>

    <form v-else id="report-form" class="flex flex-col gap-5" novalidate @submit.prevent="submit">
      <p class="text-sm text-muted">
        Reports are confidential. Use this for scams, unsafe behaviour or fake profiles.
      </p>

      <fieldset>
        <legend class="wm-label">What happened? <span class="text-error" aria-hidden="true">*</span></legend>
        <div class="flex flex-col">
          <label
            v-for="r in reasons"
            :key="r.id"
            class="flex items-start gap-3 py-2.5 min-h-11 cursor-pointer"
          >
            <input v-model="reason" type="radio" name="report-reason" :value="r.id" class="wm-check mt-0.5" @change="error = ''" />
            <span>
              <span class="block text-[15px] text-ink">{{ r.label }}</span>
              <span v-if="r.hint" class="block wm-meta">{{ r.hint }}</span>
            </span>
          </label>
        </div>
        <p v-if="error" class="wm-field-error" role="alert">
          <i class="bi bi-exclamation-circle mr-1" aria-hidden="true"></i>{{ error }}
        </p>
      </fieldset>

      <FormField
        v-model="details"
        type="textarea"
        label="Details"
        optional
        :min-height="110"
        placeholder="What happened, and when? Include amounts if money was involved."
      />
    </form>

    <template #footer>
      <button v-if="sent" type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-1" @click="close">Done</button>
      <template v-else>
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" @click="close">Cancel</button>
        <button type="submit" form="report-form" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="sending">
          <span v-if="sending" class="wm-spinner" aria-hidden="true"></span>
          {{ sending ? 'Sending…' : 'Send report' }}
        </button>
      </template>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref } from 'vue';
import BottomSheet from './BottomSheet.vue';
import FormField from './FormField.vue';
import { api } from '@/api/client.js';

const props = defineProps({
  open:         { type: Boolean, default: false },
  providerId:   { type: Number, required: true },
  providerName: { type: String, required: true },
});
const emit = defineEmits(['close']);

const reasons = [
  { id: 'scam',      label: 'Scam or fraud',                 hint: 'Asked for money upfront, fake payment requests' },
  { id: 'offline',   label: 'Asked to pay or talk outside WorkMate' },
  { id: 'no_show',   label: 'Took payment but didn’t do the work' },
  { id: 'unsafe',    label: 'Unsafe or threatening behaviour' },
  { id: 'fake',      label: 'Fake profile or pretending to be someone else' },
  { id: 'quality',   label: 'Very poor or damaging work' },
  { id: 'other',     label: 'Something else' },
];

const reason = ref('');
const details = ref('');
const error = ref('');
const sending = ref(false);
const sent = ref(false);

async function submit() {
  if (sending.value) return;
  if (!reason.value) {
    error.value = 'Choose what happened.';
    return;
  }
  sending.value = true;
  error.value = '';
  try {
    await api(`/providers/${props.providerId}/reports`, { method: 'POST', body: {
      reason: reason.value,
      details: details.value.trim() || null,
    } });
    sent.value = true;
  } catch (e) {
    error.value = e.status === 401 ? 'Sign in to send a report, so the safety team can follow up with you.' : e.message;
  } finally {
    sending.value = false;
  }
}

function close() {
  emit('close');
  // Reset after the sheet animates out
  setTimeout(() => {
    reason.value = ''; details.value = ''; error.value = ''; sent.value = false;
  }, 300);
}
</script>
