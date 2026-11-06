<template>
  <div v-if="photos.length">
    <ul class="flex flex-wrap gap-2" :aria-label="`${photos.length} ${photos.length === 1 ? 'photo' : 'photos'} of the job`">
      <li v-for="(p, i) in photos" :key="p.id">
        <button type="button" class="block overflow-hidden rounded-xl bg-[#E2E8F0] focus-visible:outline-offset-2"
          :class="size === 'lg' ? 'h-24 w-24' : 'h-16 w-16'" :aria-label="`Open photo ${i + 1} of ${photos.length}`" @click.stop="open = i">
          <img :src="p.thumb_url" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover" />
        </button>
      </li>
    </ul>
    <PhotoViewer v-if="open !== null" :media="photos" :start="open" :label="label" @close="open = null" />
  </div>
</template>

<script setup>
import { ref } from 'vue';
import PhotoViewer from '@/components/community/PhotoViewer.vue';

/** Photos of a job on a request: thumbnails that open full screen */
defineProps({
  photos: { type: Array, default: () => [] },
  label: { type: String, default: 'Photos of the job' },
  size: { type: String, default: 'md' },   // md | lg
});

const open = ref(null);
</script>
