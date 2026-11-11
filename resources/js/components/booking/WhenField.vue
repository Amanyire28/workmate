<template>
  <!-- "When do you need this service?": two plain choices, then a date and a time if needed -->
  <fieldset class="flex flex-col gap-3">
    <legend class="wm-label mb-1">When do you need this service?
      <span v-if="optional" class="font-normal text-muted">(optional: leave it if you’re flexible)</span>
      <span v-else class="text-error" aria-hidden="true">*</span>
    </legend>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
      <label v-for="o in CHOICES" :key="o.id"
        class="flex cursor-pointer items-center gap-3 rounded-xl border px-3.5 py-3"
        :class="[modelValue.timing === o.id ? 'border-primary bg-primary-tint' : 'border-line hover:bg-[#F8FAFC]', o.disabled ? 'pointer-events-none opacity-50' : '']">
        <input type="radio" :name="name" :value="o.id" :checked="modelValue.timing === o.id" :disabled="o.disabled"
          class="h-5 w-5 shrink-0 accent-[var(--color-primary)]" @change="set({ timing: o.id })" />
        <span>
          <span class="block text-[15px] font-medium text-ink">{{ o.label }}</span>
          <span class="block text-[12.5px] text-muted">{{ o.disabled ? o.disabledHint : o.hint }}</span>
        </span>
      </label>
    </div>

    <div v-if="modelValue.timing === 'scheduled'" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
      <div class="flex flex-col gap-1.5">
        <label :for="`${name}-date`" class="wm-label">Date</label>
        <input :id="`${name}-date`" type="date" class="wm-input" :min="today" :max="maxDay" :value="modelValue.date"
          :aria-invalid="errorMessage ? 'true' : undefined" @input="set({ date: $event.target.value })" />
      </div>
      <div class="flex flex-col gap-1.5">
        <label :for="`${name}-time`" class="wm-label">Time</label>
        <select :id="`${name}-time`" class="wm-input" :value="modelValue.time" @change="set({ time: $event.target.value })">
          <option value="" disabled>Choose a time</option>
          <option v-for="t in TIME_SLOTS" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </div>
      <div class="flex flex-col gap-1.5">
        <label :for="`${name}-length`" class="wm-label">How long? <span class="font-normal text-muted">(optional)</span></label>
        <select :id="`${name}-length`" class="wm-input" :value="modelValue.duration" @change="set({ duration: $event.target.value })">
          <option v-for="d in DURATIONS" :key="d.label" :value="d.value">{{ d.label }}</option>
        </select>
      </div>
    </div>

    <button v-if="optional && modelValue.timing" type="button" class="wm-link self-start !text-[13px] min-h-8" @click="set({ timing: '', date: '', time: '', duration: '' })">I’m flexible</button>
    <p v-if="modelValue.timing === 'scheduled' && modelValue.date && modelValue.time" class="text-[14px] text-ink">
      <i class="bi bi-calendar-check text-primary mr-1" aria-hidden="true"></i>{{ formatDay(isoOf(modelValue)) }} at {{ formatTime(isoOf(modelValue)) }}
    </p>
    <p v-if="errorMessage" class="wm-field-error" role="alert">{{ errorMessage }}</p>
  </fieldset>
</template>

<script setup>
import { computed, watch, useId } from 'vue';
import { TIME_SLOTS, DURATIONS, todayInUganda, formatDay, formatTime } from '@/utils/booking.js';

const props = defineProps({
  // { timing: 'asap' | 'scheduled' | '', date: 'YYYY-MM-DD', time: 'HH:MM', duration: '' | minutes }
  modelValue: { type: Object, required: true },
  errorMessage: { type: String, default: '' },
  // The provider isn't taking immediate work or bookings for later
  allowAsap: { type: Boolean, default: true },
  allowScheduled: { type: Boolean, default: true },
  // Posted jobs: no choice means "flexible"
  optional: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const name = `when-${useId()}`;
const today = todayInUganda();
const maxDay = todayInUganda(120);

const CHOICES = computed(() => [
  { id: 'asap', label: 'As soon as possible', hint: 'They’ll tell you when they can come', disabled: !props.allowAsap, disabledHint: 'Not taking immediate work right now' },
  { id: 'scheduled', label: 'Choose date and time', hint: 'Pick a day and time that suits you', disabled: !props.allowScheduled, disabledHint: 'Not taking bookings for later right now' },
].map(o => o));

// Changes can arrive together (date, then time) before the parent updates:
// build each on the last one sent, not on the props
let draft = null;
watch(() => props.modelValue, () => { draft = null; });

function set(patch) {
  draft = { ...(draft || props.modelValue), ...patch };
  emit('update:modelValue', draft);
}

/** The chosen moment as an ISO time in Uganda (UTC+3, no daylight saving) */
function isoOf(v) {
  return `${v.date}T${v.time}:00+03:00`;
}
</script>
