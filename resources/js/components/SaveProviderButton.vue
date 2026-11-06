<template>
  <!-- Sits above the card's stretched link, so tapping it doesn't open the profile -->
  <button
    v-if="!own"
    type="button"
    class="relative z-10 wm-icon-btn shrink-0"
    :class="on ? 'text-[#E11D48]' : 'text-muted hover:text-[#E11D48]'"
    :aria-pressed="on"
    :aria-label="on ? `Remove ${name} from saved providers` : `Save ${name}`"
    :title="on ? 'Saved' : 'Save'"
    @click.stop.prevent="toggleSaved(providerId, name)"
  >
    <i :class="['bi text-[19px]', on ? 'bi-heart-fill' : 'bi-heart']" aria-hidden="true"></i>
  </button>
</template>

<script setup>
import { computed } from 'vue';
import { isOwnProvider } from '@/stores/auth.js';
import { isSaved, toggleSaved } from '@/stores/saved.js';

const props = defineProps({
  providerId: { type: Number, required: true },
  name: { type: String, default: 'this provider' },
});

const on = computed(() => isSaved(props.providerId));
// Nobody saves their own profile
const own = computed(() => isOwnProvider(props.providerId));
</script>
