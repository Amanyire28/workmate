<template>
  <!-- In a sheet: comments scroll and the box to write one stays at the bottom -->
  <section :id="anchor" :class="sheet ? 'flex h-full min-h-0 flex-col' : 'wm-card scroll-mt-24'" :aria-labelledby="sheet ? undefined : 'comments-heading'" :aria-label="sheet ? 'Comments' : undefined">
    <h2 v-if="!sheet" id="comments-heading" class="text-[16px] font-semibold text-ink">
      Comments<span v-if="post.comments_count" class="ml-1 font-normal text-muted">({{ post.comments_count }})</span>
    </h2>

    <!-- Write a comment -->
    <form class="flex items-end gap-2.5" :class="sheet ? 'order-last border-t border-line bg-white px-4 pt-3 pb-[calc(12px+var(--wm-safe-bottom))]' : 'mt-3'" novalidate @submit.prevent="send(null)">
      <UserAvatar v-if="auth.user" :name="auth.user.name" :src="auth.user.avatar_url || ''" :size="36" class="mb-1" />
      <div class="min-w-0 flex-1">
        <label for="comment-new" class="sr-only">Write a comment</label>
        <textarea id="comment-new" ref="newField" v-model="draft" rows="1" :maxlength="LIMITS.comment" class="wm-input !min-h-11 !py-2.5 resize-none"
          :placeholder="post.category === 'question' ? 'Share what you know…' : 'Write a comment…'"
          @focus="needsSignIn()" @input="grow($event.target)" @keydown.enter.exact.prevent="send(null)"></textarea>
      </div>
      <button type="submit" class="wm-btn wm-btn-primary !min-h-11 !px-4" :disabled="sending === 'new' || !draft.trim()">
        <span v-if="sending === 'new'" class="wm-spinner" aria-hidden="true"></span><span>Post</span>
      </button>
    </form>
    <p v-if="error" class="wm-field-error mt-2" :class="{ 'order-last px-4 pb-2': sheet }" role="alert">{{ error }}</p>

    <!-- The comments -->
    <div :class="sheet ? 'order-first min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-4' : ''">
      <div v-if="loading" class="mt-4 flex flex-col gap-4" aria-busy="true">
        <div v-for="n in 2" :key="n" class="flex gap-3"><div class="wm-skeleton h-9 w-9 !rounded-full"></div><div class="flex-1"><div class="wm-skeleton h-4 w-32"></div><div class="wm-skeleton mt-2 h-4 w-full"></div></div></div>
      </div>
      <p v-else-if="loadError" class="wm-notice wm-notice-error mt-4" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i><span>{{ loadError }} <button type="button" class="wm-link" @click="load(true)">Try again</button></span>
      </p>
      <p v-else-if="!comments.length" class="mt-4 text-sm text-muted">{{ post.category === 'question' ? 'No answers yet. Know something that could help?' : 'No comments yet. Be the first to say something.' }}</p>

      <ul v-else class="mt-4 flex flex-col gap-4">
        <li v-for="c in comments" :key="c.id">
          <CommentItem :comment="c" @reply="startReply(c)" @delete="remove(c)" @report="$emit('report', c)" />

          <!-- Replies stay folded away until someone opens them -->
          <button v-if="c.replies.length" type="button" class="ml-11 mt-1 inline-flex min-h-9 items-center gap-2 text-[13px] font-semibold text-primary hover:underline"
            :aria-expanded="isOpen(c)" @click="toggleReplies(c)">
            <span class="h-px w-6 bg-line" aria-hidden="true"></span>
            {{ isOpen(c) ? 'Hide replies' : (c.replies.length === 1 ? 'View 1 reply' : `View ${c.replies.length} replies`) }}
          </button>

          <ul v-if="isOpen(c) || replyTo === c.id" class="mt-2 ml-11 flex flex-col gap-3 border-l-2 border-line/80 pl-3">
            <li v-for="r in (isOpen(c) ? c.replies : [])" :key="r.id">
              <CommentItem :comment="r" small @reply="startReply(c, r)" @delete="remove(r, c)" @report="$emit('report', r)" />
            </li>
            <li v-if="replyTo === c.id">
              <form class="flex items-end gap-2" novalidate @submit.prevent="send(c)">
                <label :for="`reply-${c.id}`" class="sr-only">Write a reply</label>
                <textarea :id="`reply-${c.id}`" v-model="replyDraft" rows="1" :maxlength="LIMITS.comment" class="wm-input !min-h-10 !py-2 resize-none flex-1"
                  placeholder="Write a reply…" @input="grow($event.target)" @keydown.enter.exact.prevent="send(c)" @keydown.esc="replyTo = null"></textarea>
                <button type="submit" class="wm-btn wm-btn-primary !min-h-10 !px-3.5" :disabled="sending === c.id || !replyDraft.trim()">Reply</button>
              </form>
            </li>
          </ul>
        </li>
      </ul>

      <button v-if="cursor && !loading" type="button" class="wm-btn wm-btn-ghost mt-3 !min-h-10" :disabled="loadingMore" @click="load()">
        {{ loadingMore ? 'Loading…' : 'Show more comments' }}
      </button>
    </div>
  </section>
</template>

<script setup>
import { ref, reactive, onMounted, nextTick, h } from 'vue';
import { RouterLink } from 'vue-router';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import { api, fieldErrors } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { agoLabel } from '@/stores/catalog.js';
import { LIMITS, needsSignIn, showCommunityError, personLink, toggleCommentLike, shortCount, countLabel } from '@/stores/community.js';

const props = defineProps({
  post: { type: Object, required: true },
  anchor: { type: String, default: 'comments' },
  // Shown in the comments sheet rather than on the post's page
  sheet: { type: Boolean, default: false },
});
defineEmits(['report']);

const comments = ref([]);
const cursor = ref(null);
const loading = ref(true);
const loadingMore = ref(false);
const loadError = ref('');
const draft = ref('');
const replyDraft = ref('');
const replyTo = ref(null);
const sending = ref('');
const error = ref('');
const newField = ref(null);

// Which comments have their replies showing
const opened = reactive(new Set());
const isOpen = (c) => opened.has(c.id);
function toggleReplies(c) {
  if (opened.has(c.id)) opened.delete(c.id);
  else opened.add(c.id);
}

async function load(fresh = false) {
  if (fresh) { comments.value = []; cursor.value = null; loading.value = true; }
  else loadingMore.value = true;
  loadError.value = '';
  try {
    const r = await api(`/community/posts/${props.post.id}/comments${cursor.value ? `?cursor=${encodeURIComponent(cursor.value)}` : ''}`);
    comments.value.push(...r.data);
    cursor.value = r.meta.next_cursor;
  } catch (e) {
    loadError.value = e.message;
  } finally {
    loading.value = false;
    loadingMore.value = false;
  }
}
onMounted(() => load(true));

function grow(el) {
  el.style.height = 'auto';
  // In the sheet a long comment scrolls inside its box, so the comments stay in view
  el.style.height = `${Math.min(el.scrollHeight, props.sheet ? 120 : 200)}px`;
}

async function startReply(top, reply = null) {
  if (needsSignIn()) return;
  replyTo.value = top.id;
  opened.add(top.id);   // replying shows the conversation it joins
  replyDraft.value = reply ? `@${reply.author.name.split(' ')[0]} ` : '';
  await nextTick();
  const field = document.getElementById(`reply-${top.id}`);
  if (!field) return;
  if (props.sheet) {
    // Only the comments list scrolls to it; the sheet and the page stay as they are
    field.focus({ preventScroll: true });
    field.scrollIntoView({ block: 'nearest' });
  } else {
    field.focus();
  }
}

async function send(parent) {
  if (needsSignIn()) return;
  const text = (parent ? replyDraft.value : draft.value).trim();
  const key = parent ? parent.id : 'new';
  if (!text || sending.value) return;
  sending.value = key;
  error.value = '';
  try {
    const r = await api(`/community/posts/${props.post.id}/comments`, { method: 'POST', body: { body: text, parent: parent ? parent.id : null } });
    if (parent) {
      parent.replies.push(r.data);
      replyDraft.value = '';
      replyTo.value = null;
    } else {
      // Newest at the end, where the conversation continues
      if (!cursor.value) comments.value.push(r.data);
      draft.value = '';
      if (newField.value) newField.value.style.height = '';
    }
    props.post.comments_count = r.meta.comments_count;
  } catch (e) {
    const first = Object.values(fieldErrors(e))[0];
    error.value = e.status === 429 ? 'You’re commenting quickly. Wait a moment and try again.' : (first || e.message);
  } finally {
    sending.value = '';
  }
}

async function remove(c, parent = null) {
  if (!window.confirm('Delete this comment?')) return;
  try {
    const r = await api(`/community/comments/${c.id}`, { method: 'DELETE' });
    props.post.comments_count = r.meta.comments_count;
    if (parent) parent.replies = parent.replies.filter(x => x.id !== c.id);
    else if (c.replies.length) Object.assign(c, { gone: true, body: null, author: null, can_delete: false });
    else comments.value = comments.value.filter(x => x.id !== c.id);
  } catch (e) {
    showCommunityError(e);
  }
}

/** One comment: who, when, what, and what you can do with it */
const CommentItem = {
  props: { comment: Object, small: Boolean },
  emits: ['reply', 'delete', 'report'],
  setup(p, { emit }) {
    return () => {
      const c = p.comment;
      if (c.gone) return h('p', { class: 'text-sm italic text-muted' }, 'This comment was deleted.');
      const a = c.author;
      const link = personLink(a);
      const name = link
        ? h(RouterLink, { to: link, class: 'font-semibold text-ink hover:underline' }, () => a.name)
        : h('span', { class: 'font-semibold text-ink' }, a.name);
      const action = (label, fn, cls = '') => h('button', { type: 'button', class: `min-h-9 font-semibold hover:text-ink ${cls}`, onClick: fn }, label);
      const like = h('button', {
        type: 'button',
        class: ['inline-flex min-h-9 items-center gap-1 font-semibold hover:text-ink', c.liked ? 'text-primary' : ''],
        'aria-pressed': c.liked ? 'true' : 'false',
        'aria-label': `Like. ${countLabel(c.likes_count, 'like')}`,
        onClick: () => toggleCommentLike(c),
      }, [
        h('i', { class: ['bi text-[14px]', c.liked ? 'bi-hand-thumbs-up-fill' : 'bi-hand-thumbs-up'], 'aria-hidden': 'true' }),
        c.likes_count ? h('span', { class: 'tabular-nums' }, shortCount(c.likes_count)) : null,
      ]);

      return h('div', { class: 'flex gap-2.5' }, [
        h(UserAvatar, { name: a.name, src: a.avatar_url || '', size: p.small ? 30 : 36 }),
        h('div', { class: 'min-w-0 flex-1' }, [
          h('div', { class: 'rounded-2xl bg-[#F1F5F9] px-3.5 py-2' }, [
            h('p', { class: 'flex flex-wrap items-center gap-x-1.5 text-[13.5px]' }, [
              name,
              h(VerifiedBadge, { provider: { documentVerified: a.document_verified, blueTick: a.blue_tick } }),
              a.title ? h('span', { class: 'text-muted' }, `· ${a.title}`) : null,
            ]),
            h('p', { class: 'mt-0.5 text-[14.5px] leading-relaxed text-ink whitespace-pre-line break-words' }, c.body),
          ]),
          h('div', { class: 'mt-0.5 flex flex-wrap items-center gap-x-4 px-1 text-[12.5px] text-muted' }, [
            h('time', { datetime: c.created_at }, agoLabel(c.created_at)),
            like,
            action('Reply', () => emit('reply')),
            c.can_delete ? action('Delete', () => emit('delete'), 'hover:text-error') : null,
            !c.mine ? action('Report', () => { if (!needsSignIn()) emit('report'); }) : null,
          ]),
        ]),
      ]);
    };
  },
};
</script>
