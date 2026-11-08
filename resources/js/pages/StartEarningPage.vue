<template>
  <div class="wm-container wm-container-sm wm-page">
    <BackLink to="/account">Account</BackLink>

    <div v-if="loading" class="mt-5 flex flex-col gap-3" aria-busy="true">
      <div class="wm-skeleton h-8 w-2/3"></div>
      <div class="wm-skeleton h-40 !rounded-[20px]"></div>
    </div>

    <p v-else-if="loadError" class="wm-notice wm-notice-error mt-4" role="alert">
      <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
      <span>{{ loadError }} <button type="button" class="wm-link" @click="load">Try again</button></span>
    </p>

    <template v-else>
      <!-- Progress through the five steps -->
      <nav v-if="stepIndex >= 0" class="mt-2" aria-label="Steps">
        <p class="wm-meta">Step {{ stepIndex + 1 }} of {{ STEPS.length }} · {{ STEPS[stepIndex].label }}</p>
        <ol class="mt-2 grid grid-cols-5 gap-1.5">
          <li v-for="(s, i) in STEPS" :key="s.id">
            <button type="button" class="block w-full h-1.5 rounded-full" :class="i <= stepIndex ? 'bg-primary' : 'bg-line'"
              :aria-label="`${s.label}${i < stepIndex ? ' (done)' : i === stepIndex ? ' (this step)' : ''}`"
              :aria-current="i === stepIndex ? 'step' : undefined" :disabled="i > furthest" @click="go(s.id)"></button>
          </li>
        </ol>
      </nav>

      <p v-if="error" class="wm-notice wm-notice-error mt-4" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>

      <!-- ================= Intro ================= -->
      <section v-if="step === 'intro'" class="mt-2">
        <h1 class="wm-h1">Start earning on WorkMate</h1>
        <p class="wm-lead mt-2">Get found by customers near you and apply for jobs. It takes about 5 minutes.</p>
        <ol class="mt-6 flex flex-col gap-4">
          <li v-for="(s, i) in STEPS" :key="s.id" class="flex items-start gap-3">
            <span class="w-9 h-9 shrink-0 rounded-full bg-primary-tint text-primary font-semibold flex items-center justify-center">{{ i + 1 }}</span>
            <span><span class="block font-semibold text-ink">{{ s.label }}</span><span class="wm-meta">{{ s.intro }}</span></span>
          </li>
        </ol>
        <p class="wm-notice wm-notice-info mt-6"><i class="bi bi-person-vcard" aria-hidden="true"></i><span>Have your <strong class="font-semibold">National ID</strong> with you: you’ll take a photo of both sides.</span></p>
        <p v-if="!emailVerified" class="wm-notice wm-notice-warning mt-3"><i class="bi bi-envelope" aria-hidden="true"></i><span>Confirm your email address before the last step: use the link we sent to {{ auth.user?.email }}.</span></p>
        <p v-if="!appSettings.providerSignups" class="wm-notice wm-notice-info mt-3"><i class="bi bi-pause-circle" aria-hidden="true"></i><span>WorkMate isn’t accepting new providers right now. You can get everything ready and send it when we reopen.</span></p>
        <PrimaryButton class="mt-6 w-full" @click="go('skills')">Let’s start</PrimaryButton>
      </section>

      <!-- ================= 1. Skills ================= -->
      <section v-else-if="step === 'skills'" class="mt-4">
        <h1 class="wm-h2">What work do you do?</h1>
        <p class="wm-meta mt-1">Your first skill is your main one: customers nearby find you first for it. You can add up to 5.</p>

        <ol v-if="form.professions.length" class="mt-4 flex flex-col gap-2" aria-label="Your skills">
          <li v-for="(entry, i) in form.professions" :key="entryKey(entry)" class="wm-card !p-3 flex flex-wrap items-center justify-between gap-2">
            <span class="flex flex-wrap items-center gap-2 text-[15px] font-semibold text-ink">
              {{ entryName(entry) }}
              <span v-if="i === 0" class="wm-pill wm-pill-info !py-0 !text-[11px]">Main</span>
              <span v-if="entry.other" class="wm-pill wm-pill-warning !py-0 !text-[11px]">WorkMate will add it</span>
            </span>
            <span class="flex gap-1">
              <button v-if="i > 0" type="button" class="wm-link min-h-11 px-2 text-sm" @click="makeMain(i)">Make main</button>
              <button type="button" class="wm-link min-h-11 px-2 text-sm text-error" :aria-label="`Remove ${entryName(entry)}`" @click="form.professions.splice(i, 1)">Remove</button>
            </span>
          </li>
        </ol>

        <div v-if="form.professions.length < 5" class="mt-4">
          <label for="skill-search" class="wm-label">{{ form.professions.length ? 'Add another skill' : 'Search for your skill' }}</label>
          <div class="relative">
            <i class="bi bi-search absolute left-3.5 top-1/2 -translate-y-1/2 text-muted" aria-hidden="true"></i>
            <input id="skill-search" v-model="skillQuery" type="search" class="wm-input !pl-10" placeholder="e.g. plumber, tailor, driver" autocomplete="off" />
          </div>

          <ul v-if="skillQuery.trim()" class="mt-2 wm-card !p-1.5 flex flex-col" aria-label="Matching skills">
            <li v-for="p in skillMatches" :key="p.slug">
              <button type="button" class="w-full min-h-11 px-3 py-2 rounded-xl text-left hover:bg-primary/5 flex items-center justify-between gap-3" @click="addSkill(p.slug)">
                <span><span class="text-[15px] font-medium text-ink">{{ p.name }}</span> <span class="wm-meta">· {{ p.category }}</span></span>
                <i class="bi bi-plus-lg text-primary" aria-hidden="true"></i>
              </button>
            </li>
            <li>
              <button type="button" class="w-full min-h-11 px-3 py-2 rounded-xl text-left hover:bg-primary/5 text-[15px] text-primary" @click="startOther">
                <i class="bi bi-plus-circle mr-1.5" aria-hidden="true"></i>{{ skillMatches.length ? 'Not listed? Add' : 'Add' }} “{{ skillQuery.trim() }}” as my skill
              </button>
            </li>
          </ul>

          <template v-else>
            <p class="wm-meta mt-3 mb-2">Popular</p>
            <div class="flex flex-wrap gap-2">
              <button v-for="p in popular" :key="p.slug" type="button" class="wm-chip min-h-10" @click="addSkill(p.slug)">
                <i class="bi bi-plus" aria-hidden="true"></i>{{ p.name }}
              </button>
            </div>
          </template>

          <div v-if="other.open" class="wm-card mt-3 flex flex-col gap-3">
            <FormField v-model="other.name" label="Your skill" maxlength="60" :error-message="other.error" />
            <FormField v-model="other.category" type="select" label="Closest kind of work" optional>
              <option value="">Not sure</option>
              <option v-for="c in categories" :key="c.slug" :value="c.slug">{{ c.name }}</option>
            </FormField>
            <p class="wm-meta">WorkMate checks new skills before customers can find you by them.</p>
            <div class="flex gap-2">
              <PrimaryButton variant="secondary" @click="other.open = false">Cancel</PrimaryButton>
              <PrimaryButton @click="addOther">Add skill</PrimaryButton>
            </div>
          </div>
        </div>
        <p v-if="fieldError('professions')" class="wm-field-error mt-2" role="alert">{{ fieldError('professions') }}</p>

        <StepButtons :can-continue="form.professions.length > 0" hint="Add at least one skill." @next="saveAndGo('place')" />
      </section>

      <!-- ================= 2. Place ================= -->
      <section v-else-if="step === 'place'" class="mt-4">
        <h1 class="wm-h2">Where do you work?</h1>
        <p class="wm-meta mt-1">Customers see this place, never your exact location.</p>
        <PlacePicker v-model="form.base_area" id="start-base-area" class="mt-4" label="Where you’re based" required :clearable="false"
          hint="Your town, trading centre or village." :error-message="fieldError('base_area')" />

        <details class="mt-4 wm-card !p-3" :open="form.service_areas.length > 0">
          <summary class="cursor-pointer min-h-11 flex items-center text-[15px] font-medium text-ink">Other places you travel to <span class="wm-meta ml-1">(optional)</span></summary>
          <PlacePicker id="start-service-areas" class="mt-2" label="Add a place" placeholder="Type a place to add it" reset-on-pick :clearable="false"
            :near="baseAreaPoint" :exclude="[form.base_area, ...form.service_areas]" hint="Customers there can find you too." @select="addServiceArea" />
          <ul v-if="form.service_areas.length" class="mt-2 flex flex-wrap gap-2" aria-label="Places you travel to">
            <li v-for="id in form.service_areas" :key="id" class="wm-chip !pr-1">
              {{ areaLabel(id) || id }}
              <button type="button" class="ml-1 w-8 h-8 inline-flex items-center justify-center rounded-full text-muted hover:text-ink" :aria-label="`Remove ${areaLabel(id) || id}`" @click="form.service_areas = form.service_areas.filter(x => x !== id)">
                <i class="bi bi-x" aria-hidden="true"></i>
              </button>
            </li>
          </ul>
        </details>

        <StepButtons back="skills" :can-continue="!!form.base_area" hint="Choose where you’re based." @next="saveAndGo('profile')" @back="go" />
      </section>

      <!-- ================= 3. Profile ================= -->
      <section v-else-if="step === 'profile'" class="mt-4">
        <h1 class="wm-h2">Your profile</h1>
        <p class="wm-meta mt-1">This is what customers see. A clear photo of your face helps them trust you.</p>

        <div class="wm-card mt-4 flex items-center gap-4">
          <ProfilePhotoEditor :user="auth.user" :size="64" />
          <p class="wm-meta flex-1">Tap your photo to add or change it.</p>
        </div>

        <fieldset class="mt-4">
          <legend class="wm-label">I work as</legend>
          <div class="grid grid-cols-3 gap-2">
            <button v-for="pt in PROVIDER_TYPES" :key="pt.id" type="button" :aria-pressed="form.type === pt.id"
              class="flex flex-col items-center justify-center gap-1 min-h-[64px] rounded-2xl border text-sm font-medium"
              :class="form.type === pt.id ? 'bg-primary/10 border-primary text-primary' : 'bg-white/60 border-line text-ink'" @click="form.type = pt.id">
              <i :class="['bi', pt.icon, 'text-lg']" aria-hidden="true"></i>{{ pt.label }}
            </button>
          </div>
        </fieldset>

        <div class="mt-4 flex flex-col gap-4">
          <FormField v-model="form.display_name" :label="form.type === 'individual' ? 'Name customers will see' : form.type === 'company' ? 'Company name' : 'Group name'" required :error-message="fieldError('display_name')" />
          <FormField v-if="form.type !== 'individual'" v-model="form.business_registration_number" label="Registration number" optional
            :hint="form.type === 'company' ? 'From your URSB registration certificate.' : 'If your group is registered.'" :error-message="fieldError('business_registration_number')" />
          <FormField v-model="form.bio" type="textarea" label="About your work" optional placeholder="The jobs you do best and how long you’ve done them." :min-height="96" :error-message="fieldError('bio')" />
          <div class="grid grid-cols-2 gap-3">
            <FormField v-model="form.price_from" type="number" inputmode="numeric" min="1000" step="1000" prefix="UGX" label="Prices from" optional :error-message="fieldError('price_from')" />
            <FormField v-model="form.price_basis" label="Price is" optional placeholder="per job" :error-message="fieldError('price_basis')" />
          </div>
        </div>

        <!-- How the listing will look -->
        <div class="mt-5">
          <p class="wm-meta mb-2">Customers will see</p>
          <div class="wm-card flex items-center gap-3.5">
            <UserAvatar :name="form.display_name || auth.user?.name || '?'" :src="auth.user?.avatar_url || ''" :size="52" />
            <div class="min-w-0">
              <p class="text-[15px] font-semibold text-ink truncate">{{ form.display_name || 'Your name' }}</p>
              <p class="text-[13px] text-muted truncate">{{ form.professions[0] ? entryName(form.professions[0]) : 'Your main skill' }} • {{ areaLabel(form.base_area) || 'Your place' }}</p>
              <p v-if="form.price_from" class="mt-0.5 text-[14px] font-semibold text-ink">UGX {{ Number(form.price_from).toLocaleString('en-UG') }}<span class="font-normal text-muted text-[13px]"> / {{ form.price_basis || 'job' }}</span></p>
            </div>
          </div>
        </div>

        <StepButtons back="place" :can-continue="form.display_name.trim().length > 1" hint="Add the name customers will see." @next="saveAndGo('documents')" @back="go" />
      </section>

      <!-- ================= 4. Documents ================= -->
      <section v-else-if="step === 'documents'" class="mt-4">
        <h1 class="wm-h2">Verify your identity</h1>
        <p class="wm-meta mt-1">Take clear photos of both sides of your National ID. WorkMate checks it so customers know who they’re hiring. Only WorkMate’s reviewers can open it.</p>
        <div class="mt-4">
          <GetVerifiedPage embedded @update="verification = $event" />
        </div>
        <StepButtons back="profile" :can-continue="documentsReady" :hint="documentsHint" @next="go('review')" @back="go" />
      </section>

      <!-- ================= 5. Review and send ================= -->
      <section v-else-if="step === 'review'" class="mt-4">
        <h1 class="wm-h2">{{ correcting ? 'Send your corrections' : 'Check and send' }}</h1>
        <p class="wm-meta mt-1">{{ correcting ? 'WorkMate will check what you changed.' : 'WorkMate checks your details and ID as soon as possible.' }}</p>

        <ul class="wm-card !p-0 mt-4 divide-y divide-line overflow-hidden">
          <li v-for="row in summary" :key="row.step" class="flex items-start gap-3 px-4 py-3">
            <i :class="['bi', row.ok ? 'bi-check-circle-fill text-success' : 'bi-exclamation-circle-fill text-[#B45309]', 'mt-0.5']" aria-hidden="true"></i>
            <span class="min-w-0 flex-1">
              <span class="block text-[13px] text-muted">{{ row.label }}</span>
              <span class="block text-[15px] text-ink break-words">{{ row.value }}</span>
            </span>
            <button type="button" class="wm-link min-h-11 px-2 text-sm shrink-0" @click="go(row.step)">Edit</button>
          </li>
        </ul>

        <div v-if="!emailVerified" class="wm-notice wm-notice-warning mt-4 flex-col !items-start">
          <span class="flex gap-2"><i class="bi bi-envelope" aria-hidden="true"></i><span>Confirm your email address before sending. We sent a link to <strong class="font-semibold">{{ auth.user?.email }}</strong>.</span></span>
          <span class="flex flex-wrap gap-2 mt-2">
            <button type="button" class="wm-btn wm-btn-secondary !min-h-10" :disabled="emailBusy" @click="resendEmail">{{ emailBusy ? 'Sending…' : 'Send the link again' }}</button>
            <button type="button" class="wm-btn wm-btn-ghost !min-h-10" @click="loadSession({ force: true })">I’ve confirmed it</button>
          </span>
          <span v-if="emailMessage" class="mt-1 text-[13px]">{{ emailMessage }}</span>
        </div>

        <label class="mt-4 flex items-start gap-3 rounded-2xl border border-line bg-white p-4">
          <input v-model="agreed" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-[#1769D2]" />
          <span class="text-[14px] text-ink">
            I agree to the <router-link to="/legal/provider" class="wm-link font-medium">Service Provider Agreement</router-link>.
            My details are true, and my documents are mine, current and genuine.
          </span>
        </label>

        <PrimaryButton class="mt-4 w-full" :loading="busy === 'send'" :disabled="!!busy || !agreed || !emailVerified || summary.some(r => !r.ok)" @click="send">
          {{ correcting ? 'Send corrections' : 'Send to WorkMate' }}
        </PrimaryButton>
        <p v-if="summary.some(r => !r.ok)" class="wm-meta text-center mt-2">Finish the steps marked above first.</p>
        <button type="button" class="wm-link mt-2 min-h-11 w-full text-center text-sm" @click="go('documents')">Back</button>
      </section>

      <!-- ================= Where it stands ================= -->
      <section v-else-if="step === 'status'" class="mt-2">
        <h1 class="wm-h1">{{ hub.title }}</h1>
        <p class="wm-lead mt-2">{{ hub.text }}</p>

        <!-- Timeline -->
        <ol v-if="hub.timeline" class="mt-6 flex flex-col">
          <li v-for="(t, i) in hub.timeline" :key="t.label" class="flex gap-3">
            <span class="flex flex-col items-center">
              <span class="w-8 h-8 rounded-full flex items-center justify-center text-sm"
                :class="t.state === 'done' ? 'bg-success text-white' : t.state === 'now' ? 'bg-primary text-white' : 'bg-line text-muted'" aria-hidden="true">
                <i :class="['bi', t.state === 'done' ? 'bi-check-lg' : t.icon]"></i>
              </span>
              <span v-if="i < hub.timeline.length - 1" class="w-0.5 flex-1 min-h-6 bg-line"></span>
            </span>
            <span class="pb-5">
              <span class="block font-semibold" :class="t.state === 'later' ? 'text-muted' : 'text-ink'">{{ t.label }}</span>
              <span v-if="t.text" class="wm-meta">{{ t.text }}</span>
            </span>
          </li>
        </ol>

        <!-- What WorkMate asked to correct -->
        <section v-if="corrections.length" class="mt-4" aria-labelledby="corrections-heading">
          <h2 id="corrections-heading" class="wm-h3 mb-2">What to fix</h2>
          <ul class="flex flex-col gap-3">
            <li v-for="c in corrections" :key="c.key" class="wm-card !border-[#FCD34D]">
              <p class="text-[13px] font-medium text-[#92400E]">{{ c.area }}</p>
              <p class="mt-0.5 text-[15px] text-ink">{{ c.text }}</p>
              <button type="button" class="wm-btn wm-btn-secondary !min-h-10 mt-3" @click="go(c.step)">{{ c.action }}</button>
            </li>
          </ul>
          <PrimaryButton v-if="canSend" class="mt-4 w-full" @click="go('review')">I’ve fixed them: send corrections</PrimaryButton>
          <p v-else class="wm-meta mt-3">Replaced documents go straight to WorkMate’s reviewer; there’s nothing else to send.</p>
        </section>

        <div class="mt-6 flex flex-col gap-2">
          <PrimaryButton v-if="profile?.status === 'rejected'" :loading="busy === 'reopen'" @click="reopen">Start a new application</PrimaryButton>
          <PrimaryButton v-if="needsDocumentsOnly" @click="go('documents')">Verify my identity</PrimaryButton>
          <router-link v-if="profile?.status === 'approved'" :to="`/provider/${profile.id}`" class="wm-btn wm-btn-secondary wm-btn-lg">See my public profile</router-link>
          <router-link v-if="profile?.status === 'approved'" to="/find-work" class="wm-btn wm-btn-secondary wm-btn-lg">Find work</router-link>
          <router-link v-if="profile?.status === 'approved'" to="/account?panel=provider" class="wm-link min-h-11 text-center text-sm">Edit my service details</router-link>
        </div>
      </section>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted, h } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import PlacePicker from '@/components/PlacePicker.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import ProfilePhotoEditor from '@/components/ProfilePhotoEditor.vue';
import GetVerifiedPage from '@/pages/GetVerifiedPage.vue';
import { api, fieldErrors } from '@/api/client.js';
import { auth, loadSession } from '@/stores/auth.js';
import { appSettings, rememberArea, areaById, areaLabel, loadCatalog } from '@/stores/catalog.js';
import { selectedArea } from '@/stores/location.js';

/**
 * Start earning: one guided flow to become a provider and get verified.
 * Saves the details as people go (PUT /me/provider), reuses the documents
 * screen for the ID, and sends everything together (POST /me/provider/send).
 * Afterwards it shows where things stand and exactly what WorkMate asked to fix.
 */
const STEPS = [
  { id: 'skills', label: 'Your skills', intro: 'Pick the work you do.' },
  { id: 'place', label: 'Where you work', intro: 'Choose your town or area.' },
  { id: 'profile', label: 'Your profile', intro: 'Your name, photo and prices.' },
  { id: 'documents', label: 'Your ID', intro: 'Photos of your National ID.' },
  { id: 'review', label: 'Check and send', intro: 'WorkMate checks it as soon as possible.' },
];
const PROVIDER_TYPES = [
  { id: 'individual', label: 'Just me', icon: 'bi-person' },
  { id: 'group', label: 'A group', icon: 'bi-people' },
  { id: 'company', label: 'A company', icon: 'bi-building' },
];
const POPULAR_CATEGORIES = ['plumbing', 'electrical', 'cleaning', 'construction', 'carpentry', 'painting', 'repairs', 'moving', 'driving', 'gardening'];

const route = useRoute();
const router = useRouter();
const loading = ref(true);
const loadError = ref('');
const error = ref('');
const errors = ref({});
const busy = ref('');
const profile = ref(null);
const verification = ref(null);
const categories = ref([]);
const step = ref('intro');
const agreed = ref(false);

const form = reactive({
  type: 'individual', display_name: '', business_registration_number: '', bio: '',
  professions: [], base_area: '', service_areas: [], price_from: '', price_basis: '',
});

const emailVerified = computed(() => !!auth.user?.email_verified);
const stepIndex = computed(() => STEPS.findIndex(s => s.id === step.value));
const furthest = ref(0);
const fieldError = (key) => errors.value[key] || '';

/* ---------------- Loading ---------------- */
async function load() {
  loading.value = true;
  loadError.value = '';
  try {
    const [mine, cats] = await Promise.all([api('/me/provider'), api('/categories')]);
    categories.value = cats.data;
    fill(mine.data);
    verification.value = mine.data ? (await api('/me/verification')).data : null;
    step.value = firstStep();
    furthest.value = Math.max(furthest.value, stepIndex.value);
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

function fill(p) {
  profile.value = p;
  for (const place of p?.places || []) rememberArea(place);
  if (!p) {
    form.display_name = auth.user?.name || '';
    form.base_area = selectedArea.value || '';
    return;
  }
  Object.assign(form, {
    type: p.type,
    display_name: p.display_name,
    business_registration_number: p.business_registration_number || '',
    bio: p.bio || '',
    professions: p.professions.map(x => ({ profession: x.profession, other: x.other || null, category: x.category || null, years_experience: x.years_experience ?? null })),
    base_area: p.base_area || '',
    service_areas: [...(p.service_areas || [])],
    price_from: p.price_from ?? '',
    price_basis: p.price_basis || '',
  });
}

// Editable while it's a draft or WorkMate asked for changes
const editable = computed(() => !profile.value || ['draft', 'more_info_required'].includes(profile.value.status));
const correcting = computed(() => profile.value?.status === 'more_info_required' || verification.value?.status === 'info_requested');

function firstStep() {
  const wanted = route.query.step;
  const p = profile.value;
  if (!p) return 'intro';
  if (wanted && STEPS.some(s => s.id === wanted) && (editable.value || wanted === 'documents')) return wanted;
  if (p.status !== 'draft' || corrections.value.length) return 'status';
  if (!form.professions.length) return 'skills';
  if (!form.base_area) return 'place';
  if (!documentsReady.value) return 'documents';
  return 'review';
}

function go(id) {
  error.value = '';
  step.value = id;
  const i = STEPS.findIndex(s => s.id === id);
  if (i > furthest.value) furthest.value = i;
  router.replace({ query: id === 'intro' || id === 'status' ? {} : { step: id } });
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ---------------- Saving ---------------- */
function payload() {
  return {
    type: form.type,
    display_name: form.display_name.trim(),
    bio: form.bio.trim() || null,
    business_registration_number: form.type === 'individual' ? null : (form.business_registration_number.trim() || null),
    professions: form.professions.map(p => ({
      ...(p.other ? { other: p.other, category: p.category || null } : { profession: p.profession }),
      years_experience: p.years_experience === '' || p.years_experience === null || p.years_experience === undefined ? null : Number(p.years_experience),
    })),
    base_area: form.base_area || null,
    service_areas: form.service_areas,
    price_from: form.price_from === '' || form.price_from === null ? null : Number(form.price_from),
    price_basis: form.price_basis.trim() || null,
  };
}

async function saveAndGo(next) {
  if (!editable.value) { go(next); return; }
  busy.value = 'save';
  error.value = '';
  errors.value = {};
  try {
    const res = await api('/me/provider', { method: 'PUT', body: payload() });
    const first = !profile.value;
    fill(res.data);
    if (first) {
      verification.value = (await api('/me/verification')).data;
      loadSession({ force: true });
    }
    go(next);
  } catch (e) {
    errors.value = fieldErrors(e);
    error.value = Object.keys(errors.value).length ? 'Check the highlighted details.' : e.message;
  } finally {
    busy.value = '';
  }
}

/* ---------------- Skills ---------------- */
const allProfessions = computed(() => categories.value.flatMap(c => (c.professions || []).map(p => ({ ...p, category: c.name, categorySlug: c.slug }))));
const chosen = (slug) => form.professions.some(p => p.profession === slug);
const skillQuery = ref('');
const skillMatches = computed(() => {
  const q = skillQuery.value.trim().toLowerCase();
  if (!q) return [];
  return allProfessions.value
    .filter(p => !chosen(p.slug) && (p.name.toLowerCase().includes(q) || p.category.toLowerCase().includes(q)))
    .sort((a, b) => Number(!a.name.toLowerCase().startsWith(q)) - Number(!b.name.toLowerCase().startsWith(q)))
    .slice(0, 8);
});
const popular = computed(() => POPULAR_CATEGORIES
  .map(slug => categories.value.find(c => c.slug === slug)?.professions?.[0])
  .filter(p => p && !chosen(p.slug)));
const nameOf = (slug) => allProfessions.value.find(p => p.slug === slug)?.name || slug;
const entryName = (entry) => entry.other || nameOf(entry.profession);
const entryKey = (entry) => (entry.other ? `other:${entry.other.toLowerCase()}` : entry.profession);

function addSkill(slug) {
  if (form.professions.length >= 5 || chosen(slug)) return;
  form.professions.push({ profession: slug, other: null, category: null, years_experience: null });
  skillQuery.value = '';
  errors.value.professions = '';
}
function makeMain(i) {
  const [entry] = form.professions.splice(i, 1);
  form.professions.unshift(entry);
}
const other = reactive({ open: false, name: '', category: '', error: '' });
function startOther() {
  Object.assign(other, { open: true, name: skillQuery.value.trim(), category: '', error: '' });
  skillQuery.value = '';
}
function addOther() {
  const name = other.name.trim().replace(/\s+/g, ' ');
  other.error = name.length < 3 ? 'Enter at least 3 characters.'
    : form.professions.some(p => entryName(p).toLowerCase() === name.toLowerCase()) ? 'You’ve already added this skill.' : '';
  if (other.error) return;
  form.professions.push({ profession: null, other: name, category: other.category || null, years_experience: null });
  other.open = false;
}

/* ---------------- Place ---------------- */
const baseAreaPoint = computed(() => {
  const a = areaById(form.base_area);
  return a && a.latitude != null ? { latitude: a.latitude, longitude: a.longitude } : null;
});
function addServiceArea(area) {
  if (!area?.id || form.service_areas.includes(area.id) || form.service_areas.length >= 20) return;
  form.service_areas.push(area.id);
}

/* ---------------- Documents ---------------- */
const nationalId = computed(() => verification.value?.documents?.find(d => d.kind === 'national_id') || null);
const documentsHint = computed(() => {
  const v = verification.value;
  if (!v) return 'Loading your documents…';
  if (!nationalId.value) return 'Add both sides of your National ID.';
  const q = v.requirements?.questions?.find(x => x.answer === null);
  if (q) return `Answer “${q.question}”.`;
  if (v.requirements?.missing?.length) return `Add your ${v.requirements.missing.join(', ')}.`;
  if (v.documents.some(d => d.status === 'replacement_required')) return 'Replace the documents WorkMate sent back.';
  if (v.requests?.length) return 'Add the documents WorkMate asked for.';
  return '';
});
const documentsReady = computed(() => !!verification.value && !documentsHint.value);

/* ---------------- Review ---------------- */
const summary = computed(() => [
  { step: 'skills', label: 'Skills', value: form.professions.map(entryName).join(', ') || 'None yet', ok: form.professions.length > 0 },
  { step: 'place', label: 'Where you work', value: [areaLabel(form.base_area), ...form.service_areas.map(areaLabel)].filter(Boolean).join(', ') || 'Not chosen', ok: !!form.base_area },
  { step: 'profile', label: 'Profile', value: form.display_name || 'No name yet', ok: form.display_name.trim().length > 1 },
  { step: 'documents', label: 'ID and documents', value: documentsReady.value ? `National ID${verification.value.documents.length > 1 ? ` and ${verification.value.documents.length - 1} more` : ''}` : documentsHint.value, ok: documentsReady.value },
]);

const emailBusy = ref(false);
const emailMessage = ref('');
async function resendEmail() {
  emailBusy.value = true;
  try {
    emailMessage.value = (await api('/me/email/verification-notification', { method: 'POST', body: {} })).data.message;
  } catch (e) {
    emailMessage.value = e.message;
  } finally {
    emailBusy.value = false;
  }
}

async function send() {
  busy.value = 'send';
  error.value = '';
  try {
    if (editable.value) await api('/me/provider', { method: 'PUT', body: payload() });
    await api('/me/provider/send', { method: 'POST', body: { accept_provider_terms: agreed.value } });
    agreed.value = false;
    await Promise.all([load(), loadSession({ force: true })]);
    go('status');
  } catch (e) {
    error.value = Object.values(fieldErrors(e))[0] || e.message;
    window.scrollTo({ top: 0, behavior: 'smooth' });
  } finally {
    busy.value = '';
  }
}

async function reopen() {
  busy.value = 'reopen';
  try {
    fill((await api('/me/provider/reopen', { method: 'POST' })).data);
    go('skills');
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = '';
  }
}

/* ---------------- Where it stands ---------------- */
const corrections = computed(() => {
  const out = [];
  const p = profile.value;
  const v = verification.value;
  if (p?.status === 'more_info_required') {
    out.push({ key: 'details', area: 'Your details', text: p.application?.decision_reason || 'WorkMate asked you to change your details.', step: 'skills', action: 'Fix my details' });
  }
  if (v?.status === 'info_requested' && v.decision_reason) {
    out.push({ key: 'docs-reason', area: 'Your documents', text: v.decision_reason, step: 'documents', action: 'Fix my documents' });
  }
  for (const d of v?.documents || []) {
    if (d.status === 'replacement_required') out.push({ key: `doc-${d.id}`, area: d.label, text: d.note || 'Send a clearer copy.', step: 'documents', action: 'Replace it' });
  }
  for (const r of v?.requests || []) {
    out.push({ key: `req-${r.id}`, area: 'Another document', text: r.instructions ? `${r.label}: ${r.instructions}` : r.label, step: 'documents', action: 'Add it' });
  }
  return out;
});
// The details or documents are waiting to be sent again
const canSend = computed(() => correcting.value);
// Applied or approved before the ID was part of applying: the ID is still to send
const needsDocumentsOnly = computed(() => ['approved', 'pending_review'].includes(profile.value?.status) && (!verification.value || ['draft', 'rejected'].includes(verification.value.status)));

const hub = computed(() => {
  const p = profile.value;
  const v = verification.value;
  const docsDone = v?.status === 'approved';
  if (!p) return { title: '', text: '' };
  if (p.status === 'suspended') return { title: 'Your provider account is suspended', text: p.suspension_reason ? `Reason: ${p.suspension_reason}. You can still hire people as a customer.` : 'You can still hire people as a customer. Contact WorkMate support about it.' };
  if (p.status === 'rejected') return { title: 'Your application wasn’t approved', text: p.application?.decision_reason || 'You can start a new application with your saved details.' };
  if (corrections.value.length) return { title: 'WorkMate asked you to fix a few things', text: 'Make the changes below, then send them back. Nothing else needs to be done again.' };
  if (p.status === 'pending_review' && needsDocumentsOnly.value) return { title: 'Add your ID to finish', text: 'WorkMate checks every provider’s National ID before they go live. Add it and send it, and we’ll check everything together.' };
  if (p.status === 'approved') {
    return {
      title: 'You’re live on WorkMate',
      text: docsDone ? 'Customers can find and hire you, and your profile shows the verified tick.' : 'Customers can find and hire you. Verify your identity to get the tick on your profile.',
      timeline: [
        { label: 'Sent', icon: 'bi-send', state: 'done' },
        { label: 'Checked by WorkMate', icon: 'bi-search', state: 'done' },
        { label: docsDone ? 'Live, with the verified tick' : 'Live', icon: 'bi-patch-check', state: 'done' },
      ],
    };
  }
  return {
    title: 'WorkMate is checking your details',
    text: 'We’ll let you know here and with a notification as soon as possible. You can still use WorkMate as a customer meanwhile.',
    timeline: [
      { label: 'Sent', icon: 'bi-send', state: 'done' },
      { label: 'Being checked', text: 'Your details and ID', icon: 'bi-search', state: 'now' },
      { label: 'Live on WorkMate', text: 'Customers can find and hire you', icon: 'bi-patch-check', state: 'later' },
    ],
  };
});

/* Continue / Back buttons under each step */
const StepButtons = {
  props: { canContinue: Boolean, hint: String, back: String },
  emits: ['next', 'back'],
  setup(props, { emit }) {
    return () => h('div', { class: 'mt-6 flex flex-col gap-2' }, [
      h(PrimaryButton, { class: 'w-full', loading: busy.value === 'save', disabled: !!busy.value || !props.canContinue, onClick: () => emit('next') }, () => 'Continue'),
      !props.canContinue && props.hint ? h('p', { class: 'wm-meta text-center' }, props.hint) : null,
      props.back ? h('button', { type: 'button', class: 'wm-link min-h-11 text-center text-sm', onClick: () => emit('back', props.back) }, 'Back') : null,
    ]);
  },
};

// Keep the catalogue's places available for labels
loadCatalog().catch(() => {});
</script>
