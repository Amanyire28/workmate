<template>
  <div class="wm-container wm-container-sm wm-page">
    <BackLink to="/community">Community</BackLink>
    <h1 class="wm-h1 mt-1">Discover people</h1>
    <p class="wm-meta mt-1">Follow providers and members whose work you want to see.</p>

    <div class="mt-4 flex flex-col gap-2 sm:flex-row">
      <SearchBar v-model="search" id="people-search" label="Search people" placeholder="Search by name or business" class="flex-1" />
      <label for="people-topic" class="sr-only">Profession</label>
      <select id="people-topic" v-model="topic" class="wm-input sm:w-52">
        <option value="">All professions</option>
        <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
      </select>
    </div>

    <div v-if="loading && !people.length" class="mt-5 flex flex-col gap-3" aria-busy="true">
      <div v-for="n in 4" :key="n" class="wm-card flex items-center gap-3"><div class="wm-skeleton h-12 w-12 !rounded-full"></div><div class="flex-1"><div class="wm-skeleton h-4 w-40"></div><div class="wm-skeleton mt-2 h-3 w-28"></div></div></div>
    </div>

    <EmptyState v-else-if="error && !people.length" class="mt-4" tone="error" icon="bi-wifi-off" title="People couldn’t load" :message="error">
      <button type="button" class="wm-btn wm-btn-primary !min-h-12" @click="load(true)">Try again</button>
    </EmptyState>

    <EmptyState v-else-if="!people.length" class="mt-4" icon="bi-person-plus"
      :title="search || topic ? 'Nobody found' : 'You’re following everyone here'"
      :message="search || topic ? 'Try another name or profession.' : 'As more people share their work, they’ll appear here.'" />

    <template v-else>
      <p class="mt-4 wm-meta">{{ search ? 'Matching people' : 'Recently active first' }}</p>
      <ul class="mt-2 flex flex-col gap-2">
        <li v-for="p in people" :key="p.id" class="wm-card !py-3"><PersonRow :person="p" /></li>
      </ul>
      <div class="mt-4 flex justify-center">
        <button v-if="nextPage" type="button" class="wm-btn wm-btn-secondary !min-h-11" :disabled="loading" @click="load()">{{ loading ? 'Loading…' : 'Show more people' }}</button>
      </div>
    </template>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue';
import BackLink from '@/components/BackLink.vue';
import SearchBar from '@/components/SearchBar.vue';
import EmptyState from '@/components/EmptyState.vue';
import PersonRow from '@/components/community/PersonRow.vue';
import { api } from '@/api/client.js';
import { categories, loadCatalog } from '@/stores/catalog.js';

const search = ref('');
const topic = ref('');
const people = ref([]);
const nextPage = ref(null);
const loading = ref(false);
const error = ref('');

let generation = 0;
async function load(fresh = false) {
  const mine = ++generation;
  if (fresh) { people.value = []; nextPage.value = 1; }
  loading.value = true;
  error.value = '';
  try {
    const q = new URLSearchParams({ page: nextPage.value || 1 });
    if (search.value.trim()) q.set('q', search.value.trim());
    if (topic.value) q.set('topic', topic.value);
    const r = await api(`/community/people?${q}`);
    if (mine !== generation) return;
    people.value.push(...r.data);
    nextPage.value = r.meta.next_page;
  } catch (e) {
    if (mine === generation) error.value = e.message;
  } finally {
    if (mine === generation) loading.value = false;
  }
}

// Searching waits for a pause in typing
let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => load(true), 300); });
watch(topic, () => load(true));
onMounted(() => { loadCatalog().catch(() => {}); load(true); });
</script>
