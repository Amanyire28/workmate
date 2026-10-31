<template>
  <div class="flex flex-col items-center gap-1.5 shrink-0">
    <div class="relative">
      <UserAvatar :name="user.name" :src="preview || user.avatar_url || ''" :size="size" />
      <span v-if="busy" class="absolute inset-0 flex items-center justify-center rounded-full bg-white/70" aria-hidden="true">
        <span class="wm-spinner text-primary"></span>
      </span>

      <!-- The label opens the file picker; on phones it offers the camera too -->
      <label
        :for="inputId"
        class="absolute -bottom-1 -right-1 inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-full border-2 border-white bg-primary text-white shadow-[0_4px_10px_rgba(23,105,210,0.35)] hover:bg-[#1460C2] focus-within:ring-2 focus-within:ring-primary focus-within:ring-offset-2"
        :title="user.avatar_url ? 'Change photo' : 'Add a photo'"
      >
        <i class="bi bi-camera-fill text-[15px]" aria-hidden="true"></i>
        <span class="sr-only">{{ user.avatar_url ? 'Change profile photo' : 'Add a profile photo' }}</span>
        <input
          :id="inputId"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          class="sr-only"
          :disabled="busy"
          @change="choose"
        />
      </label>
    </div>

    <button v-if="user.avatar_url && !busy" type="button" class="wm-link text-[13px] min-h-8" @click="remove">Remove photo</button>
    <p v-if="message" :class="['text-[13px] text-center max-w-[180px]', isError ? 'text-error' : 'text-success']" role="status">{{ message }}</p>
  </div>
</template>

<script setup>
import { ref, useId, onBeforeUnmount } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import { api, fieldErrors } from '@/api/client.js';
import { auth, loadCatalogAfterPhoto } from '@/stores/auth.js';

const props = defineProps({
  user: { type: Object, required: true },
  size: { type: Number, default: 72 },
});

const inputId = useId();
const busy = ref(false);
const preview = ref('');
const message = ref('');
const isError = ref(false);

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
    body.append('photo', file);
    auth.user = (await api('/me/photo', { method: 'POST', body })).data;
    message.value = 'Photo saved.';
    loadCatalogAfterPhoto(props.user);
  } catch (e) {
    isError.value = true;
    message.value = fieldErrors(e).photo || e.message;
  } finally {
    busy.value = false;
    clearPreview();
  }
}

async function remove() {
  busy.value = true;
  message.value = '';
  try {
    auth.user = (await api('/me/photo', { method: 'DELETE' })).data;
    message.value = 'Photo removed.';
    isError.value = false;
    loadCatalogAfterPhoto(props.user);
  } catch (e) {
    isError.value = true;
    message.value = e.message;
  } finally {
    busy.value = false;
  }
}

onBeforeUnmount(clearPreview);
</script>
