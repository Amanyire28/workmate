<template>
  <button
    :type="type"
    :disabled="disabled || loading"
    :aria-busy="loading ? 'true' : undefined"
    :class="['wm-btn', `wm-btn-${variant}`, { 'wm-btn-lg': size === 'lg', 'wm-btn-block': block }]"
  >
    <span v-if="loading" class="wm-spinner" aria-hidden="true"></span>
    <slot />
  </button>
</template>

<script setup>
defineProps({
  variant:  { type: String, default: 'primary' },  // primary | secondary | tint | ghost
  size:     { type: String, default: 'lg' },       // md (44px) | lg (50px)
  block:    { type: Boolean, default: true },
  type:     { type: String, default: 'button' },
  loading:  { type: Boolean, default: false },
  disabled: { type: Boolean, default: false },
});
</script>
