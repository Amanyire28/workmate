<template>
  <div class="wm-container wm-container-form wm-page">

    <!-- Success -->
    <section v-if="submitted" class="wm-card wm-card-lg text-center flex flex-col items-center gap-3" role="status" aria-live="polite">
      <span class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-success-tint text-success text-3xl" aria-hidden="true">
        <i class="bi bi-check-lg"></i>
      </span>
      <h1 class="wm-h2">Job posted</h1>
      <p class="text-sm text-success">“{{ form.title }}” is ready for workers near {{ areaLabel(form.area) }}.</p>
      <p class="text-sm text-muted max-w-[420px]">
        Providers can now see it in Find work. Their applications will appear in My Work.
      </p>
      <div class="mt-2 flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
        <router-link to="/activity" class="wm-btn wm-btn-primary wm-btn-lg">Go to My Work</router-link>
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg" @click="reset">Post another job</button>
      </div>
    </section>

    <template v-else>
      <h1 class="wm-h1">Post a job</h1>
      <p class="wm-lead mt-1.5">Describe the work and workers near you can apply.</p>

      <p class="wm-notice wm-notice-info mt-4">
        <i class="bi bi-lightbulb" aria-hidden="true"></i>
        <span>
          Need someone soon? It’s usually faster to
          <router-link to="/browse" class="wm-hit inline-block font-semibold underline underline-offset-2">find a provider</router-link>
          and request them directly.
        </span>
      </p>

      <!-- Progress -->
      <div class="mt-5">
        <div class="flex items-center justify-between wm-meta mb-2">
          <span class="font-medium text-ink">{{ stepTitles[currentStep - 1] }}</span>
          <span>Step {{ currentStep }} of {{ totalSteps }}</span>
        </div>
        <div
          class="flex gap-1.5"
          role="progressbar"
          :aria-valuenow="currentStep"
          aria-valuemin="1"
          :aria-valuemax="totalSteps"
          :aria-valuetext="`Step ${currentStep} of ${totalSteps}: ${stepTitles[currentStep - 1]}`"
        >
          <span
            v-for="step in totalSteps"
            :key="step"
            class="flex-1 h-1.5 rounded-full transition-colors"
            :class="step <= currentStep ? 'bg-primary' : 'bg-line'"
          ></span>
        </div>
      </div>

      <form class="wm-card wm-card-lg mt-5" novalidate @submit.prevent="currentStep < totalSteps ? nextStep() : submitJob()">
        <!-- Step 1: What -->
        <fieldset v-if="currentStep === 1" class="flex flex-col gap-4">
          <legend class="sr-only">{{ stepTitles[0] }}</legend>
          <FormField
            v-model="form.title"
            label="Job title"
            placeholder="e.g. Fix a leaking tap"
            required
            :error-message="errors.title"
          />

          <div role="group" aria-labelledby="category-label">
            <p id="category-label" class="wm-label">
              Service type <span class="text-error" aria-hidden="true">*</span>
            </p>
            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2">
              <ServiceCategoryCard
                v-for="cat in categories"
                :key="cat.id"
                :category="cat"
                :active="form.category === cat.id"
                compact
                @select="form.category = $event; errors.category = ''"
              />
            </div>
            <p v-if="errors.category" class="wm-field-error" role="alert">
              <i class="bi bi-exclamation-circle mr-1" aria-hidden="true"></i>{{ errors.category }}
            </p>
          </div>

          <FormField
            v-model="form.description"
            type="textarea"
            label="Description"
            optional
            placeholder="Add details that help workers understand the job."
            :min-height="150"
          />

          <PhotoPicker
            v-model="photos"
            :disabled="submitting"
            hint="Photos help workers judge the job and price it. Only approved providers who might take the job, and WorkMate, can see them; hidden details like location are removed."
          />
        </fieldset>

        <!-- Step 2: Where & when -->
        <fieldset v-else-if="currentStep === 2" class="flex flex-col gap-4">
          <legend class="sr-only">{{ stepTitles[1] }}</legend>
          <PlacePicker
            v-model="form.area"
            id="job-area"
            label="Where is the work?"
            required
            :clearable="false"
            :error-message="errors.area"
          />

          <FormField
            v-model="form.date"
            type="date"
            label="When do you need it done?"
            optional
            :min="today"
            hint="Leave empty if you’re flexible."
          />

          <FormField v-model="form.workersNeeded" type="select" label="How many workers?">
            <option value="1">1 worker</option>
            <option value="2">2 workers</option>
            <option value="3">3 workers</option>
            <option value="4">4 workers</option>
            <option value="5">5 workers</option>
            <option value="6+">6 or more</option>
          </FormField>
        </fieldset>

        <!-- Step 3: Budget -->
        <fieldset v-else-if="currentStep === 3" class="flex flex-col gap-4">
          <legend class="sr-only">{{ stepTitles[2] }}</legend>
          <p class="text-sm text-muted">Not sure? Leave it empty and workers will suggest a price.</p>
          <FormField
            v-model="form.budget"
            type="number"
            inputmode="numeric"
            min="0"
            step="1000"
            label="Budget"
            optional
            prefix="UGX"
            placeholder="e.g. 50000"
            :error-message="errors.budget"
          />
          <FormField v-model="form.budgetBasis" type="select" label="This budget is">
            <option value="fixed">A fixed total</option>
            <option value="per person">Per person</option>
            <option value="per day">Per day</option>
            <option value="negotiable">Negotiable</option>
          </FormField>
        </fieldset>

        <!-- Step 4: Review -->
        <fieldset v-else class="flex flex-col gap-4">
          <legend class="sr-only">{{ stepTitles[3] }}</legend>
          <dl class="divide-y divide-line border border-line rounded-2xl bg-white/50">
            <div v-for="row in reviewRows" :key="row.label" class="flex flex-col sm:flex-row sm:gap-4 px-4 py-3">
              <dt class="wm-meta sm:w-36 shrink-0">{{ row.label }}</dt>
              <dd class="text-[15px] text-ink break-words min-w-0">{{ row.value }}</dd>
            </div>
          </dl>
          <button type="button" class="wm-link self-start min-h-11" @click="currentStep = 1">Edit details</button>
          <PaymentNotice />
        </fieldset>

        <p v-if="submitError" class="wm-notice wm-notice-error mt-5" role="alert">
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ submitError }}</span>
        </p>

        <div v-if="submitting && photos.length" class="mt-5 flex flex-col gap-1.5" role="status" aria-live="polite">
          <div class="h-1.5 overflow-hidden rounded-full bg-line">
            <div class="h-full rounded-full bg-primary transition-[width] duration-200" :style="{ width: `${Math.round(progress * 100)}%` }"></div>
          </div>
          <p class="wm-meta">{{ progress < 1 ? `Uploading photos… ${Math.round(progress * 100)}%` : 'Posting your job…' }}</p>
        </div>

        <div class="flex gap-3 mt-6">
          <button v-if="currentStep > 1" type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" @click="currentStep--">
            Back
          </button>
          <PrimaryButton type="submit" class="flex-[2]" :loading="submitting" :disabled="currentStep === totalSteps && preparing">
            {{ currentStep < totalSteps ? 'Continue' : submitting ? 'Posting…' : preparing ? 'Preparing photos…' : submitError ? 'Try again' : 'Post job' }}
          </PrimaryButton>
        </div>
      </form>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, nextTick } from 'vue';
import FormField from '@/components/FormField.vue';
import PaymentNotice from '@/components/PaymentNotice.vue';
import ServiceCategoryCard from '@/components/ServiceCategoryCard.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import { categories, areaLabel, refreshJobs } from '@/stores/catalog.js';
import PlacePicker from '@/components/PlacePicker.vue';
import { upload, fieldErrors } from '@/api/client.js';
import { formWithPhotos, newClientToken, releasePhotos } from '@/utils/photos.js';
import { categoryLabel, formatPrice } from '@/utils/ui.js';
import { selectedArea } from '@/stores/location.js';
import { focusFirstError } from '@/utils/forms.js';

const totalSteps = 4;
const stepTitles = ['What do you need?', 'Where and when?', 'Budget', 'Check and post'];
const currentStep = ref(1);
const submitting = ref(false);
const submitted = ref(false);
const today = new Date().toISOString().slice(0, 10);

const blank = () => ({
  title: '', category: '', description: '',
  area: selectedArea.value, date: '', workersNeeded: '1',
  budget: '', budgetBasis: 'fixed',
});
const form = reactive(blank());
const errors = reactive({ title: '', category: '', area: '', budget: '' });
const photos = ref([]);
const progress = ref(0);
const preparing = computed(() => photos.value.some(p => p.preparing));
// One key per job being written, so a retry after a dropped connection can't post it twice
let clientToken = newClientToken();

const reviewRows = computed(() => [
  { label: 'Job',            value: form.title },
  { label: 'Service',        value: form.category ? categoryLabel(form.category) : '—' },
  { label: 'Description',    value: form.description.trim() || 'None' },
  { label: 'Location',       value: areaLabel(form.area) },
  { label: 'Date',           value: form.date || 'Flexible' },
  { label: 'Workers needed', value: form.workersNeeded },
  { label: 'Budget',         value: form.budget ? `${formatPrice(form.budget)} (${form.budgetBasis})` : 'Open to offers' },
  { label: 'Photos',         value: photos.value.length ? `${photos.value.length} ${photos.value.length === 1 ? 'photo' : 'photos'}` : 'None' },
]);

function validateStep() {
  Object.keys(errors).forEach(k => { errors[k] = ''; });
  if (currentStep.value === 1) {
    if (!form.title.trim()) errors.title = 'Enter a job title.';
    if (!form.category)     errors.category = 'Choose a service type.';
  }
  if (currentStep.value === 2 && !form.area) errors.area = 'Choose where the work is.';
  if (currentStep.value === 3 && form.budget !== '' && !(Number(form.budget) > 0)) {
    errors.budget = 'Enter an amount above 0, or leave it empty.';
  }
  return !Object.values(errors).some(Boolean);
}

async function nextStep() {
  if (validateStep()) {
    currentStep.value++;
  } else {
    await nextTick();
    focusFirstError();
  }
}

const submitError = ref('');

async function submitJob() {
  if (submitting.value || preparing.value) return;
  submitting.value = true;
  submitError.value = '';
  progress.value = 0;
  try {
    const body = formWithPhotos({
      category: form.category,
      title: form.title.trim(),
      description: form.description.trim() || null,
      area: form.area,
      preferred_time: form.date || null,
      workers_needed: form.workersNeeded === '6+' ? 6 : Number(form.workersNeeded),
      budget: form.budget === '' ? null : Number(form.budget),
      budget_basis: form.budget === '' ? null : form.budgetBasis,
      client_token: clientToken,
    }, photos.value.map(p => p.file));
    await upload('/jobs', body, { onProgress: (p) => { progress.value = p; } });
    refreshJobs().catch(() => { /* Find work reloads on its own next time */ });
    submitted.value = true;
    window.scrollTo({ top: 0 });
  } catch (e) {
    // Field problems point back to the step where the field is
    const errors = Object.values(fieldErrors(e));
    // Nothing is lost: the job and its photos stay, and trying again can't post it twice
    submitError.value = errors.length ? errors.join(' ')
      : e.code === 'network_error' ? 'The connection dropped. Your job and photos are still here: press Try again.'
      : e.status === 413 ? 'The photos are too large to send together. Remove one or two and try again.'
      : e.message;
  } finally {
    submitting.value = false;
  }
}

function reset() {
  Object.assign(form, blank());
  releasePhotos(photos.value);
  photos.value = [];
  clientToken = newClientToken();
  currentStep.value = 1;
  submitted.value = false;
}
</script>
