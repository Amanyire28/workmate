<template>
  <Teleport to="body">
    <Transition name="wm-comments">
      <!-- Fills the visible screen: with the keyboard open, the sheet sits just above it -->
      <div v-if="post" class="fixed inset-x-0 top-0 z-[90] flex h-dvh flex-col justify-end" :style="viewportStyle" @keydown.esc="$emit('close')">
        <div class="wm-comments-backdrop absolute inset-0 bg-ink/40" aria-hidden="true" @click="$emit('close')"></div>

        <!-- Half the screen, the same whether reading, writing or replying; dragging the handle (or tapping it) switches to 80% -->
        <div ref="panel" class="wm-comments-panel relative mx-auto flex w-full max-w-[680px] flex-col overflow-hidden rounded-t-[22px] bg-white shadow-[0_-10px_40px_rgba(15,23,42,0.18)]"
          :style="{ height: panelHeight, transform: dragY ? `translateY(${dragY}px)` : '', transition: dragging ? 'none' : '' }"
          role="dialog" aria-modal="true" :aria-labelledby="titleId" tabindex="-1">

          <!-- The handle: drag it, or tap to switch size -->
          <div class="shrink-0 cursor-grab touch-none select-none border-b border-line/70 px-4 pt-2 pb-2.5"
            @pointerdown="dragStart" @pointermove="dragMove" @pointerup="dragEnd" @pointercancel="dragEnd">
            <button type="button" class="mx-auto block h-5 w-16" :aria-label="expanded ? 'Make comments smaller' : 'Show more comments'" @click="toggle">
              <span class="mx-auto block h-1.5 w-10 rounded-full bg-[#CBD5E1]" aria-hidden="true"></span>
            </button>
            <div class="flex items-center justify-between gap-3">
              <h2 :id="titleId" class="text-[17px] font-semibold text-ink">
                Comments<span v-if="post.comments_count" class="ml-1 font-normal text-muted">{{ post.comments_count }}</span>
              </h2>
              <button type="button" class="wm-icon-btn -mr-2" aria-label="Close comments" @click="$emit('close')" @pointerdown.stop>
                <i class="bi bi-x-lg text-lg" aria-hidden="true"></i>
              </button>
            </div>
          </div>

          <CommentThread class="min-h-0 flex-1" :post="post" sheet :anchor="`sheet-comments-${post.id}`" @report="$emit('report', $event)" />
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, reactive, computed, watch, nextTick, useId, onBeforeUnmount } from 'vue';
import CommentThread from './CommentThread.vue';

const HALF = 50;
const FULL = 80;

const props = defineProps({
  // The post whose comments are open, or null when closed
  post: { type: Object, default: null },
});
const emit = defineEmits(['close', 'report']);

const titleId = useId();
const panel = ref(null);
const height = ref(HALF);
const dragY = ref(0);
const dragging = ref(false);
let startY = 0;
let moved = false;

const expanded = computed(() => height.value === FULL);

/* The visible part of the screen. On phones the keyboard takes the bottom of
   it: the sheet keeps its size and moves up above the keyboard, and only
   gets shorter when there isn't room (a strip of the page always shows). */
const viewport = reactive({ top: 0, height: 0 });
let fullHeight = 0;     // the visible height without the keyboard
let width = 0;
function syncViewport() {
  const v = window.visualViewport;
  if (window.innerWidth !== width) { width = window.innerWidth; fullHeight = 0; }   // turned round
  viewport.height = v ? v.height : window.innerHeight;
  viewport.top = v ? v.offsetTop : 0;
  fullHeight = Math.max(fullHeight, viewport.height);
}
const viewportStyle = computed(() => (viewport.height ? { top: `${viewport.top}px`, height: `${viewport.height}px` } : {}));
const panelHeight = computed(() => {
  if (!viewport.height) return `${height.value}dvh`;
  const wanted = (fullHeight * height.value) / 100;
  const room = viewport.height - Math.max(48, viewport.height * 0.12);
  return `${Math.round(Math.min(wanted, room))}px`;
});

function listen(on) {
  const method = on ? 'addEventListener' : 'removeEventListener';
  window.visualViewport?.[method]('resize', syncViewport);
  window.visualViewport?.[method]('scroll', syncViewport);
  window[method]('resize', syncViewport);
  // The page behind stays still (phones scroll it to show a focused box otherwise)
  document.documentElement.classList.toggle('wm-sheet-open', on);
}

watch(() => props.post, async (post) => {
  document.documentElement.style.overflow = post ? 'hidden' : '';
  listen(!!post);
  if (!post) return;
  fullHeight = 0;
  syncViewport();
  height.value = HALF;
  dragY.value = 0;
  await nextTick();
  panel.value?.focus({ preventScroll: true });
});

function toggle() {
  if (moved) return;
  height.value = expanded.value ? HALF : FULL;
}

/* Dragging the handle: up grows it, down shrinks it, further down closes it */
function dragStart(e) {
  dragging.value = true;
  moved = false;
  startY = e.clientY;
  e.currentTarget.setPointerCapture?.(e.pointerId);
}

function dragMove(e) {
  if (!dragging.value) return;
  const dy = e.clientY - startY;
  if (Math.abs(dy) > 6) moved = true;
  if (dy < 0) {
    // Pulling up: grow towards 80% as the finger moves
    const grown = height.value + (-dy / window.innerHeight) * 100;
    if (grown >= FULL) { height.value = FULL; startY = e.clientY; dragY.value = 0; return; }
  }
  dragY.value = Math.max(dy, expanded.value ? -0 : -40);
}

function dragEnd() {
  if (!dragging.value) return;
  dragging.value = false;
  const dy = dragY.value;
  dragY.value = 0;
  if (dy < -30) height.value = FULL;
  else if (dy > 90) {
    if (expanded.value) height.value = HALF;
    else emit('close');
  }
  setTimeout(() => { moved = false; }, 0);
}

onBeforeUnmount(() => { document.documentElement.style.overflow = ''; listen(false); });
</script>

<style scoped>
.wm-comments-panel { transition: height 0.28s cubic-bezier(0.22, 1, 0.36, 1), transform 0.28s cubic-bezier(0.22, 1, 0.36, 1); }
.wm-comments-enter-active .wm-comments-panel, .wm-comments-leave-active .wm-comments-panel { transition: transform 0.28s cubic-bezier(0.22, 1, 0.36, 1); }
.wm-comments-enter-from .wm-comments-panel, .wm-comments-leave-to .wm-comments-panel { transform: translateY(100%); }
.wm-comments-enter-active .wm-comments-backdrop, .wm-comments-leave-active .wm-comments-backdrop { transition: opacity 0.28s ease; }
.wm-comments-enter-from .wm-comments-backdrop, .wm-comments-leave-to .wm-comments-backdrop { opacity: 0; }
.wm-comments-enter-active, .wm-comments-leave-active { transition: opacity 0.28s; }
@media (prefers-reduced-motion: reduce) {
  .wm-comments-panel, .wm-comments-enter-active .wm-comments-panel, .wm-comments-leave-active .wm-comments-panel { transition: none; }
}
</style>
