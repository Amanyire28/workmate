<template>
  <AuthCard title="Choose a new password" :subtitle="email ? `For ${email}` : ''" :error="error">
    <template v-if="done">
      <p class="wm-notice wm-notice-success" role="status">
        <i class="bi bi-check-circle" aria-hidden="true"></i>
        <span>{{ done }}</span>
      </p>
      <router-link to="/sign-in" class="wm-btn wm-btn-primary wm-btn-lg wm-btn-block mt-4">Sign in</router-link>
    </template>

    <p v-else-if="!token || !email" class="wm-notice wm-notice-warning">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <span>
        This link is incomplete. Open the link from your email again, or
        <router-link to="/forgot-password" class="wm-link">ask for a new one</router-link>.
      </span>
    </p>

    <form v-else class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <FormField
        v-model="form.password"
        type="password"
        label="New password"
        autocomplete="new-password"
        required
        hint="At least 8 characters, with letters and numbers."
        :error-message="errors.password"
      />
      <FormField
        v-model="form.password_confirmation"
        type="password"
        label="Confirm new password"
        autocomplete="new-password"
        required
        :error-message="errors.password_confirmation"
      />
      <p v-if="errors.email" class="wm-notice wm-notice-error" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <span>{{ errors.email }} <router-link to="/forgot-password" class="wm-link">Send a new link</router-link></span>
      </p>
      <PrimaryButton type="submit" :loading="busy">Save new password</PrimaryButton>
    </form>
  </AuthCard>
</template>

<script setup>
import { reactive, ref, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import AuthCard from '@/components/AuthCard.vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { api, fieldErrors } from '@/api/client.js';
import { focusFirstError } from '@/utils/forms.js';

const route = useRoute();
const token = typeof route.query.token === 'string' ? route.query.token : '';
const email = typeof route.query.email === 'string' ? route.query.email : '';

const form = reactive({ password: '', password_confirmation: '' });
const errors = ref({});
const error = ref('');
const done = ref('');
const busy = ref(false);

async function submit() {
  errors.value = {
    password: form.password.length >= 8 ? '' : 'Use at least 8 characters.',
    password_confirmation: form.password === form.password_confirmation ? '' : 'The passwords don’t match.',
  };
  error.value = '';
  if (errors.value.password || errors.value.password_confirmation) {
    await nextTick();
    return focusFirstError();
  }

  busy.value = true;
  try {
    const res = await api('/auth/reset-password', { method: 'POST', body: { token, email, ...form } });
    done.value = res.data.message;
  } catch (e) {
    errors.value = fieldErrors(e);
    if (!Object.keys(errors.value).length) error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>
