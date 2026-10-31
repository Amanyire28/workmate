<template>
  <AuthCard title="Sign in" subtitle="Use the email address and password for your WorkMate account." :error="error">
    <form class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <FormField
        v-model="form.email"
        type="email"
        label="Email address"
        autocomplete="email"
        inputmode="email"
        required
        :error-message="errors.email"
      />
      <FormField
        v-model="form.password"
        type="password"
        label="Password"
        autocomplete="current-password"
        required
        :error-message="errors.password"
      />

      <div class="flex items-center justify-between gap-3">
        <label class="inline-flex min-h-11 items-center gap-2.5 text-[15px] text-ink">
          <input v-model="form.remember" type="checkbox" class="h-5 w-5 accent-primary" />
          Keep me signed in
        </label>
        <router-link to="/forgot-password" class="wm-link text-[15px]">Forgot password?</router-link>
      </div>

      <PrimaryButton type="submit" :loading="busy">Sign in</PrimaryButton>
    </form>

    <template #footer>
      New to WorkMate?
      <router-link :to="{ path: '/register', query: route.query.redirect ? { redirect: route.query.redirect } : {} }" class="wm-link font-medium">Create an account</router-link>
    </template>
  </AuthCard>
</template>

<script setup>
import { reactive, ref, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AuthCard from '@/components/AuthCard.vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { signIn, safeRedirect } from '@/stores/auth.js';
import { fieldErrors } from '@/api/client.js';
import { focusFirstError } from '@/utils/forms.js';

const route = useRoute();
const router = useRouter();

const form = reactive({ email: '', password: '', remember: false });
const errors = ref({});
const error = ref('');
const busy = ref(false);

async function submit() {
  errors.value = {
    email: form.email.trim() ? '' : 'Enter your email address.',
    password: form.password ? '' : 'Enter your password.',
  };
  error.value = '';
  if (errors.value.email || errors.value.password) {
    await nextTick();
    return focusFirstError();
  }

  busy.value = true;
  try {
    await signIn({ email: form.email.trim(), password: form.password, remember: form.remember });
    router.replace(safeRedirect(route.query.redirect));
  } catch (e) {
    form.password = '';
    errors.value = fieldErrors(e);
    if (!Object.keys(errors.value).length) error.value = e.message;
    await nextTick();
    focusFirstError();
  } finally {
    busy.value = false;
  }
}
</script>
