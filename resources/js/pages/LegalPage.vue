<template>
  <div class="wm-container wm-page max-w-3xl">
    <!-- One document -->
    <template v-if="key">
      <button v-if="cameFromApp" type="button" class="inline-flex items-center gap-1.5 min-h-11 -ml-1 px-1 rounded-lg text-sm font-medium text-muted hover:text-ink" @click="router.back()">
        <i class="bi bi-arrow-left" aria-hidden="true"></i>Back
      </button>
      <BackLink v-else to="/legal">Legal</BackLink>

      <div v-if="loading" class="mt-4 flex flex-col gap-3" aria-busy="true">
        <div class="wm-skeleton h-9 w-3/4"></div>
        <div class="wm-skeleton h-24"></div>
        <div class="wm-skeleton h-64"></div>
      </div>

      <EmptyState v-else-if="error" class="mt-4" icon="bi-file-earmark-x" title="This document couldn’t load" :message="error">
        <button type="button" class="wm-btn wm-btn-secondary" @click="load">Try again</button>
      </EmptyState>

      <article v-else-if="doc" class="mt-2">
        <h1 class="wm-h1">{{ doc.title }}</h1>
        <p class="wm-meta mt-1.5">
          Version {{ doc.version }} · published {{ formatDate(doc.published_at) }}
          <span v-if="!doc.is_current" class="wm-pill wm-pill-warning !py-0 !text-[11px] ml-1">Earlier version</span>
        </p>

        <p v-if="!doc.is_current" class="wm-notice wm-notice-warning mt-3" role="status">
          <i class="bi bi-clock-history" aria-hidden="true"></i>
          <span>This is an earlier version. <router-link :to="`/legal/${key}`" class="font-medium underline">Read the current one</router-link>.</span>
        </p>

        <div v-if="doc.summary" class="wm-notice wm-notice-info mt-4">
          <i class="bi bi-lightbulb" aria-hidden="true"></i>
          <span><strong class="font-semibold">In short:</strong> {{ doc.summary }}</span>
        </div>

        <!-- Server-rendered from the published Markdown; HTML in the text is escaped there -->
        <div class="wm-legal-body mt-5" v-html="doc.html"></div>

        <section v-if="doc.versions?.length > 1" class="mt-10" aria-labelledby="versions-heading">
          <h2 id="versions-heading" class="wm-meta font-medium uppercase tracking-wide mb-2 px-1">Version history</h2>
          <ul class="wm-card !p-0 divide-y divide-line overflow-hidden">
            <li v-for="v in doc.versions" :key="v.version_id">
              <router-link :to="{ path: `/legal/${key}`, query: v.version_id === doc.versions[0].version_id ? {} : { version: v.version_id } }"
                class="flex items-center gap-3 min-h-[52px] px-4 py-2 hover:bg-white/60" :aria-current="v.version_id === doc.version_id ? 'page' : undefined">
                <span class="flex-1 min-w-0">
                  <span class="block text-[15px] font-medium text-ink">Version {{ v.version }} <span v-if="v.version_id === doc.version_id" class="wm-meta">(showing)</span></span>
                  <span class="block wm-meta">{{ formatDate(v.published_at) }}<template v-if="v.change_note"> · {{ v.change_note }}</template></span>
                </span>
                <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
              </router-link>
            </li>
          </ul>
        </section>
      </article>
    </template>

    <!-- All documents -->
    <template v-else>
      <BackLink to="/account">Account</BackLink>
      <h1 class="wm-h1 mt-1">Legal</h1>
      <p class="wm-lead mt-1.5">How WorkMate works, what we each agree to, and how your information is protected. Terms and conditions apply to everyone who uses WorkMate.</p>

      <div v-if="!legal.loaded && !legal.error" class="mt-5 flex flex-col gap-3" aria-busy="true">
        <div v-for="n in 4" :key="n" class="wm-skeleton h-[76px] !rounded-[20px]"></div>
      </div>
      <EmptyState v-else-if="legal.error && !legal.loaded" class="mt-4" icon="bi-file-earmark-x" title="The documents couldn’t load" :message="legal.error">
        <button type="button" class="wm-btn wm-btn-secondary" @click="loadLegalDocuments({ force: true }).catch(() => {})">Try again</button>
      </EmptyState>
      <ul v-else class="mt-5 flex flex-col gap-3">
        <li v-for="d in legal.documents" :key="d.key">
          <router-link :to="`/legal/${d.key}`" class="wm-card wm-card-link flex items-center gap-4">
            <span class="wm-icon-tile" aria-hidden="true"><i :class="['bi', ICONS[d.key] || 'bi-file-earmark-text']"></i></span>
            <span class="min-w-0 flex-1">
              <span class="block text-[15px] font-semibold text-ink">{{ d.title }}</span>
              <span class="block wm-meta">Version {{ d.version }} · {{ formatDate(d.published_at) }}<template v-if="d.audience === 'providers'"> · for providers</template></span>
            </span>
            <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
          </router-link>
        </li>
      </ul>
    </template>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import BackLink from '@/components/BackLink.vue';
import EmptyState from '@/components/EmptyState.vue';
import { legal, loadLegalDocuments, fetchDocument } from '@/stores/legal.js';

const ICONS = {
  terms: 'bi-file-earmark-text', privacy: 'bi-shield-lock', provider: 'bi-tools', customer: 'bi-person-check',
  community: 'bi-people', disputes: 'bi-arrow-left-right', verification: 'bi-patch-check', safety: 'bi-exclamation-octagon',
};

const route = useRoute();
const router = useRouter();
const key = computed(() => route.params.key || null);
const doc = ref(null);
const loading = ref(false);
const error = ref('');

// Came here from inside WorkMate (the welcome screens, a form): go back there
const cameFromApp = ref(!!window.history.state?.back);

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }) : '');

async function load() {
  if (!key.value) return;
  loading.value = true;
  error.value = '';
  try {
    doc.value = await fetchDocument(key.value, route.query.version || null);
    document.title = `${doc.value.title} – WorkMate`;
  } catch (e) {
    error.value = e.status === 404 ? 'There’s no document at this address.' : e.message;
  } finally {
    loading.value = false;
  }
}

watch(() => [route.params.key, route.query.version], load);
onMounted(() => {
  if (key.value) load();
  else loadLegalDocuments().catch(() => {});
});
</script>
