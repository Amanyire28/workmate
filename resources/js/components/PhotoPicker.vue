<template>
  <div>
    <p class="wm-label">{{ label }} <span class="font-normal text-muted">(optional)</span></p>

    <ul v-if="modelValue.length" class="mb-3 grid grid-cols-3 sm:grid-cols-4 gap-2">
      <li v-for="(photo, i) in modelValue" :key="photo.key" class="relative aspect-square rounded-xl overflow-hidden bg-[#E2E8F0]">
        <img :src="photo.preview" alt="" class="w-full h-full object-cover" />
        <span v-if="photo.preparing" class="absolute inset-0 flex items-center justify-center bg-white/60" role="status">
          <span class="wm-spinner text-primary" aria-hidden="true"></span><span class="sr-only">Preparing photo {{ i + 1 }}</span>
        </span>
        <button
          type="button"
          class="absolute top-1 right-1 w-8 h-8 rounded-full bg-ink/70 text-white inline-flex items-center justify-center disabled:opacity-50"
          :aria-label="`Remove photo ${i + 1}`"
          :disabled="disabled"
          @click="remove(i)"
        >
          <i class="bi bi-x-lg text-sm" aria-hidden="true"></i>
        </button>
      </li>
    </ul>

    <label
      v-if="modelValue.length < max"
      :for="inputId"
      class="flex flex-col items-center justify-center gap-1 min-h-[88px] px-4 py-3 rounded-2xl border border-dashed border-line-strong bg-white/50 text-center cursor-pointer hover:border-primary hover:bg-primary/5 transition-colors focus-within:border-primary focus-within:ring-2 focus-within:ring-primary/30"
      :class="{ 'pointer-events-none opacity-60': disabled }"
    >
      <i class="bi bi-camera text-[22px] text-primary" aria-hidden="true"></i>
      <span class="text-sm font-medium text-ink">{{ modelValue.length ? 'Add more photos' : 'Add photos' }}</span>
      <span class="wm-meta">Up to {{ max }} photos ({{ modelValue.length }}/{{ max }}). JPG, PNG or WebP.</span>
      <input :id="inputId" type="file" accept="image/jpeg,image/png,image/webp,image/*" multiple class="sr-only" :disabled="disabled" @change="onPick" />
    </label>

    <p v-if="problem" class="wm-field-error mt-1.5" role="alert"><i class="bi bi-exclamation-circle mr-1" aria-hidden="true"></i>{{ problem }}</p>
    <p v-if="hint" class="wm-field-hint mt-1.5">{{ hint }}</p>
  </div>
</template>

<script setup>
import { ref, useId } from 'vue';
import { preparePhoto, PHOTO_MAX_MB } from '@/utils/photos.js';

/**
 * Photos to send with a form. v-model is a list of { key, file, preview,
 * preparing }: send each `file` once none is `preparing`. The form owns the
 * list (it survives steps that hide the picker) and calls releasePhotos()
 * when it's done. Photos are shrunk on the phone; the server re-draws them.
 */
const props = defineProps({
  modelValue: { type: Array, default: () => [] },
  label: { type: String, default: 'Photos of the job' },
  hint: { type: String, default: '' },
  max: { type: Number, default: 6 },
  disabled: { type: Boolean, default: false },
});
const emit = defineEmits(['update:modelValue']);

const inputId = useId();
const problem = ref('');
let keys = 0;

async function onPick(e) {
  const chosen = [...e.target.files];
  e.target.value = '';
  problem.value = '';
  const room = props.max - props.modelValue.length;
  if (chosen.length > room) problem.value = `You can add up to ${props.max} photos, so only the first ${room} were added.`;

  const list = [...props.modelValue];
  const added = [];
  for (const file of chosen.slice(0, room)) {
    if (!file.type.startsWith('image/')) { problem.value = `${file.name} isn’t a photo.`; continue; }
    const entry = { key: ++keys, file, preview: URL.createObjectURL(file), preparing: true };
    list.push(entry);
    added.push(entry);
  }
  emit('update:modelValue', list);

  // Shrink each one; the form waits until they're all ready
  for (const entry of added) {
    const ready = await preparePhoto(entry.file);
    const current = props.modelValue.find(p => p.key === entry.key);
    if (!current) continue;
    if (ready.size > PHOTO_MAX_MB * 1024 * 1024) {
      problem.value = `${entry.file.name} is too large (up to ${PHOTO_MAX_MB} MB).`;
      URL.revokeObjectURL(current.preview);
      emit('update:modelValue', props.modelValue.filter(p => p.key !== entry.key));
      continue;
    }
    emit('update:modelValue', props.modelValue.map(p => (p.key === entry.key ? { ...p, file: ready, preparing: false } : p)));
  }
}

function remove(i) {
  URL.revokeObjectURL(props.modelValue[i].preview);
  emit('update:modelValue', props.modelValue.filter((_, j) => j !== i));
}

</script>
