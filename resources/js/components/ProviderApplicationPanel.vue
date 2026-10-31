<template>
  <div class="flex flex-col gap-4">
    <div v-if="loading" class="flex items-center gap-2 wm-meta" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>Loading your service details…
    </div>

    <p v-else-if="loadError" class="wm-notice wm-notice-error" role="alert">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <span>{{ loadError }} <button type="button" class="wm-link" @click="load">Try again</button></span>
    </p>

    <template v-else>
      <!-- Where the application stands, in plain words -->
      <p v-if="banner" :class="['wm-notice', `wm-notice-${banner.tone}`]" role="status">
        <i :class="['bi', banner.icon]" aria-hidden="true"></i>
        <span>
          <strong class="font-semibold">{{ banner.title }}</strong>
          <span v-if="banner.text" class="block mt-0.5">{{ banner.text }}</span>
        </span>
      </p>

      <PrimaryButton v-if="profile?.status === 'rejected'" variant="secondary" :loading="busy === 'reopen'" @click="reopen">
        Start a new application
      </PrimaryButton>

      <form v-if="editable" class="flex flex-col gap-4" novalidate @submit.prevent="save(false)">
        <fieldset>
          <legend class="wm-label">I’m offering services as</legend>
          <div class="grid grid-cols-3 gap-2">
            <button
              v-for="pt in PROVIDER_TYPES"
              :key="pt.id"
              type="button"
              :aria-pressed="form.type === pt.id"
              class="flex flex-col items-center justify-center gap-1 min-h-[72px] rounded-2xl border text-sm font-medium transition-colors"
              :class="form.type === pt.id ? 'bg-primary/10 border-primary text-primary' : 'bg-white/60 border-line text-ink hover:bg-white/90'"
              @click="form.type = pt.id"
            >
              <i :class="['bi', pt.icon, 'text-xl']" aria-hidden="true"></i>{{ pt.label }}
            </button>
          </div>
        </fieldset>

        <FormField
          v-model="form.display_name"
          :label="form.type === 'individual' ? 'Name customers will see' : form.type === 'company' ? 'Company name' : 'Group name'"
          required
          :error-message="errors.display_name"
        />

        <FormField
          v-if="form.type !== 'individual'"
          v-model="form.business_registration_number"
          label="Registration number"
          optional
          :hint="form.type === 'company' ? 'From your URSB registration certificate.' : 'If your group is registered.'"
          :error-message="errors.business_registration_number"
        />

        <fieldset>
          <legend class="wm-label">
            Professions <span class="text-error" aria-hidden="true">*</span>
            <span class="font-normal text-muted">(up to 5; the first is your main one)</span>
          </legend>
          <div class="flex flex-wrap gap-2">
            <button
              v-for="p in professionOptions"
              :key="p.slug"
              type="button"
              class="wm-chip"
              :aria-pressed="chosenIndex(p.slug) !== -1"
              :disabled="chosenIndex(p.slug) === -1 && form.professions.length >= 5"
              @click="toggleProfession(p.slug)"
            >
              <i :class="['bi', p.icon]" aria-hidden="true"></i>{{ p.name }}
              <span v-if="chosenIndex(p.slug) === 0" class="wm-pill wm-pill-info !py-0 !text-[11px]">Main</span>
            </button>
          </div>
          <p v-if="errors.professions" class="wm-field-error" role="alert">{{ errors.professions }}</p>
        </fieldset>

        <div v-if="form.professions.length" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <FormField
            v-for="(entry, i) in form.professions"
            :key="entry.profession"
            v-model="entry.years_experience"
            type="number"
            inputmode="numeric"
            min="0"
            max="60"
            :label="`Years as a ${nameOf(entry.profession).toLowerCase()}`"
            optional
            :error-message="errors[`professions.${i}.years_experience`] || errors[`professions.${i}.profession`]"
          />
        </div>

        <FormField
          v-model="form.bio"
          type="textarea"
          label="About your work"
          optional
          placeholder="Your experience and the jobs you do best."
          :min-height="110"
          :error-message="errors.bio"
        />

        <!-- What customers see when they find you -->
        <fieldset class="flex flex-col gap-4 pt-2">
          <legend class="text-[15px] font-semibold text-ink mb-1">How customers find you</legend>

          <FormField v-model="form.base_area" type="select" label="Where you’re based" placeholder="Choose an area" optional
            hint="Customers see this area, never your exact location." :error-message="errors.base_area">
            <option v-for="a in baseAreaOptions" :key="a.id" :value="a.id">{{ a.name }}</option>
          </FormField>

          <fieldset>
            <legend class="wm-label">Areas you travel to <span class="font-normal text-muted">(optional)</span></legend>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="a in searchLocations"
                :key="a.id"
                type="button"
                class="wm-chip"
                :aria-pressed="form.service_areas.includes(a.id)"
                @click="toggleServiceArea(a.id)"
              >{{ a.wholeTown ? `All of ${a.name}` : a.name }}</button>
            </div>
          </fieldset>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <FormField v-model="form.price_from" type="number" inputmode="numeric" min="1000" step="1000" prefix="UGX"
              label="Prices from" optional :error-message="errors.price_from" />
            <FormField v-model="form.price_basis" label="Price is" optional placeholder="e.g. per job, per day"
              :error-message="errors.price_basis" />
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <FormField v-model="form.availability_status" type="select" label="Right now I’m" optional :error-message="errors.availability_status">
              <option value="">Not set</option>
              <option value="available">Available</option>
              <option value="busy">Busy</option>
              <option value="unavailable">Unavailable</option>
            </FormField>
            <FormField v-model="form.availability_note" label="Usual hours" optional placeholder="e.g. Mon–Sat, 8am–6pm"
              :error-message="errors.availability_note" />
          </div>
        </fieldset>

        <p v-if="saveError" class="wm-notice wm-notice-error" role="alert">
          <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ saveError }}</span>
        </p>
        <p v-if="saved" class="text-sm text-success text-center" role="status">
          <i class="bi bi-check-circle-fill mr-1" aria-hidden="true"></i>{{ saved }}
        </p>

        <div class="flex flex-col sm:flex-row gap-2">
          <PrimaryButton type="submit" :variant="canSubmit ? 'secondary' : 'primary'" :loading="busy === 'save'" :disabled="!!busy">
            Save details
          </PrimaryButton>
          <PrimaryButton
            v-if="canSubmit"
            :loading="busy === 'submit'"
            :disabled="!!busy || !emailVerified"
            @click="save(true)"
          >
            {{ profile?.status === 'more_info_required' ? 'Send changes for review' : 'Send for review' }}
          </PrimaryButton>
        </div>
        <p v-if="canSubmit && !emailVerified" class="wm-meta text-center">
          Confirm your email address first: use the link we sent you.
        </p>
      </form>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, nextTick } from 'vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import { api, fieldErrors } from '@/api/client.js';
import { auth, loadSession } from '@/stores/auth.js';
import { searchLocations, loadCatalog } from '@/stores/catalog.js';
import { focusFirstError } from '@/utils/forms.js';

const PROVIDER_TYPES = [
  { id: 'individual', label: 'Individual', icon: 'bi-person' },
  { id: 'group',      label: 'Group',      icon: 'bi-people' },
  { id: 'company',    label: 'Company',    icon: 'bi-building' },
];

const loading = ref(true);
const loadError = ref('');
const profile = ref(null);          // null = not applied
const categories = ref([]);
const busy = ref('');
const errors = ref({});
const saveError = ref('');
const saved = ref('');

const form = reactive({
  type: 'individual', display_name: '', business_registration_number: '', bio: '', professions: [],
  base_area: '', service_areas: [], price_from: '', price_basis: '', availability_status: '', availability_note: '',
});

const baseAreaOptions = computed(() => searchLocations.filter(a => !a.wholeTown));
function toggleServiceArea(id) {
  const i = form.service_areas.indexOf(id);
  if (i === -1) form.service_areas.push(id);
  else form.service_areas.splice(i, 1);
}

const emailVerified = computed(() => !!auth.user?.email_verified);
const editable = computed(() => !profile.value || profile.value.can_edit);
const canSubmit = computed(() => !profile.value || profile.value.can_submit);

const professionOptions = computed(() => categories.value.flatMap(c =>
  (c.professions || []).map(p => ({ ...p, icon: c.icon || 'bi-briefcase' }))));

function nameOf(slug) {
  return professionOptions.value.find(p => p.slug === slug)?.name || slug;
}
function chosenIndex(slug) {
  return form.professions.findIndex(p => p.profession === slug);
}
function toggleProfession(slug) {
  const i = chosenIndex(slug);
  if (i === -1) form.professions.push({ profession: slug, years_experience: '' });
  else form.professions.splice(i, 1);
  errors.value.professions = '';
}

const BANNERS = {
  draft:              { tone: 'info',    icon: 'bi-pencil-square', title: 'Not sent yet', text: 'Your details are saved. Send them for review when you’re ready.' },
  pending_review:     { tone: 'info',    icon: 'bi-hourglass-split', title: 'Being reviewed', text: 'WorkMate is checking your application. You can make changes once a decision is made.' },
  more_info_required: { tone: 'warning', icon: 'bi-chat-left-text', title: 'WorkMate needs more information' },
  approved:           { tone: 'success', icon: 'bi-check-circle', title: 'You’re an approved provider', text: 'Customers can now hire you. Changes you save here update your profile.' },
  rejected:           { tone: 'error',   icon: 'bi-x-circle', title: 'Your application was not approved' },
  suspended:          { tone: 'error',   icon: 'bi-slash-circle', title: 'Your provider account is suspended', text: 'You can still hire people as a customer. Contact WorkMate support about the suspension.' },
};

const banner = computed(() => {
  const p = profile.value;
  if (!p) return null;
  const b = { ...BANNERS[p.status] };
  if (p.status === 'more_info_required' || p.status === 'rejected') b.text = p.application?.decision_reason || '';
  if (p.status === 'suspended' && p.suspension_reason) b.text = `Reason: ${p.suspension_reason}. ${b.text}`;
  return b;
});

function fill(p) {
  profile.value = p;
  if (!p) {
    form.display_name = auth.user?.name || '';
    return;
  }
  Object.assign(form, {
    type: p.type,
    display_name: p.display_name,
    business_registration_number: p.business_registration_number || '',
    bio: p.bio || '',
    professions: p.professions.map(x => ({ profession: x.profession, years_experience: x.years_experience ?? '' })),
    base_area: p.base_area || '',
    service_areas: [...(p.service_areas || [])],
    price_from: p.price_from ?? '',
    price_basis: p.price_basis || '',
    availability_status: p.availability_status || '',
    availability_note: p.availability_note || '',
  });
}

async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    const [mine, cats] = await Promise.all([api('/me/provider'), api('/categories')]);
    categories.value = cats.data;
    fill(mine.data);
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
  }
}

function payload() {
  return {
    type: form.type,
    display_name: form.display_name.trim(),
    bio: form.bio.trim() || null,
    business_registration_number: form.type === 'individual' ? null : (form.business_registration_number.trim() || null),
    professions: form.professions.map(p => ({
      profession: p.profession,
      years_experience: p.years_experience === '' || p.years_experience === null ? null : Number(p.years_experience),
    })),
    base_area: form.base_area || null,
    service_areas: form.service_areas,
    price_from: form.price_from === '' || form.price_from === null ? null : Number(form.price_from),
    price_basis: form.price_basis.trim() || null,
    availability_status: form.availability_status || null,
    availability_note: form.availability_note.trim() || null,
  };
}

async function save(andSubmit) {
  errors.value = {
    display_name: form.display_name.trim() ? '' : 'Enter the name customers will see.',
    professions: form.professions.length ? '' : 'Choose at least one profession.',
  };
  saveError.value = '';
  saved.value = '';
  if (errors.value.display_name || errors.value.professions) {
    await nextTick();
    return focusFirstError();
  }

  busy.value = andSubmit ? 'submit' : 'save';
  try {
    let res = await api('/me/provider', { method: 'PUT', body: payload() });
    if (andSubmit) res = await api('/me/provider/submit', { method: 'POST', body: {} });
    fill(res.data);
    saved.value = andSubmit ? 'Sent for review.' : 'Details saved.';
    loadSession({ force: true });
    // An approved provider's public listing changes straight away
    if (res.data.status === 'approved') loadCatalog({ force: true });
  } catch (e) {
    errors.value = fieldErrors(e);
    if (!Object.keys(errors.value).length) saveError.value = e.message;
    await nextTick();
    focusFirstError();
  } finally {
    busy.value = '';
  }
}

async function reopen() {
  busy.value = 'reopen';
  try {
    fill((await api('/me/provider/reopen', { method: 'POST', body: {} })).data);
    loadSession({ force: true });
  } catch (e) {
    saveError.value = e.message;
  } finally {
    busy.value = '';
  }
}

onMounted(load);
</script>
