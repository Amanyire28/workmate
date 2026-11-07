<template>
  <div class="wm-container wm-container-sm wm-page">
    <h1 class="wm-h1">Welcome to WorkMate{{ firstName ? `, ${firstName}` : '' }}</h1>
    <p class="wm-lead mt-2">What do you want to do first? You can always do the other later.</p>

    <ul class="mt-6 flex flex-col gap-3">
      <li v-for="c in CHOICES" :key="c.id">
        <router-link :to="c.to" class="wm-card wm-card-link flex items-center gap-4 !p-5" @click="remember(c.id)">
          <span class="wm-icon-tile shrink-0" aria-hidden="true"><i :class="['bi', c.icon]"></i></span>
          <span class="min-w-0 flex-1">
            <span class="block text-[17px] font-semibold text-ink">{{ c.title }}</span>
            <span class="block wm-meta mt-0.5">{{ c.text }}</span>
          </span>
          <i class="bi bi-chevron-right text-muted" aria-hidden="true"></i>
        </router-link>
      </li>
    </ul>

    <router-link to="/account?welcome=1" class="wm-link mt-6 inline-flex min-h-11 items-center text-sm">Skip for now</router-link>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { auth } from '@/stores/auth.js';
import { rememberGetStarted as remember } from '@/utils/getStarted.js';

/** Shown once, straight after creating an account */
const CHOICES = [
  { id: 'hire', icon: 'bi-house-gear', title: 'Hire someone', text: 'Find a trusted provider near you, or post a job.', to: '/browse' },
  { id: 'earn', icon: 'bi-briefcase', title: 'Get hired for my skills', text: 'Become a provider in 5 short steps and apply for jobs.', to: '/start-earning' },
  { id: 'both', icon: 'bi-arrow-left-right', title: 'Both', text: 'Set up your provider profile first. You can hire anytime with the same account.', to: '/start-earning' },
];

const firstName = computed(() => (auth.user?.name || '').trim().split(/\s+/)[0] || '');
</script>
