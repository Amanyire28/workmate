<template>
  <!-- Like a messaging app: the person at the top, messages in between, the box to write at the bottom.
       On phones the chat fills the visible screen, so the keyboard pushes only the writing box up:
       the header stays put and the messages scroll. -->
  <div
    class="mx-auto flex w-full max-w-[860px] flex-col lg:h-[calc(100dvh-var(--wm-header-h)-1px)] lg:px-6 lg:py-4"
    :class="fitted ? 'fixed inset-x-0 z-30' : 'h-[100dvh]'"
    :style="fitted ? { top: `${viewport.top}px`, height: `${viewport.height}px` } : undefined"
  >
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
        <div ref="log" role="log" aria-live="polite" aria-label="Conversation" class="flex min-h-0 flex-1 flex-col gap-2 overflow-y-auto overscroll-contain px-3 py-3 sm:px-4" @scroll.passive="noteScroll">
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
            <!-- Photos: one large, or a grid -->
            <div v-if="photosOf(m).length" class="grid gap-1 overflow-hidden rounded-[18px]" :class="[photosOf(m).length > 1 ? 'grid-cols-2' : 'grid-cols-1', m.body || filesOf(m).length ? 'mb-1' : '']">
              <button
                v-for="p in photosOf(m)"
                :key="p.id"
                type="button"
                class="block overflow-hidden bg-[#DBE4EF]"
                :class="photosOf(m).length > 1 ? 'h-32 w-32 sm:h-40 sm:w-40' : 'max-w-[260px]'"
                aria-label="Open photo"
                @click="viewing = p"
              >
                <img :src="p.thumb_url" alt="Photo sent in the chat" loading="lazy" class="h-full w-full object-cover" :width="p.width" :height="p.height" />
              </button>
            </div>

            <!-- Documents -->
            <a
              v-for="f in filesOf(m)"
              :key="f.id"
              :href="f.url"
              :download="f.name || 'Document.pdf'"
              class="mb-1 flex max-w-[280px] items-center gap-3 rounded-[16px] bg-white px-3 py-2.5 text-ink shadow-[0_1px_3px_rgba(15,23,42,0.08)] hover:bg-[#F8FAFC]"
            >
              <span class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[#FEE2E2] text-[20px] text-[#B91C1C]" aria-hidden="true"><i class="bi bi-file-earmark-pdf"></i></span>
              <span class="min-w-0">
                <span class="block truncate text-[14px] font-medium">{{ f.name || 'Document.pdf' }}</span>
                <span class="block wm-meta !text-[12px]">PDF · {{ fileSize(f.size) }}</span>
              </span>
              <i class="bi bi-download ml-auto text-muted" aria-hidden="true"></i>
            </a>

            <p
              v-if="m.body"
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

        <!-- Writing: always at the bottom, above the keyboard -->
        <div class="shrink-0 border-t border-line bg-white/95 px-2 pt-2 backdrop-blur sm:px-3 lg:pb-2" :class="keyboardOpen ? 'pb-2' : 'pb-[calc(8px+var(--wm-safe-bottom))]'">
          <p v-if="sendError" class="wm-field-error mb-1.5 text-center" role="alert">{{ sendError }}</p>

          <!-- Files waiting to be sent -->
          <ul v-if="pending.length" class="mb-2 flex gap-2 overflow-x-auto pb-1" aria-label="Files to send">
            <li v-for="(f, i) in pending" :key="f.key" class="relative shrink-0">
              <img v-if="f.preview" :src="f.preview" alt="" class="h-16 w-16 rounded-xl object-cover" />
              <span v-else class="flex h-16 w-28 flex-col justify-center rounded-xl bg-[#F1F5F9] px-2 text-[11.5px] text-ink">
                <i class="bi bi-file-earmark-pdf text-[18px] text-[#B91C1C]" aria-hidden="true"></i>
                <span class="truncate">{{ f.file.name }}</span>
              </span>
              <button type="button" class="absolute -right-1.5 -top-1.5 inline-flex h-6 w-6 items-center justify-center rounded-full bg-ink text-white" :aria-label="`Remove ${f.file.name}`" :disabled="sending" @click="removePending(i)">
                <i class="bi bi-x text-sm" aria-hidden="true"></i>
              </button>
            </li>
          </ul>
          <div v-if="sending && progress !== null" class="mb-2 h-1 overflow-hidden rounded-full bg-[#E2E8F0]" role="progressbar" :aria-valuenow="Math.round(progress * 100)" aria-valuemin="0" aria-valuemax="100" aria-label="Sending files">
            <div class="h-full bg-primary transition-[width]" :style="{ width: `${Math.round(progress * 100)}%` }"></div>
          </div>

          <form class="flex items-end gap-1.5" @submit.prevent="send">
            <input ref="picker" type="file" class="sr-only" tabindex="-1" aria-hidden="true" multiple
              accept="image/jpeg,image/png,image/webp,application/pdf,.pdf" @change="addFiles" />
            <button type="button" class="wm-icon-btn shrink-0 !h-11 !w-11 text-[#475569]" aria-label="Add photos or a PDF" :disabled="sending || pending.length >= MAX_FILES" @click="picker?.click()">
              <i class="bi bi-paperclip text-[20px]" aria-hidden="true"></i>
            </button>
            <label for="chat-input" class="sr-only">Message {{ name }}</label>
            <textarea
              id="chat-input"
              ref="input"
              v-model="draft"
              rows="1"
              autocomplete="off"
              maxlength="2000"
              :placeholder="`Message ${name}`"
              class="max-h-32 min-h-11 min-w-0 flex-1 resize-none rounded-[22px] border border-line bg-[#F1F5F9] px-4 py-[10px] text-base leading-snug text-ink placeholder:text-muted focus:border-primary/40 focus:bg-white focus:outline-none"
              @input="grow"
              @keydown.enter="onEnter"
            ></textarea>
            <button
              type="submit"
              class="wm-btn wm-btn-primary !h-11 !w-11 !min-h-11 !px-0 shrink-0 !rounded-full"
              :disabled="!canSend"
              aria-label="Send"
            >
              <span v-if="sending" class="wm-spinner !h-4 !w-4" aria-hidden="true"></span>
              <i v-else class="bi bi-send-fill" aria-hidden="true"></i>
            </button>
          </form>
        </div>
      </template>
    </div>

    <!-- A photo, full screen -->
    <div v-if="viewing" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/90 p-3" role="dialog" aria-modal="true" aria-label="Photo" @click.self="viewing = null" @keydown.esc="viewing = null">
      <img :src="viewing.url" alt="Photo sent in the chat" class="max-h-full max-w-full rounded-lg object-contain" />
      <button ref="closeViewer" type="button" class="absolute right-3 top-[calc(12px+env(safe-area-inset-top))] inline-flex h-11 w-11 items-center justify-center rounded-full bg-white/15 text-white" aria-label="Close photo" @click="viewing = null">
        <i class="bi bi-x-lg text-lg" aria-hidden="true"></i>
      </button>
    </div>

    <!-- Either side can report the other from the chat -->
    <ReportSheet v-if="chat" :open="reportOpen" :about="chat.role === 'customer' ? 'provider' : 'customer'" :name="name"
      :provider-id="chat.role === 'customer' ? chat.provider_id : null" :conversation-id="chat.id" @close="reportOpen = false" />
  </div>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue';
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
const progress = ref(null);
const input = ref(null);
const picker = ref(null);
const log = ref(null);
const endOfLog = ref(null);
const reportOpen = ref(false);
const viewing = ref(null);
const closeViewer = ref(null);

const suggestions = [
  'Hello, are you available this week?',
  'How much would you charge for this job?',
  'Can you share photos of similar work you’ve done?',
];

/* ---------------- Fitting the visible screen (phones) ---------------- */

// On phones the keyboard shrinks the visible area but not the page, so the
// browser would scroll the whole page and hide the header. The chat follows
// the visible area instead: header on top, writing box just above the keyboard.
const viewport = reactive({ height: 0, top: 0 });
const phone = ref(false);
let fullHeight = 0;
const fitted = computed(() => phone.value && viewport.height > 0);
const keyboardOpen = computed(() => fitted.value && fullHeight - viewport.height > 120);

let nearBottom = true;
function noteScroll() {
  const el = log.value;
  if (el) nearBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 120;
}

function syncViewport() {
  const v = window.visualViewport;
  phone.value = !window.matchMedia('(min-width: 1024px)').matches;
  if (!v) return;
  const stick = nearBottom;
  viewport.height = v.height;
  viewport.top = v.offsetTop;
  fullHeight = Math.max(fullHeight, v.height);
  // The keyboard opening shouldn't hide the newest message
  if (stick) nextTick(() => endOfLog.value?.scrollIntoView({ block: 'end' }));
}

onMounted(() => {
  syncViewport();
  window.visualViewport?.addEventListener('resize', syncViewport);
  window.visualViewport?.addEventListener('scroll', syncViewport);
  window.addEventListener('resize', syncViewport);
  // The page behind the chat doesn't scroll
  document.documentElement.classList.add('wm-chat-open');
});
onBeforeUnmount(() => {
  window.visualViewport?.removeEventListener('resize', syncViewport);
  window.visualViewport?.removeEventListener('scroll', syncViewport);
  window.removeEventListener('resize', syncViewport);
  document.documentElement.classList.remove('wm-chat-open');
});

/* ---------------- Writing ---------------- */

function grow() {
  const el = input.value;
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = `${Math.min(el.scrollHeight, 128)}px`;
}

// Enter sends on a computer; on a phone it starts a new line, as in messaging apps
function onEnter(e) {
  if (e.shiftKey || e.isComposing || window.matchMedia('(pointer: coarse)').matches) return;
  e.preventDefault();
  send();
}

/* ---------------- Attachments ---------------- */

const MAX_FILES = 5;
const MAX_BYTES = 10 * 1024 * 1024;
const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];
const pending = ref([]);   // [{ key, file, preview }]
let keySeq = 0;

const canSend = computed(() => !sending.value && (draft.value.trim() !== '' || pending.value.length > 0));

function addFiles(e) {
  sendError.value = '';
  for (const file of Array.from(e.target.files || [])) {
    const isPdf = file.type === 'application/pdf' || /\.pdf$/i.test(file.name);
    if (!TYPES.includes(file.type) && !isPdf) { sendError.value = 'Send photos (JPG, PNG or WebP) or PDF documents.'; continue; }
    if (file.size > MAX_BYTES) { sendError.value = `${file.name} is over 10 MB.`; continue; }
    if (pending.value.length >= MAX_FILES) { sendError.value = `Send up to ${MAX_FILES} photos or files at a time.`; break; }
    pending.value.push({ key: ++keySeq, file, preview: isPdf ? '' : URL.createObjectURL(file) });
  }
  e.target.value = '';
}

function removePending(i) {
  const [f] = pending.value.splice(i, 1);
  if (f?.preview) URL.revokeObjectURL(f.preview);
}

function clearPending() {
  pending.value.forEach(f => f.preview && URL.revokeObjectURL(f.preview));
  pending.value = [];
}

const photosOf = m => (m.attachments || []).filter(a => a.kind === 'photo');
const filesOf = m => (m.attachments || []).filter(a => a.kind === 'file');

function fileSize(bytes) {
  if (!bytes) return '';
  return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

watch(viewing, async (photo) => {
  if (photo) { await nextTick(); closeViewer.value?.focus(); }
});

/* ---------------- Loading and sending ---------------- */

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
  if (!canSend.value || !chat.value) return;
  sending.value = true;
  sendError.value = '';
  const files = pending.value.map(f => f.file);
  progress.value = files.length ? 0 : null;
  try {
    messages.value.push(await sendMessage(chat.value.id, text, files, (p) => { progress.value = p; }));
    draft.value = '';
    clearPending();
    nearBottom = true;
    nextTick(grow);
  } catch (e) {
    sendError.value = e.message;
  } finally {
    sending.value = false;
    progress.value = null;
    input.value?.focus();
  }
}

// Opening a chat lands on the newest message; new ones glide into view
watch(() => messages.value.length, async (count, before) => {
  if (before && !nearBottom) return;   // reading older messages: don't jump
  await nextTick();
  endOfLog.value?.scrollIntoView({ block: 'end', behavior: before ? 'smooth' : 'auto' });
});

// Also runs when moving straight from one chat to another (same component instance)
watch(target, () => {
  chat.value = null;
  draft.value = '';
  clearPending();
  nearBottom = true;
  load();
}, { immediate: true });

// Check for replies while the chat is open
const poll = setInterval(() => {
  if (document.visibilityState === 'visible' && chat.value) load({ quiet: true });
}, 8000);
onBeforeUnmount(() => { clearInterval(poll); clearPending(); });
</script>
