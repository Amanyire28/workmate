<template>
  <div class="wm-container wm-page max-w-3xl">
    <BackLink to="/account">Account</BackLink>
    <h1 class="wm-h1 mt-1">Invite Friends</h1>
    <p class="wm-lead mt-1.5">Share your link with people who could use WorkMate. When someone you invite joins and confirms their email, you earn coins and move towards your next badge.</p>

    <div v-if="loading" class="mt-5 flex flex-col gap-3" aria-busy="true">
      <div class="wm-skeleton h-[140px] !rounded-[20px]"></div>
      <div class="wm-skeleton h-[96px] !rounded-[20px]"></div>
    </div>

    <EmptyState v-else-if="error" class="mt-4" icon="bi-cloud-slash" title="Your invitations couldn’t load" :message="error">
      <button type="button" class="wm-btn wm-btn-secondary" @click="load">Try again</button>
    </EmptyState>

    <template v-else-if="data">
      <!-- Coins -->
      <section v-if="data.coins" class="wm-card mt-5 flex items-center gap-4" aria-labelledby="invite-coins-heading">
        <span class="inline-flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-[#FEF3C7] text-[26px] text-[#B45309]" aria-hidden="true">
          <i class="bi bi-coin"></i>
        </span>
        <div class="min-w-0 flex-1">
          <h2 id="invite-coins-heading" class="wm-meta">Your coins</h2>
          <p class="text-[26px] font-bold leading-tight text-ink">{{ formatNumber(data.coins.balance) }} <span class="text-[15px] font-semibold text-muted">coins</span></p>
          <p v-if="data.coins.value_ugx" class="text-[14px] text-ink">Worth UGX {{ formatNumber(data.coins.value_ugx) }}</p>
          <p class="wm-meta mt-1">You earn {{ formatNumber(data.coins.per_invitation) }} coins for each friend who joins. Coins can be exchanged for cash.</p>
        </div>
      </section>

      <!-- Your link -->
      <section class="wm-card mt-5" aria-labelledby="invite-link-heading">
        <h2 id="invite-link-heading" class="text-[15px] font-semibold text-ink">Your invitation link</h2>

        <p v-if="!data.can_invite" class="wm-notice wm-notice-info mt-3">
          <i class="bi bi-info-circle" aria-hidden="true"></i>
          <span>Confirm your email address first. Invitations from your link count once your own email is confirmed.</span>
        </p>

        <template v-else>
          <p class="mt-2 rounded-xl bg-[#F1F5F9] px-3 py-2.5 text-[14px] text-ink break-all select-all">{{ data.link }}</p>
          <p class="wm-meta mt-1.5">Your code: <span class="font-semibold text-ink">{{ data.code }}</span></p>

          <div class="mt-3 flex flex-col sm:flex-row gap-2">
            <button v-if="canShare" type="button" class="wm-btn wm-btn-primary sm:flex-1" @click="share">
              <i class="bi bi-share" aria-hidden="true"></i>Share link
            </button>
            <button type="button" class="wm-btn wm-btn-secondary sm:flex-1" @click="copy">
              <i :class="['bi', copied ? 'bi-check2' : 'bi-copy']" aria-hidden="true"></i>{{ copied ? 'Copied' : 'Copy link' }}
            </button>
          </div>
          <p v-if="copyError" class="wm-notice wm-notice-error mt-3" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ copyError }}</span></p>
          <p class="wm-meta mt-3">Someone who opens your link and signs up within {{ data.attribution_days }} {{ data.attribution_days === 1 ? 'day' : 'days' }} is linked to you.</p>
        </template>
      </section>

      <!-- Progress -->
      <section class="mt-5" aria-labelledby="invite-progress-heading">
        <h2 id="invite-progress-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Your invitations</h2>
        <dl class="grid grid-cols-3 gap-3">
          <div class="wm-card !p-3 text-center">
            <dt class="wm-meta">Joined</dt>
            <dd class="mt-0.5 text-xl font-semibold text-ink">{{ data.counts.joined }}</dd>
          </div>
          <div class="wm-card !p-3 text-center">
            <dt class="wm-meta">Counted</dt>
            <dd class="mt-0.5 text-xl font-semibold text-ink">{{ data.counts.qualified }}</dd>
          </div>
          <div class="wm-card !p-3 text-center">
            <dt class="wm-meta">Waiting</dt>
            <dd class="mt-0.5 text-xl font-semibold text-ink">{{ data.counts.waiting }}</dd>
          </div>
        </dl>

        <div v-if="data.next_badge" class="wm-card mt-3">
          <p class="text-[14px] text-ink">
            <span class="font-semibold">{{ data.next_badge.remaining }} more</span>
            {{ data.next_badge.remaining === 1 ? 'member' : 'members' }} to earn <span class="font-semibold">{{ data.next_badge.name }}</span>.
          </p>
          <div class="mt-2 h-2 rounded-full bg-[#F1F5F9] overflow-hidden" role="progressbar"
            :aria-valuenow="data.counts.qualified" aria-valuemin="0" :aria-valuemax="data.next_badge.threshold"
            :aria-label="`Progress towards ${data.next_badge.name}`">
            <div class="h-full rounded-full bg-[#F59E0B]" :style="{ width: `${progress}%` }"></div>
          </div>
        </div>
        <p v-else-if="data.badges.length" class="wm-notice wm-notice-success mt-3">
          <i class="bi bi-award" aria-hidden="true"></i><span>You’ve earned every invitation badge. Thank you for helping WorkMate grow.</span>
        </p>
      </section>

      <!-- Badges -->
      <section v-if="data.badges.length" class="mt-5" aria-labelledby="invite-badges-heading">
        <h2 id="invite-badges-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Badges</h2>
        <div class="flex flex-wrap gap-2">
          <RecognitionBadge v-for="b in data.badges" :key="b.key" :badge="b" :locked="!b.earned_at" />
        </div>
      </section>

      <!-- Who joined -->
      <section class="mt-5" aria-labelledby="invite-history-heading">
        <h2 id="invite-history-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">People who joined</h2>
        <p v-if="!data.history.length" class="wm-card wm-meta">No one has joined through your link yet.</p>
        <ul v-else class="wm-card !p-0 divide-y divide-line overflow-hidden">
          <li v-for="(h, i) in data.history" :key="i" class="flex items-center gap-3 px-4 py-3">
            <span class="min-w-0 flex-1">
              <span class="block text-[15px] font-semibold text-ink truncate">{{ h.name || 'A member' }}</span>
              <span class="block wm-meta">Joined {{ formatDate(h.joined_at) }}<template v-if="h.coins"> · +{{ formatNumber(h.coins) }} coins</template></span>
            </span>
            <span :class="['wm-pill shrink-0', STATUS[h.status]?.pill || 'wm-pill-neutral']">{{ STATUS[h.status]?.label || h.status }}</span>
          </li>
        </ul>
        <p class="wm-meta mt-2 px-1">“Waiting” means they haven’t confirmed their email yet, or a person is checking the sign-up.</p>
      </section>

      <p class="wm-meta mt-6 px-1">
        Badges thank members for helping WorkMate grow; they aren’t a sign of skill, identity or verification.
        Invitations that look unusual are checked by a person, and ones that turn out not to be genuine don’t count or earn coins.
      </p>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import RecognitionBadge from '@/components/invite/RecognitionBadge.vue';
import { api } from '@/api/client.js';

const STATUS = {
  registered: { label: 'Waiting', pill: 'wm-pill-warning' },
  review:     { label: 'Waiting', pill: 'wm-pill-warning' },
  qualified:  { label: 'Counted', pill: 'wm-pill-success' },
  rejected:   { label: 'Not counted', pill: 'wm-pill-neutral' },
  revoked:    { label: 'Not counted', pill: 'wm-pill-neutral' },
};

const data = ref(null);
const loading = ref(true);
const error = ref('');
const copied = ref(false);
const copyError = ref('');
const canShare = typeof navigator !== 'undefined' && typeof navigator.share === 'function';

const progress = computed(() => {
  const next = data.value?.next_badge;
  if (!next || !next.threshold) return 0;
  return Math.min(100, Math.round((data.value.counts.qualified / next.threshold) * 100));
});

async function load() {
  loading.value = true;
  error.value = '';
  try {
    data.value = (await api('/me/referrals')).data;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
onMounted(load);

async function copy() {
  copyError.value = '';
  try {
    await navigator.clipboard.writeText(data.value.link);
    copied.value = true;
    setTimeout(() => { copied.value = false; }, 2500);
  } catch {
    copyError.value = 'Your link couldn’t be copied. Press and hold the link above to copy it.';
  }
}

async function share() {
  try {
    await navigator.share({
      title: 'Join me on WorkMate',
      text: 'I use WorkMate to find trusted service providers. Join me:',
      url: data.value.link,
    });
  } catch { /* closed the share sheet: nothing to do */ }
}

function formatNumber(n) {
  return Number(n || 0).toLocaleString('en-GB');
}

function formatDate(iso) {
  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
}
</script>
