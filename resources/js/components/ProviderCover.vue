<template>
  <div class="relative h-[140px] sm:h-[180px] lg:h-[220px] overflow-hidden wm-provider-cover">
    <img v-if="src" :src="src" alt="" class="absolute inset-0 h-full w-full object-cover" />
    <span v-if="busy" class="absolute inset-0 flex items-center justify-center bg-white/50" aria-hidden="true">
      <span class="wm-spinner text-primary"></span>
    </span>

    <!-- The owner's tools; customers only see the picture -->
    <div v-if="editable" class="absolute right-3 top-3 flex items-center gap-2">
      <button v-if="provider.coverUrl && !busy" type="button" class="wm-cover-btn" @click="remove">
        <i class="bi bi-trash3" aria-hidden="true"></i><span class="sr-only sm:not-sr-only">Remove</span>
      </button>
      <!-- The label opens the file picker; on phones it offers the camera too -->
      <label :for="inputId" class="wm-cover-btn cursor-pointer focus-within:ring-2 focus-within:ring-white">
        <i class="bi bi-camera-fill" aria-hidden="true"></i>
        <span>{{ provider.coverUrl ? 'Change cover' : 'Add cover' }}</span>
        <input :id="inputId" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" :disabled="busy" @change="choose" />
      </label>
    </div>
  </div>
  <p v-if="message" :class="['px-5 pt-2 text-[13px]', isError ? 'text-error' : 'text-success']" role="status">{{ message }}</p>
</template>

<script setup>
import { ref, computed, useId, onBeforeUnmount } from 'vue';
import { api, fieldErrors } from '@/api/client.js';

const props = defineProps({
  provider: { type: Object, required: true },
  editable: { type: Boolean, default: false },
});

const inputId = useId();
const busy = ref(false);
const preview = ref('');
const message = ref('');
const isError = ref(false);
const src = computed(() => preview.value || props.provider.coverUrl || '');

const MAX_MB = 5;

function clearPreview() {
  if (preview.value) URL.revokeObjectURL(preview.value);
  preview.value = '';
}

async function choose(event) {
  const file = event.target.files?.[0];
  event.target.value = '';           // choosing the same file again still triggers
  if (!file) return;

  message.value = '';
  isError.value = false;
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) {
    isError.value = true;
    message.value = 'Choose a JPG, PNG or WebP photo.';
    return;
  }
  if (file.size > MAX_MB * 1024 * 1024) {
    isError.value = true;
    message.value = `Choose a photo smaller than ${MAX_MB} MB.`;
    return;
  }

  clearPreview();
  preview.value = URL.createObjectURL(file);   // show it straight away while it uploads
  busy.value = true;
  try {
    const body = new FormData();
    body.append('cover', file);
    props.provider.coverUrl = (await api('/me/provider/cover', { method: 'POST', body })).data.cover_url;
    message.value = 'Cover saved.';
  } catch (e) {
    isError.value = true;
    message.value = fieldErrors(e).cover || e.message;
  } finally {
    busy.value = false;
    clearPreview();
  }
}

async function remove() {
  busy.value = true;
  message.value = '';
  try {
    props.provider.coverUrl = (await api('/me/provider/cover', { method: 'DELETE' })).data.cover_url;
    message.value = 'Cover removed.';
    isError.value = false;
  } catch (e) {
    isError.value = true;
    message.value = e.message;
  } finally {
    busy.value = false;
  }
}

onBeforeUnmount(clearPreview);
</script>
