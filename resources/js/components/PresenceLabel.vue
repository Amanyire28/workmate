<template>
  <!-- "Online now" with a green dot, or "Last seen 2 hours ago". Nothing when they hide it. -->
  <span v-if="text" class="inline-flex items-center gap-1.5 text-[13px]" :class="online ? 'text-success font-medium' : 'text-muted'">
    <span v-if="online" class="h-2 w-2 rounded-full bg-[#16A34A] shadow-[0_0_0_3px_rgba(22,163,74,0.18)]" aria-hidden="true"></span>
    {{ text }}
  </span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  // { online, last_seen_at } from the API, or null when hidden
  presence: { type: Object, default: null },
});

const online = computed(() => !!props.presence?.online);

/** Online now; Last seen 5 minutes ago / today / yesterday / 3 days ago / on 2 Oct */
const text = computed(() => {
  const p = props.presence;
  if (!p) return '';
  if (p.online) return 'Online now';
  if (!p.last_seen_at) return '';
  const seen = new Date(p.last_seen_at);
  const minutes = Math.max(1, Math.round((Date.now() - seen.getTime()) / 60000));
  if (minutes < 60) return `Last seen ${minutes} minute${minutes === 1 ? '' : 's'} ago`;
  const hours = Math.round(minutes / 60);
  const sameDay = seen.toDateString() === new Date().toDateString();
  if (sameDay) return hours <= 3 ? `Last seen ${hours} hour${hours === 1 ? '' : 's'} ago` : 'Last seen today';
  const yesterday = new Date(Date.now() - 86400000).toDateString() === seen.toDateString();
  if (yesterday) return 'Last seen yesterday';
  const days = Math.floor((Date.now() - seen.getTime()) / 86400000);
  if (days < 7) return `Last seen ${days} days ago`;
  return `Last seen on ${seen.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' })}`;
});
</script>
