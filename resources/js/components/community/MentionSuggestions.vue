<template>
  <!-- People to mention, just above (or below) the text box; never over the whole screen -->
  <div
    v-if="state.open"
    class="absolute inset-x-0 z-30 overflow-hidden rounded-2xl border border-line bg-white shadow-[0_12px_32px_rgba(15,23,42,0.16)]"
    :class="above ? 'bottom-full mb-1.5' : 'top-full mt-1.5'"
  >
    <p v-if="state.full" class="px-4 py-3 text-[13.5px] text-muted" role="status">
      You can mention up to {{ MAX_MENTIONS }} people in one post or comment.
    </p>
    <template v-else>
      <ul v-if="state.results.length" :id="listId" class="max-h-[min(212px,34vh)] overflow-y-auto overscroll-contain py-1" role="listbox" aria-label="People to mention">
        <li
          v-for="(p, i) in state.results"
          :id="`${listId}-${i}`"
          :key="p.id"
          role="option"
          :aria-selected="i === state.active"
          class="flex min-h-[48px] cursor-pointer items-center gap-2.5 px-3 py-1.5"
          :class="i === state.active ? 'bg-[#EEF5FF]' : 'hover:bg-[#F6F9FC]'"
          @pointerdown.prevent
          @mousedown.prevent
          @click="$emit('choose', p)"
        >
          <UserAvatar :name="p.name" :src="p.avatar_url || ''" :size="32" />
          <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1 text-[14.5px] font-semibold text-ink">
              <span class="truncate">{{ p.name }}</span>
              <VerifiedBadge :provider="{ documentVerified: p.document_verified, blueTick: p.blue_tick }" />
            </span>
            <span class="block truncate text-[12.5px] text-muted">{{ [p.title || (p.provider_id ? 'Provider' : 'Member'), p.area, p.following ? 'You follow them' : null].filter(Boolean).join(' · ') }}</span>
          </span>
        </li>
      </ul>
      <p v-else-if="state.loading" class="flex items-center gap-2 px-4 py-3 text-[13.5px] text-muted" role="status">
        <span class="wm-spinner !h-4 !w-4" aria-hidden="true"></span>Looking for “{{ state.query }}”…
      </p>
      <p v-else class="px-4 py-3 text-[13.5px] text-muted" role="status">No one called “{{ state.query }}” in the community yet.</p>
    </template>
  </div>
</template>

<script setup>
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import { MAX_MENTIONS } from '@/stores/mentions.js';

defineProps({
  // useMentions().state
  state: { type: Object, required: true },
  listId: { type: String, required: true },
  // Above the box (comments at the bottom of the screen, near the keyboard) or below it
  above: { type: Boolean, default: false },
});
defineEmits(['choose']);
</script>
