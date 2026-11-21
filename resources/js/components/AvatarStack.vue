<template>
  <span class="inline-flex shrink-0 items-center" :style="{ height: `${size}px` }" role="img" :aria-label="label">
    <!-- Up to three real people, overlapping, newest on top; initials when there is no picture -->
    <span v-for="(a, i) in shown" :key="a.id" class="rounded-full ring-2 ring-white" :class="i ? '-ml-3' : ''" :style="{ zIndex: shown.length - i }">
      <UserAvatar :name="a.name" :src="a.avatar_url || ''" :size="size" />
    </span>
  </span>
</template>

<script setup>
import { computed } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';

const props = defineProps({
  // [{ id, name, avatar_url }], newest first, as the notification list sends them
  actors: { type: Array, default: () => [] },
  size: { type: Number, default: 34 },
});

const shown = computed(() => props.actors.slice(0, 3));
const label = computed(() => shown.value.map(a => a.name).join(', '));
</script>
