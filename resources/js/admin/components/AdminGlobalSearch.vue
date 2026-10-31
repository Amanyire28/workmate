<template>
  <div ref="root" class="relative w-full">
    <div
      class="wm-glass-input flex h-10 items-center rounded-[10px]"
    >
      <i class="bi bi-search pl-3 pr-2 text-[15px] text-[#64748B]" aria-hidden="true"></i>
      <label :for="inputId" class="sr-only">Search customers, providers, jobs, bookings, payments and disputes</label>
      <input
        :id="inputId"
        ref="input"
        v-model="query"
        type="search"
        role="combobox"
        autocomplete="off"
        spellcheck="false"
        :aria-expanded="showList"
        :aria-controls="listId"
        aria-autocomplete="list"
        :aria-activedescendant="activeIndex >= 0 ? `${listId}-${activeIndex}` : undefined"
        placeholder="Search people, jobs, bookings, payments…"
        class="h-full min-w-0 flex-1 bg-transparent text-[13.5px] text-ink placeholder:text-[#94A3B8] focus:outline-none"
        @focus="focused = true"
        @blur="focused = false"
        @keydown.down.prevent="move(1)"
        @keydown.up.prevent="move(-1)"
        @keydown.enter.prevent="choose(results[activeIndex] || results[0])"
        @keydown.esc="query ? (query = '') : input.blur()"
      />
      <kbd class="mr-2 hidden xl:inline-flex h-5 items-center rounded border border-[#E2E8F0] px-1.5 text-[11px] text-[#64748B]" aria-hidden="true">Ctrl K</kbd>
    </div>

    <!-- Results: floating glass panel -->
    <div
      v-show="showList"
      class="absolute left-0 right-0 top-[calc(100%+6px)] z-50 max-h-[min(440px,70vh)] overflow-y-auto rounded-xl wm-glass-pop p-1.5"
    >
      <p v-if="loading && !results.length" class="px-3 py-3 text-[13px] text-[#64748B]" role="status">Searching…</p>
      <p v-else-if="error" class="px-3 py-3 text-[13px] text-error" role="alert">{{ error }}</p>
      <p v-else-if="!results.length" class="px-3 py-3 text-[13px] text-[#64748B]" role="status">No records match “{{ query.trim() }}”.</p>
      <ul :id="listId" role="listbox" aria-label="Search results">
        <template v-for="(r, i) in results" :key="`${r.type}-${r.id}`">
          <li v-if="i === 0 || results[i - 1].type !== r.type" role="presentation" class="px-3 pt-2 pb-1 text-[11px] font-semibold uppercase tracking-wide text-[#94A3B8]">
            {{ SEARCH_TYPES[r.type] }}
          </li>
          <li
            :id="`${listId}-${i}`"
            role="option"
            :aria-selected="i === activeIndex"
            class="flex cursor-pointer items-center gap-3 rounded-lg px-3 py-2"
            :class="i === activeIndex ? 'bg-[#EFF6FF]' : 'hover:bg-[#F8FAFC]'"
            @pointerdown.prevent="choose(r)"
            @pointerenter="activeIndex = i"
          >
            <span class="min-w-0 flex-1">
              <span class="block truncate text-[13.5px] font-medium text-ink">{{ r.title }}</span>
              <span class="block truncate text-[12px] text-[#64748B]">{{ r.id }} · {{ r.subtitle }}</span>
            </span>
            <AdminStatusBadge :status="r.status" />
          </li>
        </template>
      </ul>
    </div>

  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, useId } from 'vue';
import { useRouter } from 'vue-router';
import AdminStatusBadge from './AdminStatusBadge.vue';
import { globalSearch, SEARCH_TYPES } from '../data/adminApi.js';
import { can } from '../permissions.js';

const inputId = useId();
const listId = useId();
const root = ref(null);
const input = ref(null);
const query = ref('');
const focused = ref(false);
const results = ref([]);
const loading = ref(false);
const error = ref('');
const activeIndex = ref(-1);
const router = useRouter();

const showList = computed(() => focused.value && query.value.trim().length >= 2);

let timer = null;
let latest = 0;
watch(query, (q) => {
  clearTimeout(timer);
  activeIndex.value = -1;
  if (q.trim().length < 2) { results.value = []; return; }
  timer = setTimeout(async () => {
    const id = ++latest;
    loading.value = true;
    error.value = '';
    try {
      const res = await globalSearch(q, can);
      if (id === latest) results.value = res.results;
    } catch (e) {
      if (id === latest) { results.value = []; error.value = e.message; }
    } finally {
      if (id === latest) loading.value = false;
    }
  }, 160);
});

function move(dir) {
  if (!results.value.length) return;
  activeIndex.value = (activeIndex.value + dir + results.value.length) % results.value.length;
}

// Opens the record in its module (the module checks access again)
function choose(r) {
  if (!r) return;
  query.value = '';
  input.value?.blur();
  router.push(r.to);
}

// Ctrl/Cmd+K or "/" focuses search from anywhere in the admin
function onShortcut(e) {
  const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement?.tagName);
  if ((e.key === 'k' && (e.ctrlKey || e.metaKey)) || (e.key === '/' && !typing)) {
    e.preventDefault();
    input.value?.focus();
  }
}
onMounted(() => document.addEventListener('keydown', onShortcut));
onBeforeUnmount(() => { document.removeEventListener('keydown', onShortcut); clearTimeout(timer); });

defineExpose({ focus: () => input.value?.focus() });
</script>
