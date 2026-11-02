<template>
  <BottomSheet :open="open" :title="sent ? 'Report sent' : title" @close="close">
    <div v-if="sent" class="flex flex-col items-center text-center gap-3 py-4" role="status">
      <span class="w-14 h-14 rounded-full bg-[#DCFCE7] text-[#15803D] flex items-center justify-center text-2xl" aria-hidden="true"><i class="bi bi-check-lg"></i></span>
      <p class="text-[15px] text-ink">
        Thank you. The WorkMate safety team will look into it.<template v-if="name"> {{ name }} won’t be told who reported them.</template>
      </p>
      <router-link to="/account/reports" class="wm-link" @click="close">Follow it in Reports and complaints</router-link>
    </div>

    <form v-else id="report-form" class="flex flex-col gap-5" novalidate @submit.prevent="submit">
      <fieldset>
        <legend class="wm-label">What’s the problem? <span class="text-error" aria-hidden="true">*</span></legend>
        <div class="flex flex-col gap-1">
          <label v-for="r in reasons" :key="r.id" class="flex items-start gap-3 rounded-xl px-3 py-2.5 cursor-pointer hover:bg-[#F1F5F9]" :class="{ 'bg-primary-tint': reason === r.id }">
            <input v-model="reason" type="radio" name="report-reason" :value="r.id" class="wm-check mt-0.5" @change="error = ''" />
            <span>
              <span class="block text-[15px] text-ink">{{ r.label }}</span>
              <span v-if="r.hint" class="block text-[13px] text-muted">{{ r.hint }}</span>
            </span>
          </label>
        </div>
      </fieldset>

      <FormField v-model="details" type="textarea" label="What happened?" required :min-height="110" maxlength="2000"
        placeholder="When it happened, what was said or done, and anything else that helps us look into it." />

      <div class="flex flex-col gap-1.5">
        <label for="report-photos" class="wm-label">Photos or screenshots <span class="font-normal text-muted">(optional, up to 3)</span></label>
        <input id="report-photos" :key="inputKey" type="file" accept="image/jpeg,image/png,.jpg,.jpeg,.png" multiple class="wm-input !py-2" @change="pick" />
        <p v-if="photos.length" class="wm-meta">{{ photos.map(f => f.name).join(', ') }}</p>
        <p class="wm-field-hint">Only the WorkMate safety team can see them.</p>
      </div>

      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
    </form>

    <template #footer>
      <button v-if="sent" type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-1" @click="close">Done</button>
      <template v-else>
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" @click="close">Cancel</button>
        <button type="submit" form="report-form" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="sending">
          {{ sending ? 'Sending…' : 'Send report' }}
        </button>
      </template>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, computed } from 'vue';
import BottomSheet from './BottomSheet.vue';
import FormField from './FormField.vue';
import { api, fieldErrors } from '@/api/client.js';

/**
 * Report a provider, a customer, or anything else that went wrong. The
 * reported person is never told who reported them. Optionally tied to the
 * request or chat it's about.
 */
const props = defineProps({
  open:           { type: Boolean, default: false },
  about:          { type: String, default: 'provider' },   // provider | customer | general
  name:           { type: String, default: '' },           // who it's about, for the title
  providerId:     { type: Number, default: null },
  requestId:      { type: Number, default: null },
  conversationId: { type: Number, default: null },
});
const emit = defineEmits(['close', 'sent']);

const REASONS = {
  provider: [
    { id: 'scam', label: 'Scam or fraud', hint: 'Asked for money upfront, fake payment requests' },
    { id: 'no_show', label: 'Didn’t show up or didn’t do the work' },
    { id: 'offline', label: 'Asked to pay or talk outside WorkMate' },
    { id: 'abusive', label: 'Rude, abusive or harassing' },
    { id: 'unsafe', label: 'Unsafe or threatening behaviour' },
    { id: 'fake', label: 'Fake profile or pretending to be someone else' },
    { id: 'quality', label: 'Very poor or damaging work' },
    { id: 'other', label: 'Something else' },
  ],
  customer: [
    { id: 'not_paid', label: 'Didn’t pay for the work' },
    { id: 'no_show', label: 'Wasn’t there or cancelled at the last minute' },
    { id: 'abusive', label: 'Rude, abusive or harassing' },
    { id: 'unsafe', label: 'Unsafe or threatening behaviour' },
    { id: 'fake', label: 'Fake request or pretending to be someone else' },
    { id: 'other', label: 'Something else' },
  ],
  general: [
    { id: 'payment', label: 'A payment problem' },
    { id: 'safety', label: 'A safety concern' },
    { id: 'app_problem', label: 'Something isn’t working in WorkMate' },
    { id: 'other', label: 'Something else' },
  ],
};

const reasons = computed(() => REASONS[props.about] || REASONS.general);
const title = computed(() => (props.about === 'general' ? 'Report a problem' : `Report ${props.name || (props.about === 'customer' ? 'this customer' : 'this provider')}`));

const reason = ref('');
const details = ref('');
const photos = ref([]);
const inputKey = ref(0);
const error = ref('');
const sending = ref(false);
const sent = ref(false);

function pick(e) {
  const list = [...e.target.files];
  error.value = '';
  if (list.length > 3) error.value = 'Add up to 3 photos.';
  else if (list.some(f => f.size > 8 * 1024 * 1024)) error.value = 'Each photo can be up to 8 MB.';
  else if (list.some(f => !/\.(jpe?g|png)$/i.test(f.name))) error.value = 'Photos must be JPG or PNG.';
  photos.value = error.value ? [] : list;
  if (error.value) inputKey.value++;
}

async function submit() {
  if (sending.value) return;
  if (!reason.value) { error.value = 'Choose what the problem is.'; return; }
  if (details.value.trim().length < 10) { error.value = 'Tell us a little more about what happened.'; return; }

  const body = new FormData();
  body.append('about', props.about);
  body.append('reason', reason.value);
  body.append('details', details.value.trim());
  if (props.providerId) body.append('provider', props.providerId);
  if (props.requestId) body.append('request', props.requestId);
  if (props.conversationId) body.append('conversation', props.conversationId);
  for (const f of photos.value) body.append('photos[]', f);

  sending.value = true;
  error.value = '';
  try {
    await api('/reports', { method: 'POST', body });
    sent.value = true;
    emit('sent');
  } catch (e) {
    const first = Object.values(fieldErrors(e))[0];
    error.value = e.status === 401 ? 'Sign in to send a report, so the safety team can follow up with you.' : (first || e.message);
  } finally {
    sending.value = false;
  }
}

function close() {
  emit('close');
  // Reset after the sheet animates out
  setTimeout(() => {
    reason.value = ''; details.value = ''; photos.value = []; inputKey.value++; error.value = ''; sent.value = false;
  }, 300);
}
</script>
