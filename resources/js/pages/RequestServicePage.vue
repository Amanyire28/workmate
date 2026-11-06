<template>
  <div class="wm-container wm-container-form wm-page !pt-3 lg:!pt-6">
    <EmptyState
      v-if="!provider"
      icon="bi-person-x"
      title="Worker not found"
      message="This profile doesn’t exist, so a request can’t be sent."
    >
      <router-link to="/browse" class="wm-btn wm-btn-primary !min-h-12">Browse workers</router-link>
    </EmptyState>

    <template v-else>
      <BackLink :to="`/provider/${provider.id}`">Back to profile</BackLink>

      <!-- Success -->
      <section v-if="isOwnProvider(provider.id)" class="wm-card wm-card-lg mt-2 text-center flex flex-col items-center gap-3" role="status">
        <i class="bi bi-person-badge text-3xl text-primary" aria-hidden="true"></i>
        <h1 class="wm-h2">This is your own profile</h1>
        <p class="text-sm text-muted">You can’t send a request to yourself. Requests customers send you are on My Work.</p>
        <router-link to="/activity?tab=received" class="wm-btn wm-btn-primary wm-btn-lg">Requests you’ve received</router-link>
      </section>

      <section v-else-if="sent" class="wm-card wm-card-lg mt-2 text-center flex flex-col items-center gap-3" role="status" aria-live="polite">
        <span class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-success-tint text-success text-3xl" aria-hidden="true">
          <i class="bi bi-check-lg"></i>
        </span>
        <h1 class="wm-h2">Request sent</h1>
        <p class="text-sm text-success">{{ name }} has your request for {{ categoryLabel(form.service) }}.</p>
        <p class="text-sm text-muted max-w-[420px]">
          They’ll reply with availability and a price. Track it under My Work.
        </p>
        <div class="mt-2 flex flex-col sm:flex-row gap-3 w-full sm:w-auto">
          <router-link to="/activity?tab=requests" class="wm-btn wm-btn-primary wm-btn-lg">View my requests</router-link>
          <router-link :to="`/provider/${provider.id}`" class="wm-btn wm-btn-secondary wm-btn-lg">Back to profile</router-link>
        </div>
      </section>

      <template v-else>
        <h1 class="wm-h1 mt-2">Request a service</h1>
        <p class="wm-lead mt-1.5">Tell {{ name }} what you need. They’ll reply with availability and a price.</p>

        <!-- Provider summary -->
        <div class="mt-5 wm-card !p-3 flex items-center gap-3">
          <UserAvatar :name="name" :src="provider.avatarUrl" :size="44" />
          <div class="min-w-0">
            <p class="text-[15px] font-semibold text-ink truncate">{{ name }}</p>
            <p class="wm-meta truncate">{{ tradeLabel(provider) }} · {{ provider.area }}</p>
          </div>
        </div>

        <form class="wm-card wm-card-lg mt-4 flex flex-col gap-6" novalidate @submit.prevent="submit">
          <div class="flex flex-col gap-4">
            <FormField
              v-if="provider.services.length > 1"
              v-model="form.service"
              type="select"
              label="Service needed"
              placeholder="Choose a service"
              required
              :error-message="errors.service"
            >
              <option v-for="id in provider.services" :key="id" :value="id">{{ categoryLabel(id) }}</option>
            </FormField>

            <FormField
              v-model="form.details"
              type="textarea"
              label="Describe the job"
              placeholder="e.g. Kitchen sink is blocked and water drains very slowly."
              required
              :min-height="140"
              :error-message="errors.details"
              hint="A few sentences is enough. Mention size, materials and access."
            />
          </div>

          <div class="flex flex-col gap-4">
            <PlacePicker
              v-model="form.area"
              id="request-area"
              label="Where is the job?"
              required
              :clearable="false"
              :error-message="errors.area"
            />

            <FormField
              v-model="form.when"
              type="select"
              label="When do you need it?"
              placeholder="Choose a time"
              required
              :error-message="errors.when"
            >
              <option v-for="opt in whenOptions" :key="opt" :value="opt">{{ opt }}</option>
            </FormField>

            <FormField
              v-model="form.budget"
              type="number"
              inputmode="numeric"
              min="0"
              step="1000"
              label="Your budget"
              optional
              prefix="UGX"
              placeholder="e.g. 50000"
              :error-message="errors.budget"
            />
          </div>

          <PhotoPicker
            v-model="photos"
            :disabled="sending"
            :hint="`A photo of the problem helps ${name} reply with a better price. Only ${name} and WorkMate can see them; hidden details like location are removed.`"
          />

          <p v-if="sendError" class="wm-notice wm-notice-error" role="alert">
            <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ sendError }}</span>
          </p>

          <!-- Upload progress, so a slow connection doesn't look stuck -->
          <div v-if="sending && photos.length" class="flex flex-col gap-1.5" role="status" aria-live="polite">
            <div class="h-1.5 overflow-hidden rounded-full bg-line">
              <div class="h-full rounded-full bg-primary transition-[width] duration-200" :style="{ width: `${Math.round(progress * 100)}%` }"></div>
            </div>
            <p class="wm-meta">{{ progress < 1 ? `Uploading photos… ${Math.round(progress * 100)}%` : 'Sending your request…' }}</p>
          </div>

          <PrimaryButton type="submit" :loading="sending" :disabled="preparing">
            {{ sending ? 'Sending…' : preparing ? 'Preparing photos…' : sendError ? 'Try again' : 'Send request' }}
          </PrimaryButton>
        </form>
      </template>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import { isOwnProvider } from '@/stores/auth.js';
import { useRoute } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import FormField from '@/components/FormField.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { providers } from '@/stores/catalog.js';
import PlacePicker from '@/components/PlacePicker.vue';
import { displayName, tradeLabel, categoryLabel } from '@/utils/ui.js';
import { selectedArea } from '@/stores/location.js';
import { upload, fieldErrors } from '@/api/client.js';
import { focusFirstError } from '@/utils/forms.js';
import { formWithPhotos, newClientToken, releasePhotos } from '@/utils/photos.js';

const route = useRoute();
const provider = computed(() => providers.find(p => p.id === Number(route.params.id)));
const name = computed(() => (provider.value ? displayName(provider.value) : ''));

const whenOptions = ['As soon as possible', 'This week', 'Next week', 'I’m flexible'];

const form = reactive({
  service: provider.value?.services.length === 1 ? provider.value.services[0] : '',
  details: '',
  area:    selectedArea.value,
  when:    '',
  budget:  '',
});
const errors = reactive({ service: '', details: '', area: '', when: '', budget: '' });

// Opened straight from a link, the provider can arrive after the form: a single service is
// chosen for the customer (its field isn't shown, so it can't be left empty)
watch(provider, (p) => {
  if (p && p.services.length === 1 && !form.service) form.service = p.services[0];
}, { immediate: true });
const sending = ref(false);
const sent = ref(false);
const sendError = ref('');
const photos = ref([]);
const progress = ref(0);
const preparing = computed(() => photos.value.some(p => p.preparing));
// The same key for every try of this request, so a retry after a dropped connection can't send it twice
const clientToken = newClientToken();

function validate() {
  errors.service = form.service ? '' : 'Choose the service you need.';
  errors.details = form.details.trim().length >= 15 ? '' : 'Describe the job in at least 15 characters.';
  errors.area    = form.area ? '' : 'Choose where the job is.';
  errors.when    = form.when ? '' : 'Choose when you need it.';
  errors.budget  = form.budget === '' || Number(form.budget) > 0 ? '' : 'Enter an amount above 0, or leave it empty.';
  return !Object.values(errors).some(Boolean);
}

async function submit() {
  if (sending.value || preparing.value) return;
  if (!validate()) {
    await nextTick();
    focusFirstError();
    return;
  }
  sending.value = true;
  sendError.value = '';
  progress.value = 0;
  try {
    const body = formWithPhotos({
      category: form.service,
      description: form.details.trim(),
      area: form.area,
      preferred_time: form.when,
      budget: form.budget === '' ? null : Number(form.budget),
      client_token: clientToken,
    }, photos.value.map(p => p.file));
    await upload(`/providers/${provider.value.id}/requests`, body, { onProgress: (p) => { progress.value = p; } });
    releasePhotos(photos.value);
    photos.value = [];
    sent.value = true;
    window.scrollTo({ top: 0 });
  } catch (e) {
    const fields = fieldErrors(e);
    Object.assign(errors, {
      service: fields.category || '', details: fields.description || '', area: fields.area || '',
      when: fields.preferred_time || '', budget: fields.budget || '',
    });
    const photoProblem = Object.entries(fields).find(([k]) => k.startsWith('photos'));
    if (photoProblem) sendError.value = photoProblem[1];
    else if (!Object.keys(fields).length) {
      // Nothing is lost: the details and photos stay, and trying again can't send it twice
      sendError.value = e.code === 'network_error' ? 'The connection dropped. Your details and photos are still here: press Try again.'
        : e.status === 413 ? 'The photos are too large to send together. Remove one or two and try again.'
        : e.message;
    }
    await nextTick();
    focusFirstError();
  } finally {
    sending.value = false;
  }
}
</script>
