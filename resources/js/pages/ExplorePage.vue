<template>
  <div class="wm-container wm-page">
    <h1 class="wm-h1">Services</h1>
    <p class="wm-lead mt-1.5">Choose a service to see the providers who offer it.</p>

    <div class="mt-4 lg:max-w-[720px]">
      <SearchBar
        v-model="filter"
        id="explore-search"
        label="Filter services"
        placeholder="Search services, e.g. plumber"
        @submit="openFirstMatch"
      />
    </div>

    <section class="mt-6" aria-label="Service categories">
      <ul
        v-if="visible.length"
        class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-3 lg:gap-4"
      >
        <li v-for="cat in visible" :key="cat.id">
          <ServiceCategoryCard
            :category="cat"
            :to="{ path: '/browse', query: { category: cat.id } }"
            class="!min-h-[112px]"
          />
        </li>
      </ul>

      <EmptyState
        v-else
        icon="bi-search"
        title="No matching services"
        :message="`Nothing matches “${filter}”. Try another word, or search all providers instead.`"
      >
        <button type="button" class="wm-btn wm-btn-secondary" @click="filter = ''">Show all services</button>
        <router-link :to="{ path: '/browse', query: { q: filter } }" class="wm-btn wm-btn-primary">Search providers</router-link>
      </EmptyState>
    </section>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useRouter } from 'vue-router';
import SearchBar from '@/components/SearchBar.vue';
import ServiceCategoryCard from '@/components/ServiceCategoryCard.vue';
import EmptyState from '@/components/EmptyState.vue';
import { categories } from '@/stores/catalog.js';
import { categoriesMatching } from '@/utils/search.js';

const router = useRouter();
const filter = ref('');

const visible = computed(() => {
  const q = filter.value.trim().toLowerCase();
  if (!q) return categories;
  const byKeyword = categoriesMatching(q, categories);
  return categories.filter(c => byKeyword.includes(c.id) || `${c.label} ${c.description}`.toLowerCase().includes(q));
});

function openFirstMatch() {
  if (visible.value.length) {
    router.push({ path: '/browse', query: { category: visible.value[0].id } });
  } else if (filter.value.trim()) {
    router.push({ path: '/browse', query: { q: filter.value.trim() } });
  }
}
</script>
