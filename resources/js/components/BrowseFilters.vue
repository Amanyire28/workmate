<template>
  <div class="flex flex-col gap-6">
    <fieldset>
      <legend class="wm-label">Show only</legend>
      <div class="flex flex-col">
        <label class="flex items-center gap-3 min-h-11 cursor-pointer text-[15px] text-ink">
          <input
            type="checkbox"
            class="wm-check"
            :checked="modelValue.available === '1'"
            @change="set('available', $event.target.checked ? '1' : '')"
          />
          <span class="inline-flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-success" aria-hidden="true"></span>Available now
          </span>
        </label>
        <label class="flex items-center gap-3 min-h-11 cursor-pointer text-[15px] text-ink">
          <input
            type="checkbox"
            class="wm-check"
            :checked="modelValue.verified === '1'"
            @change="set('verified', $event.target.checked ? '1' : '')"
          />
          <span class="inline-flex items-center gap-1.5">
            <i class="bi bi-patch-check-fill text-primary" aria-hidden="true"></i>Verified
          </span>
        </label>
      </div>
    </fieldset>

    <PlacePicker
      :model-value="modelValue.area"
      label="Near"
      any-label="All areas"
      :id="`${idPrefix}-area`"
      @update:model-value="set('area', $event)"
    />

    <fieldset>
      <legend class="wm-label">Provider type</legend>
      <div class="flex flex-col">
        <label
          v-for="opt in typeOptions"
          :key="opt.value"
          class="flex items-center gap-3 min-h-11 cursor-pointer text-[15px] text-ink"
        >
          <input
            type="radio"
            :name="`${idPrefix}-type`"
            :value="opt.value"
            :checked="modelValue.type === opt.value"
            class="w-5 h-5 accent-primary"
            @change="set('type', opt.value)"
          />
          {{ opt.label }}
        </label>
      </div>
    </fieldset>

    <fieldset>
      <legend class="wm-label">Rating</legend>
      <div class="flex flex-col">
        <label
          v-for="opt in ratingOptions"
          :key="opt.value"
          class="flex items-center gap-3 min-h-11 cursor-pointer text-[15px] text-ink"
        >
          <input
            type="radio"
            :name="`${idPrefix}-rating`"
            :value="opt.value"
            :checked="modelValue.rating === opt.value"
            class="w-5 h-5 accent-primary"
            @change="set('rating', opt.value)"
          />
          <span class="inline-flex items-center gap-1.5">
            <i v-if="opt.value" class="bi bi-star-fill text-[#D97706] text-sm" aria-hidden="true"></i>
            {{ opt.label }}
          </span>
        </label>
      </div>
    </fieldset>

    <FormField
      :model-value="modelValue.sort"
      type="select"
      label="Sort by"
      :id="`${idPrefix}-sort`"
      @update:model-value="set('sort', $event)"
    >
      <option value="">Recommended</option>
      <option value="rating">Highest rated</option>
      <option value="price">Lowest starting price</option>
    </FormField>
  </div>
</template>

<script setup>
import FormField from './FormField.vue';
import PlacePicker from './PlacePicker.vue';

const props = defineProps({
  /** { available, verified, area, type, rating, sort } — all strings, '' means "any" */
  modelValue: { type: Object, required: true },
  idPrefix:   { type: String, default: 'filters' },
});
const emit = defineEmits(['update:modelValue']);

const typeOptions = [
  { value: '',           label: 'Any' },
  { value: 'individual', label: 'Individual' },
  { value: 'team',       label: 'Team' },
  { value: 'business',   label: 'Business' },
];

const ratingOptions = [
  { value: '',    label: 'Any rating' },
  { value: '4.5', label: '4.5 and above' },
  { value: '4.7', label: '4.7 and above' },
];

function set(key, value) {
  emit('update:modelValue', { ...props.modelValue, [key]: value });
}
</script>
