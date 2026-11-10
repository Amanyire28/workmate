<template>
  <!-- The newest community posts, below the marketplace; nothing at all until there are some -->
  <section v-if="status !== 'ready' || posts.length" class="wm-container pt-6 lg:pt-9" aria-labelledby="home-recent">
    <div class="flex items-center justify-between gap-4 mb-3 lg:mb-4">
      <h2 id="home-recent" class="text-xl font-semibold text-ink">Recent from the community</h2>
      <router-link to="/community" class="wm-link !text-sm inline-flex items-center gap-1 min-h-11 shrink-0">
        See all <i class="bi bi-arrow-right hidden lg:inline" aria-hidden="true"></i>
      </router-link>
    </div>

    <!-- Loading -->
    <ul v-if="status === 'loading'" class="wm-home-row" aria-busy="true">
      <li v-for="n in 3" :key="n" class="wm-home-row-item"><div class="wm-skeleton h-[184px] !rounded-[14px]"></div></li>
    </ul>

    <!-- Couldn't load: say so quietly, the rest of the page still works -->
    <p v-else-if="status === 'error'" class="wm-glass-card rounded-[14px] p-4 text-sm text-[#64748B]">
      Recent posts couldn’t load.
      <button type="button" class="wm-link font-medium" @click="load">Try again</button>
    </p>

    <ul v-else class="wm-home-row">
      <li v-for="p in posts" :key="p.id" class="wm-home-row-item">
        <router-link
          :to="`/community/post/${p.id}`"
          class="flex h-full flex-col gap-2.5 wm-glass-card rounded-[14px] p-4 transition-[box-shadow,border-color,transform] hover:-translate-y-0.5 hover:border-[#CBD5E1] hover:shadow-[0_8px_20px_rgba(15,23,42,0.08)]"
        >
          <div class="flex items-center gap-2.5 min-w-0">
            <UserAvatar :name="p.author.name" :src="p.author.avatar_url || ''" :size="36" />
            <div class="min-w-0 flex-1">
              <p class="text-[14px] font-semibold text-ink truncate">{{ p.author.name }}</p>
              <p class="text-[12px] text-[#64748B] truncate">
                {{ agoLabel(p.created_at) }}<template v-if="p.author.title"> · {{ p.author.title }}</template>
              </p>
            </div>
          </div>

          <div class="flex gap-3 min-w-0 flex-1">
            <div class="min-w-0 flex-1">
              <span class="inline-flex items-center gap-1 text-[11.5px] font-medium text-primary">
                <i :class="['bi', categoryOf(p.category).icon]" aria-hidden="true"></i>{{ categoryOf(p.category).label }}
              </span>
              <p class="mt-0.5 text-[14px] text-ink leading-snug line-clamp-3 break-words">{{ p.body || 'Shared photos' }}</p>
            </div>
            <img
              v-if="p.media.length"
              :src="p.media[0].thumb_url"
              :alt="p.media.length > 1 ? `${p.media.length} photos` : 'Photo'"
              width="72" height="72"
              class="w-[72px] h-[72px] shrink-0 rounded-[10px] object-cover bg-[#F1F5F9]"
              loading="lazy"
              decoding="async"
            />
          </div>

          <p class="flex items-center gap-4 text-[12.5px] text-[#64748B]">
            <span><i class="bi bi-heart mr-1" aria-hidden="true"></i>{{ p.likes_count }}<span class="sr-only"> likes</span></span>
            <span><i class="bi bi-chat mr-1" aria-hidden="true"></i>{{ p.comments_count }}<span class="sr-only"> comments</span></span>
          </p>
        </router-link>
      </li>
    </ul>
  </section>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import UserAvatar from './UserAvatar.vue';
import { api } from '@/api/client.js';
import { agoLabel } from '@/stores/catalog.js';
import { categoryOf } from '@/stores/community.js';

const SHOWN = 6;
const posts = ref([]);
const status = ref('loading');

async function load() {
  status.value = 'loading';
  try {
    const { data } = await api('/community/posts?tab=latest');
    posts.value = data.slice(0, SHOWN);
    status.value = 'ready';
  } catch {
    status.value = 'error';
  }
}
onMounted(load);
</script>

