<template>
  <BottomSheet :open="open" :title="item ? 'Edit service' : 'Add a service'" @close="cancel">
    <form :id="formId" class="flex flex-col gap-4" novalidate @submit.prevent="submit">
      <FormField v-model="title" label="Service" required maxlength="80" placeholder="e.g. Socket installation" :error-message="errors.title" />

      <div v-if="categories.length > 1" class="flex flex-col gap-1.5">
        <label :for="`${formId}-category`" class="wm-label">Type of work</label>
        <select :id="`${formId}-category`" v-model="category" class="wm-input">
          <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
        </select>
      </div>

      <fieldset class="flex flex-col gap-2">
        <legend class="wm-label mb-1">How do you price it?</legend>
        <div class="grid gap-2" :class="PRICING.length > 3 ? 'grid-cols-2 sm:grid-cols-4' : 'grid-cols-3'">
          <label v-for="o in PRICING" :key="o.id" class="flex cursor-pointer flex-col items-center justify-center gap-0.5 rounded-xl border px-2 py-2.5 text-center"
            :class="pricing === o.id ? 'border-primary bg-primary-tint text-primary' : 'border-line text-ink hover:bg-[#F8FAFC]'">
            <input v-model="pricing" type="radio" :value="o.id" class="sr-only" />
            <span class="text-[14px] font-semibold">{{ o.label }}</span>
            <span class="text-[11.5px] text-muted">{{ o.hint }}</span>
          </label>
        </div>
      </fieldset>

      <div v-if="pricing !== 'quote'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <FormField v-model="price" type="number" inputmode="numeric" min="500" step="500" prefix="UGX"
          :label="pricing === 'from' ? 'Starting price' : pricing === 'range' ? 'Lowest price' : 'Price'" required placeholder="e.g. 30000" :error-message="errors.price" />
        <FormField v-if="pricing === 'range'" v-model="priceMax" type="number" inputmode="numeric" min="500" step="500" prefix="UGX"
          label="Highest price" required placeholder="e.g. 60000" :error-message="errors.price_max" />
        <div class="flex flex-col gap-1.5">
          <label :for="`${formId}-unit`" class="wm-label">Charged <span class="font-normal text-muted">(optional)</span></label>
          <select :id="`${formId}-unit`" v-model="unit" class="wm-input">
            <option value="">—</option>
            <option v-for="u in UNITS" :key="u.id" :value="u.id">{{ u.label }}</option>
          </select>
        </div>
      </div>
      <p v-else class="wm-field-hint">Customers describe the job and you reply with a price.</p>

      <FormField v-model="description" type="textarea" label="Short description" optional maxlength="300" :min-height="80"
        placeholder="What’s included, materials, how long it usually takes" :error-message="errors.description" />

      <label v-if="item" class="flex items-start gap-3 rounded-xl bg-[#F1F5F9] p-3 cursor-pointer">
        <input v-model="active" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-[var(--color-primary)]" />
        <span>
          <span class="block text-[14.5px] font-medium text-ink">Show on my profile</span>
          <span class="block text-[13px] text-muted">Untick to hide it for now without deleting it.</span>
        </span>
      </label>

      <p class="text-[12.5px] text-muted">Prices are a guide for customers. You agree the final price with each customer before the work starts.</p>
      <p v-if="error" class="wm-notice wm-notice-error" role="alert"><i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ error }}</span></p>
      <button v-if="item" type="button" class="wm-btn wm-btn-ghost self-start !min-h-10 text-[#B91C1C]" :disabled="busy" @click="remove">
        <i class="bi bi-trash3" aria-hidden="true"></i> Remove this service
      </button>
    </form>

    <template #footer>
      <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="busy" @click="cancel">Cancel</button>
      <button type="submit" :form="formId" class="wm-btn wm-btn-primary wm-btn-lg flex-[2]" :disabled="busy">
        <template v-if="busy"><span class="wm-spinner" aria-hidden="true"></span>Saving…</template>
        <template v-else>{{ item ? 'Save changes' : 'Add service' }}</template>
      </button>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch, useId } from 'vue';
import BottomSheet from '@/components/BottomSheet.vue';
import FormField from '@/components/FormField.vue';
import { api, fieldErrors } from '@/api/client.js';
import { showFlash } from '@/stores/flash.js';
import { appSettings } from '@/stores/catalog.js';

const BASE_PRICING = [
  { id: 'from', label: 'From', hint: 'Starting price' },
  { id: 'fixed', label: 'Fixed', hint: 'Set price' },
  { id: 'quote', label: 'Quote', hint: 'Price on request' },
];
const RANGE_PRICING = { id: 'range', label: 'Range', hint: 'From – to' };
const UNITS = [
  { id: 'job', label: 'Per job' }, { id: 'visit', label: 'Per visit' }, { id: 'hour', label: 'Per hour' },
  { id: 'day', label: 'Per day' }, { id: 'item', label: 'Per item' },
];

const props = defineProps({
  open: { type: Boolean, default: false },
  // A service of theirs to edit; empty to add one
  item: { type: Object, default: null },
  // The categories they offer: [{ id, label }]
  categories: { type: Array, default: () => [] },
});
const emit = defineEmits(['close', 'saved', 'removed']);

const formId = useId();
const title = ref('');
const category = ref('');
const pricing = ref('from');
const price = ref('');
const priceMax = ref('');
const unit = ref('');
const description = ref('');
const active = ref(true);
const errors = ref({});
// A price range is offered while pricing options are on (a service already listed as one keeps it)
const PRICING = computed(() => (appSettings.pricingEnabled || props.item?.pricing_type === 'range' ? [BASE_PRICING[0], BASE_PRICING[1], RANGE_PRICING, BASE_PRICING[2]] : BASE_PRICING));
const error = ref('');
const busy = ref(false);

watch(() => props.open, (open) => {
  if (!open) return;
  errors.value = {};
  error.value = '';
  const s = props.item;
  title.value = s?.title || '';
  category.value = s?.category || props.categories[0]?.id || '';
  pricing.value = s?.pricing_type || 'from';
  price.value = s?.price ?? '';
  priceMax.value = s?.price_max ?? '';
  unit.value = s?.price_unit || '';
  description.value = s?.description || '';
  active.value = s ? s.is_active !== false : true;
});

function cancel() {
  if (!busy.value) emit('close');
}

async function submit() {
  if (busy.value) return;
  errors.value = {};
  error.value = '';
  if (!title.value.trim()) { errors.value = { title: 'Give the service a name.' }; return; }
  if (pricing.value !== 'quote' && !(Number(price.value) >= 500)) { errors.value = { price: 'Enter your usual price in UGX (at least 500).' }; return; }
  if (pricing.value === 'range' && !(Number(priceMax.value) > Number(price.value))) { errors.value = { price_max: 'Enter a highest price that is more than the lowest.' }; return; }

  const body = {
    title: title.value.trim(),
    description: description.value.trim() || null,
    category: category.value || null,
    pricing_type: pricing.value,
    price: pricing.value === 'quote' ? null : Math.round(Number(price.value)),
    price_max: pricing.value === 'range' ? Math.round(Number(priceMax.value)) : null,
    price_unit: pricing.value === 'quote' ? null : (unit.value || null),
  };
  if (props.item) body.is_active = active.value;

  busy.value = true;
  try {
    const saved = props.item
      ? (await api(`/me/provider/services/${props.item.id}`, { method: 'PATCH', body })).data
      : (await api('/me/provider/services', { method: 'POST', body })).data;
    emit('saved', saved, !props.item);
    showFlash(props.item ? 'Service saved.' : 'Service added to your profile.');
    emit('close');
  } catch (e) {
    errors.value = fieldErrors(e);
    if (!Object.keys(errors.value).length) error.value = e.message;
  } finally {
    busy.value = false;
  }
}

async function remove() {
  if (!props.item || busy.value) return;
  if (!window.confirm(`Remove “${props.item.title}” from your profile? Requests that mentioned it aren’t affected.`)) return;
  busy.value = true;
  try {
    await api(`/me/provider/services/${props.item.id}`, { method: 'DELETE' });
    emit('removed', props.item.id);
    showFlash('Service removed.');
    emit('close');
  } catch (e) {
    error.value = e.message;
  } finally {
    busy.value = false;
  }
}
</script>
