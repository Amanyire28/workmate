<template>
  <span ref="root" class="inline-flex align-middle">
    <button
      ref="trigger"
      type="button"
      class="-m-1 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[#64748B] hover:text-primary focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
      :aria-label="`More about ${label}`"
      :aria-expanded="open ? 'true' : 'false'"
      :aria-controls="open ? panelId : undefined"
      @click="onClick"
      @pointerenter="onEnter"
      @pointerleave="onLeave"
    >
      <i class="bi bi-info-circle text-[14px]" aria-hidden="true"></i>
    </button>

    <!-- Moved to the end of the page so no card, table or dialog can clip it -->
    <Teleport to="body">
      <div
        v-if="open"
        :id="panelId"
        ref="panel"
        role="note"
        data-wm-tip
        class="wm-glass-pop fixed z-[180] rounded-xl border border-[#CBDEF3] bg-white px-3.5 py-3 text-[13px] leading-relaxed text-[#334155] shadow-lg"
        :style="style"
        @pointerenter="onPanelEnter"
        @pointerleave="onLeave"
      >
        <div class="flex items-start gap-2">
          <p class="min-w-0 flex-1 break-words"><slot>{{ text }}</slot></p>
          <button type="button" class="-mr-1.5 -mt-1 inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[#64748B] hover:text-ink focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" aria-label="Close" @click="closeAndFocus">
            <i class="bi bi-x-lg text-[12px]" aria-hidden="true"></i>
          </button>
        </div>
      </div>
    </Teleport>
  </span>
</template>

<script setup>
import { ref, computed, watch, nextTick, onBeforeUnmount, useId } from 'vue';
import { usePopover } from './usePopover.js';

/**
 * A circled "i" beside a label or heading. Hover (mouse), click, tap or Enter/Space open it; Escape, a click elsewhere, the X or
 * pressing the icon again close it. Only one is open at a time. For explanations: never the only place a payment instruction,
 * a required field's guidance or a warning is shown.
 */
const props = defineProps({
  label: { type: String, default: 'this item' },   // what it explains, for screen readers
  text:  { type: String, default: '' },            // or use the default slot
});

const panelId = useId();
const panel = ref(null);
const trigger = ref(null);
const pinned = ref(false);           // opened by click, tap or keyboard: stays until closed
const pos = ref({ left: 0, top: 0, width: 280 });
const { open, root, close } = usePopover({ also: () => panel.value });

// One at a time across the page
const registry = (globalThis.__wmInfoTips = globalThis.__wmInfoTips || { current: null });
function claim() {
  if (registry.current && registry.current !== closeSelf) registry.current();
  registry.current = closeSelf;
}
function closeSelf() { pinned.value = false; close(); }

let hoverTimer = null;
const canHover = () => typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(hover: hover)').matches;

function show() { claim(); open.value = true; }
function onClick() {
  if (open.value && pinned.value) { closeSelf(); return; }
  pinned.value = true;
  show();
}
function onEnter(e) {
  if (e.pointerType !== 'mouse' || !canHover()) return;
  clearTimeout(hoverTimer);
  hoverTimer = setTimeout(show, 120);
}
function onPanelEnter() { clearTimeout(hoverTimer); }
function onLeave(e) {
  if (e.pointerType !== 'mouse') return;
  clearTimeout(hoverTimer);
  if (pinned.value) return;
  hoverTimer = setTimeout(() => { if (!pinned.value) close(); }, 160);
}
function closeAndFocus() { closeSelf(); trigger.value?.focus(); }

function place() {
  const t = trigger.value;
  if (!t) return;
  const r = t.getBoundingClientRect();
  const vw = window.innerWidth;
  const vh = window.innerHeight;
  const margin = 8;
  const width = Math.min(280, vw - margin * 2);
  const h = panel.value ? panel.value.offsetHeight : 80;
  let left = r.left + r.width / 2 - width / 2;
  left = Math.max(margin, Math.min(left, vw - width - margin));
  let top = r.bottom + 6;
  if (top + h > vh - margin && r.top - 6 - h >= margin) top = r.top - 6 - h;   // flip above when there is no room below
  top = Math.max(margin, Math.min(top, vh - h - margin));
  pos.value = { left, top, width };
}
const style = computed(() => ({ left: `${pos.value.left}px`, top: `${pos.value.top}px`, width: `${pos.value.width}px`, maxHeight: `calc(100dvh - 16px)`, overflowY: 'auto' }));

function reposition() { if (open.value) place(); }
watch(open, async (isOpen) => {
  if (isOpen) {
    place();
    await nextTick();
    place();   // again, now that the panel's real height is known
    window.addEventListener('resize', reposition);
    window.addEventListener('scroll', reposition, true);   // any scrolling container moves the icon
    window.visualViewport?.addEventListener('resize', reposition);
  } else {
    pinned.value = false;
    window.removeEventListener('resize', reposition);
    window.removeEventListener('scroll', reposition, true);
    window.visualViewport?.removeEventListener('resize', reposition);
    if (registry.current === closeSelf) registry.current = null;
  }
});
onBeforeUnmount(() => {
  clearTimeout(hoverTimer);
  window.removeEventListener('resize', reposition);
  window.removeEventListener('scroll', reposition, true);
  window.visualViewport?.removeEventListener('resize', reposition);
  if (registry.current === closeSelf) registry.current = null;
});
void props;
</script>
