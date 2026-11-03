<template>
  <BottomSheet :open="open" :title="editing ? 'Edit post' : 'Create a post'" @close="cancel">
    <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <div class="flex items-center gap-3">
        <UserAvatar :name="auth.user?.name || 'You'" :src="auth.user?.avatar_url || ''" :size="40" />
        <p class="text-[15px] font-semibold text-ink">{{ auth.user?.name }}</p>
      </div>

      <p v-if="!emailVerified" class="wm-notice wm-notice-warning" role="status">
        <i class="bi bi-envelope-exclamation" aria-hidden="true"></i>
        <span>Confirm your email address to post. Use the link we sent you, then come back.</span>
      </p>

      <fieldset>
        <legend class="wm-label">What are you sharing?</legend>
        <div class="flex flex-wrap gap-2">
          <button v-for="c in POST_CATEGORIES" :key="c.id" type="button" class="wm-chip !h-10" :aria-pressed="category === c.id" @click="category = c.id">
            <i :class="['bi', c.icon]" aria-hidden="true"></i>{{ c.label }}
          </button>
        </div>
        <p class="wm-field-hint mt-1.5">{{ categoryOf(category).hint }}</p>
      </fieldset>

      <div class="flex flex-col gap-1.5">
        <label :for="`${formId}-body`" class="sr-only">Your post</label>
        <textarea
          :id="`${formId}-body`"
          ref="bodyField"
          v-model="body"
          rows="4"
          :maxlength="LIMITS.body"
          class="wm-input !min-h-[120px] !py-3 !text-[16px] leading-relaxed resize-none"
          placeholder="Share something about your work..."
          @input="grow"
        ></textarea>
        <p v-if="body.length > LIMITS.body - 300" class="wm-field-hint text-right" aria-live="polite">{{ LIMITS.body - body.length }} characters left</p>
      </div>

      <!-- Photos: previewed before publishing; can't be changed afterwards -->
      <div v-if="!editing">
        <ul v-if="photos.length" class="mb-3 grid grid-cols-3 gap-2">
          <li v-for="(p, i) in photos" :key="p.key" class="relative aspect-square overflow-hidden rounded-xl bg-[#E2E8F0]">
            <img :src="p.preview" alt="" class="h-full w-full object-cover" />
            <span v-if="p.preparing" class="absolute inset-0 flex items-center justify-center bg-white/60"><span class="wm-spinner text-primary" aria-hidden="true"></span></span>
            <button type="button" class="absolute right-1 top-1 inline-flex h-8 w-8 items-center justify-center rounded-full bg-ink/70 text-white" :aria-label="`Remove photo ${i + 1}`" :disabled="busy" @click="removePhoto(i)">
              <i class="bi bi-x-lg text-sm" aria-hidden="true"></i>
            </button>
          </li>
        </ul>
        <input :id="`${formId}-photos`" ref="photoInput" type="file" accept="image/jpeg,image/png,image/webp,image/*" multiple class="sr-only" @change="pickPhotos" />
        <label v-if="photos.length < LIMITS.photos" :for="`${formId}-photos`" class="wm-btn wm-btn-secondary !min-h-11 cursor-pointer" :class="{ 'pointer-events-none opacity-60': busy }">
          <i class="bi bi-images" aria-hidden="true"></i>{{ photos.length ? 'Add more photos' : 'Add photos' }}
          <span class="font-normal text-muted">({{ photos.length }}/{{ LIMITS.photos }})</span>
        </label>
      </div>
      <p v-else-if="post?.media?.length" class="wm-field-hint">Photos can’t be changed. To use different photos, delete this post and share a new one.</p>

      <div class="flex flex-col gap-1.5">
        <label :for="`${formId}-topic`" class="wm-label">Profession <span class="font-normal text-muted">(optional)</span></label>
        <select :id="`${formId}-topic`" v-model="topic" class="wm-input">
          <option value="">{{ isProvider ? 'My main profession' : 'Not about one profession' }}</option>
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
        </select>
        <p class="wm-field-hint">Helps people in that line of work find it.</p>
      </div>

      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
    </form>

    <template #footer>
      <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="busy" @click="cancel">Cancel</button>
      <button type="submit" :form="formId" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="busy || !emailVerified || preparing">
        <template v-if="busy"><span class="wm-spinner" aria-hidden="true"></span>{{ progressLabel }}</template>
        <template v-else>{{ editing ? 'Save changes' : 'Publish' }}</template>
      </button>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch, nextTick, useId } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { api, fieldErrors } from '@/api/client.js';
import { auth, isProvider } from '@/stores/auth.js';
import { categories } from '@/stores/catalog.js';
import { showFlash } from '@/stores/flash.js';
import { POST_CATEGORIES, LIMITS, categoryOf, preparePhoto, publishPost } from '@/stores/community.js';

const props = defineProps({
  open: { type: Boolean, default: false },
  // A post of the member's own to edit (words, kind and profession only)
  post: { type: Object, default: null },
});
const emit = defineEmits(['close', 'published', 'updated']);

const formId = useId();
const editing = computed(() => !!props.post);
const emailVerified = computed(() => !!auth.user?.email_verified);

const category = ref('my_work');
const body = ref('');
const topic = ref('');
const photos = ref([]);      // { key, file, preview, preparing }
const error = ref('');
const busy = ref(false);
const progress = ref(0);
const bodyField = ref(null);
const photoInput = ref(null);
let keys = 0;

const preparing = computed(() => photos.value.some(p => p.preparing));
const progressLabel = computed(() => (editing.value ? 'Saving…' : progress.value > 0 && progress.value < 1 ? `Uploading ${Math.round(progress.value * 100)}%` : 'Publishing…'));

watch(() => props.open, async (open) => {
  if (!open) return;
  error.value = '';
  if (props.post) {
    category.value = props.post.category;
    body.value = props.post.body || '';
    topic.value = props.post.topic?.slug || '';
  } else if (!body.value && !photos.value.length) {
    // A fresh post: providers usually show their work, others ask
    category.value = isProvider.value ? 'my_work' : 'question';
  }
  await nextTick();
  grow();
});

function grow() {
  const el = bodyField.value;
  if (!el) return;
  el.style.height = 'auto';
  el.style.height = `${Math.min(el.scrollHeight, 360)}px`;
}

async function pickPhotos(e) {
  const chosen = [...e.target.files];
  e.target.value = '';
  error.value = '';
  const room = LIMITS.photos - photos.value.length;
  if (chosen.length > room) error.value = `You can add up to ${LIMITS.photos} photos. The first ${room} were added.`;

  for (const file of chosen.slice(0, room)) {
    if (!file.type.startsWith('image/')) { error.value = `${file.name} isn’t a photo.`; continue; }
    const entry = { key: ++keys, file, preview: URL.createObjectURL(file), preparing: true };
    photos.value.push(entry);
    const ready = await preparePhoto(file);
    const item = photos.value.find(p => p.key === entry.key);
    if (!item) continue;
    if (ready.size > LIMITS.photoMb * 1024 * 1024) {
      error.value = `${file.name} is too large (up to ${LIMITS.photoMb} MB).`;
      removePhoto(photos.value.indexOf(item));
      continue;
    }
    item.file = ready;
    item.preparing = false;
  }
}

function removePhoto(i) {
  const [gone] = photos.value.splice(i, 1);
  if (gone) URL.revokeObjectURL(gone.preview);
}

function reset() {
  body.value = '';
  topic.value = '';
  photos.value.forEach(p => URL.revokeObjectURL(p.preview));
  photos.value = [];
  progress.value = 0;
  error.value = '';
}

function cancel() {
  if (busy.value) return;
  const unsaved = editing.value ? body.value !== (props.post.body || '') : (body.value.trim() || photos.value.length);
  if (unsaved && !window.confirm(editing.value ? 'Discard your changes?' : 'Discard this post?')) return;
  if (!editing.value) reset();
  emit('close');
}

async function submit() {
  // One submission at a time, however many taps
  if (busy.value || preparing.value) return;
  error.value = '';
  const text = body.value.trim();
  if (!text && !photos.value.length && !(editing.value && props.post.media?.length)) {
    error.value = 'Write something or add a photo.';
    return;
  }

  busy.value = true;
  progress.value = 0;
  try {
    if (editing.value) {
      const updated = (await api(`/community/posts/${props.post.id}`, { method: 'PATCH', body: { body: text || null, category: category.value, topic: topic.value || null } })).data;
      emit('updated', updated);
      showFlash('Post updated.');
      emit('close');
    } else {
      const post = await publishPost({ category: category.value, body: text, topic: topic.value, photos: photos.value.map(p => p.file) }, (p) => { progress.value = p; });
      reset();
      emit('published', post);
      showFlash('Posted. Thanks for sharing.');
      emit('close');
    }
  } catch (e) {
    const first = Object.values(fieldErrors(e))[0];
    error.value = e.status === 429 ? 'You’re posting quickly. Wait a minute and try again.'
      : e.code === 'email_not_verified' ? 'Confirm your email address to post.'
      : (first || e.message);
  } finally {
    busy.value = false;
  }
}
</script>
