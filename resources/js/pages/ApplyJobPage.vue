<template>
  <div class="wm-container wm-container-form wm-page !pt-3 lg:!pt-6">
    <EmptyState
      v-if="!job"
      icon="bi-briefcase"
      title="Job not found"
      message="This job doesn’t exist or has been removed."
    >
      <router-link to="/find-work" class="wm-btn wm-btn-primary !min-h-12">Browse open jobs</router-link>
    </EmptyState>

    <template v-else>
      <BackLink :to="`/job/${job.id}`">Back to job</BackLink>

      <!-- Success -->
      <section v-if="sent" class="wm-card wm-card-lg mt-2 text-center flex flex-col items-center gap-3" role="status" aria-live="polite">
        <span class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-success-tint text-success text-3xl" aria-hidden="true">
          <i class="bi bi-check-lg"></i>
        </span>
        <h1 class="wm-h2">Application sent</h1>
        <p class="text-sm text-success">Your application for “{{ job.title }}” was sent.</p>
        <p class="text-sm text-muted max-w-[420px]">
          You’ll see the client’s reply in My Work.
        </p>
        <div class="mt-2 flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
          <router-link to="/activity" class="wm-btn wm-btn-primary wm-btn-lg">Go to My Work</router-link>
          <router-link to="/find-work" class="wm-btn wm-btn-secondary wm-btn-lg">Find more jobs</router-link>
        </div>
      </section>

      <template v-else>
        <h1 class="wm-h1 mt-2">Apply for this job</h1>
        <p class="wm-lead mt-1.5">Keep it short and honest. Say why you’re a good fit and when you can start.</p>

        <!-- Job summary -->
        <section class="wm-card mt-5" aria-labelledby="apply-job-title">
          <h2 id="apply-job-title" class="text-base font-semibold text-ink leading-snug">{{ job.title }}</h2>
          <div class="mt-3">
            <JobSummaryList :job="job" />
          </div>
        </section>

        <!-- Who can't apply, and what to do instead -->
        <div v-if="blocker" class="wm-card wm-card-lg mt-4 flex flex-col gap-3">
          <p class="wm-notice wm-notice-info">
            <i :class="['bi', blocker.icon]" aria-hidden="true"></i><span>{{ blocker.text }}</span>
          </p>
          <router-link v-if="blocker.to" :to="blocker.to" class="wm-btn wm-btn-primary wm-btn-lg">{{ blocker.action }}</router-link>
        </div>

        <form v-else class="wm-card wm-card-lg mt-4 flex flex-col gap-4" novalidate @submit.prevent="submit">
          <FormField
            v-model="form.message"
            type="textarea"
            label="Your message"
            placeholder="e.g. I’ve done similar plumbing repairs for 5 years and can bring my own tools."
            required
            :min-height="160"
            :error-message="errors.message"
            :hint="`${form.message.trim().length}/20 characters minimum`"
          />

          <FormField
            v-model="form.start"
            type="select"
            label="When can you start?"
            placeholder="Choose a time"
            required
            :error-message="errors.start"
          >
            <option v-for="opt in startOptions" :key="opt" :value="opt">{{ opt }}</option>
          </FormField>

          <FormField
            v-model="form.price"
            type="number"
            inputmode="numeric"
            min="0"
            step="1000"
            label="Your price"
            optional
            prefix="UGX"
            :placeholder="job.budget ? String(job.budget) : 'e.g. 50000'"
            :hint="job.budget ? `Leave empty to accept the posted budget (${formatPrice(job.budget)}).` : 'Suggest a price for this job.'"
            :error-message="errors.price"
          />

          <p v-if="sendError" class="wm-notice wm-notice-error" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ sendError }}</span>
          </p>

          <PrimaryButton type="submit" class="mt-2" :loading="sending">
            {{ sending ? 'Sending…' : 'Send application' }}
          </PrimaryButton>
        </form>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import JobSummaryList from '@/components/JobSummaryList.vue';
import { jobs, refreshJobs } from '@/stores/catalog.js';
import { auth, isProvider } from '@/stores/auth.js';
import { api, fieldErrors } from '@/api/client.js';
import { formatPrice } from '@/utils/ui.js';
import { focusFirstError } from '@/utils/forms.js';

const route = useRoute();
const job = computed(() => jobs.find(j => j.id === Number(route.params.id)));

const startOptions = ['Today', 'Tomorrow', 'This week', 'On the date requested'];

const form = reactive({ message: '', start: '', price: '' });
const errors = reactive({ message: '', start: '', price: '' });
const sending = ref(false);
const sent = ref(false);
const sendError = ref('');

function validate() {
  errors.message = form.message.trim().length >= 20 ? '' : 'Write at least 20 characters about why you’re a good fit.';
  errors.start   = form.start ? '' : 'Choose when you can start.';
  errors.price   = form.price === '' || Number(form.price) > 0 ? '' : 'Enter an amount above 0, or leave it empty.';
  return !Object.values(errors).some(Boolean);
}

async function submit() {
  if (sending.value) return;           // guard against double submit
  if (!validate()) {
    await nextTick();
    focusFirstError();
    return;
  }
  sending.value = true;
  sendError.value = '';
  try {
    await api(`/jobs/${job.value.id}/applications`, { method: 'POST', body: {
      message: form.message.trim(),
      start_time: form.start,
      amount: form.price === '' ? null : Number(form.price),
    } });
    sent.value = true;
    refreshJobs().catch(() => { /* counts refresh next time */ });
    window.scrollTo({ top: 0 });
  } catch (e) {
    const fields = fieldErrors(e);
    Object.assign(errors, { message: fields.message || '', start: fields.start_time || '', price: fields.amount || '' });
    if (!Object.keys(fields).length) sendError.value = e.message;
    await nextTick();
    focusFirstError();
  } finally {
    sending.value = false;
  }
}

const blocker = computed(() => {
  if (job.value?.isMine) {
    return { icon: 'bi-info-circle', text: 'This is your own job. Providers who apply will appear in My Work.', to: '/activity?tab=posted', action: 'Go to My Work' };
  }
  if (job.value?.hasApplied) {
    return { icon: 'bi-check-circle', text: 'You’ve already applied for this job.', to: '/activity?tab=applications', action: 'See your applications' };
  }
  if (!isProvider.value) {
    const status = auth.user?.provider_status;
    const waiting = status === 'pending_review' || status === 'more_info_required';
    return {
      icon: 'bi-person-badge',
      text: waiting
        ? 'Only approved providers can apply for jobs. Your application to offer services is still being handled.'
        : 'Only approved providers can apply for jobs. Apply to offer your services with the account you already have.',
      to: '/start-earning',
      action: waiting ? 'Check your application' : 'Start earning on WorkMate',
    };
  }
  return null;
});
</script>
