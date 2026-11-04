<template>
  <section :id="anchor" class="wm-card scroll-mt-24" aria-labelledby="comments-heading">
    <h2 id="comments-heading" class="text-[16px] font-semibold text-ink">
      Comments<span v-if="post.comments_count" class="ml-1 font-normal text-muted">({{ post.comments_count }})</span>
    </h2>

    <!-- Write a comment -->
    <form class="mt-3 flex items-end gap-2.5" novalidate @submit.prevent="send(null)">
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
    <p v-if="error" class="wm-field-error mt-2" role="alert">{{ error }}</p>

    <!-- The comments -->
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

        <ul v-if="c.replies.length || replyTo === c.id" class="mt-3 ml-11 flex flex-col gap-3 border-l-2 border-line/80 pl-3">
          <li v-for="r in c.replies" :key="r.id">
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
  </section>
</template>

<script setup>
import { ref, onMounted, nextTick, h } from 'vue';
import { RouterLink } from 'vue-router';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import { api, fieldErrors } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { agoLabel } from '@/stores/catalog.js';
import { LIMITS, needsSignIn, showCommunityError, personLink } from '@/stores/community.js';

const props = defineProps({
  post: { type: Object, required: true },
  anchor: { type: String, default: 'comments' },
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
  el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
}

async function startReply(top, reply = null) {
  if (needsSignIn()) return;
  replyTo.value = top.id;
  replyDraft.value = reply ? `@${reply.author.name.split(' ')[0]} ` : '';
  await nextTick();
  document.getElementById(`reply-${top.id}`)?.focus();
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
