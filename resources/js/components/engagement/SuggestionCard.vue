<template>
  <!-- A suggestion that stays in the page while it applies (no timer, nothing to dismiss) -->
  <section v-if="card" class="wm-glass-card wm-suggestion rounded-[16px] p-4" :aria-labelledby="headingId" :data-suggestion="card.key">
    <div class="flex items-start gap-3">
      <span class="wm-icon-tile shrink-0" aria-hidden="true"><i :class="['bi', card.icon]"></i></span>
      <div class="min-w-0 flex-1">
        <h2 :id="headingId" class="text-[16px] font-semibold text-ink leading-snug">{{ card.title }}</h2>
        <p class="mt-0.5 text-[13.5px] leading-snug text-[#475569]">{{ card.body }}</p>

        <div v-if="card.progress !== null && card.progress !== undefined" class="mt-2.5 flex items-center gap-2.5">
          <div class="wm-suggestion-bar flex-1" role="progressbar" :aria-valuenow="card.progress" aria-valuemin="0" aria-valuemax="100" :aria-label="`${card.progress}% complete`">
            <span :style="{ width: card.progress + '%' }"></span>
          </div>
          <span class="text-[12.5px] font-medium text-ink shrink-0">{{ card.progress }}%</span>
        </div>
      </div>
    </div>
    <router-link :to="card.action.url" class="wm-btn wm-btn-primary !min-h-11 !rounded-[12px] mt-3 w-full sm:w-auto sm:ml-[60px]">
      {{ card.action.label }}
    </router-link>
  </section>
</template>

<script setup>
import { computed, watch, onMounted } from 'vue';
import { auth } from '@/stores/auth.js';
import { engagement, loadSuggestions, suggestionFor } from '@/stores/engagement.js';

/**
 * The page's suggestion card ("Add your services and prices"...). The server
 * decides which card each page shows, so the same one isn't repeated on every
 * page; it disappears once the action is done.
 */
const props = defineProps({
  page: { type: String, required: true },   // home, account, work, provider, community
});
const headingId = computed(() => `suggestion-${props.page}-heading`);

const card = computed(() => (auth.user && engagement.userId === auth.user.id ? suggestionFor(props.page) : null));

// Checked again when they change something that counts (name, phone, photo, provider status)
const signature = computed(() => (auth.user
  ? [auth.user.id, auth.user.name, auth.user.email_verified, auth.user.phone, auth.user.avatar_url, auth.user.provider_status].join('|')
  : ''));
watch(signature, (now, before) => { if (now !== before) loadSuggestions(auth.user?.id || null); });
onMounted(() => { if (auth.user) loadSuggestions(auth.user.id); });
</script>
