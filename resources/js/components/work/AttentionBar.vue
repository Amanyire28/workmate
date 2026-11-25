<template>
  <!-- Only when something needs the provider. One button per kind of thing, each with its own count of jobs (a job with two
       conditions is counted once in each, and the counts are never added together). -->
  <section v-if="items.length" class="rounded-2xl border border-[#BFDBFE] bg-[#EFF6FF] px-3 py-2.5" aria-labelledby="needs-you-heading">
    <h2 id="needs-you-heading" class="text-[13px] font-semibold text-[#1E3A8A]">Needs your attention</h2>
    <ul class="mt-1.5 flex flex-wrap gap-2" role="list">
      <li v-for="i in items" :key="i.id">
        <button type="button" class="inline-flex min-h-11 items-center gap-2 rounded-full bg-white px-3.5 text-[14px] font-medium text-ink ring-1 ring-[#BFDBFE] transition-colors hover:bg-[#F8FAFC] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
          :class="{ 'ring-2 ring-primary': active === i.id }" :aria-pressed="active === i.id" @click="$emit('pick', i.id)">
          <i :class="['bi text-primary', i.icon]" aria-hidden="true"></i>
          <span><strong class="font-bold">{{ i.count }}</strong> {{ i.count === 1 ? i.one : i.many }}</span>
        </button>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { computed } from 'vue';
import { ATTENTION } from '@/utils/workActions.js';

const props = defineProps({
  index:  { type: Object, required: true },   // { new: [ids], price: [ids], payment: [ids] }
  active: { type: String, default: '' },
});
defineEmits(['pick']);

const items = computed(() => ATTENTION.map((a) => ({ ...a, count: (props.index[a.id] || []).length })).filter((a) => a.count > 0));
</script>
