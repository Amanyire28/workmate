<template>
  <AuthCard
    title="Create your account"
    subtitle="One account lets you hire people now and offer your own services later."
    :error="error"
  >
    <!-- Came through someone's invitation link -->
    <p v-if="invitation.inviter" class="mb-4 flex items-center gap-2.5 rounded-2xl bg-primary-tint px-3.5 py-2.5 text-[14px] text-ink">
      <i class="bi bi-person-heart text-lg text-primary" aria-hidden="true"></i>
      <span><span class="font-semibold">{{ invitation.inviter }}</span> invited you to WorkMate.</span>
    </p>

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

      <div class="flex flex-col gap-2">
        <label class="flex items-start gap-3 rounded-2xl border bg-white p-3.5" :class="errors.accept_terms ? 'border-[#B91C1C]' : 'border-line'">
          <input v-model="form.accept_terms" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-[#1769D2]" aria-describedby="accept-terms-error" :aria-invalid="!!errors.accept_terms || undefined" />
          <span class="text-[14px] text-ink">
            I am {{ appSettings.minimumAge }} or older. I agree to the
            <router-link to="/legal/terms" class="wm-link font-medium">Terms and Conditions</router-link>
            and have read the <router-link to="/legal/privacy" class="wm-link font-medium">Privacy Policy</router-link>.
          </span>
        </label>
        <p v-if="errors.accept_terms" id="accept-terms-error" class="text-[13px] text-[#B91C1C]">{{ errors.accept_terms }}</p>
        <label class="flex items-start gap-3 px-3.5 py-1">
          <input v-model="form.marketing" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-[#1769D2]" />
          <span class="text-[14px] text-ink-soft">Send me occasional news and offers from WorkMate (optional).</span>
        </label>
      </div>

      <PrimaryButton type="submit" :loading="busy">Create account</PrimaryButton>
      <p class="wm-meta text-center">Terms and conditions apply.</p>
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
import { appSettings } from '@/stores/catalog.js';
import { invitation } from '@/stores/referral.js';

const route = useRoute();
const router = useRouter();

const form = reactive({ name: '', email: '', phone: '', password: '', password_confirmation: '', accept_terms: false, marketing: false });
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
    accept_terms: form.accept_terms ? '' : 'Tick the box to confirm your age and agree to the Terms and Conditions.',
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
      accept_terms: form.accept_terms,
      marketing: form.marketing,
    });
    router.replace(safeRedirect(route.query.redirect, '/get-started'));
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
