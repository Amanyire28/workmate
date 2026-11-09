<template>
  <Teleport to="body">
    <Transition name="wm-fade">
      <div v-if="open && slide" class="fixed inset-0 z-[110] flex flex-col bg-[#0B1220] text-white lg:flex-row" role="dialog" aria-modal="true" :aria-label="`Recent work: ${slide.item.title}`"
        @keydown.esc="$emit('close')" @keydown.left.prevent="go(-1)" @keydown.right.prevent="go(1)" tabindex="-1" ref="root">

        <!-- Photos: swipe on phones, arrows on bigger screens -->
        <div class="relative min-h-0 flex-1">
          <div ref="track" class="flex h-full snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden" @scroll.passive="onScroll">
            <div v-for="(s, i) in slides" :key="s.key" class="flex h-full w-full shrink-0 snap-center items-center justify-center p-2 lg:p-6">
              <img :src="Math.abs(i - index) <= 1 ? s.photo.url : s.photo.thumb_url" :alt="`${s.item.title}, photo ${s.n} of ${s.item.photos.length}`"
                class="max-h-full max-w-full rounded-lg object-contain" :class="{ 'opacity-40': s.item.status === 'removed' }" />
            </div>
          </div>

          <button type="button" class="absolute left-3 top-[calc(10px+env(safe-area-inset-top))] inline-flex h-11 w-11 items-center justify-center rounded-full bg-black/45 hover:bg-black/60" aria-label="Close" @click="$emit('close')">
            <i class="bi bi-x-lg text-lg" aria-hidden="true"></i>
          </button>
          <span class="absolute right-3 top-[calc(14px+env(safe-area-inset-top))] rounded-full bg-black/45 px-3 py-1 text-[13px] font-medium" aria-live="polite">{{ index + 1 }} / {{ slides.length }}</span>

          <button v-if="index > 0" type="button" class="absolute left-3 top-1/2 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 hover:bg-white/25 sm:inline-flex" aria-label="Previous photo" @click="go(-1)">
            <i class="bi bi-chevron-left text-xl" aria-hidden="true"></i>
          </button>
          <button v-if="index < slides.length - 1" type="button" class="absolute right-3 top-1/2 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-white/15 hover:bg-white/25 sm:inline-flex" aria-label="Next photo" @click="go(1)">
            <i class="bi bi-chevron-right text-xl" aria-hidden="true"></i>
          </button>

          <!-- Dots for the photos of this job -->
          <div v-if="slide.item.photos.length > 1" class="pointer-events-none absolute inset-x-0 bottom-3 flex justify-center gap-1.5" aria-hidden="true">
            <span v-for="n in slide.item.photos.length" :key="n" class="h-1.5 rounded-full transition-all" :class="n === slide.n ? 'w-5 bg-white' : 'w-1.5 bg-white/45'"></span>
          </div>
        </div>

        <!-- About the job -->
        <aside class="max-h-[42vh] shrink-0 overflow-y-auto border-t border-white/10 bg-[#111A2E] px-5 pt-4 pb-[calc(16px+env(safe-area-inset-bottom))] lg:max-h-none lg:w-[360px] lg:border-l lg:border-t-0 lg:pt-16">
          <p v-if="slide.item.status === 'removed'" class="mb-3 rounded-xl bg-[#7F1D1D]/60 px-3 py-2 text-[13px]">
            <i class="bi bi-eye-slash mr-1" aria-hidden="true"></i>Removed by WorkMate<template v-if="slide.item.removed_reason">: {{ slide.item.removed_reason }}</template>. Only you can see it.
          </p>
          <h2 class="text-[18px] font-semibold leading-snug">{{ slide.item.title }}</h2>
          <p class="mt-1.5 flex flex-wrap gap-x-3 gap-y-1 text-[13.5px] text-white/70">
            <span v-if="slide.item.category_name" class="inline-flex items-center gap-1"><i class="bi bi-tools" aria-hidden="true"></i>{{ slide.item.category_name }}</span>
            <span v-if="slide.item.area_name" class="inline-flex items-center gap-1"><i class="bi bi-geo-alt" aria-hidden="true"></i>{{ slide.item.area_name }}</span>
            <span v-if="slide.item.done_on" class="inline-flex items-center gap-1"><i class="bi bi-calendar3" aria-hidden="true"></i>{{ monthLabel(slide.item.done_on) }}</span>
          </p>
          <p v-if="slide.item.description" class="mt-3 whitespace-pre-line text-[14.5px] leading-relaxed text-white/90">{{ slide.item.description }}</p>
          <p class="mt-3 text-[12px] text-white/50">Shared by {{ isOwn ? 'you' : providerName }}. Reviews on the profile come from customers who hired them through WorkMate.</p>

          <div class="mt-4 flex flex-wrap gap-2">
            <template v-if="isOwn">
              <button v-if="slide.item.status !== 'removed'" type="button" class="wm-btn !min-h-11 bg-white/10 text-white hover:bg-white/20" @click="$emit('edit', slide.item)">
                <i class="bi bi-pencil" aria-hidden="true"></i> Edit
              </button>
              <button type="button" class="wm-btn !min-h-11 bg-white/10 text-[#FCA5A5] hover:bg-white/20" @click="$emit('delete', slide.item)">
                <i class="bi bi-trash3" aria-hidden="true"></i> Delete
              </button>
            </template>
            <button v-else-if="canReport" type="button" class="wm-btn !min-h-11 bg-white/10 text-white/80 hover:bg-white/20" @click="$emit('report', slide.item)">
              <i class="bi bi-flag" aria-hidden="true"></i> Report
            </button>
          </div>
        </aside>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount } from 'vue';

const props = defineProps({
  open: { type: Boolean, default: false },
  items: { type: Array, default: () => [] },
  // Which job to open on
  startId: { type: String, default: '' },
  isOwn: { type: Boolean, default: false },
  canReport: { type: Boolean, default: false },
  providerName: { type: String, default: 'the provider' },
});
defineEmits(['close', 'edit', 'delete', 'report']);

const root = ref(null);
const track = ref(null);
const index = ref(0);

/** Every photo of every job, in order, so people can swipe through all the work */
const slides = computed(() => props.items.flatMap(item => item.photos.map((photo, i) => ({ key: `${item.id}-${i}`, item, photo, n: i + 1 }))));
const slide = computed(() => slides.value[index.value] || null);

watch(() => props.open, async (open) => {
  document.documentElement.style.overflow = open ? 'hidden' : '';
  if (!open) return;
  index.value = Math.max(0, slides.value.findIndex(s => s.item.id === props.startId));
  await nextTick();
  if (track.value) track.value.scrollLeft = index.value * track.value.clientWidth;
  root.value?.focus();
});

// Removing the last job closes the viewer; otherwise stay within range
watch(slides, (list) => { if (index.value >= list.length) index.value = Math.max(0, list.length - 1); });

function onScroll() {
  const el = track.value;
  if (!el || !el.clientWidth) return;
  index.value = Math.min(slides.value.length - 1, Math.round(el.scrollLeft / el.clientWidth));
}

function go(step) {
  const next = Math.min(slides.value.length - 1, Math.max(0, index.value + step));
  track.value?.scrollTo({ left: next * track.value.clientWidth, behavior: 'smooth' });
  index.value = next;
}

function monthLabel(ym) {
  const [y, m] = ym.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'long', year: 'numeric' });
}

onBeforeUnmount(() => { document.documentElement.style.overflow = ''; });
</script>
