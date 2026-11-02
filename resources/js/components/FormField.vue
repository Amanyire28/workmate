<template>
  <div>
    <label v-if="label" :for="inputId" class="wm-label">
      {{ label }}
      <span v-if="required" class="text-error" aria-hidden="true">*</span>
      <span v-else-if="optional" class="font-normal text-muted">(optional)</span>
    </label>

    <div class="relative">
      <span
        v-if="prefix"
        class="absolute left-3.5 top-1/2 -translate-y-1/2 text-sm font-medium text-muted pointer-events-none"
        aria-hidden="true"
      >{{ prefix }}</span>

      <textarea
        v-if="type === 'textarea'"
        :id="inputId"
        :value="modelValue"
        :placeholder="placeholder"
        :required="required"
        :disabled="disabled"
        :aria-invalid="errorMessage ? 'true' : undefined"
        :aria-describedby="describedBy"
        class="wm-input"
        :style="{ minHeight: `${minHeight}px` }"
        v-bind="$attrs"
        @input="$emit('update:modelValue', $event.target.value)"
      ></textarea>

      <select
        v-else-if="type === 'select'"
        :id="inputId"
        :value="modelValue"
        :required="required"
        :disabled="disabled"
        :aria-invalid="errorMessage ? 'true' : undefined"
        :aria-describedby="describedBy"
        class="wm-input"
        :class="{ 'text-muted': modelValue === '' }"
        v-bind="$attrs"
        @change="$emit('update:modelValue', $event.target.value)"
      >
        <option v-if="placeholder" value="" disabled>{{ placeholder }}</option>
        <slot />
      </select>

      <input
        v-else
        :id="inputId"
        :type="type === 'password' && revealed ? 'text' : type"
        :value="modelValue"
        :placeholder="placeholder"
        :required="required"
        :disabled="disabled"
        :aria-invalid="errorMessage ? 'true' : undefined"
        :aria-describedby="describedBy"
        class="wm-input"
        :class="{ 'pl-14': prefix, '!pr-12': type === 'password' }"
        v-bind="$attrs"
        @input="$emit('update:modelValue', $event.target.value)"
      />
      <!-- Show or hide what was typed in a password box -->
      <button
        v-if="type === 'password'"
        type="button"
        class="absolute right-1 top-1/2 -translate-y-1/2 w-11 h-11 flex items-center justify-center rounded-full text-muted hover:text-ink focus-visible:outline-2 focus-visible:outline-primary"
        :aria-label="revealed ? 'Hide password' : 'Show password'"
        :aria-pressed="revealed ? 'true' : 'false'"
        :aria-controls="inputId"
        :disabled="disabled"
        @click="revealed = !revealed"
      ><i :class="['bi text-[18px]', revealed ? 'bi-eye-slash' : 'bi-eye']" aria-hidden="true"></i></button>
    </div>

    <p v-if="errorMessage" :id="`${inputId}-error`" class="wm-field-error" role="alert">
      <i class="bi bi-exclamation-circle mr-1" aria-hidden="true"></i>{{ errorMessage }}
    </p>
    <p v-else-if="hint" :id="`${inputId}-hint`" class="wm-field-hint">{{ hint }}</p>
  </div>
</template>

<script setup>
import { ref, computed, useId } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  modelValue:   { type: [String, Number], default: '' },
  label:        { type: String, default: '' },
  type:         { type: String, default: 'text' },
  placeholder:  { type: String, default: '' },
  required:     { type: Boolean, default: false },
  optional:     { type: Boolean, default: false },
  disabled:     { type: Boolean, default: false },
  prefix:       { type: String, default: '' },
  errorMessage: { type: String, default: '' },
  hint:         { type: String, default: '' },
  minHeight:    { type: Number, default: 140 },
  id:           { type: String, default: '' },
});
defineEmits(['update:modelValue']);

const generatedId = useId();
const inputId = computed(() => props.id || generatedId);
// Password boxes: whether the typed text is shown
const revealed = ref(false);
const describedBy = computed(() => {
  if (props.errorMessage) return `${inputId.value}-error`;
  if (props.hint) return `${inputId.value}-hint`;
  return undefined;
});
</script>
