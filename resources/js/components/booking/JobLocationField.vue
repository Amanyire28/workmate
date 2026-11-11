<template>
  <!-- "Where is the service needed?": current location first (in the place list), or any place;
       then a landmark and directions so the provider can find it -->
  <div class="flex flex-col gap-3">
    <PlacePicker
      :model-value="modelValue.area"
      :id="id"
      label="Where is the service needed?"
      required
      :clearable="false"
      :error-message="errorMessage"
      placeholder="Search for a location…"
      @update:model-value="onArea"
      @located="onLocated"
    />

    <!-- The exact point, when they used their current location -->
    <div v-if="modelValue.pin" class="flex items-start gap-3 rounded-xl border border-success/30 bg-success-tint px-3.5 py-2.5">
      <span class="mt-0.5" aria-hidden="true">📍</span>
      <div class="min-w-0 flex-1">
        <p class="text-[14px] font-medium text-ink">Exact location saved</p>
        <p class="text-[12.5px] text-muted">{{ accuracyLabel(modelValue.pin.accuracy) }}. Only the provider you hire sees it, after they accept.</p>
      </div>
      <button type="button" class="wm-link !text-[13px] shrink-0 min-h-8" @click="set({ pin: null })">Remove</button>
    </div>
    <p v-else class="wm-field-hint">Tip: choose “📍 Use my current location” if the job is where you are now. The provider then gets directions to the exact spot.</p>

    <FormField :model-value="modelValue.landmark" label="Landmark" optional maxlength="150"
      placeholder="e.g. Blue gate opposite the church" @update:model-value="set({ landmark: $event })" />
    <FormField :model-value="modelValue.directions" type="textarea" label="Directions" optional maxlength="300" :min-height="64"
      placeholder="e.g. Second house on the left after the shop. Ring twice."
      hint="Shared with the provider only after they accept." @update:model-value="set({ directions: $event })" />
  </div>
</template>

<script setup>
import { watch } from 'vue';
import PlacePicker from '@/components/PlacePicker.vue';
import FormField from '@/components/FormField.vue';
import { accuracyLabel } from '@/utils/booking.js';

const props = defineProps({
  // { area: slug, pin: { latitude, longitude, accuracy } | null, landmark, directions }
  modelValue: { type: Object, required: true },
  errorMessage: { type: String, default: '' },
  id: { type: String, default: 'job-location' },
});
const emit = defineEmits(['update:modelValue']);

// Two changes can arrive together (the place, then its point) before the parent updates:
// build each on the last one sent, not on the props
let draft = null;
watch(() => props.modelValue, () => { draft = null; });
const current = () => draft || props.modelValue;

function set(patch) {
  draft = { ...current(), ...patch };
  emit('update:modelValue', draft);
}

// Picking another place by name drops the exact point (it was for the old place)
function onArea(area) {
  set({ area, pin: area === current().area ? current().pin : null });
}

// Their current location: keep the point too, as a fixed job destination (never tracked)
function onLocated(reading) {
  set({ pin: { latitude: reading.latitude, longitude: reading.longitude, accuracy: reading.accuracy } });
}
</script>
