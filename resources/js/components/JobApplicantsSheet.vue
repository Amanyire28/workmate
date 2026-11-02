<template>
  <BottomSheet :open="!!job" :title="job ? `Applicants for “${job.title}”` : ''" @close="$emit('close')">
    <div v-if="loading" class="flex items-center gap-2 wm-meta py-4" role="status">
      <span class="wm-spinner text-primary" aria-hidden="true"></span>Loading applicants…
    </div>
    <p v-else-if="error" class="wm-notice wm-notice-error" role="alert">
      <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span>
    </p>
    <p v-else-if="!applicants.length" class="wm-meta py-4">Nobody has applied yet.</p>

    <ul v-else class="flex flex-col gap-3">
      <li v-for="a in applicants" :key="a.id" class="wm-card !p-4 flex flex-col gap-3">
        <div class="flex items-start justify-between gap-3">
          <div class="flex items-center gap-3 min-w-0">
            <UserAvatar :name="a.provider.display_name" :src="a.provider.avatar_url || ''" :size="44" />
            <div class="min-w-0">
              <p class="text-[15px] font-semibold text-ink truncate">{{ a.provider.display_name }}</p>
              <p class="wm-meta truncate">
                {{ [a.provider.main_profession, a.provider.area].filter(Boolean).join(' · ') }}
              </p>
              <p class="wm-meta">
                <template v-if="a.provider.rating">
                  <i class="bi bi-star-fill text-[#F59E0B]" aria-hidden="true"></i>
                  {{ a.provider.rating.toFixed(1) }} ({{ a.provider.review_count }} {{ a.provider.review_count === 1 ? 'review' : 'reviews' }})
                </template>
                <template v-else>No reviews yet</template>
              </p>
            </div>
          </div>
          <StatusBadge v-if="a.status !== 'pending'" :status="a.status === 'accepted' ? 'accepted' : 'declined'" class="shrink-0" />
        </div>

        <p class="text-sm text-ink whitespace-pre-line">{{ a.message }}</p>
        <p class="wm-meta flex flex-wrap gap-x-4 gap-y-1">
          <span v-if="a.amount" class="inline-flex items-center gap-1"><i class="bi bi-cash" aria-hidden="true"></i>{{ formatPrice(a.amount) }}</span>
          <span v-if="a.start_time" class="inline-flex items-center gap-1"><i class="bi bi-calendar3" aria-hidden="true"></i>Can start {{ a.start_time }}</span>
          <span>Applied {{ agoLabel(a.applied_at).toLowerCase() }}</span>
        </p>

        <!-- Confirm before hiring: the others are told the job is filled -->
        <div v-if="confirming === a.id" class="flex flex-col gap-2 rounded-xl bg-primary-tint p-3">
          <p class="text-sm text-ink">Hire <strong>{{ a.provider.display_name }}</strong>? Everyone else who applied will be told the job is filled.</p>
          <div class="flex gap-2">
            <button type="button" class="wm-btn wm-btn-secondary !min-h-11 flex-1" :disabled="busy" @click="confirming = null">Back</button>
            <button type="button" class="wm-btn wm-btn-primary !min-h-11 flex-1" :disabled="busy" @click="hire(a)">{{ busy ? 'Hiring…' : 'Hire' }}</button>
          </div>
        </div>
        <div v-else class="flex flex-wrap gap-2 pt-1">
          <router-link :to="`/provider/${a.provider.id}`" class="wm-btn wm-btn-ghost !min-h-11">View profile</router-link>
          <router-link :to="`/messages/${a.provider.id}`" class="wm-btn wm-btn-ghost !min-h-11"><i class="bi bi-chat-dots" aria-hidden="true"></i>Message</router-link>
          <button
            v-if="canHire && a.status === 'pending'"
            type="button"
            class="wm-btn wm-btn-primary !min-h-11 ml-auto"
            :disabled="!a.provider.available"
            :title="a.provider.available ? undefined : 'This provider can’t take work right now'"
            @click="confirming = a.id"
          >Hire</button>
        </div>
      </li>
    </ul>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import BottomSheet from './BottomSheet.vue';
import UserAvatar from './UserAvatar.vue';
import StatusBadge from './StatusBadge.vue';
import { api } from '@/api/client.js';
import { agoLabel } from '@/stores/catalog.js';
import { formatPrice } from '@/utils/ui.js';

/** The providers who applied to one of the customer's posted jobs, to compare and hire */
const props = defineProps({
  job: { type: Object, default: null },   // { id, title, status }
});
const emit = defineEmits(['close', 'hired']);

const applicants = ref([]);
const loading = ref(false);
const error = ref('');
const confirming = ref(null);
const busy = ref(false);
const canHire = computed(() => props.job?.status === 'waiting');

async function load() {
  loading.value = true;
  error.value = '';
  confirming.value = null;
  try {
    applicants.value = (await api(`/me/jobs/${props.job.id}/applications`)).data;
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}
watch(() => props.job?.id, (id) => { if (id) load(); });

async function hire(a) {
  busy.value = true;
  error.value = '';
  try {
    const job = (await api(`/me/jobs/${props.job.id}/applications/${a.id}/hire`, { method: 'POST', body: {} })).data;
    emit('hired', job);
    emit('close');
  } catch (e) {
    error.value = e.message;
    confirming.value = null;
  } finally {
    busy.value = false;
  }
}
</script>
