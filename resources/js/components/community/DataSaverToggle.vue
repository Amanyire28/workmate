<template>
  <div class="flex items-center justify-between gap-3 rounded-2xl border border-line/70 bg-white/80 px-3.5 py-2.5">
    <div class="min-w-0">
      <p :id="`${uid}-label`" class="flex items-center gap-1.5 text-[14px] font-semibold text-ink">
        <i class="bi bi-cloud-arrow-down text-primary" aria-hidden="true"></i>Data Saver
      </p>
      <p :id="`${uid}-hint`" class="text-[12.5px] text-muted">
        {{ videoPrefs.dataSaver ? 'Videos load only when you tap play.' : videoPrefs.reducedMotion ? 'Videos still wait for a tap: your phone is set to reduce motion.' : 'Videos play silently as you scroll to them.' }}
      </p>
    </div>
    <button
      type="button"
      role="switch"
      :aria-checked="videoPrefs.dataSaver ? 'true' : 'false'"
      :aria-labelledby="`${uid}-label`"
      :aria-describedby="`${uid}-hint`"
      class="relative h-7 w-12 shrink-0 rounded-full transition-colors"
      :class="videoPrefs.dataSaver ? 'bg-primary' : 'bg-[#CBD5E1]'"
      @click="setDataSaver(!videoPrefs.dataSaver)"
    >
      <span class="absolute top-0.5 left-0.5 h-6 w-6 rounded-full bg-white shadow transition-transform" :class="videoPrefs.dataSaver ? 'translate-x-5' : ''" aria-hidden="true"></span>
    </button>
  </div>
</template>

<script setup>
import { useId } from 'vue';
import { videoPrefs, setDataSaver } from '@/stores/video.js';

const uid = useId();
</script>
