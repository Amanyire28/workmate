<template>
  <div ref="root" class="relative">
    <label v-if="label" :for="inputId" class="wm-label">
      {{ label }}
      <span v-if="required" class="text-error" aria-hidden="true">*</span>
      <span v-else-if="optional" class="font-normal text-muted">(optional)</span>
    </label>

    <div class="relative">
      <i class="bi bi-geo-alt absolute left-3.5 top-1/2 -translate-y-1/2 text-muted pointer-events-none" aria-hidden="true"></i>
      <input
        :id="inputId"
        ref="input"
        v-model="text"
        type="text"
        role="combobox"
        class="wm-input !pl-10"
        :class="{ '!pr-10': modelValue && !open }"
        :placeholder="placeholder"
        autocomplete="off"
        autocapitalize="words"
        spellcheck="false"
        :aria-expanded="open ? 'true' : 'false'"
        :aria-controls="listId"
        aria-autocomplete="list"
        :aria-activedescendant="active >= 0 ? `${listId}-${active}` : undefined"
        :aria-invalid="errorMessage ? 'true' : undefined"
        :aria-describedby="describedBy"
        @focus="onFocus"
        @input="onInput"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter="onEnter"
        @keydown.esc="close(true)"
        @blur="onBlur"
      />
      <button
        v-if="modelValue && !open && clearable"
        type="button"
        class="absolute right-1 top-1/2 -translate-y-1/2 w-10 h-10 flex items-center justify-center text-muted hover:text-ink"
        :aria-label="`Clear ${label || 'place'}`"
        @click="clear"
      ><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>

    <ul
      v-show="open"
      :id="listId"
      role="listbox"
      class="absolute z-30 left-0 right-0 mt-1 max-h-72 overflow-y-auto rounded-[14px] border border-line bg-white py-1 shadow-lg"
    >
      <li v-if="anyLabel && !query" :id="`${listId}--1`" role="option" :aria-selected="!modelValue"
        class="flex items-center gap-2.5 px-3 min-h-11 cursor-pointer text-[15px] text-ink hover:bg-[#F1F5F9]"
        @mousedown.prevent="pick(null)">
        <i class="bi bi-globe2 text-muted" aria-hidden="true"></i>{{ anyLabel }}
      </li>
      <li v-if="!query && options.length" class="px-3 pt-2 pb-1 text-[12px] font-medium uppercase tracking-wide text-muted" role="presentation">Popular</li>
      <li
        v-for="(o, i) in options"
        :id="`${listId}-${i}`"
        :key="o.id"
        role="option"
        :aria-selected="o.id === modelValue"
        class="flex items-center gap-2.5 px-3 min-h-11 cursor-pointer"
        :class="i === active ? 'bg-primary-tint' : 'hover:bg-[#F1F5F9]'"
        @mousedown.prevent="pick(o)"
      >
        <i class="bi bi-geo-alt text-muted" aria-hidden="true"></i>
        <span class="min-w-0 flex-1">
          <span class="block text-[15px] text-ink truncate">{{ o.wholeTown ? `All of ${o.name}` : o.name }}</span>
          <span v-if="o.district && o.district !== o.name" class="block text-[12.5px] text-muted">{{ o.district }}</span>
        </span>
        <i v-if="o.id === modelValue" class="bi bi-check-lg text-primary" aria-hidden="true"></i>
      </li>
      <li v-if="query && loading && !options.length" class="px-3 py-3 wm-meta" role="status">Searching…</li>
      <li v-else-if="query && !loading && !options.length" class="px-3 py-3 wm-meta" role="status">
        {{ failed ? 'Couldn’t search places. Check your connection and try again.' : `No place called “${query}”. Try a nearby town or trading centre.` }}
      </li>
    </ul>

    <p v-if="errorMessage" :id="`${inputId}-error`" class="wm-field-error" role="alert">{{ errorMessage }}</p>
    <p v-else-if="hint" :id="`${inputId}-hint`" class="wm-field-hint">{{ hint }}</p>
  </div>
</template>

<script setup>
import { ref, computed, watch, useId } from 'vue';
import { searchLocations, searchPlaces, areaById, areaLabel } from '@/stores/catalog.js';

/**
 * Type-to-search over every place in Uganda. v-model is the place's id
 * (slug), or '' for none / anywhere. Before anyone types, the popular
 * places are offered.
 */
const props = defineProps({
  modelValue:   { type: String, default: '' },
  label:        { type: String, default: '' },
  placeholder:  { type: String, default: 'Type a place, e.g. Kyebando' },
  hint:         { type: String, default: '' },
  errorMessage: { type: String, default: '' },
  required:     { type: Boolean, default: false },
  optional:     { type: Boolean, default: false },
  // Offer "anywhere" as the first choice; its text, e.g. "All areas"
  anyLabel:     { type: String, default: '' },
  clearable:    { type: Boolean, default: true },
  // Rank results near this { latitude, longitude }
  near:         { type: Object, default: null },
  // Keep the box empty after choosing (for adding several places)
  resetOnPick:  { type: Boolean, default: false },
  exclude:      { type: Array, default: () => [] },
  id:           { type: String, default: '' },
});
const emit = defineEmits(['update:modelValue', 'select']);

const uid = useId();
const inputId = computed(() => props.id || `place-${uid}`);
const listId = computed(() => `${inputId.value}-list`);
const describedBy = computed(() => (props.errorMessage ? `${inputId.value}-error` : props.hint ? `${inputId.value}-hint` : undefined));

const root = ref(null);
const input = ref(null);
const text = ref('');
const open = ref(false);
const active = ref(-1);
const loading = ref(false);
const failed = ref(false);
const results = ref([]);

const labelOf = (id) => (id ? areaLabel(id) : (props.anyLabel || ''));
const showSelected = () => { text.value = props.resetOnPick ? '' : labelOf(props.modelValue); };
// Show the chosen place (once the places have loaded, too)
watch(() => [props.modelValue, searchLocations.length], () => { if (!open.value) showSelected(); }, { immediate: true });

const query = computed(() => {
  const t = text.value.trim();
  return t.length >= 2 && t !== labelOf(props.modelValue) ? t : '';
});
const popular = computed(() => searchLocations.filter(l => l.featured));
const options = computed(() => (query.value ? results.value : popular.value).filter(o => !props.exclude.includes(o.id)));

let timer = null;
let latest = 0;
function onInput() {
  open.value = true;
  active.value = -1;
  clearTimeout(timer);
  if (!query.value) { results.value = []; return; }
  loading.value = true;
  timer = setTimeout(async () => {
    const mine = ++latest;
    try {
      const found = await searchPlaces(query.value, props.near);
      if (mine === latest) { results.value = found; failed.value = false; }
    } catch {
      if (mine === latest) { results.value = []; failed.value = true; }
    } finally {
      if (mine === latest) loading.value = false;
    }
  }, 220);
}

function onFocus() {
  open.value = true;
  // Select the current text so typing replaces it
  requestAnimationFrame(() => input.value?.select());
}

function close(restore) {
  open.value = false;
  active.value = -1;
  if (restore) showSelected();
}

function onBlur() {
  // Leaving without choosing keeps the previous place
  setTimeout(() => { if (!root.value?.contains(document.activeElement)) close(true); }, 120);
}

function move(step) {
  if (!open.value) { open.value = true; return; }
  const n = options.value.length;
  if (!n) return;
  active.value = (active.value + step + n) % n;
}

function onEnter(e) {
  if (!open.value) return;
  e.preventDefault();
  const o = options.value[active.value >= 0 ? active.value : 0];
  if (o) pick(o);
}

function pick(o) {
  if (o) {
    // Results from a search become known places, so labels and distances work
    if (!areaById(o.id)) searchLocations.push(o);
  }
  emit('update:modelValue', o ? o.id : '');
  emit('select', o || null);
  open.value = false;
  active.value = -1;
  results.value = [];
  text.value = props.resetOnPick ? '' : (o ? areaLabel(o) : (props.anyLabel || ''));
}

function clear() {
  pick(null);
  input.value?.focus();
}
</script>
