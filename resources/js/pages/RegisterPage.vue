<template>
  <AuthCard
    title="Create your account"
    subtitle="One account lets you hire people now and offer your own services later."
    :error="error"
  >
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <FormField v-model="form.name" label="Full name" autocomplete="name" required :error-message="errors.name" />
      <FormField
        v-model="form.email"
        type="email"
        label="Email address"
        autocomplete="email"
        inputmode="email"
        required
        hint="We’ll send a link to confirm it."
        :error-message="errors.email"
      />
      <FormField
        v-model="form.phone"
        type="tel"
        label="Phone number"
        optional
        placeholder="0772 123 456"
        autocomplete="tel"
        :error-message="errors.phone"
      />
      <FormField
        v-model="form.password"
        type="password"
        label="Password"
        autocomplete="new-password"
        required
        hint="At least 8 characters, with letters and numbers."
        :error-message="errors.password"
      />
      <FormField
        v-model="form.password_confirmation"
        type="password"
        label="Confirm password"
        autocomplete="new-password"
        required
        :error-message="errors.password_confirmation"
      />

      <PrimaryButton type="submit" :loading="busy">Create account</PrimaryButton>
    </form>

    <template #footer>
      Already have an account?
      <router-link :to="{ path: '/sign-in', query: route.query.redirect ? { redirect: route.query.redirect } : {} }" class="wm-link font-medium">Sign in</router-link>
    </template>
  </AuthCard>
</template>

<script setup>
import { reactive, ref, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthCard from '@/components/AuthCard.vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { register, safeRedirect } from '@/stores/auth.js';
import { fieldErrors } from '@/api/client.js';
import { focusFirstError } from '@/utils/forms.js';

const route = useRoute();
const router = useRouter();

const form = reactive({ name: '', email: '', phone: '', password: '', password_confirmation: '' });
const errors = ref({});
const error = ref('');
const busy = ref(false);

// Quick checks for obvious slips; the server makes the real decision
function check() {
  return {
    name: form.name.trim() ? '' : 'Enter your name.',
    email: /^\S+@\S+\.\S+$/.test(form.email.trim()) ? '' : 'Enter a valid email address.',
    password: form.password.length >= 8 ? '' : 'Use at least 8 characters.',
    password_confirmation: form.password === form.password_confirmation ? '' : 'The passwords don’t match.',
  };
}

async function submit() {
  errors.value = check();
  error.value = '';
  if (Object.values(errors.value).some(Boolean)) {
    await nextTick();
    return focusFirstError();
  }

  busy.value = true;
  try {
    await register({
      name: form.name.trim(),
      email: form.email.trim(),
      phone: form.phone.trim() || null,
      password: form.password,
      password_confirmation: form.password_confirmation,
    });
    router.replace(safeRedirect(route.query.redirect, '/account?welcome=1'));
  } catch (e) {
    errors.value = fieldErrors(e);
    if (!Object.keys(errors.value).length) error.value = e.message;
    await nextTick();
    focusFirstError();
  } finally {
    busy.value = false;
  }
}
</script>
