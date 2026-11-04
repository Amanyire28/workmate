<template>
  <div class="flex items-center gap-3">
    <component :is="link ? 'router-link' : 'span'" :to="link || undefined" class="shrink-0" :tabindex="link ? -1 : undefined" aria-hidden="true">
      <UserAvatar :name="person.name" :src="person.avatar_url || ''" :size="compact ? 40 : 48" />
    </component>
    <div class="min-w-0 flex-1">
      <p class="flex items-center gap-1.5 font-semibold text-ink leading-tight" :class="compact ? 'text-[14px]' : 'text-[15px]'">
        <router-link v-if="link" :to="link" class="truncate hover:underline">{{ person.name }}</router-link>
        <span v-else class="truncate">{{ person.name }}</span>
        <VerifiedBadge :provider="{ documentVerified: person.document_verified, blueTick: person.blue_tick }" />
      </p>
      <p class="mt-0.5 truncate text-muted" :class="compact ? 'text-[12.5px]' : 'text-[13px]'">
        {{ [person.title, person.area, !compact && person.posts_count ? `${person.posts_count} ${person.posts_count === 1 ? 'post' : 'posts'}` : ''].filter(Boolean).join(' · ') || 'Community member' }}
      </p>
    </div>
    <button v-if="!person.me" type="button" class="wm-btn shrink-0 !px-3.5 !text-[13px]" :class="[person.following ? 'wm-btn-secondary' : 'wm-btn-primary', compact ? '!min-h-9' : '!min-h-10']"
      :aria-pressed="person.following" :aria-label="`${person.following ? 'Unfollow' : 'Follow'} ${person.name}`" @click="toggleFollow(person)">
      {{ person.following ? 'Following' : 'Follow' }}
    </button>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import UserAvatar from '@/components/UserAvatar.vue';
import VerifiedBadge from '@/components/VerifiedBadge.vue';
import { toggleFollow, personLink } from '@/stores/community.js';

const props = defineProps({
  person: { type: Object, required: true },
  compact: { type: Boolean, default: false },
});

const link = computed(() => personLink(props.person));
</script>
