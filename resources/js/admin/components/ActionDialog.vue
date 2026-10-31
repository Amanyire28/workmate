<template>
  <Teleport to="body">
    <Transition name="wm-dialog">
      <div v-if="open" class="fixed inset-0 z-[140] flex items-end sm:items-center justify-center sm:p-6" @keydown.esc="$emit('close')">
        <div class="absolute inset-0 bg-[#0F172A]/40" aria-hidden="true" @click="$emit('close')"></div>
        <form
          ref="panel"
          role="dialog"
          aria-modal="true"
          :aria-labelledby="titleId"
          :aria-describedby="message ? messageId : undefined"
          tabindex="-1"
          class="wm-dialog-panel wm-glass-pop relative flex max-h-[90dvh] w-full flex-col rounded-t-2xl sm:max-w-[480px] sm:rounded-2xl focus:outline-none"
          novalidate
          @submit.prevent="submit"
          @keydown.tab="trapFocus"
        >
          <div class="flex items-start gap-3 px-5 pt-5">
            <span
              class="inline-flex h-10 w-10 shrink-0 items-center justify-center rounded-full text-lg"
              :class="tone === 'danger' ? 'bg-[#FDECEC] text-[#B91C1C]' : 'bg-[#EFF6FF] text-primary'"
              aria-hidden="true"
            ><i :class="['bi', icon || (tone === 'danger' ? 'bi-exclamation-triangle' : 'bi-check2-circle')]"></i></span>
            <div class="min-w-0 pt-1">
              <h2 :id="titleId" class="text-[17px] font-semibold text-ink leading-snug">{{ title }}</h2>
              <p v-if="message" :id="messageId" class="mt-1 text-[13.5px] text-[#475569] leading-relaxed">{{ message }}</p>
            </div>
          </div>

          <div v-if="fields.length" class="flex flex-col gap-4 overflow-y-auto px-5 pt-4">
            <div v-for="f in fields" :key="f.key">
              <label :for="`${titleId}-${f.key}`" class="block text-[13px] font-medium text-ink">
                {{ f.label }}<span v-if="f.required" class="text-[#B91C1C]" aria-hidden="true"> *</span>
                <span v-if="f.required" class="sr-only"> (required)</span>
              </label>
              <textarea
                v-if="f.type === 'textarea'"
                :id="`${titleId}-${f.key}`"
                v-model="values[f.key]"
                rows="3"
                class="mt-1.5 w-full rounded-lg border px-3 py-2 text-[14px] text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                :class="errors[f.key] ? 'border-[#B91C1C]' : 'border-[#CBD5E1]'"
                :aria-invalid="errors[f.key] ? 'true' : undefined"
                :aria-describedby="errors[f.key] ? `${titleId}-${f.key}-err` : f.help ? `${titleId}-${f.key}-help` : undefined"
              ></textarea>
              <select
                v-else-if="f.type === 'select'"
                :id="`${titleId}-${f.key}`"
                v-model="values[f.key]"
                class="mt-1.5 h-10 w-full rounded-lg border border-[#CBD5E1] bg-white px-2.5 text-[14px] text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                :aria-invalid="errors[f.key] ? 'true' : undefined"
              >
                <option v-for="[v, l] in f.options" :key="v" :value="v">{{ l }}</option>
              </select>
              <input
                v-else
                :id="`${titleId}-${f.key}`"
                v-model="values[f.key]"
                :type="f.type === 'number' ? 'number' : 'text'"
                class="mt-1.5 h-10 w-full rounded-lg border px-3 text-[14px] text-ink focus:outline-none focus:ring-2 focus:ring-primary"
                :class="errors[f.key] ? 'border-[#B91C1C]' : 'border-[#CBD5E1]'"
                :aria-invalid="errors[f.key] ? 'true' : undefined"
                :aria-describedby="errors[f.key] ? `${titleId}-${f.key}-err` : f.help ? `${titleId}-${f.key}-help` : undefined"
              />
              <p v-if="errors[f.key]" :id="`${titleId}-${f.key}-err`" class="mt-1 text-[12.5px] text-[#B91C1C]">{{ errors[f.key] }}</p>
              <p v-else-if="f.help" :id="`${titleId}-${f.key}-help`" class="mt-1 text-[12.5px] text-[#64748B]">{{ f.help }}</p>
            </div>
          </div>

          <p v-if="error" class="mx-5 mt-4 flex items-start gap-1.5 rounded-lg bg-[#FEF2F2] px-3 py-2 text-[12.5px] text-[#B91C1C]" role="alert">
            <i class="bi bi-exclamation-triangle mt-px" aria-hidden="true"></i>{{ error }}
          </p>
          <p v-else class="mx-5 mt-4 flex items-start gap-1.5 rounded-lg bg-[#F8FAFC] px-3 py-2 text-[12px] text-[#64748B]">
            <i class="bi bi-journal-check mt-px" aria-hidden="true"></i>
            This is saved straight away and recorded in the audit log with your name and reason.
          </p>

          <div class="flex flex-col-reverse gap-2 px-5 pb-[calc(16px+env(safe-area-inset-bottom,0px))] pt-4 sm:flex-row sm:justify-end">
            <button type="button" class="wm-btn wm-btn-secondary !min-h-10 !rounded-lg" :disabled="busy" @click="$emit('close')">Cancel</button>
            <button type="submit" class="wm-btn !min-h-10 !rounded-lg" :disabled="busy" :aria-busy="busy ? 'true' : undefined" :class="tone === 'danger' ? 'bg-[#B91C1C] text-white hover:bg-[#991B1B]' : 'wm-btn-primary'">
              <span v-if="busy" class="wm-spinner" aria-hidden="true"></span>{{ busy ? 'Saving…' : confirmLabel }}
            </button>
          </div>
        </form>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { ref, reactive, watch, nextTick, useId } from 'vue';

/**
 * Confirmation and small forms for record actions: approve, reject with a reason,
 * change a value. Required fields are validated before anything is recorded.
 */
const props = defineProps({
  open:         { type: Boolean, default: false },
  title:        { type: String, default: '' },
  message:      { type: String, default: '' },
  confirmLabel: { type: String, default: 'Confirm' },
  tone:         { type: String, default: 'primary' },   // primary | danger
  icon:         { type: String, default: '' },
  fields:       { type: Array, default: () => [] },     // [{ key, label, type, options?, required?, help?, initial?, min? }]
  busy:         { type: Boolean, default: false },      // waiting for the server
  error:        { type: String, default: '' },          // the server's answer when it refused
});
const emit = defineEmits(['close', 'confirm']);

const titleId = useId();
const messageId = useId();
const panel = ref(null);
const values = reactive({});
const errors = reactive({});
let returnFocusTo = null;

watch(() => props.open, async (isOpen) => {
  if (isOpen) {
    returnFocusTo = document.activeElement;
    for (const k of Object.keys(values)) delete values[k];
    for (const k of Object.keys(errors)) delete errors[k];
    for (const f of props.fields) values[f.key] = f.initial ?? (f.type === 'select' ? f.options[0][0] : '');
    await nextTick();
    (panel.value?.querySelector('textarea, input, select') || panel.value)?.focus();
  } else {
    returnFocusTo?.focus?.();
  }
});

function submit() {
  for (const k of Object.keys(errors)) delete errors[k];
  for (const f of props.fields) {
    const v = values[f.key];
    const blank = v == null || String(v).trim() === '';
    if (f.required && blank) errors[f.key] = `${f.label} is required.`;
    else if (f.minLength && String(v).trim().length < f.minLength) errors[f.key] = `Please write at least ${f.minLength} characters.`;
    else if (f.type === 'number' && !blank && Number.isNaN(Number(v))) errors[f.key] = 'Enter a number.';
  }
  const firstError = props.fields.find(f => errors[f.key]);
  if (firstError) {
    panel.value?.querySelector(`#${CSS.escape(`${titleId}-${firstError.key}`)}`)?.focus();
    return;
  }
  const out = {};
  for (const f of props.fields) out[f.key] = f.type === 'number' ? Number(values[f.key]) : (typeof values[f.key] === 'string' ? values[f.key].trim() : values[f.key]);
  emit('confirm', out);
}

function trapFocus(e) {
  const focusable = panel.value?.querySelectorAll('button:not(:disabled), input, select, textarea, [href]');
  if (!focusable?.length) return;
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (e.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) { e.preventDefault(); last.focus(); }
  else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
}
</script>

<style scoped>
.wm-dialog-enter-active, .wm-dialog-leave-active { transition: opacity 0.18s ease; }
.wm-dialog-enter-active .wm-dialog-panel, .wm-dialog-leave-active .wm-dialog-panel { transition: transform 0.2s ease; }
.wm-dialog-enter-from, .wm-dialog-leave-to { opacity: 0; }
.wm-dialog-enter-from .wm-dialog-panel, .wm-dialog-leave-to .wm-dialog-panel { transform: translateY(12px); }
</style>
