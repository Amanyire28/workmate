<template>
  <BottomSheet :open="!!item" :title="ratePrompt.thanked ? 'Thank you' : 'Your job is done'" @close="ratePrompt.thanked ? next() : notNow()">
    <template v-if="item">
      <!-- Saved -->
      <div v-if="ratePrompt.thanked" class="flex flex-col items-center text-center gap-3 py-4" role="status">
        <span class="w-14 h-14 rounded-full bg-[#DCFCE7] text-[#15803D] flex items-center justify-center text-2xl" aria-hidden="true">
          <i class="bi bi-check-lg"></i>
        </span>
        <p class="text-[15px] text-ink">Your rating of <strong class="font-semibold">{{ item.provider.display_name }}</strong> is posted. It helps others choose who to hire.</p>
      </div>

      <form v-else id="rate-provider-form" class="flex flex-col gap-4" @submit.prevent="send">
        <div class="flex items-center gap-3">
          <UserAvatar :name="item.provider.display_name" :src="item.provider.avatar_url || ''" :size="52" />
          <p class="text-[15px] text-ink leading-snug">
            <strong class="font-semibold">{{ item.provider.display_name }}</strong> has finished your {{ categoryLabel(item.category).toLowerCase() }} job. How did it go?
          </p>
        </div>

        <fieldset>
          <legend class="wm-label">Your rating <span class="text-error" aria-hidden="true">*</span></legend>
          <div class="flex gap-1" role="radiogroup" aria-label="Stars">
            <button
              v-for="n in 5"
              :key="n"
              type="button"
              role="radio"
              :aria-checked="rating === n"
              :aria-label="`${n} star${n === 1 ? '' : 's'}: ${WORDS[n - 1]}`"
              class="w-12 h-12 flex items-center justify-center rounded-xl text-[30px] leading-none transition-transform active:scale-90"
              :class="n <= (hover || rating) ? 'text-[#F59E0B]' : 'text-[#CBD5E1]'"
              @click="rating = n"
              @mouseenter="hover = n"
              @mouseleave="hover = 0"
            >
              <i :class="['bi', n <= (hover || rating) ? 'bi-star-fill' : 'bi-star']" aria-hidden="true"></i>
            </button>
          </div>
          <p class="mt-1 text-sm" :class="rating ? 'text-ink' : 'text-muted'">{{ rating ? WORDS[rating - 1] : 'Tap the stars to rate.' }}</p>
        </fieldset>

        <FormField
          v-model="comment"
          type="textarea"
          label="Tell others about the work"
          optional
          maxlength="1000"
          placeholder="What went well? Was anything wrong?"
          :min-height="90"
        />

        <p v-if="ratePrompt.error" class="wm-notice wm-notice-error" role="alert">
          <i class="bi bi-exclamation-circle" aria-hidden="true"></i><span>{{ ratePrompt.error }}</span>
        </p>
      </form>
    </template>

    <template #footer>
      <template v-if="ratePrompt.thanked">
        <button type="button" class="wm-btn wm-btn-primary wm-btn-lg flex-1" @click="next">Continue</button>
      </template>
      <template v-else>
        <button type="button" class="wm-btn wm-btn-secondary wm-btn-lg flex-1" :disabled="ratePrompt.busy" @click="notNow">
          {{ ratePrompt.fromAppOpen ? 'Continue to WorkMate' : 'Not now' }}
        </button>
        <button type="submit" form="rate-provider-form" class="wm-btn wm-btn-primary wm-btn-lg flex-1" :disabled="!rating || ratePrompt.busy">
          {{ ratePrompt.busy ? 'Posting…' : 'Post rating' }}
        </button>
      </template>
    </template>
  </BottomSheet>
</template>

<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import BottomSheet from './BottomSheet.vue';
import FormField from './FormField.vue';
import UserAvatar from './UserAvatar.vue';
import { ratePrompt, submitRating, notNow, next, checkWaitingReviews } from '@/stores/reviews.js';
import { auth } from '@/stores/auth.js';
import { categoryLabel } from '@/utils/ui.js';

const WORDS = ['Poor', 'Not great', 'Okay', 'Good', 'Excellent'];

const item = computed(() => ratePrompt.current);
const rating = ref(0);
const hover = ref(0);
const comment = ref('');

// A fresh form for each job
watch(item, () => { rating.value = 0; hover.value = 0; comment.value = ''; });

function send() {
  if (rating.value) submitRating(rating.value, comment.value, ratingSaved);
}

// Finished jobs to rate come first when the app opens or someone signs in,
// and again when the app comes back to the screen
watch(() => auth.user?.id, (id) => { if (id) checkWaitingReviews({ force: true }); }, { immediate: true });
function onVisible() {
  if (document.visibilityState === 'visible' && auth.user) checkWaitingReviews();
}
onMounted(() => document.addEventListener('visibilitychange', onVisible));
onBeforeUnmount(() => document.removeEventListener('visibilitychange', onVisible));

// Pages that list the job (My Work) update it in place
function ratingSaved(id, review) {
  window.dispatchEvent(new CustomEvent('workmate:rated', { detail: { requestId: id, review } }));
}
</script>
