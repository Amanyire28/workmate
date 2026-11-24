<template>
  <div v-if="visible" class="wm-notice" :class="toneClass" :role="critical || tone === 'error' ? 'alert' : 'note'">
    <i :class="['bi mt-0.5 shrink-0', iconName]" aria-hidden="true"></i>
    <div class="min-w-0 flex-1 break-words"><slot /></div>
    <button v-if="!critical" type="button" class="-mr-1.5 -mt-1 inline-flex h-8 w-8 shrink-0 items-center justify-center rounded-full opacity-70 hover:bg-black/5 hover:opacity-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" aria-label="Dismiss this notice" @click="close">
      <i class="bi bi-x-lg text-[13px]" aria-hidden="true"></i>
    </button>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { auth } from '@/stores/auth.js';
import { isDismissed, dismiss, onNoticeChange } from '../utils/noticeMemory.js';

/**
 * A notice with an icon and a visible X. It shows by default and never goes away by itself. The X hides it for this administrator
 * until its `version` changes (put the condition it describes in the version, for example "off" / "on", never a figure).
 * `critical` notices have no X and cannot be hidden: use them for something that still needs attention.
 * If the browser cannot remember the choice, it simply shows again next visit.
 */
const props = defineProps({
  id:       { type: String, required: true },          // stable, e.g. "finance.estimates.simulation"
  version:  { type: [String, Number], default: '' },   // changes when the notice's meaning or condition changes
  tone:     { type: String, default: 'info' },         // info | warning | error | success
  icon:     { type: String, default: '' },
  critical: { type: Boolean, default: false },
});

const closedHere = ref(false);   // closed in this visit even if it could not be remembered
const hidden = ref(false);
const userId = computed(() => auth.user?.id ?? null);
const toneClass = computed(() => `wm-notice-${['info', 'warning', 'error', 'success'].includes(props.tone) ? props.tone : 'info'}`);
const iconName = computed(() => props.icon || ({ warning: 'bi-exclamation-triangle', error: 'bi-exclamation-circle', success: 'bi-check-circle' }[props.tone] || 'bi-info-circle'));
const visible = computed(() => props.critical || !(hidden.value || closedHere.value));

function read() {
  hidden.value = !props.critical && isDismissed(userId.value, props.id, props.version);
}
function close() {
  closedHere.value = true;
  dismiss(userId.value, props.id, props.version);
}

let off = null;
onMounted(() => { read(); off = onNoticeChange((kind) => { if (kind === 'reopen') closedHere.value = false; read(); }); });
onBeforeUnmount(() => { if (off) off(); });
// A different notice, version or person starts fresh; a re-read after someone else's change must not undo this visit's close
watch(() => [props.id, props.version, userId.value, props.critical], () => { closedHere.value = false; read(); });
</script>
