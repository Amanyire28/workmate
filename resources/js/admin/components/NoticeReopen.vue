<template>
  <button v-if="count" type="button" class="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2.5 text-[13px] font-medium text-primary hover:bg-[#EFF6FF] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary" @click="reopenAll(userId, scope)">
    <i class="bi bi-info-circle" aria-hidden="true"></i>Show {{ count === 1 ? 'hidden notice' : `${count} hidden notices` }}
  </button>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';
import { auth } from '@/stores/auth.js';
import { dismissedIds, reopenAll, onNoticeChange } from '../utils/noticeMemory.js';

/** Brings back the notices this administrator closed on one screen (by id prefix). Shows only when there are some. */
const props = defineProps({ scope: { type: String, required: true } });

const n = ref(0);
const userId = computed(() => auth.user?.id ?? null);
const count = computed(() => n.value);
function refresh() { n.value = dismissedIds(userId.value, props.scope).length; }

let off = null;
onMounted(() => { refresh(); off = onNoticeChange(refresh); });
onBeforeUnmount(() => { if (off) off(); });
</script>
