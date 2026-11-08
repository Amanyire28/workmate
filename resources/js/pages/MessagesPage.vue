<template>
  <div class="wm-container wm-container-sm wm-page">
    <h1 class="wm-h1">Messages</h1>

    <div v-if="state === 'loading'" class="mt-5 wm-card flex items-center gap-3 wm-meta" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>Loading your chats…
    </div>

    <EmptyState
      v-else-if="state === 'error'"
      tone="error"
      icon="bi-wifi-off"
      title="Couldn’t load your messages"
      message="Check your connection and try again."
    >
      <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="load">Try again</button>
    </EmptyState>

    <ul v-else-if="threads.length" class="mt-5 wm-card !p-0 overflow-hidden divide-y divide-line">
      <li v-for="t in threads" :key="t.id">
        <router-link
          :to="t.to"
          class="flex items-center gap-3 px-4 py-3.5 min-h-[72px] hover:bg-white/60 transition-colors"
        >
          <UserAvatar :name="t.name" :src="t.avatar" :size="48" />
          <div class="min-w-0 flex-1">
            <div class="flex items-baseline justify-between gap-3">
              <p class="text-[15px] text-ink truncate" :class="t.unread ? 'font-bold' : 'font-semibold'">
                {{ t.name }}
                <VerifiedBadge v-if="t.provider" :provider="t.provider" class="ml-0.5" />
                <span v-if="t.role === 'provider'" class="wm-pill wm-pill-info !py-0 !text-[11px] ml-1">Customer</span>
              </p>
              <span class="wm-meta shrink-0">{{ timeLabel(t.last.sent_at) }}</span>
            </div>
            <div class="flex items-center justify-between gap-3">
              <p class="text-sm truncate" :class="t.unread ? 'text-ink font-medium' : 'text-muted'">
                <span v-if="t.last.mine">You: </span>{{ t.last.body }}
              </p>
              <span
                v-if="t.unread"
                class="shrink-0 min-w-5 h-5 px-1.5 rounded-full bg-primary text-white text-[11px] font-bold leading-5 text-center"
              >{{ t.unread }}<span class="sr-only"> unread</span></span>
            </div>
          </div>
        </router-link>
      </li>
    </ul>

    <EmptyState
      v-else
      icon="bi-chat-dots"
      title="No messages yet"
      message="Message a provider to ask about availability, price or past work before you hire them."
    >
      <router-link to="/browse" class="wm-btn wm-btn-primary !min-h-12">Find a provider</router-link>
    </EmptyState>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import EmptyState from '@/components/EmptyState.vue';
import { providers } from '@/stores/catalog.js';
import { loadThreads } from '@/stores/messages.js';
import { displayName } from '@/utils/ui.js';
import { timeLabel } from '@/utils/time.js';

const state = ref('loading');
const raw = ref([]);

const threads = computed(() => raw.value.map((c) => {
  const provider = c.role === 'customer' ? providers.find(p => p.id === c.provider_id) : null;
  return {
    id: c.id,
    role: c.role,
    provider,
    name: provider ? displayName(provider) : c.counterpart_name,
    avatar: provider?.avatarUrl || c.counterpart_avatar_url,
    last: c.last_message,
    unread: c.unread,
    // Customers reach chats by provider; providers by conversation
    to: c.role === 'customer' ? `/messages/${c.provider_id}` : `/messages/c/${c.id}`,
  };
}).filter(t => t.last));

async function load() {
  state.value = 'loading';
  try {
    raw.value = await loadThreads();
    state.value = 'ready';
  } catch {
    state.value = 'error';
  }
}

onMounted(load);
</script>
