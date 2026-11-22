<template>
  <!-- Every version and step of a price, oldest first, read from the server only when opened -->
  <details class="text-[13.5px]" @toggle="onToggle">
    <summary class="cursor-pointer font-medium text-muted min-h-9 inline-flex items-center">Price history</summary>
    <p v-if="state === 'loading'" class="text-muted py-1">Loading…</p>
    <p v-else-if="state === 'error'" class="text-[#B91C1C] py-1" role="alert">{{ error }}</p>
    <ol v-else-if="events.length" class="mt-1 flex flex-col gap-1.5 border-l-2 border-line pl-3">
      <li v-for="(e, i) in events" :key="i">
        <span class="text-ink">{{ eventWords(e) }}</span>
        <span class="block text-[12px] text-muted">{{ when(e.at) }}<template v-if="e.note"> · “{{ e.note }}”</template></span>
      </li>
    </ol>
    <p v-else class="text-muted py-1">No price has been proposed yet.</p>
  </details>
</template>

<script setup>
import { ref } from 'vue';
import { api } from '@/api/client.js';
import { eventWords } from '@/utils/pricing.js';

const props = defineProps({
  // e.g. /me/requests/5/pricing or /me/received-requests/5/pricing
  path: { type: String, required: true },
});

const events = ref([]);
const state = ref('idle');     // idle | loading | ready | error
const error = ref('');

const when = (iso) => new Date(iso).toLocaleString('en-UG', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' });

async function onToggle(e) {
  if (!e.target.open) return;
  // Always asked again on opening, so it shows what is true now
  state.value = 'loading';
  try {
    events.value = (await api(props.path)).data.events;
    state.value = 'ready';
  } catch (err) {
    error.value = err.message;
    state.value = 'error';
  }
}
</script>
