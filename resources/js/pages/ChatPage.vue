<template>
  <!-- Like a messaging app: the person at the top, messages in between, the box to write at the bottom -->
  <div class="mx-auto flex h-[100dvh] w-full max-w-[860px] flex-col lg:h-[calc(100dvh-var(--wm-header-h)-1px)] lg:px-6 lg:py-4">
    <div class="flex min-h-0 flex-1 flex-col bg-[#EEF3F9] lg:overflow-hidden lg:rounded-[22px] lg:border lg:border-line lg:shadow-[0_8px_30px_rgba(15,23,42,0.08)]">

      <!-- The other person -->
      <header class="z-20 flex shrink-0 items-center gap-1.5 border-b border-line bg-white/95 px-1.5 pb-2 pt-[calc(8px+env(safe-area-inset-top))] backdrop-blur sm:px-3 lg:pt-2">
        <router-link to="/messages" class="wm-icon-btn" aria-label="Back to messages">
          <i class="bi bi-arrow-left text-xl" aria-hidden="true"></i>
        </router-link>
        <template v-if="chat">
          <component
            :is="provider ? 'router-link' : 'div'"
            :to="provider ? `/provider/${provider.id}` : undefined"
            class="flex min-h-11 min-w-0 flex-1 items-center gap-2.5 rounded-xl"
          >
            <UserAvatar :name="name" :src="provider?.avatarUrl || chat.counterpart_avatar_url" :size="40" />
            <span class="min-w-0">
              <span class="block truncate text-[16px] font-semibold text-ink">
                {{ name }} <VerifiedBadge v-if="provider" :provider="provider" />
              </span>
              <span v-if="provider" class="block truncate text-[12.5px] text-muted">{{ availability.label }}<template v-if="availability.detail"> · {{ availability.detail }}</template></span>
              <span v-else class="block truncate text-[12.5px] text-muted">Customer</span>
            </span>
          </component>
          <button type="button" class="wm-icon-btn text-muted" :aria-label="chat.role === 'customer' ? 'Report this provider' : 'Report this customer'" @click="reportOpen = true">
            <i class="bi bi-flag text-lg" aria-hidden="true"></i>
          </button>
        </template>
        <span v-else class="flex-1 text-[16px] font-semibold text-ink">Chat</span>
      </header>

      <div v-if="state === 'loading'" class="flex flex-1 items-center justify-center gap-3 wm-meta" role="status">
        <span class="wm-spinner text-primary" aria-hidden="true"></span>Opening chat…
      </div>

      <div v-else-if="state === 'error'" class="flex-1 overflow-y-auto px-4">
        <EmptyState icon="bi-chat-left-dots" title="This chat isn’t available" :message="loadError">
          <router-link to="/messages" class="wm-btn wm-btn-primary !min-h-12">Back to messages</router-link>
        </EmptyState>
      </div>

      <template v-else-if="chat">
        <!-- Messages scroll; the bars stay put -->
        <div role="log" aria-live="polite" aria-label="Conversation" class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto overscroll-contain px-3 py-3 sm:px-4">
          <p class="mx-auto mb-1 max-w-[440px] rounded-xl bg-[#FEF3C7] px-3 py-2 text-center text-[12.5px] leading-snug text-[#92400E]">
            <i class="bi bi-shield-exclamation mr-1" aria-hidden="true"></i>Keep chats and payments on WorkMate. Never send money before the work is agreed.
          </p>
          <p v-if="chat.is_sample_provider && chat.role === 'customer'" class="mx-auto max-w-[440px] rounded-xl bg-white/80 px-3 py-2 text-center text-[12.5px] text-muted">
            This is a sample profile, so nobody will reply.
          </p>

          <p v-if="!messages.length" class="my-6 text-center wm-meta">
            {{ chat.role === 'customer' ? 'Ask about availability, price or past work before you hire.' : 'No messages yet.' }}
          </p>

          <div
            v-for="m in messages"
            :key="m.id"
            class="flex max-w-[82%] flex-col"
            :class="m.mine ? 'self-end items-end' : 'self-start items-start'"
          >
            <p
              class="px-3.5 py-2 text-[15px] leading-snug whitespace-pre-wrap break-words"
              :class="m.mine
                ? 'rounded-[18px] rounded-br-md text-white bg-[linear-gradient(180deg,#2B7BE6,#1769D2)] shadow-[0_2px_8px_rgba(23,105,210,0.22)]'
                : 'rounded-[18px] rounded-bl-md bg-white text-ink shadow-[0_1px_3px_rgba(15,23,42,0.08)]'"
            >{{ m.body }}</p>
            <span class="mt-0.5 px-1 wm-meta !text-[11px]">{{ timeLabel(m.sent_at) }}</span>
          </div>

          <!-- Suggested openers -->
          <div v-if="!messages.length && chat.role === 'customer'" class="mt-2 flex flex-wrap justify-center gap-2" role="group" aria-label="Suggested messages">
            <button
              v-for="s in suggestions"
              :key="s"
              type="button"
              class="wm-chip !h-auto min-h-[38px] max-w-full !shrink py-2 !whitespace-normal text-left"
              @click="draft = s; input?.focus()"
            >{{ s }}</button>
          </div>
          <div ref="endOfLog"></div>
        </div>

        <!-- Writing: always at the bottom -->
        <div class="shrink-0 border-t border-line bg-white/95 px-2 pt-2 pb-[calc(8px+var(--wm-safe-bottom))] backdrop-blur sm:px-3 lg:pb-2">
          <p v-if="sendError" class="wm-field-error mb-1.5 text-center" role="alert">{{ sendError }}</p>
          <form class="flex items-center gap-2" @submit.prevent="send">
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
              class="h-11 min-w-0 flex-1 rounded-full border border-line bg-[#F1F5F9] px-4 text-base text-ink placeholder:text-muted focus:border-primary/40 focus:bg-white focus:outline-none"
            />
            <button
              type="submit"
              class="wm-btn wm-btn-primary !w-11 !px-0 shrink-0 !rounded-full"
              :disabled="!draft.trim() || sending"
              aria-label="Send"
            >
              <i class="bi bi-send-fill" aria-hidden="true"></i>
            </button>
          </form>
        </div>
      </template>
    </div>

    <!-- Either side can report the other from the chat -->
    <ReportSheet v-if="chat" :open="reportOpen" :about="chat.role === 'customer' ? 'provider' : 'customer'" :name="name"
      :provider-id="chat.role === 'customer' ? chat.provider_id : null" :conversation-id="chat.id" @close="reportOpen = false" />
  </div>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';
import { isOwnProvider } from '@/stores/auth.js';
import { useRoute } from 'vue-router';
import EmptyState from '@/components/EmptyState.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import ReportSheet from '@/components/ReportSheet.vue';
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
  // Your own provider profile: there's nobody to message
  if (target.value.providerId && isOwnProvider(target.value.providerId)) {
    loadError.value = 'This is your own profile, so you can’t message it. Messages customers send you are in Messages.';
    state.value = 'error';
    return;
  }
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
// Opening a chat lands on the newest message; new ones glide into view
watch(() => messages.value.length, async (count, before) => {
  await nextTick();
  endOfLog.value?.scrollIntoView({ block: 'end', behavior: before ? 'smooth' : 'auto' });
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
