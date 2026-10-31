<template>
  <form role="search" class="wm-search" @submit.prevent="$emit('submit', modelValue)">
    <label :for="inputId" class="sr-only">{{ label || placeholder }}</label>
    <i class="bi bi-search text-muted text-[20px] leading-none pl-3.5 pr-2.5" aria-hidden="true"></i>
    <input
      :id="inputId"
      ref="input"
      type="search"
      enterkeyhint="search"
      autocomplete="off"
      :value="modelValue"
      :placeholder="placeholder"
      @input="$emit('update:modelValue', $event.target.value)"
    />
    <button
      v-if="modelValue"
      type="button"
      class="wm-icon-btn text-muted mr-0.5"
      aria-label="Clear search"
      @click="clear"
    >
      <i class="bi bi-x-circle-fill text-[18px]" aria-hidden="true"></i>
    </button>
  </form>
</template>

<script setup>
import { ref } from 'vue';

const props = defineProps({
  modelValue:  { type: String, default: '' },
  placeholder: { type: String, default: 'Search…' },
  label:       { type: String, default: '' },
  id:          { type: String, default: 'search' },
});
const emit = defineEmits(['update:modelValue', 'submit']);

const inputId = props.id;
const input = ref(null);

function clear() {
  emit('update:modelValue', '');
  input.value?.focus();
}

defineExpose({ focus: () => input.value?.focus() });
</script>
