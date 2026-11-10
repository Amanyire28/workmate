<template>
  <!-- ============ Featured projects: up to three flagship jobs, a few large cards ============ -->
  <section v-if="featured.length || (isOwn && !loading && !failed)" id="projects" class="wm-card wm-card-lg scroll-mt-[calc(var(--wm-header-h)+16px)]" aria-labelledby="projects-heading">
    <div class="flex items-center justify-between gap-3">
      <h2 id="projects-heading" class="text-[19px] lg:text-xl font-semibold text-ink">Featured projects</h2>
      <button v-if="isOwn && featured.length && featured.length < MAX_FEATURED && !full" type="button" class="wm-btn wm-btn-tint !min-h-10 !px-3.5" @click="add(true)">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add project
      </button>
    </div>

    <!-- The owner, before featuring anything: one quiet prompt -->
    <div v-if="isOwn && !featured.length" class="mt-3 flex items-center gap-4 rounded-2xl border-2 border-dashed border-[#F59E0B]/35 bg-[#FFFBEB] p-4">
      <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white text-[22px] text-[#B45309] shadow-sm" aria-hidden="true"><i class="bi bi-stars"></i></span>
      <div class="min-w-0 flex-1">
        <p class="text-[15px] font-semibold text-ink">Show your flagship jobs</p>
        <p class="text-[13.5px] text-muted">Feature up to {{ MAX_FEATURED }} of your best projects, with up to {{ MAX_FEATURED_PHOTOS }} photos each. They appear first on your profile.</p>
      </div>
      <button type="button" class="wm-btn wm-btn-primary !min-h-11 shrink-0 max-sm:!px-3" :disabled="full" @click="add(true)">
        <i class="bi bi-plus-lg" aria-hidden="true"></i><span class="max-sm:sr-only"> Add project</span>
      </button>
    </div>

    <!-- Phones: swipe through the cards (the next one peeks in). Computers: side by side. -->
    <ul v-else class="mt-3 flex snap-x snap-mandatory gap-3 overflow-x-auto pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden lg:grid lg:overflow-visible lg:pb-0"
      :class="featured.length === 1 ? 'lg:grid-cols-1' : 'lg:grid-cols-2'">
      <li v-for="p in featured" :key="p.id" class="shrink-0 snap-start"
        :class="[featured.length === 1 ? 'w-full' : 'w-[86%] sm:w-[62%] lg:w-auto', featured.length === 3 ? 'lg:last:col-span-2' : '']">
        <button type="button" class="group block w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2 rounded-2xl"
          :aria-label="`${p.title}${p.photos.length > 1 ? `, ${p.photos.length} photos` : ''}`" @click="view(p)">
          <!-- However many photos they chose: one fills the card, two sit side by side,
               three or more show one large and two small, with the rest counted -->
          <div class="relative grid gap-[3px] overflow-hidden rounded-2xl bg-[#E2E8F0]"
            :class="[mosaic(p), featured.length === 1 || (featured.length === 3 && p === featured[2]) ? 'aspect-[16/9]' : 'aspect-[4/3]']">
            <img :src="p.photos[0]?.thumb_url" alt="" loading="lazy" decoding="async"
              class="h-full w-full object-cover transition-transform duration-500 group-hover:scale-[1.03]"
              :class="[p.photos.length >= 3 ? 'col-span-2 row-span-2' : '', { 'opacity-40 grayscale': p.status === 'removed' }]" />
            <img v-for="(ph, i) in p.photos.slice(1, 3)" :key="i" :src="ph.thumb_url" alt="" loading="lazy" decoding="async"
              class="h-full w-full object-cover" :class="{ 'opacity-40 grayscale': p.status === 'removed' }" />
            <span v-if="p.photos.length > 3" class="pointer-events-none absolute bottom-0 right-0 flex h-1/2 w-1/3 items-center justify-center bg-black/45 text-[18px] font-semibold text-white">
              +{{ p.photos.length - 3 }}
            </span>
            <span class="absolute left-2 top-2 inline-flex items-center gap-1 rounded-full bg-white/90 px-2 py-0.5 text-[11.5px] font-semibold text-[#B45309] shadow-sm">
              <i class="bi bi-star-fill text-[10px]" aria-hidden="true"></i>Featured
            </span>
            <span v-if="p.status === 'removed'" class="absolute right-2 top-2 rounded-full bg-[#B91C1C] px-2 py-0.5 text-[11px] font-semibold text-white">Removed</span>
          </div>
          <p class="mt-2.5 line-clamp-1 text-[16px] font-semibold text-ink group-hover:text-primary">{{ p.title }}</p>
          <p class="mt-0.5 flex flex-wrap gap-x-2.5 text-[13px] text-muted">
            <span v-if="p.area_name"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ p.area_name }}</span>
            <span v-if="p.done_on">{{ monthLabel(p.done_on) }}</span>
            <span v-if="p.photos.length > 1"><i class="bi bi-images" aria-hidden="true"></i> {{ p.photos.length }} photos</span>
          </p>
          <p v-if="p.description" class="mt-1 line-clamp-2 text-[14px] leading-snug text-[#475569]">{{ p.description }}</p>
        </button>
      </li>
    </ul>
  </section>

  <!-- ============ Recent work: everything else, as a compact grid ============ -->
  <section v-if="isOwn || recent.length || loading" id="work" class="wm-card wm-card-lg scroll-mt-[calc(var(--wm-header-h)+16px)]" aria-labelledby="work-heading">
    <div class="flex items-center justify-between gap-3">
      <h2 id="work-heading" class="text-[19px] lg:text-xl font-semibold text-ink">
        Recent work<span v-if="recentShown" class="ml-1.5 text-[15px] font-normal text-muted">{{ recentShown }}</span>
      </h2>
      <button v-if="isOwn && recent.length && !full" type="button" class="wm-btn wm-btn-tint !min-h-10 !px-3.5" @click="add(false)">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add work
      </button>
    </div>

    <!-- Loading -->
    <ul v-if="loading && !items.length" class="mt-3 grid grid-cols-3 sm:grid-cols-4 gap-1.5 sm:gap-2" aria-busy="true" aria-label="Loading work">
      <li v-for="n in 3" :key="n" class="wm-skeleton aspect-square !rounded-xl"></li>
    </ul>

    <p v-else-if="failed" class="mt-3 text-sm text-muted">Their work couldn’t load. <button type="button" class="wm-link" @click="load">Try again</button></p>

    <!-- The owner, before adding anything here -->
    <div v-else-if="isOwn && !recent.length" class="mt-3 flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-primary/25 bg-primary-tint/60 px-5 py-7 text-center">
      <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-white text-primary text-[22px] shadow-sm" aria-hidden="true"><i class="bi bi-images"></i></span>
      <p class="text-[16px] font-semibold text-ink">Show customers your everyday jobs</p>
      <p class="max-w-[340px] text-[14px] text-muted">A few photos of each finished job help customers choose you. They stay on your profile.</p>
      <button type="button" class="wm-btn wm-btn-primary mt-2 !min-h-11" :disabled="full" @click="add(false)"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add a job</button>
    </div>

    <template v-else>
      <ul class="mt-3 grid grid-cols-3 sm:grid-cols-4 gap-1.5 sm:gap-2">
        <li v-for="item in visible" :key="item.id">
          <button type="button" class="group relative block aspect-square w-full overflow-hidden rounded-xl bg-[#E2E8F0] focus:outline-none focus-visible:ring-2 focus-visible:ring-primary focus-visible:ring-offset-2"
            :aria-label="`${item.title}${item.photos.length > 1 ? `, ${item.photos.length} photos` : ''}`" @click="view(item)">
            <img :src="item.photos[0]?.thumb_url" alt="" loading="lazy" decoding="async" class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.04]" :class="{ 'opacity-40 grayscale': item.status === 'removed' }" />
            <span class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent px-2 pb-1.5 pt-6 text-left text-[12px] font-medium leading-tight text-white line-clamp-2">{{ item.title }}</span>
            <span v-if="item.photos.length > 1" class="absolute right-1.5 top-1.5 inline-flex items-center gap-1 rounded-full bg-black/55 px-1.5 py-0.5 text-[11px] font-semibold text-white">
              <i class="bi bi-images" aria-hidden="true"></i>{{ item.photos.length }}
            </span>
            <span v-if="item.status === 'removed'" class="absolute left-1.5 top-1.5 rounded-full bg-[#B91C1C] px-1.5 py-0.5 text-[10.5px] font-semibold text-white">Removed</span>
          </button>
        </li>
      </ul>
      <button v-if="recent.length > PREVIEW" type="button" class="wm-btn wm-btn-secondary mt-3 w-full !min-h-11" :aria-expanded="expanded" @click="expanded = !expanded">
        {{ expanded ? 'Show less' : `See all work (${recent.length})` }}
      </button>
      <p v-if="isOwn && full" class="mt-2 text-[13px] text-muted">You’re showing {{ MAX_ITEMS }} jobs, the most allowed. Delete an older one to add more.</p>
      <p v-if="!isOwn" class="mt-2 text-[12.5px] text-muted">Photos shared by the provider.</p>
    </template>

    <!-- A featured project opens on its own photos; recent work swipes through all of it -->
    <WorkViewer :open="!!viewing" :items="viewerItems" :start-id="viewing || ''" :is-own="isOwn" :can-report="!isOwn" :provider-name="providerName"
      @close="viewing = null" @edit="edit" @delete="remove" @report="report" />
    <WorkComposer v-if="isOwn" :open="composing" :item="editing" :featured="composeFeatured" :can-feature="featured.length < MAX_FEATURED"
      @close="composing = false; editing = null" @saved="saved" />
    <ReportSheet v-if="!isOwn" :open="!!reporting" about="work" :work-id="reporting?.id" @close="reporting = null" />
  </section>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import WorkViewer from './WorkViewer.vue';
import WorkComposer from './WorkComposer.vue';
import ReportSheet from '@/components/ReportSheet.vue';
import { api } from '@/api/client.js';
import { auth } from '@/stores/auth.js';
import { showFlash } from '@/stores/flash.js';

const PREVIEW = 6;
const MAX_ITEMS = 30;
const MAX_FEATURED = 3;
const MAX_FEATURED_PHOTOS = 6;

const props = defineProps({
  provider: { type: Object, required: true },
  isOwn: { type: Boolean, default: false },
  providerName: { type: String, default: '' },
});

const router = useRouter();
const route = useRoute();
const items = ref([]);
const loading = ref(false);
const failed = ref(false);
const expanded = ref(false);
const viewing = ref(null);
const composing = ref(false);
const composeFeatured = ref(false);
const editing = ref(null);
const reporting = ref(null);

const featured = computed(() => items.value.filter(i => i.featured));
const recent = computed(() => items.value.filter(i => !i.featured));
const recentShown = computed(() => recent.value.filter(i => i.status !== 'removed').length);
const visible = computed(() => (expanded.value ? recent.value : recent.value.slice(0, PREVIEW)));
const full = computed(() => items.value.length >= MAX_ITEMS);
const viewerItems = computed(() => {
  const item = items.value.find(i => i.id === viewing.value);
  return item?.featured ? [item] : recent.value;
});

async function load() {
  loading.value = true;
  failed.value = false;
  try {
    // The owner also sees work WorkMate removed, and why
    items.value = (await api(props.isOwn ? '/me/provider/work' : `/providers/${props.provider.id}/work`)).data;
  } catch {
    failed.value = true;
  } finally {
    loading.value = false;
  }
  syncCount();
}

watch(() => [props.provider.id, props.isOwn], () => { items.value = []; expanded.value = false; load(); }, { immediate: true });

/** Keeps the "N work" line on provider cards right without reloading the catalogue */
function syncCount() {
  props.provider.workCount = items.value.filter(i => i.status !== 'removed').length;
}

function view(item) { viewing.value = item.id; }

function add(asFeatured) {
  if (!auth.user?.email_verified) {
    showFlash('Confirm your email address first. Use the link we sent you.', 'error');
    return;
  }
  editing.value = null;
  composeFeatured.value = asFeatured;
  composing.value = true;
}

function edit(item) {
  viewing.value = null;
  editing.value = item;
  composeFeatured.value = !!item.featured;
  composing.value = true;
}

function saved(item, created) {
  if (created) items.value = [item, ...items.value];
  else items.value = items.value.map(i => (i.id === item.id ? item : i));
  syncCount();
}

async function remove(item) {
  if (!window.confirm(item.featured ? 'Delete this project from your profile?' : 'Delete this work from your profile?')) return;
  try {
    await api(`/me/provider/work/${item.id}`, { method: 'DELETE' });
    items.value = items.value.filter(i => i.id !== item.id);
    // A deleted project closes its viewer; recent work moves on to the next job
    if (item.featured || !recent.value.length) viewing.value = null;
    syncCount();
    showFlash(item.featured ? 'Project deleted.' : 'Work deleted.');
  } catch (e) {
    showFlash(e.message, 'error');
  }
}

function report(item) {
  if (!auth.user) {
    router.push({ path: '/sign-in', query: { redirect: route.fullPath } });
    return;
  }
  viewing.value = null;
  reporting.value = item;
}

/** The mosaic's grid for a project's photo count */
function mosaic(p) {
  const n = p.photos.length;
  if (n >= 3) return 'grid-cols-3 grid-rows-2';
  return n === 2 ? 'grid-cols-2' : 'grid-cols-1';
}

function monthLabel(ym) {
  const [y, m] = ym.split('-').map(Number);
  return new Date(y, m - 1, 1).toLocaleDateString('en-GB', { month: 'short', year: 'numeric' });
}
</script>
