<template>
  <header class="flex flex-col gap-3 lg:flex-row lg:items-end lg:justify-between">
    <div class="min-w-0">
      <div class="flex flex-wrap items-center gap-2">
        <h1 class="text-[22px] lg:text-2xl font-semibold tracking-[-0.01em] text-ink">{{ title }}</h1>
        <span
          v-if="demo"
          class="inline-flex items-center gap-1 h-6 px-2 rounded-md bg-[#EAF3FF] text-[#1E5AA8] text-[11.5px] font-semibold"
          title="Records from the WorkMate database. Invented sample records are marked “(sample)” and left out of the figures."
        >
          <i class="bi bi-database" aria-hidden="true"></i>Live data
        </span>
      </div>
      <p v-if="description" class="mt-1 text-sm text-[#64748B] max-w-[720px]">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </header>
</template>

<script setup>
defineProps({
  title:       { type: String, required: true },
  description: { type: String, default: '' },
  // Shows the "Live data" badge (the prop kept its old name)
  demo:        { type: Boolean, default: false },
});
</script>
