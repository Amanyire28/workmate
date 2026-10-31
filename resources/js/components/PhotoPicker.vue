<template>
  <div>
    <p class="wm-label">{{ label }} <span class="font-normal text-muted">(optional)</span></p>

    <label
      :for="inputId"
      class="flex flex-col items-center justify-center gap-1 min-h-[88px] px-4 py-3 rounded-2xl border border-dashed border-line-strong bg-white/50 text-center cursor-pointer hover:border-primary hover:bg-primary/5 transition-colors focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/30"
    >
      <i class="bi bi-camera text-[22px] text-primary" aria-hidden="true"></i>
      <span class="text-sm font-medium text-ink">Add photos</span>
      <span class="wm-meta">Up to {{ max }} images. They stay on your device in this preview.</span>
      <input
        :id="inputId"
        type="file"
        accept="image/*"
        multiple
        class="sr-only"
        :disabled="photos.length >= max"
        @change="onPick"
      />
    </label>

    <ul v-if="photos.length" class="mt-3 grid grid-cols-3 sm:grid-cols-4 gap-2">
      <li v-for="(photo, i) in photos" :key="photo.url" class="relative aspect-square rounded-lg overflow-hidden border border-line">
        <img :src="photo.url" :alt="photo.name" class="w-full h-full object-cover" />
        <button
          type="button"
          class="absolute top-1 right-1 w-8 h-8 rounded-full bg-ink/70 text-white inline-flex items-center justify-center"
          :aria-label="`Remove ${photo.name}`"
          @click="remove(i)"
        >
          <i class="bi bi-x-lg text-sm" aria-hidden="true"></i>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup>
import { ref, onBeforeUnmount, useId } from 'vue';

defineProps({
  label: { type: String, default: 'Photos of the job' },
});

const max = 6;
const inputId = useId();
const photos = ref([]);

function onPick(e) {
  const files = [...e.target.files].slice(0, max - photos.value.length);
  for (const file of files) photos.value.push({ name: file.name, url: URL.createObjectURL(file) });
  e.target.value = '';
}

function remove(i) {
  URL.revokeObjectURL(photos.value[i].url);
  photos.value.splice(i, 1);
}

onBeforeUnmount(() => photos.value.forEach(p => URL.revokeObjectURL(p.url)));
</script>
