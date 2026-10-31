<template>
  <div class="wm-container wm-container-sm wm-page">
    <div class="mx-auto w-full max-w-[440px]">
      <h1 class="wm-h1">{{ title }}</h1>
      <p v-if="subtitle" class="wm-meta mt-1.5">{{ subtitle }}</p>

      <!-- Problems that aren't about one field -->
      <p v-if="error" class="wm-notice wm-notice-error mt-5" role="alert">
        <i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
        <span>{{ error }}</span>
      </p>

      <div class="wm-card wm-card-lg mt-5">
        <slot />
      </div>

      <div v-if="$slots.footer" class="mt-5 text-center text-[15px] text-muted">
        <slot name="footer" />
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  title:    { type: String, required: true },
  subtitle: { type: String, default: '' },
  error:    { type: String, default: '' },
});
</script>
