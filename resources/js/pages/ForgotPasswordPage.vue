<template>
  <AuthCard
    title="Reset your password"
    subtitle="Enter your account’s email address and we’ll send you a link to choose a new password."
    :error="error"
  >
    <p v-if="sent" class="wm-notice wm-notice-success" role="status">
      <i class="bi bi-envelope-check" aria-hidden="true"></i>
      <span>{{ sent }}</span>
    </p>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <FormField
        v-model="email"
        type="email"
        label="Email address"
        autocomplete="email"
        inputmode="email"
        required
        :error-message="errors.email"
      />
      <PrimaryButton type="submit" :loading="busy">Send reset link</PrimaryButton>
    </form>

    <template #footer>
      <router-link to="/sign-in" class="wm-link font-medium">Back to sign in</router-link>
    </template>
  </AuthCard>
</template>

<script setup>
import { ref, nextTick } from 'vue';
import AuthCard from '@/components/AuthCard.vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { api, fieldErrors } from '@/api/client.js';
import { focusFirstError } from '@/utils/forms.js';

const email = ref('');
const errors = ref({});
const error = ref('');
const sent = ref('');
const busy = ref(false);

async function submit() {
  errors.value = { email: email.value.trim() ? '' : 'Enter your email address.' };
  error.value = '';
  if (errors.value.email) {
    await nextTick();
    return focusFirstError();
  }

  busy.value = true;
  try {
    const res = await api('/auth/forgot-password', { method: 'POST', body: { email: email.value.trim() } });
    sent.value = res.data.message;
  } catch (e) {
    errors.value = fieldErrors(e);
    if (!Object.keys(errors.value).length) error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>
