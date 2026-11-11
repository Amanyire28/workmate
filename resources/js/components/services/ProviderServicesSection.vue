<template>
  <!-- Services & Prices: what they do and what they usually charge. Customers only see it when there's something listed. -->
  <section v-if="isOwn || listings.length" id="services" class="wm-card wm-card-lg scroll-mt-[calc(var(--wm-header-h)+16px)]" aria-labelledby="services-heading">
    <div class="flex items-center justify-between gap-3">
      <h2 id="services-heading" class="text-[19px] lg:text-xl font-semibold text-ink">Services &amp; Prices</h2>
      <button v-if="isOwn && listings.length && listings.length < MAX" type="button" class="wm-btn wm-btn-tint !min-h-10 !px-3.5" @click="openAdd">
        <i class="bi bi-plus-lg" aria-hidden="true"></i> Add service
      </button>
    </div>

    <div v-if="loading && !listings.length" class="mt-3 flex flex-col gap-2" aria-busy="true">
      <div v-for="n in 2" :key="n" class="wm-skeleton h-14 !rounded-xl"></div>
    </div>

    <p v-else-if="failed" class="mt-3 text-sm text-muted">Their services couldn’t load. <button type="button" class="wm-link" @click="load">Try again</button></p>

    <!-- The owner, before listing anything -->
    <div v-else-if="isOwn && !listings.length" class="mt-3 flex items-center gap-4 rounded-2xl border-2 border-dashed border-primary/25 bg-primary-tint/60 p-4">
      <span class="inline-flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white text-[22px] text-primary shadow-sm" aria-hidden="true"><i class="bi bi-tags"></i></span>
      <div class="min-w-0 flex-1">
        <p class="text-[15px] font-semibold text-ink">List what you do and your usual prices</p>
        <p class="text-[13.5px] text-muted">For example “Socket installation, from UGX 30,000”. Customers can request a service straight from the list.</p>
      </div>
      <button type="button" class="wm-btn wm-btn-primary !min-h-11 shrink-0 max-sm:!px-3" @click="openAdd">
        <i class="bi bi-plus-lg" aria-hidden="true"></i><span class="max-sm:sr-only"> Add service</span>
      </button>
    </div>

    <template v-else>
      <div v-for="group in groups" :key="group.key" class="mt-4 first:mt-3">
        <h3 v-if="groups.length > 1 || group.name" class="mb-1.5 text-[13px] font-semibold uppercase tracking-wide text-muted">{{ group.name ? `${group.name}` : 'Services' }}</h3>
        <ul class="divide-y divide-line rounded-xl border border-line">
          <li v-for="s in group.items" :key="s.id" class="flex items-center gap-3 px-3.5 py-3" :class="{ 'bg-[#F8FAFC]': s.is_active === false }">
            <div class="min-w-0 flex-1">
              <p class="text-[15px] font-medium text-ink" :class="{ 'text-muted': s.is_active === false }">
                {{ s.title }}
                <span v-if="s.is_active === false" class="wm-pill wm-pill-neutral !py-0 !text-[11px] ml-1">Hidden</span>
              </p>
              <p v-if="s.description" class="mt-0.5 text-[13px] leading-snug text-muted line-clamp-2">{{ s.description }}</p>
              <p class="mt-0.5 text-[14px] font-semibold" :class="s.pricing_type === 'quote' ? 'text-muted' : 'text-ink'">{{ servicePriceLabel(s) }}</p>
            </div>

            <!-- Customers: request this service -->
            <router-link v-if="!isOwn" :to="{ path: `/provider/${provider.id}/request`, query: { service: s.id } }"
              class="wm-btn wm-btn-tint !min-h-10 !px-3 shrink-0" :aria-label="`Request ${s.title}`">
              Request
            </router-link>

            <!-- Owner: order and edit -->
            <div v-else class="flex shrink-0 items-center">
              <button type="button" class="wm-icon-btn !w-9 !h-9 text-muted" :disabled="busy || s.position === first" :aria-label="`Move ${s.title} up`" @click="move(s, -1)">
                <i class="bi bi-arrow-up" aria-hidden="true"></i>
              </button>
              <button type="button" class="wm-icon-btn !w-9 !h-9 text-muted" :disabled="busy || s.position === last" :aria-label="`Move ${s.title} down`" @click="move(s, 1)">
                <i class="bi bi-arrow-down" aria-hidden="true"></i>
              </button>
              <button type="button" class="wm-icon-btn !w-9 !h-9 text-ink" :aria-label="`Edit ${s.title}`" @click="openEdit(s)">
                <i class="bi bi-pencil" aria-hidden="true"></i>
              </button>
            </div>
          </li>
        </ul>
      </div>
      <p class="mt-3 text-[12.5px] text-muted">
        <i class="bi bi-info-circle mr-1" aria-hidden="true"></i>Prices are a guide. Agree the final price with {{ isOwn ? 'each customer' : 'the provider' }} before the work starts.
      </p>
    </template>

    <ServiceComposer v-if="isOwn" :open="composing" :item="editing" :categories="offered" @close="closeComposer" @saved="saved" @removed="removed" />
  </section>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import ServiceComposer from './ServiceComposer.vue';
import { api } from '@/api/client.js';
import { showFlash } from '@/stores/flash.js';
import { categoryLabel, servicePriceLabel } from '@/utils/ui.js';

const MAX = 30;

const props = defineProps({
  provider: { type: Object, required: true },
  isOwn: { type: Boolean, default: false },
});

const listings = ref([]);
const loading = ref(false);
const failed = ref(false);
const busy = ref(false);
const composing = ref(false);
const editing = ref(null);

// The categories they offer, for the composer's choice
const offered = computed(() => (props.provider.services || []).map(id => ({ id, label: categoryLabel(id) })));

/** Grouped under each category's name (in their own order), e.g. "Electrical" */
const groups = computed(() => {
  const out = [];
  for (const s of listings.value) {
    const key = s.category || '-';
    let g = out.find(x => x.key === key);
    if (!g) { g = { key, name: s.category_name || '', items: [] }; out.push(g); }
    g.items.push(s);
  }
  return out;
});
const first = computed(() => listings.value[0]?.position);
const last = computed(() => listings.value[listings.value.length - 1]?.position);

async function load() {
  loading.value = true;
  failed.value = false;
  try {
    listings.value = (await api(props.isOwn ? '/me/provider/services' : `/providers/${props.provider.id}/services`)).data;
  } catch {
    failed.value = true;
  } finally {
    loading.value = false;
  }
}
watch(() => [props.provider.id, props.isOwn], () => { listings.value = []; load(); }, { immediate: true });

function openAdd() { editing.value = null; composing.value = true; }
function openEdit(s) { editing.value = s; composing.value = true; }
function closeComposer() { composing.value = false; editing.value = null; }

function saved(item, created) {
  listings.value = created ? [...listings.value, item] : listings.value.map(s => (s.id === item.id ? item : s));
}
function removed(id) {
  listings.value = listings.value.filter(s => s.id !== id);
}

async function move(s, step) {
  const list = [...listings.value];
  const i = list.findIndex(x => x.id === s.id);
  const j = i + step;
  if (j < 0 || j >= list.length) return;
  [list[i], list[j]] = [list[j], list[i]];
  busy.value = true;
  try {
    listings.value = (await api('/me/provider/services/order', { method: 'POST', body: { ids: list.map(x => x.id) } })).data;
  } catch (e) {
    showFlash(e.message, 'error');
    load();
  } finally {
    busy.value = false;
  }
}
</script>
