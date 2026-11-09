<template>
  <!-- Customers only see it when there's work to show; the owner always does -->
  <section v-if="isOwn || items.length || loading" id="work" class="wm-card wm-card-lg scroll-mt-[calc(var(--wm-header-h)+16px)]" aria-labelledby="work-heading">
    <div class="flex items-center justify-between gap-3">
      <h2 id="work-heading" class="text-[19px] lg:text-xl font-semibold text-ink">
        Recent work<span v-if="shown.length" class="ml-1.5 text-[15px] font-normal text-muted">{{ shown.length }}</span>
      </h2>
      <button v-if="isOwn && items.length && !full" type="button" class="wm-btn wm-btn-tint !min-h-10 !px-3.5" @click="add">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add work
      </button>
    </div>

    <!-- Loading -->
    <ul v-if="loading && !items.length" class="mt-3 grid grid-cols-3 sm:grid-cols-4 gap-1.5 sm:gap-2" aria-busy="true" aria-label="Loading work">
      <li v-for="n in 3" :key="n" class="wm-skeleton aspect-square !rounded-xl"></li>
    </ul>

    <p v-else-if="failed" class="mt-3 text-sm text-muted">Their work couldn’t load. <button type="button" class="wm-link" @click="load">Try again</button></p>

    <!-- The owner, before adding anything -->
    <div v-else-if="isOwn && !items.length" class="mt-3 flex flex-col items-center gap-2 rounded-2xl border-2 border-dashed border-primary/25 bg-primary-tint/60 px-5 py-7 text-center">
      <span class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-white text-primary text-[22px] shadow-sm" aria-hidden="true"><i class="bi bi-images"></i></span>
      <p class="text-[16px] font-semibold text-ink">Show customers your best jobs</p>
      <p class="max-w-[340px] text-[14px] text-muted">Photos of finished work help customers choose you. They stay on your profile.</p>
      <button type="button" class="wm-btn wm-btn-primary mt-2 !min-h-11" @click="add"><i class="bi bi-plus-lg" aria-hidden="true"></i> Add your first job</button>
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
      <button v-if="items.length > PREVIEW" type="button" class="wm-btn wm-btn-secondary mt-3 w-full !min-h-11" :aria-expanded="expanded" @click="expanded = !expanded">
        {{ expanded ? 'Show less' : `See all work (${items.length})` }}
      </button>
      <p v-if="isOwn && full" class="mt-2 text-[13px] text-muted">You’re showing {{ MAX_ITEMS }} jobs, the most allowed. Delete an older one to add more.</p>
      <p v-if="!isOwn" class="mt-2 text-[12.5px] text-muted">Photos shared by the provider.</p>
    </template>

    <WorkViewer :open="!!viewing" :items="items" :start-id="viewing || ''" :is-own="isOwn" :can-report="!isOwn" :provider-name="providerName"
      @close="viewing = null" @edit="edit" @delete="remove" @report="report" />
    <WorkComposer v-if="isOwn" :open="composing" :item="editing" @close="composing = false; editing = null" @saved="saved" />
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
const editing = ref(null);
const reporting = ref(null);

const shown = computed(() => items.value.filter(i => i.status !== 'removed'));
const visible = computed(() => (expanded.value ? items.value : items.value.slice(0, PREVIEW)));
const full = computed(() => items.value.length >= MAX_ITEMS);

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
  props.provider.workCount = shown.value.length;
}

function view(item) { viewing.value = item.id; }

function add() {
  if (!auth.user?.email_verified) {
    showFlash('Confirm your email address first. Use the link we sent you.', 'error');
    return;
  }
  editing.value = null;
  composing.value = true;
}

function edit(item) {
  viewing.value = null;
  editing.value = item;
  composing.value = true;
}

function saved(item, created) {
  if (created) items.value = [item, ...items.value];
  else items.value = items.value.map(i => (i.id === item.id ? item : i));
  syncCount();
}

async function remove(item) {
  if (!window.confirm('Delete this work from your profile?')) return;
  try {
    await api(`/me/provider/work/${item.id}`, { method: 'DELETE' });
    items.value = items.value.filter(i => i.id !== item.id);
    if (!items.value.length) viewing.value = null;
    syncCount();
    showFlash('Work deleted.');
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
</script>
