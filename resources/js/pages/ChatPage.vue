<template>
  <div class="wm-container wm-container-sm wm-page !pt-3 lg:!pt-6 !pb-4">
    <div v-if="state === 'loading'" class="wm-card mt-2 flex items-center gap-3 wm-meta" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>Opening chat…
    </div>

    <EmptyState
      v-else-if="state === 'error'"
      icon="bi-chat-left-dots"
      title="This chat isn’t available"
      :message="loadError"
    >
      <router-link to="/messages" class="wm-btn wm-btn-primary !min-h-12">Back to messages</router-link>
    </EmptyState>

    <template v-else-if="chat">
      <!-- Conversation header -->
      <header class="sticky top-[calc(var(--wm-header-h)+8px)] z-20 wm-card !py-2.5 !px-2 sm:!px-3 flex items-center gap-2">
        <router-link to="/messages" class="wm-icon-btn" aria-label="Back to messages">
          <i class="bi bi-arrow-left text-lg" aria-hidden="true"></i>
        </router-link>
        <component
          :is="provider ? 'router-link' : 'div'"
          :to="provider ? `/provider/${provider.id}` : undefined"
          class="flex items-center gap-3 min-w-0 flex-1 min-h-11 rounded-xl"
        >
          <UserAvatar :name="name" :src="provider?.avatarUrl || chat.counterpart_avatar_url" :size="40" />
          <span class="min-w-0">
            <span class="block text-[15px] font-semibold text-ink truncate">
              {{ name }} <VerifiedBadge v-if="provider" :provider="provider" />
            </span>
            <span v-if="provider" class="block wm-meta truncate">{{ availability.label }}<template v-if="availability.detail"> · {{ availability.detail }}</template></span>
            <span v-else class="block wm-meta truncate">Customer</span>
          </span>
        </component>
        <button v-if="provider" type="button" class="wm-icon-btn text-muted" aria-label="Report this provider" @click="reportOpen = true">
          <i class="bi bi-flag text-lg" aria-hidden="true"></i>
        </button>
      </header>

      <p v-if="chat.is_sample_provider && chat.role === 'customer'" class="wm-notice wm-notice-info mt-3 !text-[13px]">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        <span>This is a sample profile shown while WorkMate is in development, so nobody will reply.</span>
      </p>

      <p class="wm-notice wm-notice-warning mt-3 !text-[13px]">
        <i class="bi bi-shield-exclamation" aria-hidden="true"></i>
        <span>
          Keep chats and payments on WorkMate. Never send money before the work is agreed.
        </span>
      </p>

      <!-- Messages -->
      <div role="log" aria-live="polite" aria-label="Conversation" class="mt-4 flex flex-col gap-2 min-h-[30dvh]">
        <p v-if="!messages.length" class="text-center wm-meta my-6">
          {{ chat.role === 'customer' ? 'Ask about availability, price or past work before you hire.' : 'No messages yet.' }}
        </p>

        <div
          v-for="m in messages"
          :key="m.id"
          class="flex flex-col max-w-[82%]"
          :class="m.mine ? 'self-end items-end' : 'self-start items-start'"
        >
          <p
            class="px-4 py-2.5 text-[15px] leading-snug whitespace-pre-wrap break-words"
            :class="m.mine
              ? 'rounded-[20px] rounded-br-md text-white bg-[linear-gradient(180deg,#2B7BE6,#1769D2)] shadow-[0_4px_12px_rgba(23,105,210,0.25)]'
              : 'rounded-[20px] rounded-bl-md text-ink wm-glass-strong'"
          >{{ m.body }}</p>
          <span class="wm-meta !text-[11px] mt-0.5 px-1">{{ timeLabel(m.sent_at) }}</span>
        </div>
        <div ref="endOfLog"></div>
      </div>

      <!-- Suggested openers -->
      <div v-if="!messages.length && chat.role === 'customer'" class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Suggested messages">
        <button
          v-for="s in suggestions"
          :key="s"
          type="button"
          class="wm-chip !h-auto min-h-[38px] max-w-full !shrink py-2 !whitespace-normal text-left"
          @click="draft = s; input?.focus()"
        >{{ s }}</button>
      </div>

      <!-- Composer: floats above the mobile tab bar -->
      <form
        class="sticky bottom-[calc(var(--wm-nav-clearance)+8px)] lg:bottom-6 mt-4 z-20 wm-card !p-1.5 !rounded-full flex items-center gap-1.5"
        @submit.prevent="send"
      >
        <label for="chat-input" class="sr-only">Message {{ name }}</label>
        <input
          id="chat-input"
          ref="input"
          v-model="draft"
          type="text"
          autocomplete="off"
          enterkeyhint="send"
          maxlength="2000"
          :placeholder="`Message ${name}`"
          class="flex-1 min-w-0 h-11 px-4 bg-transparent text-base text-ink placeholder:text-muted focus:outline-none"
        />
        <button
          type="submit"
          class="wm-btn wm-btn-primary !w-11 !px-0 shrink-0"
          :disabled="!draft.trim() || sending"
          aria-label="Send"
        >
          <i class="bi bi-send-fill" aria-hidden="true"></i>
        </button>
      </form>
      <p v-if="sendError" class="wm-field-error mt-2 text-center" role="alert">{{ sendError }}</p>
    </template>

    <ReportProviderSheet v-if="provider" :open="reportOpen" :provider-id="provider.id" :provider-name="name" @close="reportOpen = false" />
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import EmptyState from '@/components/EmptyState.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import ReportProviderSheet from '@/components/ReportProviderSheet.vue';
import { providers } from '@/stores/catalog.js';
import { displayName } from '@/utils/ui.js';
import { availabilityInfo } from '@/utils/trust.js';
import { timeLabel } from '@/utils/time.js';
import { openChat, sendMessage } from '@/stores/messages.js';

const route = useRoute();

// Customers open chats by provider (/messages/:id); providers by conversation (/messages/c/:conversationId)
const target = computed(() => (route.params.conversationId
  ? { conversationId: Number(route.params.conversationId) }
  : { providerId: Number(route.params.id) }));

const state = ref('loading');
const loadError = ref('');
const chat = ref(null);
const messages = ref([]);

// On the customer side the header shows the provider's public profile
const provider = computed(() => (chat.value?.role === 'customer'
  ? providers.find(p => p.id === chat.value.provider_id) || null
  : null));
const name = computed(() => (provider.value ? displayName(provider.value) : chat.value?.counterpart_name || ''));
const availability = computed(() => (provider.value ? availabilityInfo(provider.value) : {}));

const draft = ref('');
const sending = ref(false);
const sendError = ref('');
const input = ref(null);
const endOfLog = ref(null);
const reportOpen = ref(false);

const suggestions = [
  'Hello, are you available this week?',
  'How much would you charge for this job?',
  'Can you share photos of similar work you’ve done?',
];

async function load({ quiet = false } = {}) {
  if (!quiet) state.value = 'loading';
  try {
    const data = await openChat(chat.value && quiet ? { conversationId: chat.value.id } : target.value);
    chat.value = data;
    messages.value = data.messages;
    state.value = 'ready';
  } catch (e) {
    if (quiet) return;   // a missed background check is retried on the next one
    loadError.value = e.status === 404 ? 'This conversation doesn’t exist or you’re not part of it.' : e.message;
    state.value = 'error';
  }
}

async function send() {
  const text = draft.value.trim();
  if (!text || !chat.value || sending.value) return;
  sending.value = true;
  sendError.value = '';
  try {
    messages.value.push(await sendMessage(chat.value.id, text));
    draft.value = '';
  } catch (e) {
    sendError.value = e.message;
  } finally {
    sending.value = false;
    input.value?.focus();
  }
}

// Keep the newest message in view
watch(() => messages.value.length, async () => {
  await nextTick();
  endOfLog.value?.scrollIntoView({ block: 'end', behavior: 'smooth' });
});

// Also runs when moving straight from one chat to another (same component instance)
watch(target, () => {
  chat.value = null;
  draft.value = '';
  load();
}, { immediate: true });

// Check for replies while the chat is open
const poll = setInterval(() => {
  if (document.visibilityState === 'visible' && chat.value) load({ quiet: true });
}, 8000);
onBeforeUnmount(() => clearInterval(poll));
</script>
