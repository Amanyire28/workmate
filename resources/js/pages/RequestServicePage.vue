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
        <h1 class="wm-h2">Request sent!</h1>
        <p class="text-sm text-success">{{ name }} has your request for {{ listing ? listing.title : categoryLabel(form.service) }}.</p>
        <p class="text-sm text-muted max-w-[420px]">
          <template v-if="form.when.timing === 'scheduled'">Your provider will review your request and confirm whether they can attend at the selected time.</template>
          <template v-else>Your provider will review your request and tell you when they can come.</template>
          It’s also in your chat with them. Track it under My Work.
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

        <!-- Busy or paused: say so before they fill anything in -->
        <div v-if="provider.workStatus === 'busy'" class="mt-4 flex items-start gap-3 rounded-2xl border border-[#F59E0B]/30 bg-[#FFFBEB] px-4 py-3">
          <i class="bi bi-hourglass-split text-lg text-[#B45309] mt-0.5" aria-hidden="true"></i>
          <div>
            <p class="text-[15px] font-semibold text-ink">Currently working on another assignment</p>
            <p class="text-[13.5px] text-[#475569]">You can still book this provider for a later date. Choose when you need the service, and they will confirm their availability.</p>
          </div>
        </div>
        <p v-else-if="provider.workStatus === 'paused'" class="wm-notice wm-notice-info mt-4">
          <i class="bi bi-pause-circle" aria-hidden="true"></i><span>This provider is not accepting new requests at the moment. Try again later, or find another provider.</span>
        </p>

        <form class="wm-card wm-card-lg mt-4 flex flex-col gap-6" novalidate @submit.prevent="submit">
          <div class="flex flex-col gap-4">
            <!-- Chosen from their Services & Prices: the customer still describes the exact job -->
            <div v-if="listing" class="flex items-start gap-3 rounded-xl border border-primary/25 bg-primary-tint/60 px-3.5 py-3">
              <i class="bi bi-tag text-lg text-primary mt-0.5" aria-hidden="true"></i>
              <div class="min-w-0 flex-1">
                <p class="text-[15px] font-semibold text-ink">{{ listing.title }}</p>
                <p class="text-[13.5px] text-muted">{{ servicePriceLabel(listing) }}<template v-if="listing.pricing_type !== 'quote'"> · a guide; you agree the final price</template></p>
              </div>
              <button type="button" class="wm-link !text-[13px] shrink-0 min-h-8" @click="clearListing">Change</button>
            </div>

            <FormField
              v-if="provider.services.length > 1 && !listing"
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

          <div class="flex flex-col gap-5">
            <WhenField v-model="form.when" :error-message="errors.when"
              :allow-asap="provider.acceptsImmediate !== false && provider.workStatus !== 'busy'" :allow-scheduled="provider.acceptsBookings !== false" />

            <JobLocationField v-model="form.where" id="request-area" :error-message="errors.area" />

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

          <!-- What will be sent, in plain words -->
          <dl v-if="summaryReady" class="rounded-xl bg-[#F8FAFC] px-4 py-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-[14px]" aria-label="Your request">
            <dt class="text-muted">Service</dt><dd class="text-ink font-medium">{{ listing ? listing.title : categoryLabel(form.service) }}</dd>
            <template v-if="form.when.timing === 'scheduled'">
              <dt class="text-muted">Date</dt><dd class="text-ink">{{ formatDay(whenIso) }}</dd>
              <dt class="text-muted">Time</dt><dd class="text-ink">{{ formatTime(whenIso) }}</dd>
            </template>
            <template v-else><dt class="text-muted">When</dt><dd class="text-ink">As soon as possible</dd></template>
            <dt class="text-muted">Location</dt><dd class="text-ink">{{ areaLabel(form.where.area) }}<template v-if="form.where.landmark">, {{ form.where.landmark }}</template></dd>
          </dl>

          <PaymentNotice />

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

          <PrimaryButton type="submit" :loading="sending" :disabled="preparing || provider.workStatus === 'paused'">
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
import PaymentNotice from '@/components/PaymentNotice.vue';
import EmptyState from '@/components/EmptyState.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import FormField from '@/components/FormField.vue';
import PhotoPicker from '@/components/PhotoPicker.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { providers } from '@/stores/catalog.js';
import { displayName, tradeLabel, categoryLabel, servicePriceLabel } from '@/utils/ui.js';
import { areaLabel } from '@/stores/catalog.js';
import WhenField from '@/components/booking/WhenField.vue';
import JobLocationField from '@/components/booking/JobLocationField.vue';
import { formatDay, formatTime } from '@/utils/booking.js';
import { selectedArea, currentAreaId } from '@/stores/location.js';
import { api, upload, fieldErrors } from '@/api/client.js';
import { focusFirstError } from '@/utils/forms.js';
import { formWithPhotos, newClientToken, releasePhotos } from '@/utils/photos.js';

const route = useRoute();
const provider = computed(() => providers.find(p => p.id === Number(route.params.id)));
const name = computed(() => (provider.value ? displayName(provider.value) : ''));

const form = reactive({
  service: provider.value?.services.length === 1 ? provider.value.services[0] : '',
  details: '',
  // Where the work is: starts from their picked place or where they are now; theirs to change
  where:   { area: selectedArea.value || currentAreaId.value, pin: null, landmark: '', directions: '' },
  // When: as soon as possible, or a date and time (Uganda time)
  when:    { timing: '', date: '', time: '', duration: '' },
  budget:  '',
});
const whenIso = computed(() => (form.when.date && form.when.time ? `${form.when.date}T${form.when.time}:00+03:00` : ''));
const summaryReady = computed(() => !!form.where.area && (form.when.timing === 'asap' || !!whenIso.value));
// A busy provider can only be booked for later
watch(provider, (p) => {
  if (p && (p.workStatus === 'busy' || p.acceptsImmediate === false) && !form.when.timing) form.when = { ...form.when, timing: 'scheduled' };
}, { immediate: true });
const errors = reactive({ service: '', details: '', area: '', when: '', budget: '' });

// A service picked from the provider's Services & Prices (?service=ID): its type of work is filled in
const listing = ref(null);
async function loadListing() {
  const id = Number(route.query.service);
  if (!id || !provider.value) return;
  try {
    const list = (await api(`/providers/${provider.value.id}/services`)).data;
    const found = list.find(s => s.id === id);
    if (!found) return;
    listing.value = found;
    if (found.category && provider.value.services.includes(found.category)) form.service = found.category;
  } catch { /* the form works without it */ }
}
watch(() => [provider.value?.id, route.query.service], loadListing, { immediate: true });
function clearListing() {
  listing.value = null;
  if (provider.value?.services.length > 1) form.service = '';
}

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
  errors.area    = form.where.area ? '' : 'Choose where the job is.';
  errors.when    = !form.when.timing ? 'Choose when you need it.'
    : form.when.timing === 'scheduled' && !(form.when.date && form.when.time) ? 'Choose a date and a time.' : '';
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
      area: form.where.area,
      timing: form.when.timing,
      scheduled_start: form.when.timing === 'scheduled' ? `${form.when.date} ${form.when.time}` : null,
      duration_minutes: form.when.timing === 'scheduled' && form.when.duration ? Number(form.when.duration) : null,
      // The exact point only when they used their current location for it
      latitude: form.where.pin ? form.where.pin.latitude.toFixed(6) : null,
      longitude: form.where.pin ? form.where.pin.longitude.toFixed(6) : null,
      location_accuracy: form.where.pin ? Math.round(form.where.pin.accuracy || 0) : null,
      landmark: form.where.landmark.trim() || null,
      directions: form.where.directions.trim() || null,
      budget: form.budget === '' ? null : Number(form.budget),
      ...(listing.value ? { service: listing.value.id } : {}),
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
      service: fields.category || '', details: fields.description || '', area: fields.area || fields.latitude || '',
      when: fields.scheduled_start || fields.timing || fields.duration_minutes || fields.preferred_time || '', budget: fields.budget || '',
    });
    // The time is taken, too soon or too far: show it by the time, with the polite message
    if (!Object.keys(fields).length && ['time_taken', 'time_too_soon', 'time_too_far', 'provider_not_immediate', 'provider_not_booking'].includes(e.code)) {
      errors.when = e.message;
      await nextTick();
      focusFirstError();
      return;
    }
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
