<template>
  <div class="flex flex-col gap-3">
    <!-- One slot per side, or one slot for one or more pages -->
    <div v-for="slot in slots" :key="slot.id" class="rounded-[14px] border border-dashed border-line-strong p-3">
      <p v-if="slot.label" class="mb-2 text-[13px] font-medium text-ink">{{ slot.label }}</p>

      <ul v-if="filesFor(slot).length" class="mb-2 flex flex-col gap-2">
        <li v-for="f in filesFor(slot)" :key="f.url" class="flex items-center gap-3">
          <img v-if="f.type.startsWith('image/') && f.type !== 'image/heic'" :src="f.url" alt="" class="h-12 w-12 shrink-0 rounded-lg object-cover bg-[#F1F5F9]" />
          <span v-else class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-lg bg-[#F1F5F9] text-xl text-muted" aria-hidden="true"><i class="bi bi-file-earmark-pdf"></i></span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium text-ink">{{ f.name }}</span>
            <span class="block wm-meta">{{ formatSize(f.size) }}</span>
          </span>
          <a :href="f.url" target="_blank" rel="noopener" class="wm-link min-h-11 inline-flex items-center">Preview<span class="sr-only"> {{ f.name }}</span></a>
          <button type="button" class="wm-btn-danger-ghost wm-btn !min-h-11 !px-2.5" @click="remove(f)">Remove<span class="sr-only"> {{ f.name }}</span></button>
        </li>
      </ul>

      <label v-if="canAdd(slot)" class="wm-btn wm-btn-secondary cursor-pointer focus-within:ring-2 focus-within:ring-primary">
        <i class="bi bi-upload" aria-hidden="true"></i>{{ filesFor(slot).length ? 'Add another page' : slot.button }}
        <input
          type="file"
          class="sr-only"
          :accept="accept"
          :multiple="slot.multiple"
          :aria-describedby="`${uid}-rules`"
          @change="add(slot, $event)"
        />
      </label>
      <button v-else-if="!slot.multiple && filesFor(slot).length" type="button" class="wm-link min-h-11" @click="remove(filesFor(slot)[0])">Replace</button>
    </div>

    <p :id="`${uid}-rules`" class="text-[12.5px] text-muted">{{ rules }}</p>
    <p v-for="e in errors" :key="e" class="text-sm text-error" role="alert">{{ e }}</p>

    <div v-if="doc.expiry.required">
      <label :for="`${uid}-exp`" class="wm-label !mb-1">Expiry date on the document</label>
      <input
        :id="`${uid}-exp`"
        type="date"
        class="wm-input !h-11 max-w-[220px]"
        :value="modelValue?.expiresAt || ''"
        :aria-invalid="expiryError ? 'true' : undefined"
        :aria-describedby="expiryError ? `${uid}-exp-err` : undefined"
        @change="update({ expiresAt: $event.target.value })"
      />
      <p v-if="expiryError && touchedExpiry" :id="`${uid}-exp-err`" class="wm-field-error">{{ expiryError }}</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, useId } from 'vue';
import { expiryProblem } from '@/stores/verification.js';

/**
 * File picker for one verification document. Checks format and size before
 * accepting a file and previews it with a local object URL; nothing is uploaded.
 */
const props = defineProps({
  doc:        { type: Object, required: true },   // a requirement from verificationRequirements.js
  modelValue: { type: Object, default: null },    // { files: [{ name, type, size, url, side }], expiresAt }
});
const emit = defineEmits(['update:modelValue']);

const uid = useId();
const errors = ref([]);
const touchedExpiry = ref(false);

const slots = computed(() => (props.doc.frontBack
  ? [{ id: 'front', label: 'Front', button: 'Choose front', multiple: false }, { id: 'back', label: 'Back', button: 'Choose back', multiple: false }]
  : [{ id: 'all', label: '', button: 'Choose file', multiple: props.doc.multiPage || props.doc.count > 1 }]));

const accept = computed(() => props.doc.formats.map(f => (f === 'pdf' ? '.pdf,application/pdf' : `.${f}`)).join(','));
const rules = computed(() => `${props.doc.formats.map(f => f.toUpperCase()).join(', ')}, up to ${props.doc.maxSizeMb} MB each${props.doc.frontBack ? '. Both sides needed' : ''}${props.doc.multiPage ? '. Include every page' : ''}.`);
const expiryError = computed(() => expiryProblem(props.doc, props.modelValue?.expiresAt));

const files = () => props.modelValue?.files || [];
const filesFor = (slot) => files().filter(f => (slot.id === 'all' ? true : f.side === slot.id));
const canAdd = (slot) => (slot.multiple ? filesFor(slot).length < (props.doc.multiPage ? 10 : props.doc.count) : !filesFor(slot).length);

function update(patch) {
  if ('expiresAt' in patch) touchedExpiry.value = true;
  emit('update:modelValue', { files: files(), expiresAt: props.modelValue?.expiresAt || '', ...patch });
}

function add(slot, event) {
  errors.value = [];
  const accepted = [];
  for (const file of event.target.files) {
    const ext = file.name.split('.').pop().toLowerCase().replace('jpeg', 'jpg');
    if (!props.doc.formats.includes(ext)) { errors.value.push(`${file.name}: use ${props.doc.formats.map(f => f.toUpperCase()).join(', ')}.`); continue; }
    if (file.size > props.doc.maxSizeMb * 1024 * 1024) { errors.value.push(`${file.name} is larger than ${props.doc.maxSizeMb} MB.`); continue; }
    accepted.push({ name: file.name, type: file.type || (ext === 'pdf' ? 'application/pdf' : `image/${ext}`), size: file.size, url: URL.createObjectURL(file), side: slot.id === 'all' ? null : slot.id });
  }
  event.target.value = '';
  if (accepted.length) update({ files: [...files(), ...accepted] });
}

function remove(f) {
  URL.revokeObjectURL(f.url);
  update({ files: files().filter(x => x !== f) });
}

function formatSize(bytes) {
  return bytes > 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
</script>
