<template>
  <!-- Amber, never blue: it thanks people for inviting members and must not look like a verification tick -->
  <button type="button"
    class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-[12.5px] font-semibold transition-colors"
    :class="locked ? 'border-line bg-[#F1F5F9] text-muted' : 'border-[#FCD34D] bg-[#FFFBEB] text-[#92400E] hover:bg-[#FEF3C7]'"
    :aria-label="`${badge.name}${locked ? ' (not earned yet)' : ''}: what this badge means`"
    @click="open = true">
    <i :class="['bi', locked ? 'bi-lock' : badge.icon || 'bi-award']" aria-hidden="true"></i>{{ badge.name }}
  </button>

  <BottomSheet :open="open" :title="badge.name" @close="open = false">
    <div class="flex flex-col items-center gap-3 text-center">
      <span class="inline-flex h-16 w-16 items-center justify-center rounded-full text-[30px]"
        :class="locked ? 'bg-[#F1F5F9] text-muted' : 'bg-[#FEF3C7] text-[#B45309]'" aria-hidden="true">
        <i :class="['bi', badge.icon || 'bi-award']"></i>
      </span>
      <p class="text-[15px] text-ink">{{ badge.description }}</p>
      <p v-if="badge.earned_at && !locked" class="wm-meta">Earned {{ formatDate(badge.earned_at) }}</p>
      <p v-else-if="badge.threshold" class="wm-meta">Earned by inviting {{ badge.threshold }} new {{ badge.threshold === 1 ? 'member' : 'members' }} who join WorkMate.</p>
      <p class="rounded-xl bg-[#F1F5F9] px-3 py-2 text-[13px] text-muted">
        Invitation badges thank members for helping WorkMate grow. They aren’t a sign of skill, identity or verification, and they don’t change what anyone can do.
      </p>
    </div>
  </BottomSheet>
</template>

<script setup>
import { ref } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';

defineProps({
  // { name, description, icon, earned_at?, threshold? }
  badge: { type: Object, required: true },
  locked: { type: Boolean, default: false },
});

const open = ref(false);

function formatDate(iso) {
  return new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' });
}
</script>
