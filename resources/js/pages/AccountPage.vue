<template>
  <div class="wm-container wm-container-sm wm-page">
    <h1 class="wm-h1">Account</h1>

    <p v-if="route.query.admin === 'denied'" class="wm-notice wm-notice-warning mt-5" role="alert">
      <i class="bi bi-shield-lock" aria-hidden="true"></i>
      <span>Your account doesn’t have access to the admin area. Ask a WorkMate Super Administrator if you need it.</span>
    </p>

    <!-- Result of opening the confirmation link from the email -->
    <p v-if="verificationNotice" :class="['wm-notice mt-5', `wm-notice-${verificationNotice.tone}`]" role="status">
      <i :class="['bi', verificationNotice.icon]" aria-hidden="true"></i>
      <span>{{ verificationNotice.text }}</span>
    </p>

    <div v-if="auth.status === 'unknown' || auth.status === 'loading'" class="wm-card mt-5 flex items-center gap-3 wm-meta" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>Checking your account…
    </div>

    <p v-else-if="auth.status === 'unavailable'" class="wm-notice wm-notice-warning mt-5" role="alert">
      <i class="bi bi-cloud-slash" aria-hidden="true"></i>
      <span>
        We can’t reach your account right now, so signing in isn’t available.
        <button type="button" class="wm-link" @click="loadSession({ force: true })">Try again</button>
      </span>
    </p>

    <!-- Signed out -->
    <section v-else-if="!user" class="wm-card wm-card-lg mt-5 text-center" aria-labelledby="account-signin-heading">
      <span class="wm-icon-tile mx-auto" aria-hidden="true"><i class="bi bi-person-circle"></i></span>
      <h2 id="account-signin-heading" class="wm-h2 mt-3">Sign in to WorkMate</h2>
      <p class="wm-meta mt-1">Keep your requests, messages and services in one account.</p>
      <div class="mt-5 flex flex-col sm:flex-row gap-2 justify-center">
        <router-link to="/sign-in?redirect=/account" class="wm-btn wm-btn-primary wm-btn-lg">Sign in</router-link>
        <router-link to="/register?redirect=/account" class="wm-btn wm-btn-secondary wm-btn-lg">Create an account</router-link>
      </div>
    </section>

    <!-- Signed in -->
    <template v-else>
      <section class="wm-card mt-5 flex items-center gap-4" aria-label="Profile summary">
        <ProfilePhotoEditor :user="user" :size="72" />
        <div class="min-w-0">
          <p class="text-xl font-semibold text-ink truncate">{{ user.name }}</p>
          <p class="wm-meta mt-0.5 truncate">{{ user.email }}</p>
          <p class="mt-1.5 flex flex-wrap gap-1.5">
            <span v-if="user.capabilities.provider" class="wm-pill wm-pill-info">Offering services</span>
            <span v-if="!user.email_verified" class="wm-pill wm-pill-warning">Email not confirmed</span>
          </p>
        </div>
      </section>

      <p v-if="route.query.welcome" class="wm-notice wm-notice-success mt-3" role="status">
        <i class="bi bi-check-circle" aria-hidden="true"></i>
        <span>Welcome to WorkMate! Your account is ready.</span>
      </p>

      <div v-if="!user.email_verified" class="wm-notice wm-notice-info mt-3">
        <i class="bi bi-envelope" aria-hidden="true"></i>
        <span>
          Confirm your email address with the link we sent to {{ user.email }}.
          <button type="button" class="wm-link" :disabled="resend.busy" @click="resendVerification">
            {{ resend.busy ? 'Sending…' : 'Send it again' }}
          </button>
          <span v-if="resend.message" class="block mt-1" role="status">{{ resend.message }}</span>
        </span>
      </div>
    </template>

    <!-- Administrators get a way into the admin area -->
    <router-link v-if="user?.permissions?.length" to="/admin" class="wm-card wm-card-link mt-3 flex items-center gap-4">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-speedometer2"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">Admin area</span>
        <span class="block wm-meta">Manage WorkMate with your administrator role</span>
      </span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>

    <!-- Becoming a provider: one guided flow -->
    <router-link v-if="user && !['approved', 'suspended'].includes(user.provider_status)" to="/start-earning"
      class="wm-card wm-card-link mt-3 flex items-center gap-4"
      :class="user.provider_status === 'more_info_required' ? '!border-[#FCD34D]' : '!border-primary/30'">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-briefcase"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">{{ START.title }}</span>
        <span class="block wm-meta">{{ START.text }}</span>
      </span>
      <span v-if="user.provider_status === 'more_info_required'" class="wm-pill wm-pill-warning !py-0 !text-[11px]">Action needed</span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>

    <!-- Providers kept for later -->
    <router-link v-if="user" to="/saved" class="wm-card wm-card-link mt-3 flex items-center gap-4">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-heart"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">Saved providers</span>
        <span class="block wm-meta">{{ saved.ids.size ? `${saved.ids.size} saved` : 'Tap the heart on a provider to keep them here' }}</span>
      </span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>

    <!-- Reports, complaints and notices from WorkMate -->
    <router-link v-if="user" to="/account/reports" class="wm-card wm-card-link mt-3 flex items-center gap-4">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-flag"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">Reports and complaints</span>
        <span class="block wm-meta">{{ unreadNotices ? `${unreadNotices} new ${unreadNotices === 1 ? 'notice' : 'notices'} from WorkMate` : 'Report a problem or follow one you reported' }}</span>
      </span>
      <span v-if="unreadNotices" class="wm-pill wm-pill-warning !py-0 !text-[11px]">New</span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>

    <!-- Document verification (approved providers; applicants do it in Start earning) -->
    <router-link v-if="user && ['approved', 'suspended'].includes(user.provider_status)" to="/account/verification" class="wm-card wm-card-link mt-3 flex items-center gap-4">
      <span class="wm-icon-tile !bg-[#F1F5F9] !text-[#94A3B8]" aria-hidden="true"><i class="bi bi-patch-check-fill"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">{{ myVerification && myVerification.status !== 'draft' ? 'Your verification' : 'Get verified' }}</span>
        <span class="block wm-meta">{{ verificationSummary }}</span>
      </span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>

    <!-- Profile, security & services -->
    <section v-if="user" class="mt-6" aria-labelledby="account-profile-heading">
      <h2 id="account-profile-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Profile</h2>
      <div class="flex flex-col gap-2">

        <div class="wm-card !p-0 overflow-hidden">
          <button
            type="button"
            class="w-full flex items-center gap-3 min-h-[52px] px-4 text-left hover:bg-white/60"
            :aria-expanded="openPanel === 'profile'"
            aria-controls="panel-profile"
            @click="toggle('profile')"
          >
            <i class="bi bi-person text-xl text-muted" aria-hidden="true"></i>
            <span class="flex-1 text-[15px] font-medium text-ink">Edit profile</span>
            <i :class="['bi text-muted transition-transform', openPanel === 'profile' ? 'bi-chevron-up' : 'bi-chevron-down']" aria-hidden="true"></i>
          </button>
          <form
            v-show="openPanel === 'profile'"
            id="panel-profile"
            class="px-4 pb-4 pt-5 flex flex-col gap-4 border-t border-line"
            novalidate
            @submit.prevent="saveProfile"
          >
            <FormField v-model="profile.name" label="Full name" placeholder="Your full name" autocomplete="name" required :error-message="profileErrors.name" />
            <FormField
              v-model="profile.phone"
              type="tel"
              label="Phone number"
              optional
              placeholder="0772 123 456"
              autocomplete="tel"
              :error-message="profileErrors.phone"
            />
            <FormField v-model="profile.locale" type="select" label="Language" :error-message="profileErrors.locale">
              <option value="en">English</option>
              <option value="lg">Luganda</option>
            </FormField>
            <PlacePicker v-model="profile.area" id="profile-area" label="Your area" hint="Saved on this device." />
            <p v-if="profileError" class="wm-notice wm-notice-error" role="alert">
              <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ profileError }}</span>
            </p>
            <PrimaryButton type="submit" :loading="savingProfile">Save profile</PrimaryButton>
            <p v-if="profileSaved" class="text-sm text-success text-center" role="status">
              <i class="bi bi-check-circle-fill mr-1" aria-hidden="true"></i>Profile saved.
            </p>
          </form>
        </div>

        <div class="wm-card !p-0 overflow-hidden">
          <button
            type="button"
            class="w-full flex items-center gap-3 min-h-[52px] px-4 text-left hover:bg-white/60"
            :aria-expanded="openPanel === 'password'"
            aria-controls="panel-password"
            @click="toggle('password')"
          >
            <i class="bi bi-key text-xl text-muted" aria-hidden="true"></i>
            <span class="flex-1 text-[15px] font-medium text-ink">Change password</span>
            <i :class="['bi text-muted', openPanel === 'password' ? 'bi-chevron-up' : 'bi-chevron-down']" aria-hidden="true"></i>
          </button>
          <form
            v-show="openPanel === 'password'"
            id="panel-password"
            class="px-4 pb-4 pt-5 flex flex-col gap-4 border-t border-line"
            novalidate
            @submit.prevent="savePassword"
          >
            <FormField v-model="passwords.current_password" type="password" label="Current password" autocomplete="current-password" required :error-message="passwordErrors.current_password" />
            <FormField
              v-model="passwords.password"
              type="password"
              label="New password"
              autocomplete="new-password"
              required
              hint="At least 8 characters, with letters and numbers."
              :error-message="passwordErrors.password"
            />
            <FormField v-model="passwords.password_confirmation" type="password" label="Confirm new password" autocomplete="new-password" required :error-message="passwordErrors.password_confirmation" />
            <p v-if="passwordError" class="wm-notice wm-notice-error" role="alert">
              <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ passwordError }}</span>
            </p>
            <PrimaryButton type="submit" :loading="savingPassword">Change password</PrimaryButton>
            <p v-if="passwordSaved" class="text-sm text-success text-center" role="status">
              <i class="bi bi-check-circle-fill mr-1" aria-hidden="true"></i>{{ passwordSaved }}
            </p>
          </form>
        </div>

        <div v-if="['approved', 'suspended'].includes(user.provider_status)" class="wm-card !p-0 overflow-hidden">
          <button
            type="button"
            class="w-full flex items-center gap-3 min-h-[52px] px-4 text-left hover:bg-white/60"
            :aria-expanded="openPanel === 'provider'"
            aria-controls="panel-provider"
            @click="toggle('provider')"
          >
            <i class="bi bi-tools text-xl text-muted" aria-hidden="true"></i>
            <span class="flex-1">
              <span class="block text-[15px] font-medium text-ink">Your service details</span>
              <span class="block wm-meta">{{ providerSummary }}</span>
            </span>
            <i :class="['bi text-muted', openPanel === 'provider' ? 'bi-chevron-up' : 'bi-chevron-down']" aria-hidden="true"></i>
          </button>
          <div v-if="openPanel === 'provider'" id="panel-provider" class="px-4 pb-4 pt-5 border-t border-line">
            <ProviderApplicationPanel />
          </div>
        </div>

        <router-link
          v-if="['approved', 'suspended'].includes(user.provider_status) && user.provider_profile_id"
          :to="`/provider/${user.provider_profile_id}`"
          class="wm-card wm-card-link !py-0 flex items-center gap-3 min-h-[52px]"
        >
          <i class="bi bi-image text-xl text-muted" aria-hidden="true"></i>
          <span class="flex-1">
            <span class="block text-[15px] font-medium text-ink">Your profile and work</span>
            <span class="block wm-meta">Show your recent work and change your cover photo</span>
          </span>
          <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
        </router-link>
      </div>
    </section>

    <!-- Signed out: explain how to start offering services -->
    <router-link
      v-else-if="auth.status === 'ready'"
      to="/register?redirect=/start-earning"
      class="wm-card wm-card-link mt-3 flex items-center gap-4"
    >
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-tools"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">Start earning on WorkMate</span>
        <span class="block wm-meta">Create an account, then get hired for your skills in 5 short steps</span>
      </span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>

    <PushNotificationSetting v-if="user" />
    <router-link v-if="user" to="/notifications?settings=1" class="wm-card wm-card-link mt-3 flex items-center gap-4">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-sliders"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">What you’re notified about</span>
        <span class="block wm-meta">Requests, messages, comments, likes and followers</span>
      </span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>

    <router-link v-if="user" to="/account/devices" class="wm-card wm-card-link mt-3 flex items-center gap-4">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-phone"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">Devices</span>
        <span class="block wm-meta">Where you’re signed in, and signing other devices out</span>
      </span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>
    <router-link v-if="user" to="/account/legal" class="wm-card wm-card-link mt-3 flex items-center gap-4">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-shield-lock"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">Legal and privacy</span>
        <span class="block wm-meta">Terms you agreed to, news and offers, and device permissions</span>
      </span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>
    <router-link v-else to="/legal" class="wm-card wm-card-link mt-3 flex items-center gap-4">
      <span class="wm-icon-tile" aria-hidden="true"><i class="bi bi-file-earmark-text"></i></span>
      <span class="min-w-0 flex-1">
        <span class="block text-[15px] font-semibold text-ink">Terms and privacy</span>
        <span class="block wm-meta">Terms and conditions apply to everyone who uses WorkMate</span>
      </span>
      <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
    </router-link>

    <!-- Support contact from Admin → Settings -->
    <section v-if="appSettings.supportPhone || appSettings.supportEmail" class="mt-6" aria-labelledby="account-help-heading">
      <h2 id="account-help-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Help</h2>
      <div class="wm-card !p-0 overflow-hidden divide-y divide-line">
        <a v-if="appSettings.supportPhone" :href="`tel:${appSettings.supportPhone.replace(/\s+/g, '')}`" class="flex items-center gap-3 px-4 min-h-[56px] hover:bg-white/60">
          <i class="bi bi-telephone text-xl text-muted" aria-hidden="true"></i>
          <span class="flex-1"><span class="block text-[15px] font-medium text-ink">Call WorkMate support</span><span class="block wm-meta">{{ appSettings.supportPhone }}</span></span>
        </a>
        <a v-if="appSettings.supportEmail" :href="`mailto:${appSettings.supportEmail}`" class="flex items-center gap-3 px-4 min-h-[56px] hover:bg-white/60">
          <i class="bi bi-envelope text-xl text-muted" aria-hidden="true"></i>
          <span class="flex-1"><span class="block text-[15px] font-medium text-ink">Email WorkMate support</span><span class="block wm-meta">{{ appSettings.supportEmail }}</span></span>
        </a>
      </div>
    </section>

    <!-- Shortcuts -->
    <nav class="mt-6" aria-labelledby="account-shortcuts-heading">
      <h2 id="account-shortcuts-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Shortcuts</h2>
      <ul class="wm-card !p-0 divide-y divide-line overflow-hidden">
        <li v-for="link in shortcuts" :key="link.to">
          <router-link :to="link.to" class="flex items-center gap-3 min-h-[52px] px-4 hover:bg-white/60">
            <i :class="['bi', link.icon, 'text-xl text-muted']" aria-hidden="true"></i>
            <span class="flex-1 text-[15px] font-medium text-ink">{{ link.label }}</span>
            <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
          </router-link>
        </li>
        <li v-if="!pwa.standalone">
          <button type="button" class="w-full flex items-center gap-3 min-h-[52px] px-4 text-left hover:bg-white/60" @click="openInstall">
            <i class="bi bi-phone text-xl text-muted" aria-hidden="true"></i>
            <span class="flex-1 text-[15px] font-medium text-ink">Get the app</span>
            <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
          </button>
        </li>
      </ul>
    </nav>

    <button v-if="user" type="button" class="wm-btn wm-btn-danger-ghost wm-btn-block mt-6" :disabled="signingOut" @click="doSignOut">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i>{{ signingOut ? 'Signing out…' : 'Sign out' }}
    </button>
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import ProfilePhotoEditor from '@/components/ProfilePhotoEditor.vue';
import FormField from '@/components/FormField.vue';
import PrimaryButton from '@/components/PrimaryButton.vue';
import ProviderApplicationPanel from '@/components/ProviderApplicationPanel.vue';
import PushNotificationSetting from '@/components/PushNotificationSetting.vue';
import { saved } from '@/stores/saved.js';
import PlacePicker from '@/components/PlacePicker.vue';
import { appSettings } from '@/stores/catalog.js';
import { selectedArea } from '@/stores/location.js';
import { focusFirstError } from '@/utils/forms.js';
import { VERIFICATION_STATUS } from '@/utils/verificationStatus.js';
import { auth, loadSession, updateProfile, signOut } from '@/stores/auth.js';
import { api, fieldErrors } from '@/api/client.js';
import { pwa, openInstall } from '@/stores/pwa.js';

const route = useRoute();
const router = useRouter();
const user = computed(() => auth.user);

loadSession();

// The provider's document check, if they've started one
const myVerification = ref(null);
// Warnings from the safety team not read yet
const unreadNotices = ref(0);
const verificationSummary = computed(() => (myVerification.value && myVerification.value.status !== 'draft'
  ? VERIFICATION_STATUS[myVerification.value.status]?.label || myVerification.value.status
  : 'Your National ID and supporting documents'));
watch(user, (u) => {
  if (u) api('/me/verification').then(r => { myVerification.value = r.data; }).catch(() => {});
  if (u) api('/me/warnings').then(r => { unreadNotices.value = r.data.filter(w => !w.read).length; }).catch(() => {});
}, { immediate: true });

const VERIFICATION_RESULTS = {
  success: { tone: 'success', icon: 'bi-check-circle', text: 'Your email address is confirmed.' },
  expired: { tone: 'warning', icon: 'bi-clock-history', text: 'That confirmation link has expired.' },
  invalid: { tone: 'error', icon: 'bi-exclamation-triangle', text: 'That confirmation link isn’t valid.' },
};
const verificationNotice = computed(() => {
  const result = VERIFICATION_RESULTS[route.query.email_verification];
  if (!result || result.tone === 'success') return result || null;
  // Signed in: the "Send it again" link is right below; otherwise sign in first
  const next = user.value
    ? (user.value.email_verified ? ' Your email address is already confirmed.' : ' Use “Send it again” below for a new one.')
    : ' Sign in and ask for a new one.';
  return { ...result, text: result.text + next };
});
if (route.query.email_verification === 'success') loadSession({ force: true });

const PROVIDER_SUMMARIES = {
  not_applied: 'Get hired with the same account',
  draft: 'Application not sent yet',
  pending_review: 'Application being reviewed',
  more_info_required: 'WorkMate needs more information',
  approved: 'Approved: customers can hire you',
  rejected: 'Application not approved',
  suspended: 'Provider account suspended',
};
const providerSummary = computed(() => PROVIDER_SUMMARIES[user.value?.provider_status] || PROVIDER_SUMMARIES.not_applied);

// The Start earning card, by where the application stands
const START_CARDS = {
  not_applied: { title: 'Start earning on WorkMate', text: 'Get found by customers and apply for jobs. 5 short steps.' },
  draft: { title: 'Finish becoming a provider', text: 'Your details are saved. Pick up where you left off.' },
  pending_review: { title: 'Your application is being checked', text: 'We’ll let you know as soon as possible.' },
  more_info_required: { title: 'WorkMate asked you to fix something', text: 'See what to change and send it back.' },
  rejected: { title: 'Your application wasn’t approved', text: 'See why, and start again when you’re ready.' },
};
const START = computed(() => START_CARDS[user.value?.provider_status] || START_CARDS.not_applied);

// /account?panel=provider opens the service profile directly (linked from Home)
const openPanel = ref(route.query.panel === 'provider' ? 'provider' : '');
function toggle(id) {
  openPanel.value = openPanel.value === id ? '' : id;
}

// Profile
const profile = reactive({ name: '', phone: '', locale: 'en', area: selectedArea.value });
watch(user, (u) => {
  if (!u) return;
  Object.assign(profile, { name: u.name, phone: u.phone || '', locale: u.locale || 'en' });
}, { immediate: true });

const profileErrors = ref({});
const profileError = ref('');
const savingProfile = ref(false);
const profileSaved = ref(false);

async function saveProfile() {
  profileErrors.value = { name: profile.name.trim() ? '' : 'Enter your name.' };
  profileError.value = '';
  profileSaved.value = false;
  if (profileErrors.value.name) {
    await nextTick();
    return focusFirstError();
  }
  savingProfile.value = true;
  try {
    await updateProfile({ name: profile.name.trim(), phone: profile.phone.trim() || null, locale: profile.locale });
    if (profile.area) selectedArea.value = profile.area;
    profileSaved.value = true;
  } catch (e) {
    profileErrors.value = fieldErrors(e);
    if (!Object.keys(profileErrors.value).length) profileError.value = e.message;
    await nextTick();
    focusFirstError();
  } finally {
    savingProfile.value = false;
  }
}

// Password
const passwords = reactive({ current_password: '', password: '', password_confirmation: '' });
const passwordErrors = ref({});
const passwordError = ref('');
const passwordSaved = ref('');
const savingPassword = ref(false);

async function savePassword() {
  passwordErrors.value = {
    current_password: passwords.current_password ? '' : 'Enter your current password.',
    password: passwords.password.length >= 8 ? '' : 'Use at least 8 characters.',
    password_confirmation: passwords.password === passwords.password_confirmation ? '' : 'The passwords don’t match.',
  };
  passwordError.value = '';
  passwordSaved.value = '';
  if (Object.values(passwordErrors.value).some(Boolean)) {
    await nextTick();
    return focusFirstError();
  }
  savingPassword.value = true;
  try {
    const res = await api('/me/password', { method: 'PUT', body: { ...passwords } });
    passwordSaved.value = res.data.message;
    Object.assign(passwords, { current_password: '', password: '', password_confirmation: '' });
  } catch (e) {
    passwordErrors.value = fieldErrors(e);
    if (!Object.keys(passwordErrors.value).length) passwordError.value = e.message;
    await nextTick();
    focusFirstError();
  } finally {
    savingPassword.value = false;
  }
}

// Email confirmation
const resend = reactive({ busy: false, message: '' });
async function resendVerification() {
  resend.busy = true;
  try {
    resend.message = (await api('/me/email/verification-notification', { method: 'POST', body: {} })).data.message;
  } catch (e) {
    resend.message = e.message;
  } finally {
    resend.busy = false;
  }
}

const signingOut = ref(false);
async function doSignOut() {
  signingOut.value = true;
  try {
    await signOut();
  } finally {
    signingOut.value = false;
    router.push('/');
  }
}

const shortcuts = [
  { to: '/activity',  label: 'My work',     icon: 'bi-briefcase' },
  { to: '/post-job',  label: 'Post a job',  icon: 'bi-plus-square' },
  { to: '/find-work', label: 'Find work',   icon: 'bi-search' },
];
</script>
