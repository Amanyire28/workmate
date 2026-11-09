<template>
  <BottomSheet :open="open" :title="editing ? 'Edit work' : 'Add work'" @close="cancel">
    <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <!-- Photos first: they're what customers look at -->
      <div v-if="!editing">
        <p class="wm-label">Photos <span class="font-normal text-muted">(up to {{ MAX_PHOTOS }})</span></p>
        <ul class="grid grid-cols-4 gap-2">
          <li v-for="(p, i) in photos" :key="p.key" class="relative aspect-square overflow-hidden rounded-xl bg-[#E2E8F0]">
            <img :src="p.preview" alt="" class="h-full w-full object-cover" />
            <span v-if="i === 0" class="absolute left-1 bottom-1 rounded-full bg-ink/70 px-1.5 py-0.5 text-[10.5px] font-semibold text-white">Cover</span>
            <span v-if="p.preparing" class="absolute inset-0 flex items-center justify-center bg-white/60"><span class="wm-spinner text-primary" aria-hidden="true"></span></span>
            <button type="button" class="absolute right-1 top-1 inline-flex h-7 w-7 items-center justify-center rounded-full bg-ink/70 text-white" :aria-label="`Remove photo ${i + 1}`" :disabled="busy" @click="removePhoto(i)">
              <i class="bi bi-x-lg text-[12px]" aria-hidden="true"></i>
            </button>
          </li>
          <li v-if="photos.length < MAX_PHOTOS">
            <label :for="`${formId}-photos`"
              class="flex aspect-square cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-primary/30 bg-primary-tint text-primary hover:border-primary/60"
              :class="{ 'pointer-events-none opacity-60': busy }">
              <i class="bi bi-camera text-xl" aria-hidden="true"></i>
              <span class="text-[12px] font-semibold">{{ photos.length ? 'Add' : 'Add photos' }}</span>
            </label>
          </li>
        </ul>
        <input :id="`${formId}-photos`" type="file" accept="image/jpeg,image/png,image/webp,image/*" multiple class="sr-only" @change="pickPhotos" />
        <p class="wm-field-hint mt-1.5">Clear photos of the finished job work best. Before-and-after photos are great too.</p>
      </div>
      <p v-else class="wm-field-hint">Photos can’t be changed. To use different photos, delete this work and add it again.</p>

      <FormField v-model="title" label="What was the job?" required maxlength="120" placeholder="e.g. Fixed a leaking kitchen pipe" :error-message="errors.title" />

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="flex flex-col gap-1.5">
          <label :for="`${formId}-category`" class="wm-label">Type of job</label>
          <select :id="`${formId}-category`" v-model="category" class="wm-input">
            <option value="">My main profession</option>
            <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
          </select>
        </div>
        <div class="flex flex-col gap-1.5">
          <label :for="`${formId}-month`" class="wm-label">When <span class="font-normal text-muted">(optional)</span></label>
          <input :id="`${formId}-month`" v-model="doneOn" type="month" class="wm-input" :max="thisMonth" />
          <p v-if="errors.done_on" class="wm-field-error">{{ errors.done_on }}</p>
        </div>
      </div>

      <PlacePicker :id="`${formId}-area`" v-model="area" label="Where" optional placeholder="Your base area" hint="Leave empty to use your base area." />

      <FormField v-model="description" type="textarea" label="About the job" optional maxlength="500" :min-height="90"
        placeholder="What you did, materials used, how long it took" :error-message="errors.description" />

      <label v-if="!editing" class="flex items-start gap-3 rounded-xl bg-[#F1F5F9] p-3 cursor-pointer">
        <input v-model="share" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-[var(--color-primary)]" />
        <span>
          <span class="block text-[14.5px] font-medium text-ink">Also share it in the Community</span>
          <span class="block text-[13px] text-muted">It stays on your profile either way.</span>
        </span>
      </label>

      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
    </form>

    <template #footer>
      <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="busy" @click="cancel">Cancel</button>
      <button type="submit" :form="formId" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="busy || preparing">
        <template v-if="busy"><span class="wm-spinner" aria-hidden="true"></span>{{ progressLabel }}</template>
        <template v-else>{{ editing ? 'Save changes' : 'Add to my profile' }}</template>
      </button>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch, useId } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import FormField from '@/components/FormField.vue';
import PlacePicker from '@/components/PlacePicker.vue';
import { api, upload, fieldErrors } from '@/api/client.js';
import { categories } from '@/stores/catalog.js';
import { showFlash } from '@/stores/flash.js';
import { preparePhoto } from '@/utils/photos.js';

const MAX_PHOTOS = 4;
const PHOTO_MB = 8;

const props = defineProps({
  open: { type: Boolean, default: false },
  // Work of the provider's own to edit (details only)
  item: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const formId = useId();
const editing = computed(() => !!props.item);
const thisMonth = new Date().toISOString().slice(0, 7);

const title = ref('');
const category = ref('');
const doneOn = ref('');
const area = ref('');
const description = ref('');
const share = ref(false);
const photos = ref([]);      // { key, file, preview, preparing }
const errors = ref({});
const error = ref('');
const busy = ref(false);
const progress = ref(0);
let keys = 0;

const preparing = computed(() => photos.value.some(p => p.preparing));
const progressLabel = computed(() => (editing.value ? 'Saving…' : progress.value > 0 && progress.value < 1 ? `Uploading ${Math.round(progress.value * 100)}%` : 'Saving…'));

watch(() => props.open, (open) => {
  if (!open) return;
  error.value = '';
  errors.value = {};
  if (props.item) {
    title.value = props.item.title;
    category.value = props.item.category || '';
    doneOn.value = props.item.done_on || '';
    area.value = props.item.area || '';
    description.value = props.item.description || '';
  }
});

async function pickPhotos(e) {
  const chosen = [...e.target.files];
  e.target.value = '';
  error.value = '';
  const room = MAX_PHOTOS - photos.value.length;
  if (chosen.length > room) error.value = `You can add up to ${MAX_PHOTOS} photos. The first ${room} were added.`;

  for (const file of chosen.slice(0, room)) {
    if (!file.type.startsWith('image/')) { error.value = `${file.name} isn’t a photo.`; continue; }
    const entry = { key: ++keys, file, preview: URL.createObjectURL(file), preparing: true };
    photos.value.push(entry);
    const ready = await preparePhoto(file);
    const item = photos.value.find(p => p.key === entry.key);
    if (!item) continue;
    if (ready.size > PHOTO_MB * 1024 * 1024) {
      error.value = `${file.name} is too large (up to ${PHOTO_MB} MB).`;
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
  title.value = '';
  category.value = '';
  doneOn.value = '';
  area.value = '';
  description.value = '';
  share.value = false;
  photos.value.forEach(p => URL.revokeObjectURL(p.preview));
  photos.value = [];
  progress.value = 0;
  error.value = '';
  errors.value = {};
}

function cancel() {
  if (busy.value) return;
  const unsaved = editing.value ? title.value !== props.item.title || description.value !== (props.item.description || '') : (title.value.trim() || photos.value.length);
  if (unsaved && !window.confirm(editing.value ? 'Discard your changes?' : 'Discard this work?')) return;
  if (!editing.value) reset();
  emit('close');
}

async function submit() {
  // One submission at a time, however many taps
  if (busy.value || preparing.value) return;
  error.value = '';
  errors.value = {};
  if (!editing.value && !photos.value.length) { error.value = 'Add at least one photo of the work.'; return; }
  if (!title.value.trim()) { errors.value = { title: 'Say what the job was.' }; return; }

  busy.value = true;
  progress.value = 0;
  try {
    if (editing.value) {
      const updated = (await api(`/me/provider/work/${props.item.id}`, { method: 'PATCH', body: {
        title: title.value.trim(), description: description.value.trim() || null, category: category.value || null, area: area.value || null, done_on: doneOn.value || null,
      } })).data;
      emit('saved', updated, false);
      showFlash('Work updated.');
    } else {
      const form = new FormData();
      form.append('title', title.value.trim());
      if (description.value.trim()) form.append('description', description.value.trim());
      if (category.value) form.append('category', category.value);
      if (area.value) form.append('area', area.value);
      if (doneOn.value) form.append('done_on', doneOn.value);
      if (share.value) form.append('share', '1');
      photos.value.forEach(p => form.append('photos[]', p.file, p.file.name || 'photo.jpg'));
      const res = await upload('/me/provider/work', form, { onProgress: (v) => { progress.value = v; } });
      emit('saved', res.data, true);
      showFlash(res.share_error ? `Added to your profile. It couldn’t be shared in the Community: ${res.share_error}` : 'Added to your profile.');
      reset();
    }
    emit('close');
  } catch (e) {
    errors.value = fieldErrors(e);
    error.value = Object.keys(errors.value).length ? (errors.value.photos || errors.value['photos.0'] || '') : e.message;
  } finally {
    busy.value = false;
  }
}
</script>
